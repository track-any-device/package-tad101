<?php

declare(strict_types=1);

namespace TrackAnyDevice\Tad101;

use TrackAnyDevice\Drivers\Concerns\QueuesSmsCommands;
use TrackAnyDevice\Drivers\Contracts\DeviceDriverInterface;
use TrackAnyDevice\Drivers\ValueObjects\AddOnCommand;
use TrackAnyDevice\Drivers\ValueObjects\SignalObject;
use TrackAnyDevice\Core\Enums\DeviceCommandStatus;
use TrackAnyDevice\Core\Enums\DeviceLogSource;
use TrackAnyDevice\Core\Enums\SignalEventType;
use TrackAnyDevice\Core\Enums\SignalSource;
use TrackAnyDevice\Tad101\Events\Tad101CommandEvent;
use TrackAnyDevice\Core\Models\Device;
use TrackAnyDevice\Core\Models\DeviceCommand;
use TrackAnyDevice\Core\Services\DeviceLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Driver for the TAD101 universal WebSocket streaming protocol.
 *
 * TAD101 carries device telemetry over a Pusher-compatible WebSocket
 * (Soketi). Any device that can open a TLS WebSocket and speak the
 * envelope defined in docs/devices/tad101.md is a first-class citizen:
 * Android / iOS apps, Arduino / ESP32 boards, Raspberry Pi clusters,
 * and anything else.
 *
 * Architectural rules (RULE DOC-1 .. DOC-5 in the spec):
 *  - TAD101 is a strict SUPERSET of every other driver's command surface.
 *    If JT808/GT06/H02/GPS103 expose a command, TAD101 exposes an
 *    equivalent that either streams it or no-ops gracefully.
 *  - `parseEventToSignal()` is pure: no I/O, no DB, never throws.
 *  - Commands prefer the live stream when supportsStream() is true; the
 *    GSM SMS fallback only fires when the device record has a gsm_number.
 *
 * TAD101_DOC_UPDATE: DOC-5 — keep docs/devices/tad101.md and the Part 4
 * snippets in the public docs synchronised whenever this file changes.
 */
class Tad101Driver implements DeviceDriverInterface
{
    use QueuesSmsCommands;

    // ── Identity ──────────────────────────────────────────────────────────

    public function getStreamChannel(): string
    {
        return 'soketi';
    }

    /**
     * A TAD101 device is "streaming" when we've seen any signal recently OR
     * the device record has been explicitly marked connected (set by the
     * Soketi presence webhook).
     */
    public function supportsStream(Device $device): bool
    {
        $window = (int) config('tad101.stream_freshness_minutes', 5);

        if ($device->last_signal_at !== null && $device->last_signal_at->isAfter(now()->subMinutes($window))) {
            return true;
        }

        return (bool) ($device->metadata['tad101_connected'] ?? false);
    }

    // ── Core: Parse Inbound Event ─────────────────────────────────────────

    /**
     * Parse a TAD101 envelope into a SignalObject.
     *
     * Accepts either the raw envelope (with top-level `tad`, `event`, `ts`,
     * `payload` keys) OR a `{ source, raw }` shape used by the SMS pipeline
     * for parity with the rest of the drivers.
     *
     * @param  array<string, mixed>  $rawEvent
     */
    public function parseEventToSignal(array $rawEvent, Device $device): SignalObject
    {
        // SMS fall-through: this driver doesn't natively receive SMS, but
        // when a TAD101 device also has a SIM (e.g. fallback path), we still
        // emit a minimal SignalObject so the IncomingSmsObserver doesn't drop it.
        if (($rawEvent['source'] ?? null) === SignalSource::GsmSms->value) {
            return $this->parseSmsToSignal((string) ($rawEvent['raw'] ?? ''), $device);
        }

        $payload = is_array($rawEvent['payload'] ?? null) ? $rawEvent['payload'] : [];
        $eventName = (string) ($rawEvent['event'] ?? 'update');
        $extra = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];

        return new SignalObject(
            eventType: $this->mapEventType($eventName, $payload),
            source: SignalSource::StreamSoketi,
            latitude: isset($payload['lat']) ? (float) $payload['lat'] : null,
            longitude: isset($payload['lng']) ? (float) $payload['lng'] : null,
            altitude: isset($payload['alt']) ? (int) $payload['alt'] : null,
            speed: isset($payload['speed']) ? (float) $payload['speed'] : null,
            direction: isset($payload['direction']) ? (int) $payload['direction'] : null,
            gpsFixed: (bool) ($payload['gps_fixed'] ?? false),
            satellites: isset($payload['satellites']) ? (int) $payload['satellites'] : null,
            positioningType: isset($payload['pos_type']) ? (string) $payload['pos_type'] : null,
            hdop: isset($payload['hdop']) ? (float) $payload['hdop'] : null,
            batteryPercent: isset($payload['battery_pct']) ? (int) $payload['battery_pct'] : null,
            batteryVoltage: isset($payload['battery_mv']) ? (int) $payload['battery_mv'] : null,
            batteryCapacityMah: isset($payload['battery_mah']) ? (int) $payload['battery_mah'] : null,
            batteryLength: isset($payload['battery_eta']) ? (string) $payload['battery_eta'] : null,
            gsmSignal: isset($payload['gsm_signal']) ? (int) $payload['gsm_signal'] : null,
            networkSignal: isset($payload['network_signal']) ? (int) $payload['network_signal'] : null,
            mcc: isset($payload['mcc']) ? (int) $payload['mcc'] : null,
            mnc: isset($payload['mnc']) ? (int) $payload['mnc'] : null,
            lac: isset($payload['lac']) ? (int) $payload['lac'] : null,
            cellId: isset($payload['cell_id']) ? (int) $payload['cell_id'] : null,
            workingMode: isset($payload['mode']) ? (string) $payload['mode'] : null,
            alarmFlags: isset($payload['alarm_flags']) ? (int) $payload['alarm_flags'] : null,
            statusFlags: isset($payload['status_flags']) ? (int) $payload['status_flags'] : null,
            level: isset($payload['level']) ? (float) $payload['level'] : null,
            temperature: isset($payload['temperature']) ? (float) $payload['temperature'] : null,
            rawPayload: json_encode($rawEvent, JSON_UNESCAPED_SLASHES),
            extra: array_filter([
                'seq' => $rawEvent['seq'] ?? null,
                'tad_event' => $eventName,
                'cmd_id' => $payload['cmd_id'] ?? null,
                'cmd_status' => $payload['status'] ?? null,
                'custom_type' => $payload['custom_type'] ?? null,
                'extra' => $extra ?: null,
            ], static fn ($v) => $v !== null),
            deviceTime: $this->parseDeviceTime($rawEvent['ts'] ?? null),
        );
    }

    public function parseSmsToSignal(string $rawSms, Device $device): SignalObject
    {
        // Pure stream protocol — preserve any SMS we receive as a raw
        // heartbeat so it isn't silently dropped.
        return new SignalObject(
            eventType: SignalEventType::Heartbeat,
            source: SignalSource::GsmSms,
            rawPayload: $rawSms,
        );
    }

    // ── Requests / Modes ──────────────────────────────────────────────────

    public function requestSignal(string $signalType, Device $device): void
    {
        if ($this->supportsStream($device)) {
            $this->dispatchStreamCommand($device, 'request_signal', ['type' => $signalType]);

            return;
        }

        if ($device->gsm_number) {
            $this->queueSms('request_location', [], $device);
        }
    }

    public function setMode(string $mode, Device $device, array $params = []): void
    {
        $payload = array_merge(['mode' => $mode], $params);

        if ($this->supportsStream($device)) {
            $this->dispatchStreamCommand($device, 'set_mode', $payload);

            return;
        }

        if ($device->gsm_number) {
            $this->queueSms('set_mode', $payload, $device);
        }
    }

    public function getMode(Device $device): ?string
    {
        if ($this->supportsStream($device)) {
            $this->dispatchStreamCommand($device, 'get_mode', []);
        } elseif ($device->gsm_number) {
            $this->queueSms('check_params', [], $device);
        }

        return $device->metadata['working_mode'] ?? null;
    }

    // ── Onboarding ────────────────────────────────────────────────────────

    /**
     * Provision a freshly-registered TAD101 device:
     *  1. Generate a per-device secret so the Pusher channel auth endpoint
     *     can verify the device.
     *  2. If the device also has a SIM, fire the equivalent GSM SMS
     *     bootstrap (server host, APN, timezone, default mode) so the
     *     device still onboards when it can't reach the WebSocket.
     *  3. Push a one-time `onboard` command onto the stream channel so the
     *     device receives its credentials and starts emitting telemetry.
     */
    public function onboardingAction(Device $device): void
    {
        $plainSecret = Str::random(48);
        $metadata = $device->metadata ?? [];
        $metadata['tad101_secret'] = bcrypt($plainSecret);
        $metadata['tad101_provisioned_at'] = now()->toIso8601String();
        $device->metadata = $metadata;
        $device->save();

        $host = (string) (config('tad101.server_host') ?? parse_url((string) config('app.url'), PHP_URL_HOST) ?? 'localhost');
        $port = (int) config('tad101.soketi_port', 6001);
        $wsUrl = (string) (config('tad101.ws_url') ?? sprintf('wss://%s', $host));
        $defaultMode = (string) config('tad101.default_mode', 'vibration');

        if ($device->gsm_number) {
            $this->queueSms('set_server', ['host' => $host, 'port' => $port], $device);
            if ($device->gsmNetwork) {
                $this->queueSms('set_apn', ['apn' => $device->gsmNetwork->apn], $device);
            }
            $this->queueSms('set_timezone', ['offset' => (int) config('tad101.timezone_offset', 0)], $device);
            $this->queueSms('set_mode', ['mode' => $defaultMode], $device);
        }

        $this->dispatchStreamCommand($device, 'onboard', [
            'channel' => 'tad101.device.'.$device->imei,
            'secret_key' => $plainSecret,
            'server_ws' => $wsUrl,
            'server_host' => $host,
            'server_port' => $port,
            'default_mode' => $defaultMode,
            'report_interval' => (int) config('tad101.default_report_interval', 30),
        ]);
    }

    // ── Add-on Commands ───────────────────────────────────────────────────
    //
    // TAD101_DOC_UPDATE: DOC-3 — every command added here MUST also be
    // reflected in docs/devices/tad101.md (Part 12) and, where applicable,
    // appear in the GSM SMS map in `buildSmsBody()` below.

    public function addOnCommands(): array
    {
        return [
            // ── NETWORK ───────────────────────────────────────────────────
            new AddOnCommand('set_apn', 'Set APN',
                ['apn' => ['type' => 'string', 'required' => true]],
                'network', false),
            new AddOnCommand('set_server', 'Set Server Host/Port',
                ['host' => ['type' => 'string', 'required' => true], 'port' => ['type' => 'integer', 'required' => true]],
                'network', false),
            new AddOnCommand('set_timezone', 'Set Timezone Offset',
                ['offset' => ['type' => 'integer', 'min' => -12, 'max' => 14, 'required' => true]],
                'network', false),

            // ── TRACKING ──────────────────────────────────────────────────
            new AddOnCommand('set_mode', 'Set Tracking Mode',
                ['mode' => ['type' => 'select', 'options' => ['power_saving', 'timing', 'realtime', 'vibration'], 'required' => true], 'interval' => ['type' => 'string']],
                'tracking', false),
            new AddOnCommand('request_location', 'Request Location Now', [], 'tracking', false),
            new AddOnCommand('get_mode', 'Query Current Mode', [], 'tracking', false),

            // ── PHONE & SOS ───────────────────────────────────────────────
            new AddOnCommand('set_family_numbers', 'Set Family Numbers',
                ['number1' => ['type' => 'string', 'required' => true], 'number2' => ['type' => 'string']],
                'phone', false),
            new AddOnCommand('check_family_numbers', 'Check Family Numbers', [], 'phone', false),
            new AddOnCommand('set_sos_number', 'Set SOS Number',
                ['number' => ['type' => 'string', 'required' => true]],
                'alarm', false),
            new AddOnCommand('remove_sos_number', 'Remove SOS Number', [], 'alarm', false),
            new AddOnCommand('set_whitelist', 'Set Call Whitelist',
                ['numbers' => ['type' => 'tags', 'required' => true]],
                'phone', false),
            new AddOnCommand('check_whitelist', 'Check Whitelist', [], 'phone', false),

            // ── ALARMS ────────────────────────────────────────────────────
            new AddOnCommand('set_alarm_delivery', 'Set Alarm Delivery',
                ['mode' => ['type' => 'select', 'options' => [1, 2, 3], 'required' => true]],
                'alarm', false),
            new AddOnCommand('low_battery_alarm', 'Low Battery Alarm',
                ['enabled' => ['type' => 'boolean']],
                'alarm', false),
            new AddOnCommand('set_wakeup_alarm', 'Wakeup Alarm',
                ['time' => ['type' => 'time', 'required' => true], 'days' => ['type' => 'string']],
                'alarm', false),
            new AddOnCommand('set_dnd', 'Do Not Disturb',
                ['start' => ['type' => 'time'], 'end' => ['type' => 'time'], 'days' => ['type' => 'string']],
                'utility', false),
            new AddOnCommand('delete_dnd', 'Delete DND', [], 'utility', false),
            new AddOnCommand('set_sleep_period', 'Auto Sleep Period',
                ['start' => ['type' => 'time'], 'end' => ['type' => 'time'], 'days' => ['type' => 'string']],
                'utility', false),
            new AddOnCommand('delete_sleep_period', 'Delete Sleep Period', [], 'utility', false),

            // ── INTERCOM ─────────────────────────────────────────────────
            new AddOnCommand('enable_intercom', 'Enable Intercom',
                ['enabled' => ['type' => 'boolean']],
                'intercom', false),
            new AddOnCommand('set_intercom_group', 'Set Intercom Group',
                ['group_name' => ['type' => 'string', 'required' => true, 'max' => 200]],
                'intercom', false),

            // ── UTILITY ──────────────────────────────────────────────────
            new AddOnCommand('check_params', 'Check Device Parameters', [], 'utility', false),
            new AddOnCommand('sub_check_params', 'Sub-Check Parameters', [], 'utility', false),
            new AddOnCommand('set_volume', 'Adjust Volume',
                ['level' => ['type' => 'integer', 'min' => 0, 'max' => 8, 'required' => true]],
                'utility', false),
            new AddOnCommand('check_firmware', 'Check Firmware Version', [], 'utility', false),
            new AddOnCommand('reboot', 'Reboot Device', [], 'utility', false),
            new AddOnCommand('factory_reset', 'Factory Reset', [], 'utility', false),

            // ── TAD101 NATIVE (no GSM equivalent) ────────────────────────
            new AddOnCommand('tad101_ping', 'Ping Device (TAD101)', [], 'tad101', false),
            new AddOnCommand('tad101_config_dump', 'Dump Full Config', [], 'tad101', false),
            new AddOnCommand('tad101_set_secret', 'Rotate Auth Secret', [], 'tad101', false),
            new AddOnCommand('tad101_set_report_interval', 'Set Report Interval',
                ['seconds' => ['type' => 'integer', 'min' => 5, 'max' => 86400, 'required' => true]],
                'tad101', false),
        ];
    }

    public function addOnCommand(string $commandName, array $parameters, Device $device): void
    {
        if ($this->supportsStream($device)) {
            $this->dispatchStreamCommand($device, $commandName, $parameters);

            return;
        }

        if ($device->gsm_number && $this->commandHasSmsFallback($commandName)) {
            $this->queueSms($commandName, $parameters, $device);
        }
    }

    /**
     * Render the SMS body for a TAD101 command's GSM fallback.
     *
     * Called by DeviceCommandObserver when a TAD101 device record also has
     * a SIM and the live stream is unavailable.
     *
     * @param  array<string, mixed>  $params
     */
    public function buildSmsBody(string $commandType, array $params): ?string
    {
        $password = (string) ($params['password'] ?? '123456');

        return match ($commandType) {
            'set_apn' => "APN{$password} ".($params['apn'] ?? 'internet'),
            'set_server' => "adminip{$password} ".($params['host'] ?? '').' '.($params['port'] ?? 7018),
            'set_timezone' => "timezone{$password} ".($params['offset'] ?? 0),
            'set_mode' => $this->buildModeSms($password, $params),
            'request_location' => "query{$password}",
            'set_family_numbers' => "familynum{$password} ".trim(($params['number1'] ?? '').' '.($params['number2'] ?? '')),
            'check_family_numbers' => "ckauthnum{$password}",
            'set_sos_number' => "admin{$password}".(empty($params['number']) ? '' : ' '.$params['number']),
            'remove_sos_number' => "admin{$password}",
            'set_whitelist' => 'whitenum'.$password.' '.implode(' ', (array) ($params['numbers'] ?? $params['number'] ?? [])),
            'check_whitelist' => "ckwhitenum{$password}",
            'set_alarm_delivery' => "KC{$password} ".($params['mode'] ?? 2),
            'low_battery_alarm' => 'lowbattery'.$password.' '.(($params['enabled'] ?? true) ? 'on' : 'off'),
            'set_wakeup_alarm' => "almclock{$password} ".($params['time'] ?? '08:00').' '.($params['days'] ?? '1111100'),
            'set_dnd' => "silent{$password} ".($params['start'] ?? '22:00').' '.($params['end'] ?? '08:00').' '.($params['days'] ?? '1111111').' 3',
            'delete_dnd' => "silent{$password}",
            'set_sleep_period' => "slptime{$password} ".($params['start'] ?? '23:01').' '.($params['end'] ?? '05:31').' '.($params['days'] ?? '1111110').' 1',
            'delete_sleep_period' => "slptime{$password}",
            'enable_intercom' => "interon{$password} ".(($params['enabled'] ?? true) ? '1' : '0'),
            'set_intercom_group' => "group{$password} ".($params['group_name'] ?? ''),
            'check_params' => "check{$password}",
            'sub_check_params' => "subcheck{$password}",
            'set_volume' => "vol{$password} ".($params['level'] ?? 4),
            'check_firmware' => "ver{$password}",
            default => null,
        };
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Record a stream command as a DeviceCommand row AND broadcast it onto
     * the TAD101 channel. The row exists for audit; the broadcast is what
     * the device actually receives.
     *
     * @param  array<string, mixed>  $params
     */
    private function dispatchStreamCommand(Device $device, string $cmd, array $params): void
    {
        if (! $device->imei) {
            return;
        }

        $cmdId = (string) Str::uuid();

        DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => $cmd,
            'command_payload' => json_encode([
                'cmd_id' => $cmdId,
                'params' => $params,
            ], JSON_UNESCAPED_SLASHES),
            'channel' => 'soketi',
            'status' => DeviceCommandStatus::Pending,
            'requested_by' => auth()->id(),
        ]);

        event(new Tad101CommandEvent($device->imei, $cmd, $cmdId, $params));

        DeviceLog::out(
            source: DeviceLogSource::Tad101,
            summary: $cmd,
            payload: ['cmd' => $cmd, 'cmd_id' => $cmdId, 'params' => $params],
            device: $device,
        );
    }

    private function mapEventType(string $eventName, array $payload): SignalEventType
    {
        // 'alarm' is generic — let the alarm bits decide the specific event.
        if ($eventName === 'alarm') {
            $alarmFlags = (int) ($payload['alarm_flags'] ?? 0);
            if ($alarmFlags & 0b0001) {
                return SignalEventType::Sos;
            }
            if ($alarmFlags & 0b0100) {
                return SignalEventType::PunchIn;
            }
            if ($alarmFlags & 0b1000) {
                return SignalEventType::PunchOut;
            }

            return SignalEventType::Alarm;
        }

        return SignalEventType::tryFrom($eventName) ?? SignalEventType::Update;
    }

    private function parseDeviceTime(mixed $ts): ?CarbonImmutable
    {
        if ($ts === null || $ts === '') {
            return null;
        }

        try {
            if (is_numeric($ts)) {
                return CarbonImmutable::createFromTimestampUTC((int) $ts);
            }

            return CarbonImmutable::parse((string) $ts)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function commandHasSmsFallback(string $commandName): bool
    {
        return $this->buildSmsBody($commandName, []) !== null;
    }

    private function buildModeSms(string $password, array $params): string
    {
        $mode = $params['mode'] ?? 'vibration';
        $interval = $params['interval'] ?? null;

        $modeCode = match ($mode) {
            'power_saving', 0, '0' => 0,
            'timing', 1, '1' => 1,
            'realtime', 2, '2' => 2,
            default => 3,
        };

        if ($modeCode === 0) {
            return "md{$password} 0";
        }

        $interval ??= match ($modeCode) {
            1 => '20M',
            2 => '60S',
            default => '30S',
        };

        return "md{$password} {$modeCode} {$interval}";
    }
}

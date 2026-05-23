<?php

declare(strict_types=1);

namespace TrackAnyDevice\Tad101\Http\Controllers;

use TrackAnyDevice\Tad101\Tad101Driver;
use TrackAnyDevice\Core\Enums\DeviceLogSource;
use Illuminate\Routing\Controller;
use TrackAnyDevice\Core\Jobs\CheckBeatViolation;
use TrackAnyDevice\Core\Models\Device;
use TrackAnyDevice\Core\Services\DeviceLog;
use TrackAnyDevice\Core\Services\SignalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * REST entry point for TAD101 telemetry.
 *
 * Used by devices that cannot keep a long-lived WebSocket open (constrained
 * micros, batch uploads from intermittent connectivity, debugging). The
 * envelope shape is identical to the WebSocket `client-tad101-signal`
 * payload — see docs/devices/tad101.md Part 3.
 *
 * Authenticates by IMEI + bearer secret in the `Authorization` header
 * (`Bearer <secret>`) so the device's TAD101 secret never appears in a
 * URL or query string.
 */
class Tad101InboundController extends Controller
{
    public function __construct(
        private readonly Tad101Driver $driver,
        private readonly SignalService $signalService,
    ) {}

    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tad' => 'required|in:101',
            'v' => 'nullable|string|max:16',
            'imei' => 'required|string|max:64',
            'event' => 'required|string|max:64',
            'ts' => 'required|integer',
            'seq' => 'required|integer|min:0',
            'payload' => 'required|array',
        ]);

        $device = Device::where('imei', $data['imei'])->first();

        if ($device === null) {
            DeviceLog::in(
                source: DeviceLogSource::Tad101,
                summary: 'REST inbound from unknown IMEI',
                payload: ['envelope' => $data, 'transport' => 'rest'],
                imei: $data['imei'],
                level: 'warning',
            );

            return response()->json(['error' => 'unknown_device'], 404);
        }

        if (! $this->authenticated($request, $device)) {
            DeviceLog::in(
                source: DeviceLogSource::Tad101,
                summary: 'REST inbound rejected: bad credentials',
                payload: ['envelope' => $data, 'transport' => 'rest'],
                device: $device,
                level: 'warning',
            );

            return response()->json(['error' => 'unauthorized'], 401);
        }

        try {
            $signal = $this->driver->parseEventToSignal($data, $device);
            $stored = $this->signalService->record($signal, $device);
        } catch (\Throwable $e) {
            // Signal pipeline blew up — surface the failure on the device
            // log feed so an engineer building a new integration sees the
            // exception class + message without needing tail access on
            // storage/logs/laravel.log. The platform Log channel still
            // captures the full stack trace for ops.
            Log::error('TAD101 inbound: signal processing failed', [
                'imei' => $device->imei,
                'event' => $data['event'] ?? null,
                'error' => $e->getMessage(),
            ]);

            DeviceLog::in(
                source: DeviceLogSource::Tad101,
                summary: 'Signal processing failed: '.$e->getMessage(),
                payload: [
                    'envelope' => $data,
                    'transport' => 'rest',
                    'error' => [
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                        'file' => basename($e->getFile()).':'.$e->getLine(),
                    ],
                ],
                device: $device,
                level: 'error',
            );

            return response()->json(['error' => 'signal_processing_failed'], 500);
        }

        if ($stored->latitude !== null && $stored->longitude !== null) {
            CheckBeatViolation::dispatch($device->id, $stored->latitude, $stored->longitude);
        }

        DeviceLog::in(
            source: DeviceLogSource::Tad101,
            summary: $data['event'].' event received (REST)',
            payload: [
                'envelope' => $data,
                'transport' => 'rest',
                'parsed' => [
                    'lat' => $stored->latitude,
                    'lng' => $stored->longitude,
                    'battery' => $stored->batteryLevel ?? null,
                ],
            ],
            device: $device,
        );

        return response()->json([
            'status' => 'ok',
            'received_at' => $stored->serverTime->toIso8601ZuluString(),
            'seq' => $data['seq'],
        ]);
    }

    private function authenticated(Request $request, Device $device): bool
    {
        $token = $request->bearerToken() ?? $request->header('X-Tad101-Secret');
        $storedHash = $device->metadata['tad101_secret'] ?? null;

        if (! is_string($token) || $token === '' || ! is_string($storedHash)) {
            Log::warning('TAD101 inbound: missing credentials', ['imei' => $device->imei]);

            return false;
        }

        return Hash::check($token, $storedHash);
    }
}

<?php

declare(strict_types=1);

namespace TrackAnyDevice\Tad101\Jobs;

use TrackAnyDevice\Tad101\Tad101Driver;
use TrackAnyDevice\Core\Enums\DeviceLogSource;
use TrackAnyDevice\Core\Jobs\CheckBeatViolation;
use TrackAnyDevice\Core\Models\Device;
use TrackAnyDevice\Core\Services\DeviceLog;
use TrackAnyDevice\Core\Services\SignalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Translate a single Soketi webhook event into a persisted TAD101 signal.
 *
 * Soketi posts events with a JSON-encoded `data` string. We decode it, look
 * up the device by the envelope's IMEI, run it through Tad101Driver, and
 * persist via SignalService. The job is queued so the webhook receiver
 * stays under Soketi's HTTP timeout.
 */
class ProcessTad101WebhookJob implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, mixed>  $event */
    public function __construct(public readonly array $event) {}

    public function handle(Tad101Driver $driver, SignalService $signalService): void
    {
        $payload = $this->decodeData($this->event['data'] ?? null);

        if ($payload === null) {
            return;
        }

        $imei = $payload['imei'] ?? null;
        if (! is_string($imei) || $imei === '') {
            Log::warning('TAD101 webhook: missing imei', ['event' => $this->event['name'] ?? null]);

            return;
        }

        $device = Device::where('imei', $imei)->first();

        if ($device === null) {
            Log::warning('TAD101 webhook: unknown imei', ['imei' => $imei]);

            return;
        }

        try {
            $signal = $driver->parseEventToSignal($payload, $device);
            $stored = $signalService->record($signal, $device);

            if ($stored->latitude !== null && $stored->longitude !== null) {
                CheckBeatViolation::dispatch($device->id, $stored->latitude, $stored->longitude);
            }
        } catch (\Throwable $e) {
            // Failed signal write — surface to the device log feed so
            // developers building TAD101 clients can see the exception
            // without needing access to the queue worker logs.
            Log::error('TAD101 webhook: signal processing failed', [
                'imei' => $imei,
                'event' => $this->event['name'] ?? null,
                'error' => $e->getMessage(),
            ]);

            DeviceLog::in(
                source: DeviceLogSource::Tad101,
                summary: 'Signal processing failed: '.$e->getMessage(),
                payload: [
                    'event' => $this->event['name'] ?? null,
                    'envelope' => $payload,
                    'transport' => 'soketi-webhook',
                    'error' => [
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                        'file' => basename($e->getFile()).':'.$e->getLine(),
                    ],
                ],
                device: $device,
                level: 'error',
            );

            // Re-throw so the queue records the job as failed and we
            // pick it up in failed_jobs for retry / inspection.
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private function decodeData(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}

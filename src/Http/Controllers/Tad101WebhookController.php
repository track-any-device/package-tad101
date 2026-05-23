<?php

declare(strict_types=1);

namespace TrackAnyDevice\Tad101\Http\Controllers;

use TrackAnyDevice\Core\Enums\DeviceLogSource;
use Illuminate\Routing\Controller;
use TrackAnyDevice\Tad101\Jobs\ProcessTad101WebhookJob;
use TrackAnyDevice\Core\Models\Device;
use TrackAnyDevice\Core\Services\DeviceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Soketi → Laravel webhook receiver for TAD101 client events.
 *
 * Soketi is configured (see appendix in docs/devices/tad101.md) to forward
 * every `client_event` to this endpoint. We verify the signature, fan each
 * event out onto a queued job, and return immediately so Soketi's webhook
 * worker isn't blocked.
 */
class Tad101WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if (! $this->verifySignature($request)) {
            Log::warning('TAD101 webhook: signature mismatch');

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $events = (array) $request->input('events', []);
        $processed = 0;

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $name = (string) ($event['name'] ?? '');

            if (! str_starts_with($name, 'client-tad101')) {
                continue;
            }

            // Surface the raw client event on the runtime log before the
            // job processes it — engineers can see the wire payload even
            // if downstream processing fails.
            $imei = $this->extractImei($event);
            $device = $imei ? Device::where('imei', $imei)->first() : null;

            DeviceLog::in(
                source: DeviceLogSource::Tad101,
                summary: $name.' (WebSocket)',
                payload: ['event' => $event, 'transport' => 'soketi-webhook'],
                device: $device,
                imei: $imei,
            );

            ProcessTad101WebhookJob::dispatch($event);
            $processed++;
        }

        return response()->json(['status' => 'ok', 'processed' => $processed]);
    }

    /**
     * Best-effort IMEI lookup inside a Soketi webhook event so the
     * device-log emission can attach to the right tenant scope. Falls
     * back to null when the device hasn't sent its IMEI in the payload.
     *
     * @param  array<string, mixed>  $event
     */
    private function extractImei(array $event): ?string
    {
        $channel = (string) ($event['channel'] ?? '');
        if (preg_match('/tad101\.device\.([A-Za-z0-9_-]+)/', $channel, $m) === 1) {
            return $m[1];
        }

        $data = $event['data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        return is_array($data) && isset($data['imei']) ? (string) $data['imei'] : null;
    }

    private function verifySignature(Request $request): bool
    {
        $secret = (string) config('tad101.webhook_secret', '');

        if ($secret === '') {
            // No secret configured — accept all in non-production so local
            // Soketi dev setups work out of the box. Production deployments
            // MUST set TAD101_WEBHOOK_SECRET.
            return app()->environment(['local', 'testing']);
        }

        $signature = (string) $request->header('X-Pusher-Signature', '');

        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}

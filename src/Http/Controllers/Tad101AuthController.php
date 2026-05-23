<?php

declare(strict_types=1);

namespace TrackAnyDevice\Tad101\Http\Controllers;

use Illuminate\Routing\Controller;
use TrackAnyDevice\Core\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Pusher-compatible channel auth endpoint for TAD101 devices.
 *
 * A device opens a WebSocket to Soketi, subscribes to its private channel
 * `private-tad101.device.{imei}` and is then asked by Soketi to prove it
 * owns the channel. The device sends `socket_id + channel_name` to this
 * endpoint along with its TAD101 secret. We verify the secret and return
 * the HMAC-SHA256 channel auth string Soketi expects.
 *
 * See: https://pusher.com/docs/channels/server_api/authorizing-users/
 */
class Tad101AuthController extends Controller
{
    public function auth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'imei' => 'required|string|max:64',
            'secret_key' => 'required|string|max:255',
            'socket_id' => 'required|string|max:255',
            'channel_name' => 'required|string|max:255',
            'device_type_slug' => 'nullable|string|max:64',
        ]);

        $device = Device::where('imei', $data['imei'])->first();

        if ($device === null) {
            Log::warning('TAD101 auth: unknown IMEI', ['imei' => $data['imei']]);

            return response()->json(['error' => 'unknown_device'], 404);
        }

        if ($device->status?->value === 'retired') {
            return response()->json(['error' => 'device_retired'], 403);
        }

        $storedHash = $device->metadata['tad101_secret'] ?? null;

        if (! is_string($storedHash) || ! Hash::check($data['secret_key'], $storedHash)) {
            Log::warning('TAD101 auth: bad secret', ['imei' => $device->imei]);

            return response()->json(['error' => 'invalid_secret'], 403);
        }

        if (! $this->channelBelongsToDevice($data['channel_name'], $device->imei)) {
            return response()->json(['error' => 'channel_mismatch'], 403);
        }

        $appKey = (string) config('broadcasting.connections.pusher.key', '');
        $appSecret = (string) config('broadcasting.connections.pusher.secret', '');

        if ($appKey === '' || $appSecret === '') {
            return response()->json(['error' => 'broadcasting_not_configured'], 500);
        }

        $channelData = json_encode([
            'user_id' => $device->id,
            'user_info' => [
                'imei' => $device->imei,
                'tenant_id' => $device->tenant_id,
                'owner_id' => $device->user_id,
            ],
        ], JSON_UNESCAPED_SLASHES);

        $isPresence = str_starts_with($data['channel_name'], 'presence-');
        $stringToSign = $isPresence
            ? $data['socket_id'].':'.$data['channel_name'].':'.$channelData
            : $data['socket_id'].':'.$data['channel_name'];

        $signature = hash_hmac('sha256', $stringToSign, $appSecret);

        $response = ['auth' => $appKey.':'.$signature];
        if ($isPresence) {
            $response['channel_data'] = $channelData;
        }

        // Mark the device as currently connected — drivers use this in
        // supportsStream() to prefer the live channel over GSM SMS.
        $device->forceFill([
            'metadata' => array_merge($device->metadata ?? [], [
                'tad101_connected' => true,
                'tad101_last_auth_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return response()->json($response);
    }

    private function channelBelongsToDevice(string $channelName, string $imei): bool
    {
        $expectedSuffix = 'tad101.device.'.$imei;

        // Soketi prefixes private/presence channels in the wire name.
        return $channelName === 'private-'.$expectedSuffix
            || $channelName === 'presence-'.$expectedSuffix
            || $channelName === $expectedSuffix;
    }
}

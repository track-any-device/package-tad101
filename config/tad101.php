<?php

declare(strict_types=1);

return [

    // Protocol identity.
    'version' => '1.0.0',
    'channel_prefix' => 'tad101.device.',

    // Public host the TAD101 device opens its WebSocket against. Defaults to
    // the host part of APP_URL so dev environments work without extra config.
    'server_host' => env('TAD101_SERVER_HOST'),

    // Soketi public WebSocket port (browsers + devices connect here). Falls
    // back to PUSHER_PORT because we run Soketi as our Pusher-compatible
    // broadcaster — the values must match the docker-compose service.
    'soketi_port' => (int) env('TAD101_SOKETI_PORT', env('PUSHER_PORT', 6001)),

    // Full ws(s):// URL used by the docs / onboarding payload.
    'ws_url' => env('TAD101_WS_URL'),

    // Timezone offset hint sent to devices that mirror their clock from us
    // (Arduino / ESP32 without NTP). Falls back to the platform's APP timezone
    // offset so we don't double-configure it.
    'timezone_offset' => (int) env('TAD101_TIMEZONE_OFFSET', 0),

    // Shared secret used to sign Soketi → Laravel webhook calls.
    'webhook_secret' => env('TAD101_WEBHOOK_SECRET'),

    // Window after which a device is considered no longer streaming. Drivers
    // use this in supportsStream() to fall back to GSM SMS when applicable.
    'stream_freshness_minutes' => (int) env('TAD101_STREAM_FRESHNESS_MINUTES', 5),

    // Default tracking mode applied during onboarding for fresh devices.
    'default_mode' => env('TAD101_DEFAULT_MODE', 'vibration'),

    // Default heartbeat / report interval (seconds) for stream-only devices.
    'default_report_interval' => (int) env('TAD101_DEFAULT_REPORT_INTERVAL', 30),

];

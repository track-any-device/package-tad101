<?php

namespace TrackAnyDevice\Tad101;

use Illuminate\Support\Facades\Route;
use TrackAnyDevice\Tad101\Http\Controllers\Tad101AuthController;
use TrackAnyDevice\Tad101\Http\Controllers\Tad101InboundController;
use TrackAnyDevice\Tad101\Http\Controllers\Tad101WebhookController;
use TrackAnyDevice\Tad101\Http\Controllers\TadDocsController;

/**
 * Register TAD101 protocol routes within the calling route groups.
 *
 * Usage in routes/api.php (inside your API middleware group):
 *
 *   Tad101::apiRoutes();   // POST tad101/auth, inbound, webhook
 *
 * Usage in routes/web.php (inside your web middleware group):
 *
 *   Tad101::webRoutes();   // GET docs/tad101/*
 */
class Tad101
{
    /**
     * Register the TAD101 REST API endpoints:
     *   POST  tad101/auth     → Pusher channel auth for WebSocket devices
     *   POST  tad101/inbound  → REST telemetry fallback
     *   POST  tad101/webhook  → Soketi webhook receiver
     */
    public static function apiRoutes(): void
    {
        Route::prefix('tad101')->group(function () {
            Route::post('auth', [Tad101AuthController::class, 'auth'])
                ->name('api.tad101.auth');
            Route::post('inbound', [Tad101InboundController::class, 'receive'])
                ->name('api.tad101.inbound');
            Route::post('webhook', [Tad101WebhookController::class, 'handle'])
                ->name('api.tad101.webhook');
        });
    }

    /**
     * Register the TAD101 developer documentation pages:
     *   GET  docs/tad101/overview, architecture, envelope, android, ios,
     *        arduino, raspberry-pi, sensors, commands, ideas, changelog
     */
    public static function webRoutes(): void
    {
        Route::prefix('docs/tad101')->name('docs.tad101.')->group(function () {
            Route::get('/', [TadDocsController::class, 'overview'])->name('overview');
            Route::get('architecture', [TadDocsController::class, 'architecture'])->name('architecture');
            Route::get('envelope', [TadDocsController::class, 'envelope'])->name('envelope');
            Route::get('android', [TadDocsController::class, 'android'])->name('android');
            Route::get('ios', [TadDocsController::class, 'ios'])->name('ios');
            Route::get('arduino', [TadDocsController::class, 'arduino'])->name('arduino');
            Route::get('raspberry-pi', [TadDocsController::class, 'raspberryPi'])->name('raspberry-pi');
            Route::get('sensors', [TadDocsController::class, 'sensors'])->name('sensors');
            Route::get('commands', [TadDocsController::class, 'commands'])->name('commands');
            Route::get('ideas', [TadDocsController::class, 'presentYourIdea'])->name('ideas');
            Route::get('changelog', [TadDocsController::class, 'changelog'])->name('changelog');
        });
    }
}

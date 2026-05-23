<?php

namespace TrackAnyDevice\Tad101;

use Illuminate\Support\ServiceProvider;

class Tad101ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tad101.php', 'tad101');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tad101.php' => config_path('tad101.php'),
            ], 'tad101-config');
        }

        // Register Tad101Driver as a singleton and bind it to all four
        // TAD101 device-type slugs so DeviceServiceProvider::driverFor()
        // resolves the same instance for android_app, ios_app, arduino,
        // and raspberry_pi device types.
        $this->app->singleton(Tad101Driver::class);

        foreach (['android_app', 'ios_app', 'arduino', 'raspberry_pi'] as $slug) {
            $this->app->bind("device.driver.{$slug}", Tad101Driver::class);
        }
    }
}

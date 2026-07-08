<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Force HTTPS scheme when behind reverse proxy / tunnel
        if (env('APP_FORCE_HTTPS', false)) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // Schedule the post dispatcher to run every minute
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            $schedule->command('posts:dispatch-scheduled')->everyMinute();
        });
    }
}

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
        // HTTPS forcing is now handled by DynamicAppUrl middleware
        // which auto-detects domain vs IP access

        // Schedule the post dispatcher to run every minute
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            $schedule->command('posts:dispatch-scheduled')->everyMinute();

            // WhatsApp presence check — every 30 minutes for all active consents.
            // Polls Evolution API /chat/findChats to capture last-message timestamps
            // for consented contacts. Lower frequency = less API load + more ethical.
            // 30 min gives 48 samples/day per contact = enough for daily heatmap.
            $schedule->command('whatsapp:presence-check')->everyThirtyMinutes();
        });
    }
}

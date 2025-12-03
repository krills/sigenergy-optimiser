<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Battery optimization runs every 15 minutes during daylight hours (6 AM - 10 PM)
        $schedule->command('battery:auto-optimize')
                 ->everyFifteenMinutes()
                 ->between('06:00', '22:00')
                 ->withoutOverlapping()
                 ->onOneServer()
                 ->runInBackground();

        // Update Nord Pool prices every hour
        $schedule->command('battery:auto-optimize --force')
                 ->hourly()
                 ->withoutOverlapping()
                 ->onOneServer()
                 ->runInBackground();

        // Daily system health check at 7 AM
        $schedule->command('sigenergy:test')
                 ->dailyAt('07:00')
                 ->onOneServer();

        // Example: Weekly optimization report (placeholder for future feature)
        // $schedule->command('battery:weekly-report')
        //          ->weeklyOn(1, '08:00') // Monday at 8 AM
        //          ->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
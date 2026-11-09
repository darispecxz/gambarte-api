<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('reconciliation:run')
                 ->everySixHours()
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/reconciliation.log'));

        $schedule->command('notifications:send')
                 ->everyFiveMinutes()
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/notifications.log'));

        if (config('binance_pay.history.api_key') && config('binance_pay.history.api_secret') && config('binance_pay.history.receiver_id')) {
            $schedule->command('cripto:binance-pay-sync')
                     ->everyTwoMinutes()
                     ->withoutOverlapping()
                     ->appendOutputTo(storage_path('logs/binance-pay-history.log'));
        }
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}

<?php

use App\Console\Commands\BatteryControllerCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(BatteryControllerCommand::SIGNATURE)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/battery-controller.log'));

<?php

use Illuminate\Support\Facades\Schedule;

// Schedule queue worker monitoring
Schedule::command('queue:work --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requests without a callback after `stale_after_minutes` become "timeout".
Schedule::command('activepieces:expire-stale')->everyMinute()->withoutOverlapping();

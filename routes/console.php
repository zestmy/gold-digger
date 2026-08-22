<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| Requires cron on the server:
|
|     * * * * * cd /var/www/gold-digger && php artisan schedule:run >> /dev/null 2>&1
|
| Without it nothing here runs. Everything scheduled is therefore written to be a
| correction rather than a dependency - the dashboard reads command expiry directly, so a
| server with no cron shows the right thing and merely accumulates stale rows.
|
*/

// Lapsed commands are already refused by scopeClaimable; this is what stops them sitting
// at `pending` for ever and makes their fate legible in the row.
Schedule::command('commands:sweep')->everyFiveMinutes()->withoutOverlapping();

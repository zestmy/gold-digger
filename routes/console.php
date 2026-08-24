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

// Health checks and alerting. Every minute, because the thing being watched for is an
// executor that has gone quiet - and the interesting question is how long ago, which a
// coarser schedule answers badly.
//
// withoutOverlapping is what lets HealthMonitor enforce "one open incident per key" in
// application code instead of a unique index MySQL cannot express.
Schedule::command('bot:monitor')->everyMinute()->withoutOverlapping();

// The economic calendar behind the news blackout filter. Hourly: the week's schedule
// barely moves, but `actual` values print through the day, and NewsBlackout stops
// trusting the data after six hours - so this is five consecutive failures of headroom
// before the filter begins holding entries.
//
// Without cron this never runs, and a news filter that is switched on will hold every
// entry as `news_data_stale` rather than trade unprotected. That is the intended
// direction, and it is the one thing in this file whose absence changes trading.
Schedule::command('news:fetch')->hourly()->withoutOverlapping();

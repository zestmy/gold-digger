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

// Telegram signal capture. Every minute, because a copied signal is worth acting on within
// the bar it was posted on and not the hour.
//
// withoutOverlapping matters more here than elsewhere: getUpdates confirms as it reads, so
// two concurrent polls would have one of them advancing the offset past messages the other
// is still storing.
Schedule::command('telegram:poll')->everyMinute()->withoutOverlapping();

// The rest of the copier, unattended.
//
// Three separate commands rather than one, because they fail differently and a failure in
// a later stage must not cost an earlier one. A message that arrived is worth keeping
// whether or not the model was reachable; an approval is worth keeping whether or not the
// broker was.
//
// Review runs a minute behind capture in effect rather than by configuration - poll writes
// the row, and the next tick finds it awaiting review. Signals go stale at 45 minutes, so
// there is a wide margin before a scheduler hiccup costs anything.
Schedule::command('telegram:review')->everyMinute()->withoutOverlapping();

// The step that places real orders without anyone present.
//
// Every gate is re-checked inside the executor when this runs - an approval from twenty
// minutes ago is not permission - and the AI fund cap bounds what any run of this can
// lose. What makes it safe to schedule is not this line; it is that the executor refuses
// to size beyond the fund, refuses a stale signal, and refuses one whose stop the market
// has already passed.
//
// Unlike the dashboard's Execute button, this announces what it opened. An autonomous
// copier that trades silently is indistinguishable from one that has stopped.
Schedule::command('telegram:execute')->everyMinute()->withoutOverlapping();

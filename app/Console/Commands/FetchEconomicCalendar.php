<?php

namespace App\Console\Commands;

use App\Services\News\CalendarFeed;
use Illuminate\Console\Command;

/**
 * Refresh the economic calendar.
 *
 * Runs hourly. The week's schedule barely changes, but `actual` values print through the
 * day and forecasts are revised, and - more to the point - `NewsBlackout` treats the data
 * as unusable once it is six hours old. An hourly refresh means five consecutive failures
 * before the filter starts holding entries, which is past any transient outage.
 */
class FetchEconomicCalendar extends Command
{
    protected $signature = 'news:fetch';

    protected $description = 'Refresh the economic calendar used by the news blackout filter';

    public function handle(CalendarFeed $feed): int
    {
        $result = $feed->refresh();

        if (! $result['ok']) {
            $this->error($result['error'] ?? 'Calendar refresh failed.');

            // Non-zero so a scheduler or CI run treats it as the failure it is. The
            // filter's own staleness check is what protects trading; this is only how a
            // human finds out early.
            return self::FAILURE;
        }

        $this->info("Calendar refreshed: {$result['imported']} events, {$result['skipped']} skipped.");

        return self::SUCCESS;
    }
}

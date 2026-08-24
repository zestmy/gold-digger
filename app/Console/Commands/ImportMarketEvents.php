<?php

namespace App\Console\Commands;

use App\Models\MarketEvent;
use App\Services\News\CalendarSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import Market Events
 *
 * Pulls the economic calendar into `market_events`. Scheduled hourly.
 *
 * ## Why hourly, for a feed that publishes a week at a time
 *
 * Because the times move. A release scheduled for Thursday can shift by a day, and the feed
 * revises silently. Importing once a week means trading a stale copy of the calendar for six
 * days; importing hourly costs one small request and keeps the copy honest. It also means a
 * few hours of feed outage is invisible rather than fatal.
 *
 * ## An empty fetch changes nothing
 *
 * A source that returns no rows is treated as "no new information", never as "the week is
 * empty". Wiping the calendar on a failed request would silently disable the filter at the
 * exact moment the operator has least reason to suspect it - and the filter fails open, so a
 * wiped calendar trades straight through NFP. `HealthMonitor` watches the calendar horizon
 * for the case where this goes on long enough to matter.
 *
 * ## Upsert, never insert
 *
 * The natural key is (currency, title, scheduled_at). Re-importing a week updates the impact
 * and the forecast/previous/actual figures in place. Rows whose *time* was revised arrive as
 * new rows, and the old one is left alone rather than guessed at - a stale extra blackout
 * window is a cost of a few skipped setups, where deleting the wrong row is a trade taken
 * into a release.
 */
class ImportMarketEvents extends Command
{
    protected $signature = 'news:import
                            {--prune-days=90 : Delete events older than this many days. 0 keeps everything.}';

    protected $description = 'Import the economic calendar used by the news blackout filter';

    public function handle(CalendarSource $source): int
    {
        $events = $source->fetch();

        if ($events === []) {
            // Not a failure exit code: the scheduler runs this hourly and a transient feed
            // outage should not fill failed-job logs with something the monitor already
            // watches for properly.
            $this->warn('Calendar source returned no events; leaving '.MarketEvent::count().' stored events untouched.');

            return self::SUCCESS;
        }

        $now = now();
        $rows = [];

        foreach ($events as $event) {
            $rows[] = [
                'source' => $source->name(),
                'title' => $event['title'],
                'currency' => $event['currency'],
                'impact' => $event['impact'],
                'scheduled_at' => $event['scheduled_at'],
                'forecast' => $event['forecast'],
                'previous' => $event['previous'],
                'actual' => $event['actual'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $before = MarketEvent::count();

        DB::transaction(function () use ($rows) {
            // Chunked because the weekly feed is a few hundred rows and a single statement
            // with that many placeholders is needlessly close to MySQL's limit.
            foreach (array_chunk($rows, 100) as $chunk) {
                MarketEvent::upsert(
                    $chunk,
                    ['currency', 'title', 'scheduled_at'],
                    ['source', 'impact', 'forecast', 'previous', 'actual', 'updated_at'],
                );
            }
        });

        $added = MarketEvent::count() - $before;

        $this->info(sprintf(
            '%d events imported from %s: %d new, %d updated.',
            count($rows),
            $source->name(),
            $added,
            count($rows) - $added,
        ));

        $this->prune();

        $horizon = MarketEvent::horizon();

        $this->line($horizon === null
            ? '<fg=gray>Calendar is empty.</>'
            : '<fg=gray>Calendar now runs to '.$horizon->toDateTimeString().' UTC.</>');

        return self::SUCCESS;
    }

    /**
     * Drop events too old to be worth keeping.
     *
     * Not zero-retention: a backtest over the last month has to see the blackouts that were in
     * force during it, or it measures a different system from the one that traded. Ninety days
     * covers the analytics pages' longest window with room over.
     */
    private function prune(): void
    {
        $days = (int) $this->option('prune-days');

        if ($days <= 0) {
            return;
        }

        $deleted = MarketEvent::query()
            ->where('scheduled_at', '<', now()->subDays($days))
            ->delete();

        if ($deleted > 0) {
            $this->line("<fg=gray>Pruned {$deleted} events older than {$days} days.</>");
        }
    }
}

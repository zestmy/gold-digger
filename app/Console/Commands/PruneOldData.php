<?php

namespace App\Console\Commands;

use App\Models\AiUsage;
use App\Models\Alert;
use App\Models\BotLog;
use App\Models\Candle;
use App\Models\EconomicEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prune the tables that grow without end.
 *
 * ## What this is for
 *
 * Nothing in this application ever deleted anything. On one operator's laptop that is
 * invisible; measured, `candles` was 91% of the database, and it is stored per broker
 * account - so it multiplies by tenant rather than being shared. One symbol on M5 is about
 * 75,000 bars a year, and the box this runs on has 2GB of RAM shared with the queue worker
 * and a walk-forward optimiser that is already memory-bounded because of it.
 *
 * ## What it will not touch
 *
 * `trades`, `trade_partials`, `trade_screenshots` and `daily_summaries` are the financial
 * record. `signals`, `telegram_signals` and `chart_analyses` are the evidence for whether
 * any of this works - all three store refusals as carefully as decisions, precisely so that
 * "was the filter too strict" and "was the analyst any good" stay answerable. Deleting them
 * to save disk would undo the reason they exist.
 *
 * That list is deliberately not configurable. A retention setting that could be turned up
 * until it ate the trade history is a setting somebody will eventually turn up.
 *
 * ## Bars are counted, not dated
 *
 * The one decision worth not re-litigating. A days-based cutoff applies unevenly across
 * timeframes: 90 days leaves 25,000 M5 bars and 1,500 H1 bars, so the same policy would
 * barely touch one series and starve another. Consumers ask in bars - the evaluator wants
 * 300, the strategy improver wants 20,000 - so bars are the unit that keeps every series
 * equally useful.
 *
 * ## Dry by habit
 *
 * `--dry` reports every count without deleting, and it is worth using before the first real
 * run on any deployment. A prune that turns out to have been wrong is not undone by editing
 * the setting afterwards.
 */
class PruneOldData extends Command
{
    protected $signature = 'data:prune
                            {--dry : Report what would be deleted, and delete nothing}';

    protected $description = 'Delete observational data past its retention, leaving the record intact';

    /** Rows per delete, so a large prune does not hold one long lock. */
    private const CHUNK = 2000;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        if ($dry) {
            $this->comment('Dry run - nothing will be deleted.');
        }

        $total = 0;

        $total += $this->pruneCandles($dry);
        $total += $this->pruneByAge($dry, 'bot_logs', BotLog::query(), 'created_at', 'bot_log_days');
        $total += $this->pruneByAge($dry, 'ai_usage', AiUsage::acrossTenants(), 'created_at', 'ai_usage_days');
        $total += $this->pruneByAge($dry, 'economic_events', EconomicEvent::query(), 'scheduled_at', 'economic_event_days');
        $total += $this->pruneResolvedAlerts($dry);

        $this->newLine();
        $this->info($dry
            ? sprintf('%s rows would be deleted.', number_format($total))
            : sprintf('%s rows deleted.', number_format($total)));

        // Printed after a real run because the number is the point of the exercise, and
        // because MySQL does not return the space to the filesystem on delete - the table
        // stays the same size on disk and reuses the freed pages. Somebody checking `df`
        // and seeing no change should find that stated rather than discover it.
        if (! $dry && ($mb = $this->databaseMegabytes()) !== null) {
            $this->line(sprintf(
                '  <fg=gray>Database now %s MB. InnoDB reuses freed pages rather than returning them, so this figure moves slowly.</>',
                number_format($mb, 1),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Keep the newest N bars of every series, and drop what is behind them.
     *
     * Per series rather than per table, because "the newest 30,000 bars" across a table
     * holding two accounts and two timeframes would keep whichever series happens to be
     * busiest and delete the rest entirely.
     *
     * The cutoff is found by asking for the Nth newest `open_time` and deleting strictly
     * below it. Doing it by time rather than by id matters: bars can be backfilled out of
     * order from a vendor, and an id-based cutoff would delete recent bars that happened to
     * be inserted late.
     */
    private function pruneCandles(bool $dry): int
    {
        $keep = (int) config('trading.retention.candle_bars_per_series', 30000);

        if ($keep <= 0) {
            $this->line('  candles                 keeping everything (retention off)');

            return 0;
        }

        $series = Candle::acrossTenants()
            ->select('broker_account_id', 'symbol', 'timeframe')
            ->selectRaw('count(*) as bars')
            ->groupBy('broker_account_id', 'symbol', 'timeframe')
            ->havingRaw('count(*) > ?', [$keep])
            ->get();

        if ($series->isEmpty()) {
            $this->line(sprintf('  candles                 nothing over %s bars in any series', number_format($keep)));

            return 0;
        }

        $deleted = 0;

        foreach ($series as $row) {
            $base = fn () => Candle::acrossTenants()
                ->where('broker_account_id', $row->broker_account_id)
                ->where('symbol', $row->symbol)
                ->where('timeframe', $row->timeframe);

            // The open_time of the oldest bar worth keeping. Everything strictly older goes.
            $cutoff = $base()
                ->orderByDesc('open_time')
                ->offset($keep - 1)
                ->limit(1)
                ->value('open_time');

            if ($cutoff === null) {
                continue;
            }

            $count = (clone $base())->where('open_time', '<', $cutoff)->count();

            $this->line(sprintf(
                '  candles  acct %-3s %-9s %-4s %s of %s bars%s',
                $row->broker_account_id,
                $row->symbol,
                $row->timeframe,
                number_format($count),
                number_format($row->bars),
                $dry ? ' (dry)' : '',
            ));

            if (! $dry) {
                $count = $this->deleteInChunks(fn () => (clone $base())->where('open_time', '<', $cutoff));
            }

            $deleted += $count;
        }

        return $deleted;
    }

    /**
     * Everything older than its retention, on whichever column dates it.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     */
    private function pruneByAge(bool $dry, string $label, $query, string $column, string $setting): int
    {
        $days = (int) config("trading.retention.{$setting}", 0);

        if ($days <= 0) {
            $this->line(sprintf('  %-22s keeping everything (retention off)', $label));

            return 0;
        }

        $before = now()->subDays($days);
        $count = (clone $query)->where($column, '<', $before)->count();

        $this->line(sprintf(
            '  %-22s %s older than %d days%s',
            $label,
            number_format($count),
            $days,
            $dry ? ' (dry)' : '',
        ));

        if ($dry || $count === 0) {
            return $count;
        }

        return $this->deleteInChunks(fn () => (clone $query)->where($column, '<', $before));
    }

    /**
     * Resolved incidents only.
     *
     * A firing alert is never pruned however old it is: how long something has been broken
     * is the most interesting thing about it, and an outage nobody has fixed is exactly the
     * row somebody will come looking for.
     */
    private function pruneResolvedAlerts(bool $dry): int
    {
        return $this->pruneByAge(
            $dry,
            'alerts (resolved)',
            Alert::acrossTenants()->whereNotNull('resolved_at'),
            'resolved_at',
            'resolved_alert_days',
        );
    }

    /**
     * Delete in bounded batches.
     *
     * A single `delete()` over tens of thousands of rows holds one lock for its whole
     * duration, on the same MySQL the dashboard and the executor are talking to. Chunking
     * turns that into many short locks, which a live trading system notices far less.
     *
     * @param  callable(): \Illuminate\Database\Eloquent\Builder<*>  $query
     */
    private function deleteInChunks(callable $query): int
    {
        $deleted = 0;

        do {
            $batch = $query()->limit(self::CHUNK)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }

    /**
     * Total size the database occupies, for the line printed after a real run.
     */
    private function databaseMegabytes(): ?float
    {
        try {
            $row = DB::selectOne(
                'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS mb
                 FROM information_schema.TABLES WHERE table_schema = DATABASE()'
            );

            return $row?->mb === null ? null : (float) $row->mb;
        } catch (\Throwable) {
            // SQLite has no information_schema, and a size report is not worth failing a
            // prune over.
            return null;
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Strategy;
use App\Services\Backtest\MarketAssumptions;
use App\Services\Backtest\ParameterGrid;
use App\Services\Backtest\SweepRunner;
use App\Services\Backtest\WalkForward;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Optimise Strategy
 *
 * Grid search over strategy parameters, either across the whole series (`--sweep`) or
 * validated fold by fold on unseen bars (the default).
 *
 * Walk-forward is the default deliberately. A plain sweep is the thing people reach for and the
 * thing that misleads them, so getting one requires asking.
 */
class OptimiseStrategy extends Command
{
    protected $signature = 'backtest:optimise
                            {strategy? : Strategy id. Defaults to the only active one}
                            {--param=* : name=values, e.g. ema_fast=10,20,30 or adx_threshold=20:30:5}
                            {--sweep : Fit the whole series instead of validating out-of-sample}
                            {--folds=4 : Walk-forward folds}
                            {--min-trades= : Results below this are not ranked}
                            {--account= : Broker account whose candles to read}
                            {--symbol= : Series to search. Defaults to the resolved symbol on the heartbeat}
                            {--from= : Earliest bar}
                            {--to= : Latest bar}
                            {--balance=10000 : Starting balance}
                            {--spread= : Fixed spread in pips}
                            {--slippage=0.3 : Adverse slippage in pips}
                            {--commission=7.0 : Commission per lot per side}
                            {--max=400 : Refuse to run more combinations than this}
                            {--json= : Write the full report to this file}';

    protected $description = 'Search strategy parameters, validated on bars the search never saw';

    public function handle(WalkForward $walkForward, SweepRunner $sweeper): int
    {
        $strategy = $this->resolveStrategy();

        if ($strategy === null) {
            return self::FAILURE;
        }

        try {
            $grid = new ParameterGrid($this->option('param'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $combinations = $grid->combinations();

        if ($combinations === []) {
            $this->error('Nothing to search. Give at least one --param, for example:');
            $this->line('  php artisan backtest:optimise --param="ema_fast=10,15,20" --param="adx_threshold=20:30:5"');

            return self::FAILURE;
        }

        if (count($combinations) > (int) $this->option('max')) {
            $this->error(sprintf(
                '%d combinations exceeds the --max of %d.',
                count($combinations),
                (int) $this->option('max'),
            ));
            // The limit is a hint about method as much as about runtime.
            $this->line('  Searching many parameters at once finds the corner of the grid that fits');
            $this->line('  this stretch of history. Two or three at a time says more.');

            return self::FAILURE;
        }

        $heartbeat = BotHeartbeat::where('user_id', $strategy->user_id)->orderByDesc('last_seen_at')->first();
        $accountId = $this->option('account') !== null ? (int) $this->option('account') : $heartbeat?->broker_account_id;
        // Overridable for the same reason strategy:improve needs it: the newest heartbeat
        // does not always describe the series holding the history you mean to search.
        $symbol = $this->option('symbol') ?: ($heartbeat?->resolved_symbol ?: $strategy->symbol);

        $entry = $this->candles($accountId, $symbol, $strategy->timeframe_entry);
        $trend = $this->candles($accountId, $symbol, $strategy->timeframe_trend);

        if ($entry === []) {
            $this->error("No {$strategy->timeframe_entry} candles stored for {$symbol}.");

            return self::FAILURE;
        }

        $market = MarketAssumptions::fromHeartbeat($heartbeat, array_filter([
            'spreadPips' => $this->option('spread') !== null ? (float) $this->option('spread') : null,
            'slippagePips' => (float) $this->option('slippage'),
            'commissionPerLot' => (float) $this->option('commission'),
            'startingBalance' => (float) $this->option('balance'),
        ], fn ($v) => $v !== null));

        $settings = BotSettings::where('user_id', $strategy->user_id)->first();

        $this->newLine();
        // A literal ·, not the HTML entity. This is a terminal, and `&middot;` printed as
        // four characters of markup in every sweep anyone has ever run.
        $this->line("<options=bold>{$strategy->name}</> on {$symbol} · ".count($entry).' bars');
        $this->line('  searching '.count($combinations).' combination(s) over: '.implode(', ', array_keys($grid->axes())));
        $this->newLine();

        return $this->option('sweep')
            ? $this->sweep($sweeper, $strategy, $entry, $trend, $combinations, $market, $settings)
            : $this->walkForward($walkForward, $strategy, $entry, $trend, $combinations, $market, $settings);
    }

    // =========================================================================
    // WALK FORWARD
    // =========================================================================

    private function walkForward(WalkForward $walkForward, Strategy $strategy, array $entry, array $trend, array $combinations, MarketAssumptions $market, ?BotSettings $settings): int
    {
        $folds = max(2, (int) $this->option('folds'));
        $minTrades = $this->option('min-trades') !== null ? (int) $this->option('min-trades') : 10;

        $bar = $this->output->createProgressBar($folds * (count($combinations) + 1));
        $bar->start();

        $report = $walkForward->run(
            $strategy, $entry, $trend, $combinations, $market, $settings, $folds, $minTrades,
            fn () => $bar->advance(),
        );

        $bar->finish();
        $this->newLine(2);

        foreach ($report->notes as $note) {
            $this->warn($note);
        }

        $tested = $report->tested();

        if ($tested === []) {
            $this->warn('No fold produced a qualifying result. Either there are too few bars, or no '
                .'combination traded enough to be worth ranking.');

            return self::SUCCESS;
        }

        $skipped = count($report->folds) - count($tested);

        if ($skipped > 0) {
            // Otherwise a fold simply disappears from the table and the reader is left to
            // wonder whether it failed or was never run.
            $this->warn(sprintf(
                '  %d fold(s) produced no qualifying combination and are not shown - usually too '
                .'few bars in the training window to trade enough times.',
                $skipped,
            ));
            $this->newLine();
        }

        $this->line('<options=bold>Fold by fold</>');
        $this->table(
            ['Fold', 'Winning parameters', 'In-sample net', 'Out-of-sample net', 'OOS trades'],
            array_map(fn (array $f) => [
                $f['fold'],
                $this->describe($f['parameters']),
                number_format((float) $f['in_sample']['net_pnl'], 2),
                number_format((float) $f['out_of_sample']['net_pnl'], 2),
                $f['out_of_sample']['trades'],
            ], $tested),
        );

        $oos = $report->outOfSample();
        $deg = $report->degradation();

        $this->line('<options=bold>Out-of-sample, stitched across folds</>');
        $this->table(['Metric', 'Value'], [
            ['Trades', $oos['trades']],
            ['Net P&L', ($oos['net_pnl'] >= 0 ? '+' : '').number_format($oos['net_pnl'], 2)],
            ['Win rate', $oos['win_rate'].'%'],
            ['Expectancy per trade', ($oos['expectancy'] >= 0 ? '+' : '').number_format($oos['expectancy'], 2)],
            ['Profitable folds', $oos['folds_profitable'].' of '.$oos['folds_tested']],
        ]);

        $this->line('<options=bold>Did it generalise?</>');
        $this->line(sprintf(
            '  in-sample %s per trade, out-of-sample %s per trade%s',
            number_format($deg['in_sample_expectancy'], 2),
            number_format($deg['out_of_sample_expectancy'], 2),
            $deg['retained_pct'] !== null ? ' ('.$deg['retained_pct'].'% retained)' : '',
        ));
        $this->newLine();

        // The verdict, coloured by what it says rather than by preference.
        $deg['out_of_sample_expectancy'] > 0
            ? $this->info('  '.$deg['verdict'])
            : $this->warn('  '.$deg['verdict']);

        $this->newLine();
        $this->presentStability($report->stability());

        if ($this->option('json')) {
            file_put_contents($this->option('json'), json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('Full report written to '.$this->option('json'));
        }

        return self::SUCCESS;
    }

    private function presentStability(array $stability): void
    {
        if ($stability['stable'] === null) {
            return;
        }

        $this->line('<options=bold>Parameter stability</>');

        foreach ($stability['per_parameter'] as $name => $info) {
            $this->line(sprintf(
                '  %-20s %s%s',
                $name,
                implode(' → ', array_map(fn ($v) => floor($v) == $v ? (int) $v : $v, $info['values'])),
                $info['stable'] ? '' : '   (unstable)',
            ));
        }

        $this->newLine();

        if (! $stability['stable']) {
            // Weaker evidence than the out-of-sample result, but strong evidence against.
            $this->warn('  The winning parameters moved between folds. That usually means the surface is '
                .'flat and the search is following noise rather than an optimum.');
            $this->newLine();
        }
    }

    // =========================================================================
    // SWEEP
    // =========================================================================

    private function sweep(SweepRunner $sweeper, Strategy $strategy, array $entry, array $trend, array $combinations, MarketAssumptions $market, ?BotSettings $settings): int
    {
        $minTrades = $this->option('min-trades') !== null
            ? (int) $this->option('min-trades')
            : SweepRunner::DEFAULT_MIN_TRADES;

        $bar = $this->output->createProgressBar(count($combinations));
        $bar->start();

        $results = $sweeper->run(
            $strategy, $entry, $trend, $combinations, $market, $settings, $minTrades,
            fn () => $bar->advance(),
        );

        $bar->finish();
        $this->newLine(2);

        $ranked = SweepRunner::rank($results);
        $qualified = array_filter($ranked, fn ($r) => $r->qualifies);

        $this->table(
            ['Parameters', 'Trades', 'Net', 'PF', 'Max DD', 'Score'],
            array_map(fn ($r) => [
                $r->label().($r->qualifies ? '' : ' *'),
                $r->metrics['trades'],
                number_format((float) $r->metrics['net_pnl'], 2),
                $r->metrics['profit_factor'] ?: '-',
                number_format((float) $r->metrics['max_drawdown'], 2),
                $r->qualifies ? number_format($r->score, 2) : '-',
            ], array_slice($ranked, 0, 15)),
        );

        if (count($qualified) < count($ranked)) {
            $this->line('  * fewer than '.$minTrades.' trades - not ranked, because a handful of trades '
                .'is a coincidence rather than an edge.');
            $this->newLine();
        }

        $agreement = SweepRunner::agreement($results);

        if (! $agreement['agree']) {
            $this->warn('  The metrics disagree about the winner:');
            foreach ($agreement['winners'] as $metric => $winner) {
                $this->line(sprintf('    %-14s %s', $metric, $winner));
            }
            $this->line('  That disagreement is the finding: the ranking is being driven by noise.');
            $this->newLine();
        }

        // The point that matters more than any row in the table above.
        $this->warn('  A sweep fits the sample. These numbers say which combination best matched this');
        $this->warn('  stretch of history, not which will work next. Run without --sweep to test the');
        $this->warn('  winner on bars the search never saw.');

        if ($this->option('json')) {
            file_put_contents(
                $this->option('json'),
                json_encode(array_map(fn ($r) => $r->toArray(), $ranked), JSON_PRETTY_PRINT),
            );
            $this->line('Full results written to '.$this->option('json'));
        }

        return self::SUCCESS;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function describe(array $parameters): string
    {
        $parts = [];

        foreach ($parameters as $name => $value) {
            $parts[] = $name.'='.(floor($value) == $value ? (int) $value : $value);
        }

        return implode(' ', $parts) ?: '-';
    }

    private function resolveStrategy(): ?Strategy
    {
        if ($this->argument('strategy')) {
            $strategy = Strategy::find((int) $this->argument('strategy'));

            if ($strategy === null) {
                $this->error('No strategy with id '.$this->argument('strategy').'.');
            }

            return $strategy;
        }

        $active = Strategy::where('is_active', true)->get();

        if ($active->count() === 1) {
            return $active->first();
        }

        $this->error($active->isEmpty() ? 'No active strategy. Pass an id.' : 'Several active strategies. Pass an id.');

        return null;
    }

    /**
     * @return array<int, Candle>
     */
    private function candles(?int $accountId, string $symbol, string $timeframe): array
    {
        return Candle::query()
            ->when($accountId !== null, fn ($q) => $q->where('broker_account_id', $accountId))
            ->where('symbol', $symbol)
            ->where('timeframe', strtoupper($timeframe))
            ->when($this->option('from'), fn ($q, $from) => $q->where('open_time', '>=', $from))
            ->when($this->option('to'), fn ($q, $to) => $q->where('open_time', '<=', $to))
            ->orderBy('open_time')
            ->get()
            ->all();
    }
}

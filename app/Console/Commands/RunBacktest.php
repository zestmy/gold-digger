<?php

namespace App\Console\Commands;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Strategy;
use App\Services\Backtest\Backtester;
use App\Services\Backtest\MarketAssumptions;
use Illuminate\Console\Command;

/**
 * Run Backtest
 *
 * Replays a strategy over the candles already stored and reports what it would have done.
 *
 * Reads only. Nothing here writes a signal, a trade or a command - a backtest that left rows
 * behind would poison the very analytics it exists to inform.
 */
class RunBacktest extends Command
{
    protected $signature = 'backtest
                            {strategy? : Strategy id. Defaults to the only active one}
                            {--account= : Broker account whose candles to read}
                            {--from= : Earliest bar, as a date}
                            {--to= : Latest bar, as a date}
                            {--balance=10000 : Starting balance}
                            {--risk= : Risk percent per trade, overriding bot settings}
                            {--spread= : Fixed spread in pips, overriding each bar\'s own}
                            {--slippage=0.3 : Adverse slippage in pips on every market order}
                            {--commission=7.0 : Commission per lot per side}
                            {--json= : Write the full report to this file}
                            {--trades : List every simulated trade}';

    protected $description = 'Replay a strategy over stored candles and report the result';

    public function handle(Backtester $backtester): int
    {
        $strategy = $this->resolveStrategy();

        if ($strategy === null) {
            return self::FAILURE;
        }

        $heartbeat = BotHeartbeat::where('user_id', $strategy->user_id)
            ->orderByDesc('last_seen_at')
            ->first();

        $accountId = $this->option('account') !== null
            ? (int) $this->option('account')
            : $heartbeat?->broker_account_id;

        $symbol = $heartbeat?->resolved_symbol ?: $strategy->symbol;

        $entry = $this->candles($accountId, $symbol, $strategy->timeframe_entry);
        $trend = $this->candles($accountId, $symbol, $strategy->timeframe_trend);

        if ($entry === []) {
            $this->error("No {$strategy->timeframe_entry} candles stored for {$symbol}.");
            $this->line('  Candles arrive when the Expert Advisor pushes them. See docs/COMMISSIONING.md.');

            return self::FAILURE;
        }

        $market = MarketAssumptions::fromHeartbeat($heartbeat, array_filter([
            'spreadPips' => $this->option('spread') !== null ? (float) $this->option('spread') : null,
            'slippagePips' => (float) $this->option('slippage'),
            'commissionPerLot' => (float) $this->option('commission'),
            'startingBalance' => (float) $this->option('balance'),
        ], fn ($v) => $v !== null));

        $settings = BotSettings::where('user_id', $strategy->user_id)->first();

        if ($this->option('risk') !== null && $settings !== null) {
            // Not persisted - the model is only a carrier for the run.
            $settings->risk_percentage = (float) $this->option('risk');
        }

        $this->header($strategy, $symbol, $entry, $trend, $market);

        $report = $backtester->run($strategy, $entry, $trend, $market, $settings);

        $this->present($report);

        if ($this->option('json')) {
            file_put_contents($this->option('json'), json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('Full report written to '.$this->option('json'));
        }

        return self::SUCCESS;
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

        $this->error($active->isEmpty()
            ? 'No active strategy. Pass an id explicitly.'
            : 'Several active strategies. Pass an id explicitly.');

        foreach (Strategy::orderBy('id')->get() as $s) {
            $this->line("  {$s->id}  {$s->name}".($s->is_active ? ' (active)' : ''));
        }

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

    private function header(Strategy $strategy, string $symbol, array $entry, array $trend, MarketAssumptions $market): void
    {
        $this->newLine();
        $this->line("<options=bold>{$strategy->name}</> on {$symbol}");
        $this->line(sprintf(
            '  %s %d bars (%s to %s), %s %d bars',
            $strategy->timeframe_entry,
            count($entry),
            $entry[0]->open_time->toDateString(),
            end($entry)->open_time->toDateString(),
            $strategy->timeframe_trend,
            count($trend),
        ));
        $this->line(sprintf(
            '  spread %s, slippage %.1f pips, commission %.2f/lot, pip value %.2f',
            $market->spreadPips !== null ? number_format($market->spreadPips, 1).' pips' : "each bar's own",
            $market->slippagePips,
            $market->commissionPerLot,
            $market->pipValuePerLot,
        ));
        $this->newLine();
    }

    private function present($report): void
    {
        foreach ($report->notes as $note) {
            $this->warn($note);
        }

        $m = $report->metrics();

        if ($m['trades'] === 0) {
            $this->warn('No trades were taken.');
            $this->presentSkips($report);

            return;
        }

        $money = fn (float $v): string => ($v >= 0 ? '+' : '').number_format($v, 2);

        $this->table(['Metric', 'Value'], [
            ['Trades', $m['trades']],
            ['Win rate', $m['win_rate'].'%'],
            ['Profit factor', $m['profit_factor'] ?: 'n/a (no losing trades)'],
            ['Expectancy per trade', $money($m['expectancy'])],
            ['', ''],
            ['Gross P&L', $money($m['gross_pnl'])],
            ['Costs', number_format($m['costs'], 2)],
            ['Net P&L', $money($m['net_pnl'])],
            ['Return', $m['return_pct'].'%'],
            ['', ''],
            ['Average win', $money($m['avg_win'])],
            ['Average loss', $money($m['avg_loss'])],
            ['Largest win', $money($m['largest_win'])],
            ['Largest loss', $money($m['largest_loss'])],
            ['Max drawdown', number_format($m['max_drawdown'], 2).' ('.$m['max_drawdown_pct'].'%)'],
            ['', ''],
            ['Final balance', number_format($report->finalBalance, 2)],
        ]);

        $exits = $report->exitBreakdown();

        if ($exits !== []) {
            $this->line('<options=bold>How positions ended</>');
            foreach ($exits as $reason => $count) {
                $this->line(sprintf('  %-18s %d', $reason, $count));
            }
            $this->newLine();
        }

        $this->presentSkips($report);

        if ($report->unclosed !== []) {
            $this->warn(count($report->unclosed).' position(s) were still open when the data ran out; '
                .'they are excluded from every figure above.');
        }

        if ($this->option('trades')) {
            $this->table(
                ['Opened', 'Dir', 'Lots', 'Entry', 'Ended', 'Net'],
                array_map(fn (array $t) => [
                    $t['opened_at'], $t['direction'], $t['lots'], $t['entry_price'],
                    $t['closure_reason'], number_format($t['net_pnl'], 2),
                ], array_map(fn ($t) => $t->toArray(), $report->trades)),
            );
        }
    }

    private function presentSkips($report): void
    {
        if ($report->skips === []) {
            return;
        }

        arsort($report->skips);

        // Usually the most useful part of the output: few trades from many bars is normally
        // a filter that is too tight, and this says which one.
        $this->line('<options=bold>Setups declined</>');

        foreach ($report->skips as $reason => $count) {
            $this->line(sprintf('  %-24s %d', $reason, $count));
        }

        $this->newLine();
    }
}

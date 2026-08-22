<?php

namespace App\Services\Backtest;

use App\Models\Strategy;
use Carbon\CarbonInterface;

/**
 * Backtest Report
 *
 * What the walk found, in the same terms the Analytics page uses - win rate, profit factor,
 * average and largest win and loss - so a backtested strategy and a traded one can be compared
 * without translating between two vocabularies.
 *
 * Two figures here are not on the Analytics page and matter more than most of the ones that
 * are:
 *
 *   - **Max drawdown**, the largest peak-to-trough fall in equity. A strategy's net profit says
 *     nothing about whether anyone could have sat through it.
 *   - **Skips by reason**, the same `skip_reason` vocabulary the live system records. A backtest
 *     that took four trades from three hundred bars is usually a filter problem, not a market
 *     one, and this is what says which filter.
 */
final class BacktestReport
{
    /** @var array<int, SimulatedTrade> */
    public array $trades = [];

    /** @var array<int, SimulatedTrade> Positions still open when the data ran out. */
    public array $unclosed = [];

    /** @var array<string, int> */
    public array $skips = [];

    /** @var array<int, array{at: string, equity: float}> */
    public array $equity = [];

    public array $notes = [];

    public int $entriesTaken = 0;

    public float $finalBalance = 0.0;

    private float $peakEquity = 0.0;

    public float $maxDrawdown = 0.0;

    public float $maxDrawdownPct = 0.0;

    public function __construct(
        public readonly Strategy $strategy,
        public readonly MarketAssumptions $market,
    ) {}

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }

    public function skip(string $reason): void
    {
        $this->skips[$reason] = ($this->skips[$reason] ?? 0) + 1;
    }

    public function countEntry(): void
    {
        $this->entriesTaken++;
    }

    public function openEquity(float $balance, CarbonInterface $at): void
    {
        $this->peakEquity = $balance;
        $this->equity[] = ['at' => $at->toDateTimeString(), 'equity' => round($balance, 2)];
    }

    /**
     * Record a point on the equity curve and update the drawdown.
     *
     * Measured on realised equity at each close rather than bar by bar, so it understates a
     * dip that recovered inside an open position. That is the honest limit of a
     * close-to-close walk; a tick-level model would say otherwise.
     */
    public function markEquity(float $balance, CarbonInterface $at): void
    {
        $this->equity[] = ['at' => $at->toDateTimeString(), 'equity' => round($balance, 2)];

        $this->peakEquity = max($this->peakEquity, $balance);

        $fall = $this->peakEquity - $balance;

        if ($fall > $this->maxDrawdown) {
            $this->maxDrawdown = $fall;
            $this->maxDrawdownPct = $this->peakEquity > 0 ? ($fall / $this->peakEquity) * 100 : 0.0;
        }
    }

    /**
     * @param  bool  $counted  False for positions the data ran out on - they have no result yet
     */
    public function recordTrade(SimulatedTrade $trade, bool $counted = true): void
    {
        if ($counted) {
            $this->trades[] = $trade;

            return;
        }

        $this->unclosed[] = $trade;
    }

    public function finalise(float $balance): void
    {
        $this->finalBalance = round($balance, 2);
    }

    // =========================================================================
    // METRICS
    // =========================================================================

    /**
     * @return array<string, float|int>
     */
    public function metrics(): array
    {
        $count = count($this->trades);

        if ($count === 0) {
            return [
                'trades' => 0,
                'win_rate' => 0.0,
                'profit_factor' => 0.0,
                'gross_pnl' => 0.0,
                'costs' => 0.0,
                'net_pnl' => 0.0,
                'avg_win' => 0.0,
                'avg_loss' => 0.0,
                'largest_win' => 0.0,
                'largest_loss' => 0.0,
                'max_drawdown' => 0.0,
                'max_drawdown_pct' => 0.0,
                'expectancy' => 0.0,
                'return_pct' => 0.0,
            ];
        }

        $nets = array_map(fn (SimulatedTrade $t) => $t->netPnl, $this->trades);

        $wins = array_values(array_filter($nets, fn ($n) => $n > 0));
        $losses = array_values(array_filter($nets, fn ($n) => $n < 0));

        $grossProfit = array_sum($wins);
        $grossLoss = abs(array_sum($losses));

        $net = array_sum($nets);
        $start = $this->market->startingBalance;

        return [
            'trades' => $count,
            'win_rate' => round((count($wins) / $count) * 100, 1),
            // No losses at all is not an infinite edge, it is too few trades. Reported as
            // zero rather than a number that invites belief.
            'profit_factor' => $grossLoss > 0 ? round($grossProfit / $grossLoss, 2) : 0.0,
            'gross_pnl' => round(array_sum(array_map(fn (SimulatedTrade $t) => $t->grossPnl, $this->trades)), 2),
            'costs' => round(array_sum(array_map(fn (SimulatedTrade $t) => $t->costs, $this->trades)), 2),
            'net_pnl' => round($net, 2),
            'avg_win' => $wins === [] ? 0.0 : round(array_sum($wins) / count($wins), 2),
            'avg_loss' => $losses === [] ? 0.0 : round(array_sum($losses) / count($losses), 2),
            'largest_win' => $wins === [] ? 0.0 : round(max($wins), 2),
            'largest_loss' => $losses === [] ? 0.0 : round(min($losses), 2),
            'max_drawdown' => round($this->maxDrawdown, 2),
            'max_drawdown_pct' => round($this->maxDrawdownPct, 2),
            // What one trade is worth on average, which is the figure that decides whether
            // the strategy is worth running at all.
            'expectancy' => round($net / $count, 2),
            'return_pct' => $start > 0 ? round(($net / $start) * 100, 2) : 0.0,
        ];
    }

    /**
     * How positions ended, which is where a ladder problem shows up.
     *
     * @return array<string, int>
     */
    public function exitBreakdown(): array
    {
        $counts = [];

        foreach ($this->trades as $trade) {
            $reason = $trade->closureReason ?? 'unknown';
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'strategy' => [
                'id' => $this->strategy->id,
                'name' => $this->strategy->name,
                'ema_fast' => $this->strategy->ema_fast,
                'ema_slow' => $this->strategy->ema_slow,
                'adx_threshold' => (float) $this->strategy->adx_threshold,
                'sl_atr_multiplier' => (float) $this->strategy->sl_atr_multiplier,
                'tp_pips' => [
                    (float) $this->strategy->tp1_pips,
                    (float) $this->strategy->tp2_pips,
                    $this->strategy->tp3_pips !== null ? (float) $this->strategy->tp3_pips : null,
                ],
            ],
            'assumptions' => [
                'pip_size' => $this->market->pipSize,
                'pip_value_per_lot' => $this->market->pipValuePerLot,
                'spread_pips' => $this->market->spreadPips ?? 'per-bar',
                'slippage_pips' => $this->market->slippagePips,
                'commission_per_lot' => $this->market->commissionPerLot,
                'starting_balance' => $this->market->startingBalance,
            ],
            'metrics' => $this->metrics(),
            'entries_taken' => $this->entriesTaken,
            'skips' => $this->skips,
            'exits' => $this->exitBreakdown(),
            'unclosed' => count($this->unclosed),
            'final_balance' => $this->finalBalance,
            'equity' => $this->equity,
            'trades' => array_map(fn (SimulatedTrade $t) => $t->toArray(), $this->trades),
            'notes' => $this->notes,
        ];
    }
}

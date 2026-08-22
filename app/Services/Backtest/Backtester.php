<?php

namespace App\Services\Backtest;

use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Strategy;
use App\Services\Strategy\PositionSizer;
use App\Services\Strategy\StrategyEvaluator;
use App\Services\Strategy\TradingSession;

/**
 * Backtester
 *
 * Walks a stored candle series bar by bar and simulates what the live system would have done.
 *
 * ## It calls the same evaluator the live path calls
 *
 * This is the point of the whole class. `StrategyEvaluator` answers "is there a signal on the
 * most recent closed bar" - so walking history is a matter of handing it progressively longer
 * slices, never a second implementation of the entry rules. A backtester with its own copy of
 * the logic drifts from the thing that trades, and then its results describe a strategy nobody
 * is running.
 *
 * The exit side mirrors `TradeManager` for the same reason: rungs detected on bar close and
 * filled at market, the final target sitting on the order as a broker-side limit, break-even
 * once the first rung actually fills.
 *
 * ## Every assumption leans pessimistic
 *
 * Where the outcome inside a bar is ambiguous, the losing interpretation is taken. The
 * specific choices are commented where they are applied; the important ones are:
 *
 *   - A signal on bar i fills at bar i+1's open, never at bar i's close. Using the close is
 *     look-ahead: the decision was made *from* that price.
 *   - A bar that spans both the stop and a target is assumed to have hit the stop first.
 *     Without tick data the order is unknowable, and the alternative is a backtest that
 *     converts every losing bar into a winner.
 *   - Ladder rungs fill at the bar's close, not at the rung. That is what the live system
 *     really does - it notices on bar close and closes at market - so a backtest that fills
 *     at the rung is measuring a system that does not exist.
 *
 * ## What it does not model
 *
 * Swap, requotes, weekend gaps as anything other than the next bar's open, and partial fills.
 * News spikes appear only as whatever the bar recorded.
 */
final class Backtester
{
    public function __construct(
        private readonly StrategyEvaluator $evaluator = new StrategyEvaluator,
        private readonly PositionSizer $sizer = new PositionSizer,
        private readonly TradingSession $sessions = new TradingSession,
    ) {}

    /**
     * @param  array<int, Candle>  $entryCandles  Oldest-first
     * @param  array<int, Candle>  $trendCandles  Oldest-first
     */
    public function run(
        Strategy $strategy,
        array $entryCandles,
        array $trendCandles,
        MarketAssumptions $market,
        ?BotSettings $settings = null,
    ): BacktestReport {
        $report = new BacktestReport($strategy, $market);

        $emaSlow = (int) $strategy->ema_slow;
        $period = (int) $strategy->atr_period;
        $warmup = max($emaSlow + 2, (2 * $period) + 1);

        $total = count($entryCandles);

        if ($total <= $warmup + 1) {
            $report->note("Not enough bars: {$total} supplied, at least ".($warmup + 2).' needed for warm-up.');

            return $report;
        }

        $balance = $market->startingBalance;
        $report->openEquity($balance, $entryCandles[$warmup]->open_time);

        /** @var array<int, SimulatedTrade> $open */
        $open = [];

        $maxConcurrent = (int) ($settings?->max_concurrent_trades ?? 1);
        $sessionsAllowed = $settings?->allowed_sessions;
        $minAtr = $settings?->min_atr_threshold !== null ? (float) $settings->min_atr_threshold : null;
        $riskPct = (float) ($settings?->risk_percentage ?? 1.0);

        // The last bar has no successor to fill an entry on, so entries stop one short.
        for ($i = $warmup; $i < $total; $i++) {
            $bar = $entryCandles[$i];

            // ---- manage what is already open, against this bar ----
            foreach ($open as $key => $trade) {
                $trade->barsHeld++;

                $this->manage($trade, $bar, $strategy, $entryCandles, $i, $market, $report);

                if (! $trade->isOpen()) {
                    $balance += $trade->netPnl;
                    $report->recordTrade($trade);
                    $report->markEquity($balance, $bar->open_time);
                    unset($open[$key]);
                }
            }

            if ($i >= $total - 1) {
                // No next bar to fill on.
                continue;
            }

            // ---- look for an entry ----
            $slice = array_slice($entryCandles, max(0, $i - StrategyEvaluator::LOOKBACK_BARS + 1), min($i + 1, StrategyEvaluator::LOOKBACK_BARS));
            $trendSlice = $this->trendUpTo($trendCandles, $bar->open_time);

            $setup = $this->evaluator->evaluate($strategy, $slice, $trendSlice);

            if ($setup === null) {
                continue;
            }

            $skip = $this->objection($setup, $strategy, $sessionsAllowed, $minAtr, count($open), $maxConcurrent, $report, $balance, $market);

            if ($skip !== null) {
                $report->skip($skip);

                continue;
            }

            $stopDistance = (float) $strategy->sl_atr_multiplier * $setup->atr;
            $stopPips = $market->priceToPips($stopDistance);

            $lots = $this->sizer->size($balance, $riskPct, $stopPips, $market->pipValuePerLot);

            if ($lots === null || $lots <= 0) {
                $report->skip('lot_size_unavailable');

                continue;
            }

            $open[] = $this->enter($setup, $entryCandles[$i + 1], $strategy, $stopDistance, $stopPips, $lots, $market, $report);
        }

        // Anything still open at the end is closed at the last price, marked so it can be
        // excluded - an open position is not a result, and counting it as one inflates
        // whichever side it happens to be sitting on.
        $last = $entryCandles[$total - 1];

        foreach ($open as $trade) {
            $exit = $this->exitPrice($trade, (float) $last->close, $market, slip: true);
            $pips = $this->pipsFor($trade, $exit, $market);

            $trade->close(
                'unclosed',
                $trade->remainingLots,
                $exit,
                $pips,
                $market->money($pips, $trade->remainingLots),
                $market->commissionPerLot * $trade->remainingLots,
                $last->open_time,
            );

            $balance += $trade->netPnl;
            $report->recordTrade($trade, counted: false);
        }

        $report->finalise($balance);

        return $report;
    }

    // =========================================================================
    // ENTRY
    // =========================================================================

    private function enter(
        $setup,
        Candle $fillBar,
        Strategy $strategy,
        float $stopDistance,
        float $stopPips,
        float $lots,
        MarketAssumptions $market,
        BacktestReport $report,
    ): SimulatedTrade {
        $isBuy = $setup->isBuy();
        $sign = $isBuy ? 1.0 : -1.0;

        // The signal was produced from bar i's close, so filling at that close would be
        // trading on information the decision was made from. The next bar's open is the
        // first price the system could actually have reached.
        $mid = (float) $fillBar->open;
        $spread = $market->spreadPipsFor($fillBar->spread_points);

        // Candle prices are bid: a buy crosses the spread on the way in, a sell on the way
        // out. Slippage is adverse in both directions.
        $entry = $isBuy
            ? $mid + $market->pipsToPrice($spread + $market->slippagePips)
            : $mid - $market->pipsToPrice($market->slippagePips);

        $target = fn (?float $pips): ?float => $pips === null
            ? null
            : $entry + ($sign * $market->pipsToPrice($pips));

        $report->countEntry();

        return new SimulatedTrade(
            direction: $setup->direction,
            entryPrice: $entry,
            lots: $lots,
            stopPrice: $entry - ($sign * $stopDistance),
            tp1: $target((float) $strategy->tp1_pips),
            tp2: $target((float) $strategy->tp2_pips),
            tp3: $target($strategy->tp3_pips !== null ? (float) $strategy->tp3_pips : null),
            stopPips: round($stopPips, 2),
            openedAt: $fillBar->open_time,
            features: $setup->features,
        );
    }

    // =========================================================================
    // MANAGEMENT
    // =========================================================================

    /**
     * @param  array<int, Candle>  $entryCandles
     */
    private function manage(
        SimulatedTrade $trade,
        Candle $bar,
        Strategy $strategy,
        array $entryCandles,
        int $i,
        MarketAssumptions $market,
        BacktestReport $report,
    ): void {
        $high = (float) $bar->high;
        $low = (float) $bar->low;
        $close = (float) $bar->close;

        $stopHit = $trade->isBuy() ? $low <= $trade->stopPrice : $high >= $trade->stopPrice;
        $final = $trade->finalTarget();
        $targetHit = $final !== null && ($trade->isBuy() ? $high >= $final : $low <= $final);

        // Both inside one bar. Without ticks the order is unknowable, and assuming the
        // target would turn every losing bar into a winner - so the stop is taken.
        if ($stopHit) {
            $fill = $trade->stopPrice - ($trade->isBuy() ? $market->pipsToPrice($market->slippagePips) : -$market->pipsToPrice($market->slippagePips));

            // A trailing stop that fires is a different outcome from the original stop being
            // hit, and the exit breakdown is where anyone would look to tell whether trailing
            // is helping or cutting winners short.
            $reason = match (true) {
                $trade->trailing => 'trailing_stop',
                $trade->breakEven => 'break_even_stop',
                default => 'sl',
            };

            $this->settle($trade, $reason, $trade->remainingLots, $fill, $bar, $market);

            return;
        }

        if ($targetHit) {
            // A broker-side take profit is a limit order: it fills at the level or better,
            // and does not pay slippage.
            $this->settle($trade, $trade->tp3 !== null ? 'tp3' : 'tp2', $trade->remainingLots, $final, $bar, $market);

            return;
        }

        // ---- whole-position exits, checked before rungs, as TradeManager does ----
        if ($strategy->exit_on_reversal) {
            $slice = array_slice($entryCandles, max(0, $i - StrategyEvaluator::LOOKBACK_BARS + 1), min($i + 1, StrategyEvaluator::LOOKBACK_BARS));
            $reversal = $this->evaluator->crossDirection($slice, (int) $strategy->ema_fast, (int) $strategy->ema_slow);

            if ($reversal !== null && $reversal !== $trade->direction) {
                $this->settle($trade, 'reversal_exit', $trade->remainingLots, $this->exitPrice($trade, $close, $market, slip: true), $bar, $market);

                return;
            }
        }

        if ($strategy->max_holding_bars !== null && $trade->barsHeld >= (int) $strategy->max_holding_bars) {
            $this->settle($trade, 'time_exit', $trade->remainingLots, $this->exitPrice($trade, $close, $market, slip: true), $bar, $market);

            return;
        }

        // ---- the ladder ----
        $rungs = ['tp1' => $trade->tp1];

        // TP2 is only a rung when it is not itself the level on the order.
        if ($trade->tp3 !== null) {
            $rungs['tp2'] = $trade->tp2;
        }

        foreach ($rungs as $name => $level) {
            if ($level === null || $trade->hasFilled($name)) {
                continue;
            }

            $reached = $trade->isBuy() ? $high >= $level : $low <= $level;

            if (! $reached) {
                continue;
            }

            $pct = $name === 'tp1' ? (float) $strategy->tp1_close_pct : (float) $strategy->tp2_close_pct;
            $lots = round($trade->lots * ($pct / 100.0), 4);

            if ($lots <= 0 || $lots >= $trade->remainingLots) {
                continue;
            }

            // Filled at the bar's *close*, not at the rung. The live system notices when the
            // bar closes and then closes at market, so a fill at the rung would be measuring
            // a system nobody built. This is the single biggest source of optimism in a
            // naive ladder backtest.
            $this->settle($trade, $name, $lots, $this->exitPrice($trade, $close, $market, slip: true), $bar, $market);

            // Break-even once the first rung has actually filled, matching TradeManager -
            // including the offset that makes the phrase mean what it says.
            if ($name === 'tp1' && $trade->isOpen()) {
                $trade->stopPrice = $this->breakEvenPrice($strategy, $trade, $market);
                $trade->breakEven = true;
            }
        }

        // The trail is applied last and only to a still-open position. Checked after the
        // ladder so a bar that took a rung can also tighten the stop, and after the exits so
        // a position about to close is not modified on its way out.
        if ($trade->isOpen()) {
            $trade->observe($high, $low);

            $trailed = $this->trailingStop($strategy, $trade, $market);

            if ($trailed !== null && $this->isTighter($trade, $trailed)) {
                $trade->stopPrice = $trailed;
                $trade->trailing = true;
            }
        }
    }

    /**
     * Break-even, plus whatever the strategy wants to cover its costs.
     */
    private function breakEvenPrice($strategy, SimulatedTrade $trade, MarketAssumptions $market): float
    {
        $offset = (float) ($strategy->breakeven_offset_pips ?? 0);

        if ($offset <= 0) {
            return $trade->entryPrice;
        }

        return $trade->entryPrice + (($trade->isBuy() ? 1.0 : -1.0) * $market->pipsToPrice($offset));
    }

    /**
     * Where the trailing stop sits, or null when it is off or not yet triggered.
     *
     * Deliberately the same rule as TradeManager: measured from the best price seen, engaged
     * only past the trigger. The backtest is only useful if it models the system that trades.
     */
    private function trailingStop($strategy, SimulatedTrade $trade, MarketAssumptions $market): ?float
    {
        $trigger = $strategy->trail_trigger_pips !== null ? (float) $strategy->trail_trigger_pips : null;
        $distance = $strategy->trail_distance_pips !== null ? (float) $strategy->trail_distance_pips : null;

        if ($trigger === null || $distance === null || $distance <= 0 || $trade->bestPrice === null) {
            return null;
        }

        $profit = $trade->isBuy()
            ? $trade->bestPrice - $trade->entryPrice
            : $trade->entryPrice - $trade->bestPrice;

        if ($market->priceToPips($profit) < $trigger) {
            return null;
        }

        return $trade->isBuy()
            ? $trade->bestPrice - $market->pipsToPrice($distance)
            : $trade->bestPrice + $market->pipsToPrice($distance);
    }

    /**
     * A stop only ever moves toward profit. Loosening it would widen a risk that was decided
     * when the position opened.
     */
    private function isTighter(SimulatedTrade $trade, float $level): bool
    {
        return $trade->isBuy()
            ? $level > $trade->stopPrice
            : $level < $trade->stopPrice;
    }

    /**
     * Apply a close and account for it.
     */
    private function settle(SimulatedTrade $trade, string $reason, float $lots, float $price, Candle $bar, MarketAssumptions $market): void
    {
        $pips = $this->pipsFor($trade, $price, $market);

        $trade->close(
            $reason,
            $lots,
            $price,
            $pips,
            $market->money($pips, $lots),
            // Commission is charged per side; the entry's share is attributed here so a
            // partially closed position pays proportionally rather than all at once.
            $market->commissionPerLot * $lots * 2,
            $bar->open_time,
        );
    }

    /**
     * Exit price for a market order, crossing the spread where the direction requires it.
     */
    private function exitPrice(SimulatedTrade $trade, float $mid, MarketAssumptions $market, bool $slip): float
    {
        $slippage = $slip ? $market->pipsToPrice($market->slippagePips) : 0.0;

        // A buy is closed by selling at bid; a sell is closed by buying at ask.
        return $trade->isBuy()
            ? $mid - $slippage
            : $mid + $slippage;
    }

    private function pipsFor(SimulatedTrade $trade, float $exit, MarketAssumptions $market): float
    {
        $move = $trade->isBuy() ? $exit - $trade->entryPrice : $trade->entryPrice - $exit;

        return $market->priceToPips($move);
    }

    // =========================================================================
    // FILTERS
    // =========================================================================

    /**
     * The first reason this setup would not have been traded, mirroring SignalGenerator.
     */
    private function objection(
        $setup,
        Strategy $strategy,
        ?array $sessionsAllowed,
        ?float $minAtr,
        int $openCount,
        int $maxConcurrent,
        BacktestReport $report,
        float $balance,
        MarketAssumptions $market,
    ): ?string {
        if (! $this->sessions->isOpen($sessionsAllowed, $setup->barTime)) {
            return 'session_closed';
        }

        if ($setup->adx < (float) $strategy->adx_threshold) {
            return 'adx_below_threshold';
        }

        if ($minAtr !== null && $setup->atr < $minAtr) {
            return 'atr_below_threshold';
        }

        if ($openCount >= max(1, $maxConcurrent)) {
            return 'max_trades_reached';
        }

        if ($balance <= 0) {
            return 'account_blown';
        }

        return null;
    }

    /**
     * Trend bars available at a point in time.
     *
     * Sliced by timestamp rather than by index, because the two series tick at different
     * rates. Taking a fixed number of trend bars would let an H1 bar that had not closed yet
     * inform an M5 decision - look-ahead that flatters every result.
     *
     * @param  array<int, Candle>  $trendCandles
     * @return array<int, Candle>
     */
    private function trendUpTo(array $trendCandles, $at): array
    {
        $usable = [];

        foreach ($trendCandles as $candle) {
            if ($candle->open_time->greaterThan($at)) {
                break;
            }

            $usable[] = $candle;
        }

        return count($usable) > StrategyEvaluator::LOOKBACK_BARS
            ? array_slice($usable, -StrategyEvaluator::LOOKBACK_BARS)
            : $usable;
    }
}

<?php

namespace App\Services\Strategy;

use App\Models\BotHeartbeat;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\TradePartial;

/**
 * Trade Manager
 *
 * Watches open positions and closes them in stages. Until this existed, `tp1_close_pct`,
 * `tp2_close_pct`, `exit_on_reversal` and `max_holding_bars` were columns nothing read: an
 * entry ran to its final target or its stop, and the ladder the strategy described never
 * happened.
 *
 * Runs on the same trigger as signal generation - a newly closed bar on the strategy's
 * entry timeframe - and issues `close` and `modify` commands the EA claims on its next poll.
 *
 * ## The ladder is detected from bars, and that has a cost
 *
 * MT5 puts one take-profit on a position, and this system gives it the *final* target so a
 * rung meant to take half does not close the whole thing. Everything before that final
 * target therefore has to be noticed here and closed at market.
 *
 * The consequence is honest and worth stating: a rung is detected when the bar that touched
 * it *closes*, and filled at whatever the market is a moment later. If price spiked through
 * TP1 and came back inside the same bar, the fill is worse than TP1 - possibly much worse.
 * The stop is still broker-side, so this costs profit rather than risking capital, but it is
 * a real difference between the ladder as configured and the ladder as executed.
 *
 * The precise alternative is to open one position per rung, each with its own broker-side
 * TP. That is exact and has no latency, but it triples position count, margin and the
 * `max_concurrent_trades` accounting, and makes one trade several tickets. Worth revisiting;
 * not worth doing quietly.
 *
 * ## Close percentages are fractions of the *initial* position
 *
 * `strategies` defaults to 50 / 30 / 20, which sums to exactly 100 and is only coherent as
 * fractions of what was opened. The migration's own comment says TP2 closes its share "of
 * REMAINING", which contradicts those defaults: 30% of the 50% left after TP1 is 15% of the
 * position, and a tp3 of 20% would then leave a third of the trade open forever. The
 * defaults are taken as the intent and the comment as the mistake. The UI says only
 * "TP1 Close %", so nothing outside the schema disagrees.
 *
 * ## Idempotence
 *
 * Every action carries a fixed idempotency key - `close:{trade}:tp1`, `modify:{trade}:be` -
 * so `TradeCommand::enqueue` collapses a repeat into the row that already exists. This is
 * what makes it safe to re-check a rung on every bar rather than tracking which rungs have
 * been taken: the second enqueue is a lookup, not a second close.
 */
final class TradeManager
{
    public function __construct(
        private readonly StrategyEvaluator $evaluator = new StrategyEvaluator,
        private readonly SymbolResolver $symbols = new SymbolResolver,
    ) {}

    /**
     * Manage every open position belonging to one strategy.
     *
     * @return array<int, array{trade_id: int, action: string}> what was queued, for the caller to report
     */
    public function manage(Strategy $strategy, ?int $brokerAccountId = null): array
    {
        $trades = Trade::where('strategy_id', $strategy->id)
            // Only positions this system opened. Reconciliation attributes adopted
            // positions to a strategy because strategy_id is NOT NULL, but they belong to
            // no strategy - and closing a position somebody opened by hand because
            // max_holding_bars elapsed is the worst thing this class could do.
            ->where('origin', 'bot')
            ->whereIn('status', ['open', 'partially_closed'])
            ->get();

        if ($trades->isEmpty()) {
            return [];
        }

        $heartbeat = $this->heartbeat($strategy->user_id, $brokerAccountId);
        $accountId = $brokerAccountId ?? $heartbeat?->broker_account_id;

        // Same resolution as the entry side, from the same place - a ladder sized against one
        // instrument's pip value while the entry was sized against another's would be a
        // quietly wrong position rather than a visible failure.
        $spec = $this->symbols->for($accountId, $strategy->symbol, $heartbeat);
        $symbol = $spec['symbol'];

        $candles = Candle::recentSeries(
            $accountId,
            $symbol,
            $strategy->timeframe_entry,
            StrategyEvaluator::LOOKBACK_BARS,
        );

        if ($candles === []) {
            return [];
        }

        // One reading of the reversal condition for all of this strategy's positions: it is
        // a property of the series, not of any single trade.
        $reversal = $this->evaluator->crossDirection(
            $candles,
            (int) $strategy->ema_fast,
            (int) $strategy->ema_slow,
        );

        $actions = [];

        foreach ($trades as $trade) {
            foreach ($this->actionsFor($strategy, $trade, $candles, $reversal, $spec) as $action) {
                $actions[] = ['trade_id' => $trade->id, 'action' => $action];
            }
        }

        return $actions;
    }

    /**
     * Manage every active strategy's positions.
     *
     * @return array<int, array{trade_id: int, action: string}>
     */
    public function manageAll(?int $brokerAccountId = null): array
    {
        $actions = [];

        foreach (Strategy::where('is_active', true)->get() as $strategy) {
            $actions = array_merge($actions, $this->manage($strategy, $brokerAccountId));
        }

        return $actions;
    }

    // =========================================================================
    // PER-TRADE DECISIONS
    // =========================================================================

    /**
     * Decide and queue everything this position needs on this bar.
     *
     * Exits are considered before rungs. A reversal or a timeout closes the whole remaining
     * position, which makes taking a partial first pointless - two commands where one would
     * do, and the partial's fill would move the exit's fill.
     *
     * @param  array<int, Candle>  $candles  Oldest-first, entry timeframe
     * @return array<int, string>
     */
    private function actionsFor(
        Strategy $strategy,
        Trade $trade,
        array $candles,
        ?string $reversal,
        array $spec,
    ): array {
        $since = $this->barsSinceEntry($candles, $trade);

        if ($since === []) {
            return [];
        }

        // --- Whole-position exits -------------------------------------------------

        if ($strategy->exit_on_reversal && $reversal !== null && $reversal !== $trade->direction) {
            $this->queueClose($trade, 'reversal_exit', null);

            return ['reversal_exit'];
        }

        if ($strategy->max_holding_bars !== null && count($since) >= (int) $strategy->max_holding_bars) {
            $this->queueClose($trade, 'time_exit', null);

            return ['time_exit'];
        }

        // --- The take-profit ladder ------------------------------------------------

        $actions = [];

        foreach ($this->rungsReached($trade, $since) as $rung) {
            $volume = $this->rungVolume($strategy, $trade, $rung, $spec);

            if ($volume === null) {
                continue;
            }

            $this->queueClose($trade, $rung, $volume);
            $actions[] = $rung;
        }

        // --- Break-even ------------------------------------------------------------

        // Only once the first rung has actually been *filled*, not merely queued: moving the
        // stop to entry while the partial is still in flight would leave the full position
        // sitting on a break-even stop, which is a different trade from the one intended.
        $breakEven = $this->breakEvenPrice($strategy, $trade, $spec);

        if ($this->hasFilledRung($trade, 'tp1') && ! $this->stopAtOrBeyond($trade, $breakEven)) {
            $this->queueBreakEven($trade, $breakEven);
            $actions[] = 'break_even';
        }

        // --- Trailing --------------------------------------------------------------

        $trailed = $this->trailingStop($strategy, $trade, $since, $spec);

        if ($trailed !== null && ! $this->stopAtOrBeyond($trade, $trailed)) {
            $this->queueTrail($trade, $trailed);
            $actions[] = 'trail';
        }

        return $actions;
    }

    /**
     * Which take-profit levels price has traded through since the position opened.
     *
     * Measured across every bar since entry rather than only the newest one, so a rung that
     * was reached while the dashboard was down is still acted on when it comes back. The
     * idempotency key is what keeps that from re-closing a rung already taken.
     *
     * The *final* target is deliberately absent, because it is the level sitting on the
     * order itself and the broker closes the remainder without being asked. Which level
     * that is depends on the trade: TP3 when it has one, and otherwise TP2. Laddering the
     * final target as well would queue a partial close at the exact price the broker is
     * about to close the whole position at.
     *
     * @param  array<int, Candle>  $since
     * @return array<int, string>
     */
    private function rungsReached(Trade $trade, array $since): array
    {
        $high = max(array_map(static fn (Candle $c) => (float) $c->high, $since));
        $low = min(array_map(static fn (Candle $c) => (float) $c->low, $since));

        $rungs = ['tp1' => $trade->tp1_price];

        if ($trade->tp3_price !== null) {
            $rungs['tp2'] = $trade->tp2_price;
        }

        $reached = [];

        foreach ($rungs as $rung => $level) {
            if ($level === null) {
                continue;
            }

            $hit = $trade->direction === 'buy'
                ? $high >= (float) $level
                : $low <= (float) $level;

            if ($hit) {
                $reached[] = $rung;
            }
        }

        return $reached;
    }

    /**
     * Lots to close at one rung, or null when the position cannot be divided that way.
     *
     * Returns null rather than a best effort in two cases, both of which would otherwise
     * produce a command the broker refuses:
     *
     * - the share works out below the broker's minimum volume, which the executor snaps to
     *   zero ("Normalised volume is zero; nothing to send");
     * - closing it would leave a remainder below that minimum, which the broker will not
     *   let the position sit at.
     *
     * A position too small to divide runs to its final target whole. That is the honest
     * outcome for a 0.01-lot trade, and better than a failing command at every rung.
     */
    private function rungVolume(Strategy $strategy, Trade $trade, string $rung, array $spec): ?float
    {
        $pct = $rung === 'tp1'
            ? (float) $strategy->tp1_close_pct
            : (float) $strategy->tp2_close_pct;

        if ($pct <= 0.0) {
            return null;
        }

        $volume = round((float) $trade->initial_lot_size * ($pct / 100.0), 4);
        $remaining = (float) $trade->remaining_lot_size;

        // Never ask to close more than is still open - the earlier rung may have filled for
        // less than requested, or a manual close may have taken part of it.
        if ($volume >= $remaining) {
            return null;
        }

        $min = $spec['volume_min'];

        if ($min !== null && ($volume < $min || ($remaining - $volume) < $min)) {
            return null;
        }

        return $volume;
    }

    /**
     * Where a break-even stop actually goes.
     *
     * The entry price plus `breakeven_offset_pips` in the profitable direction. Moving a stop
     * to exactly the entry leaves the trade losing whatever it paid to get there - the spread
     * crossed on entry, commission both sides, any slippage - which on a gold scalp is a real
     * share of a 30-pip first target. The offset is what makes the phrase true.
     *
     * Defaults to zero, so a strategy that never sets it behaves exactly as before.
     */
    private function breakEvenPrice(Strategy $strategy, Trade $trade, array $spec): float
    {
        $offset = (float) ($strategy->breakeven_offset_pips ?? 0);
        $pipSize = $spec['pip_size'];

        if ($offset <= 0 || $pipSize === null || $pipSize <= 0) {
            return (float) $trade->entry_price;
        }

        $sign = $trade->direction === 'buy' ? 1.0 : -1.0;

        return (float) $trade->entry_price + ($sign * $offset * $pipSize);
    }

    /**
     * Where the trailing stop should sit now, or null when it should not move.
     *
     * Measured from the best price the position has seen since it opened - the highest high on
     * a buy - rather than from the latest close. A stop that followed the close would loosen
     * every time price pulled back, which is not a trailing stop; it is a stop that drifts.
     *
     * Two conditions have to hold before it engages: the position must be at least
     * `trail_trigger_pips` in profit at its best, and the resulting level must be an
     * improvement. Both are what stop this turning into a stream of `modify` commands that
     * shuffle the stop around by a fraction of a pip.
     *
     * @param  array<int, Candle>  $since  Bars closed since the position opened
     */
    private function trailingStop(Strategy $strategy, Trade $trade, array $since, array $spec): ?float
    {
        $trigger = $strategy->trail_trigger_pips !== null ? (float) $strategy->trail_trigger_pips : null;
        $distance = $strategy->trail_distance_pips !== null ? (float) $strategy->trail_distance_pips : null;
        $pipSize = $spec['pip_size'];

        // Trailing is off by default. It changes P&L, and a setting that changes P&L should
        // not arrive switched on.
        if ($trigger === null || $distance === null || $distance <= 0 || $pipSize === null || $pipSize <= 0) {
            return null;
        }

        $isBuy = $trade->direction === 'buy';

        $best = $isBuy
            ? max(array_map(static fn (Candle $c) => (float) $c->high, $since))
            : min(array_map(static fn (Candle $c) => (float) $c->low, $since));

        $profitPips = (($isBuy ? $best - (float) $trade->entry_price : (float) $trade->entry_price - $best)) / $pipSize;

        if ($profitPips < $trigger) {
            return null;
        }

        return $isBuy
            ? $best - ($distance * $pipSize)
            : $best + ($distance * $pipSize);
    }

    /**
     * Is the stop already at or past a proposed level?
     *
     * "Past" means further into profit. A stop only ever moves one way: loosening it would
     * widen the risk on a position whose risk was decided when it opened, and no rule in this
     * system is allowed to do that.
     */
    private function stopAtOrBeyond(Trade $trade, float $level): bool
    {
        if ($trade->sl_price === null) {
            return false;
        }

        $current = (float) $trade->sl_price;

        // Under a tenth of a pip on gold is not a move worth a command round trip.
        $epsilon = 0.005;

        return $trade->direction === 'buy'
            ? $current >= $level - $epsilon
            : $current <= $level + $epsilon;
    }

    /**
     * Bars that have closed since the position opened.
     *
     * `opened_at` is written when the fill is reported, so the bar the entry happened on has
     * usually already closed by then and is correctly excluded - its high and low belong to
     * the setup, not to the position.
     *
     * @param  array<int, Candle>  $candles
     * @return array<int, Candle>
     */
    private function barsSinceEntry(array $candles, Trade $trade): array
    {
        if ($trade->opened_at === null) {
            return [];
        }

        return array_values(array_filter(
            $candles,
            static fn (Candle $c) => $c->open_time->greaterThanOrEqualTo($trade->opened_at),
        ));
    }

    // =========================================================================
    // STATE
    // =========================================================================

    /**
     * Has a rung actually been filled - not merely commanded?
     *
     * Reads `trade_partials`, which only gains a row when the EA reports a real deal. This
     * is why the EA has to send the rung back with the fill: a broker deal cannot say which
     * ladder step it was, so the command states it and the EA echoes it.
     */
    private function hasFilledRung(Trade $trade, string $rung): bool
    {
        return TradePartial::where('trade_id', $trade->id)
            ->where('close_reason', $rung)
            ->exists();
    }

    // =========================================================================
    // COMMANDS
    // =========================================================================

    /**
     * Queue a close, whole or partial.
     *
     * `reason` travels on the wire so the fill can be recorded as the rung it was, rather
     * than as the "manual" that a broker-side deal reason flattens every commanded close
     * into. A null volume means the whole remaining position.
     */
    private function queueClose(Trade $trade, string $reason, ?float $volume): void
    {
        TradeCommand::enqueue(
            user: $trade->user,
            type: 'close',
            payload: [
                'symbol' => $trade->symbol,
                'ticket' => $trade->mt5_ticket,
                'volume' => $volume ?? (float) $trade->remaining_lot_size,
                'reason' => $reason,
                'trade_id' => $trade->id,
            ],
            account: $trade->brokerAccount,
            idempotencyKey: "close:{$trade->id}:{$reason}",
            // Deliberately no expiry, unlike an entry. An entry that waited out its bar is
            // no longer the trade the strategy intended; an exit that is late is still the
            // exit, and expiring it would leave a position open that something decided
            // should not be.
            expiresInSeconds: null,
        );
    }

    /**
     * Queue the break-even stop move.
     *
     * The stop goes to the entry price exactly. That is not quite free - commission and swap
     * are still owed - but "break-even" meaning "entry" is what the term is understood to
     * mean, and padding it by an invented number of pips would be a rule nobody configured.
     */
    private function queueBreakEven(Trade $trade, float $level): void
    {
        TradeCommand::enqueue(
            user: $trade->user,
            type: 'modify',
            payload: [
                'symbol' => $trade->symbol,
                'ticket' => $trade->mt5_ticket,
                'sl_price' => round($level, 5),
                'trade_id' => $trade->id,
                'reason' => 'break_even',
            ],
            account: $trade->brokerAccount,
            idempotencyKey: "modify:{$trade->id}:break_even",
            expiresInSeconds: null,
        );
    }

    /**
     * Queue a trailing stop move.
     *
     * Unlike break-even, this happens repeatedly over a position's life, so the idempotency
     * key carries the level itself. Keyed on the trade alone, only the first move would ever
     * be queued; keyed on nothing, a stop that wandered by a fraction of a pip would produce a
     * command per bar.
     *
     * The level is bucketed to whole pips-worth of price for the same reason - two proposals
     * that round to the same stop are the same instruction.
     */
    private function queueTrail(Trade $trade, float $level): void
    {
        $bucket = number_format($level, 2, '.', '');

        TradeCommand::enqueue(
            user: $trade->user,
            type: 'modify',
            payload: [
                'symbol' => $trade->symbol,
                'ticket' => $trade->mt5_ticket,
                'sl_price' => round($level, 5),
                'trade_id' => $trade->id,
                'reason' => 'trail',
            ],
            account: $trade->brokerAccount,
            idempotencyKey: "modify:{$trade->id}:trail:{$bucket}",
            expiresInSeconds: null,
        );
    }

    private function heartbeat(int $userId, ?int $brokerAccountId): ?BotHeartbeat
    {
        return BotHeartbeat::query()
            ->where('user_id', $userId)
            ->when($brokerAccountId !== null, fn ($q) => $q->where('broker_account_id', $brokerAccountId))
            ->orderByDesc('last_seen_at')
            ->first();
    }
}

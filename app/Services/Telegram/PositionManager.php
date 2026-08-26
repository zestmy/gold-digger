<?php

namespace App\Services\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Ai\AiFund;
use App\Services\Strategy\SymbolResolver;

/**
 * Position Manager
 *
 * Looks after a copied position once it is open, without waiting to be told.
 *
 * ## Why this exists separately from TradeManager
 *
 * TradeManager has trailed stops and moved them to break-even since it was written, and it
 * selects `origin = 'bot'` - so none of it ever reached a copied trade. Until now a copied
 * position had two things looking after it: the stop the order carries, and whatever the
 * provider remembers to post. Providers go quiet, sleep, and post "secure half" twenty
 * minutes after the move that warranted it.
 *
 * They are separate rather than merged because their ladders are genuinely different. A
 * strategy trade's targets are its own, in pips it chose; a copied trade's are a stranger's
 * prices. Forcing one code path to mean both would make every change to either risk the
 * other, on the two paths where a mistake opens or closes real positions.
 *
 * ## Everything is measured in R
 *
 * A copied trade's stop is whatever the provider chose - five points on one signal and
 * forty on the next. "Break even at 20 pips" is a third of the way on the first and four
 * times the stop on the second, so a pip trigger cannot be right for both. One times what
 * this trade risked means the same thing on every signal from every provider, which is the
 * only unit under which one setting is correct across all of them.
 *
 * R is the risk the position *opened* with, and it is fixed for the life of the trade. It
 * has to come from `initial_sl_price`, because `sl_price` is live: the reconciler writes
 * the terminal's current stop back onto the row, so the moment this class moves a stop it
 * would otherwise be measuring R against its own last decision. A break-even move made R
 * zero and dropped the position out of management for good - a configured trailing stop
 * moved once and then froze - and a trail landing past the entry made every subsequent
 * distance drift from the multiple that was configured. Neither reported anything.
 *
 * ## It can only ever reduce risk
 *
 * Stops move toward the entry and never away, partials reduce the position and never add
 * to it. That is what makes it safe to run unattended: the worst outcome of a wrong
 * decision here is a smaller winner, never a larger loser. The same rule the follow-up
 * executor enforces, for the same reason.
 */
final class PositionManager
{
    /** How long the terminal has to act on a management instruction. */
    private const EXPIRY_SECONDS = 120;

    public function __construct(
        private readonly SignalSeries $series = new SignalSeries,
    ) {}

    /**
     * Walk this account's open copied positions and act on any that have earned it.
     *
     * @return array<int, string> What was done, for the command's own output
     */
    public function manage(User $user): array
    {
        $settings = BotSettings::where('user_id', $user->id)->first();

        if ($settings === null || ! $settings->is_active) {
            return [];
        }

        $trigger = $settings->copier_protect_at_r;

        // Nothing configured is not an error, it is the default. A deployment that predates
        // these settings was not managing copied trades and must not start silently.
        if ($trigger === null || $trigger <= 0.0) {
            return [];
        }

        $heartbeat = BotHeartbeat::where('user_id', $user->id)->orderByDesc('last_seen_at')->first();

        if ($heartbeat === null || ! $heartbeat->isOnline() || ! $heartbeat->algo_trading_enabled) {
            return [];
        }

        $done = [];

        $trades = Trade::where('user_id', $user->id)
            ->where('origin', AiFund::ORIGIN)
            ->whereIn('status', ['open', 'partially_closed'])
            ->get();

        foreach ($trades as $trade) {
            foreach ($this->actionsFor($trade, $settings, $heartbeat, (float) $trigger) as $action) {
                $done[] = $action;
            }
        }

        return $done;
    }

    /**
     * @return array<int, string>
     */
    private function actionsFor(Trade $trade, BotSettings $settings, BotHeartbeat $heartbeat, float $trigger): array
    {
        // Positions opened before `initial_sl_price` existed fall back to the live stop,
        // which is what every position used until now. For those the old drift remains
        // until they close; there is no honest way to recover an opening risk that was
        // never recorded.
        $opening = $trade->initial_sl_price ?? $trade->sl_price;

        $risk = $opening === null ? 0.0 : abs((float) $trade->entry_price - (float) $opening);
        $best = $this->bestPriceSince($trade, $heartbeat);

        // No stop, or no bars since entry: there is no R to measure against and no reading
        // to measure. Both are refusals rather than assumptions.
        if ($trade->sl_price === null || $risk <= 0.0 || $best === null) {
            return [];
        }

        $isBuy = strtolower((string) $trade->direction) === 'buy';

        // How far the trade has run, at its best, as a multiple of what it risked.
        $advance = $isBuy
            ? ($best - (float) $trade->entry_price) / $risk
            : ((float) $trade->entry_price - $best) / $risk;

        if ($advance < $trigger) {
            return [];
        }

        $actions = [];

        // --- Bank part of it ------------------------------------------------------
        //
        // Before the stop moves, deliberately. If both are queued in one pass and only one
        // reaches the terminal, having taken profit and not moved the stop is a better
        // place to be than the reverse.
        if ($settings->copier_profit_lock_pct !== null && $settings->copier_profit_lock_pct > 0) {
            if ($this->lockProfit($trade, $heartbeat, (int) $settings->copier_profit_lock_pct)) {
                $actions[] = 'profit_lock';
            }
        }

        // --- Trailing, or break-even ----------------------------------------------
        //
        // Trailing supersedes break-even rather than running beside it: a trailing stop
        // that has advanced past the entry already is the break-even move, and queueing
        // both would send two modifies for one intention.
        $target = null;

        if ($settings->copier_trail_distance_r !== null && $settings->copier_trail_distance_r > 0.0) {
            $distance = (float) $settings->copier_trail_distance_r * $risk;

            $target = $isBuy ? $best - $distance : $best + $distance;
        } elseif ($settings->copier_breakeven) {
            $target = $this->breakEvenPrice($trade, $settings, $heartbeat, $isBuy, $best);
        }

        if ($target !== null && $this->improves($trade, $target, $isBuy)) {
            $this->queue($trade, $heartbeat, 'modify', [
                'ticket' => $trade->mt5_ticket,
                'sl_price' => round($target, (int) ($heartbeat->digits ?? 2)),
                // Zero leaves the target alone; see CFXSExecutor::ModifyPosition.
                'tp_price' => 0.0,
                'reason' => 'copier-protect',
            ], "protect:{$trade->id}:".$this->bucket($target));

            $actions[] = $settings->copier_trail_distance_r ? 'trail' : 'break_even';
        }

        return $actions;
    }

    /**
     * Where a break-even stop actually goes.
     *
     * The entry plus `copier_breakeven_offset_pips` in the profitable direction. Closing at
     * the entry exactly is not breaking even - the position has already paid the spread it
     * crossed to get in, and it still owes commission both ways. On a five-point gold stop
     * against a two-point spread that is a large share of 1R booked as a loss on every
     * trade this protection rescues, which is the opposite of what it was turned on for.
     *
     * The offset is in pips, alone among these settings. Everything else here is in R
     * because a copied stop is a stranger's choice and no pip figure could be right across
     * providers. This is not about the trade: it is what the broker charges to hold the
     * instrument, the same size whether the provider risked five points or forty. In R it
     * would shrink exactly where the cost bites hardest.
     *
     * Unconfigured, or with no pip size to place it in price, the stop goes to the entry -
     * which is what this did before the setting existed.
     */
    private function breakEvenPrice(
        Trade $trade,
        BotSettings $settings,
        BotHeartbeat $heartbeat,
        bool $isBuy,
        float $best,
    ): float {
        $entry = (float) $trade->entry_price;
        $offset = $settings->copier_breakeven_offset_pips;

        if ($offset === null || (float) $offset <= 0.0) {
            return $entry;
        }

        $spec = app(SymbolResolver::class)->for($heartbeat->broker_account_id, $trade->symbol, $heartbeat);
        $pipSize = $spec['pip_size'];

        if ($pipSize === null || $pipSize <= 0.0) {
            // Refusing to place a level in a unit the account has not reported, the same
            // rule the sizing path follows.
            return $entry;
        }

        $padded = $entry + (($isBuy ? 1.0 : -1.0) * (float) $offset * $pipSize);

        // A padded stop has to stay behind the market on both readings that exist: the best
        // price the position ever saw, and the last price it is at now. Past either one it
        // is a stop on the wrong side of price, which the broker refuses outright or fills
        // as an immediate exit - turning a protective move into a close.
        //
        // Both are needed. The best price alone misses a position that has run far and
        // retraced; the last close alone misses one whose padding was never earned. The
        // trigger keeps this rare, but rare and closes-the-position is worth the check.
        $last = $this->series->closeFor($heartbeat->broker_account_id, (string) $trade->symbol);

        $limit = $last === null
            ? $best
            : ($isBuy ? min($best, $last) : max($best, $last));

        $beyondTheMarket = $isBuy ? $padded >= $limit : $padded <= $limit;

        return $beyondTheMarket ? $entry : $padded;
    }

    /**
     * Take a share of what remains off the table, once.
     */
    private function lockProfit(Trade $trade, BotHeartbeat $heartbeat, int $percent): bool
    {
        $spec = app(SymbolResolver::class)->for($heartbeat->broker_account_id, $trade->symbol, $heartbeat);

        $step = (float) ($spec['volume_step'] ?? 0.01);
        $min = (float) ($spec['volume_min'] ?? 0.01);
        $remaining = (float) $trade->remaining_lot_size;

        $volume = floor(($remaining * $percent / 100) / $step) * $step;

        // A remainder the broker cannot hold is not a partial close, it is a full exit -
        // and a full exit is emphatically not what "lock some profit" asked for.
        if ($volume < $min || ($remaining - $volume) < $min) {
            return false;
        }

        return $this->queue($trade, $heartbeat, 'close', [
            'ticket' => $trade->mt5_ticket,
            'volume' => round($volume, 2),
            'reason' => 'copier-profit-lock',
            // Once per position, whatever this is called. Without it every pass would take
            // another share until the position was gone.
        ], "profit-lock:{$trade->id}");
    }

    /**
     * Does this level move the stop toward the entry?
     *
     * The whole safety property in one function. A stop that would sit further away is
     * refused here exactly as it is in the follow-up executor.
     */
    private function improves(Trade $trade, float $target, bool $isBuy): bool
    {
        $current = $trade->sl_price === null ? null : (float) $trade->sl_price;

        if ($current === null) {
            return true;
        }

        return $isBuy ? $target > $current : $target < $current;
    }

    /**
     * The best price this position has seen since it opened.
     *
     * From closed bars rather than a live tick, because closed bars are what this system
     * has. The cost is honest: a spike that reversed inside its own bar is seen at the
     * bar's extreme, so the trail can be set from a price the position never realistically
     * had. It moves the stop closer than the market justified, which loses upside rather
     * than risking capital - the same direction of error the ladder already accepts.
     */
    private function bestPriceSince(Trade $trade, BotHeartbeat $heartbeat): ?float
    {
        if ($trade->opened_at === null) {
            return null;
        }

        $isBuy = strtolower((string) $trade->direction) === 'buy';

        // Scoped to the account holding the position. Unscoped, a second account's bars
        // for the same instrument could supply an extreme this position never saw - and
        // this reading is what decides whether the stop moves and to where.
        return $this->series->extremeSince(
            $heartbeat->broker_account_id,
            (string) $trade->symbol,
            $trade->opened_at,
            $isBuy,
        );
    }

    /**
     * Group a price into a band so an unchanged trail does not re-queue every minute.
     *
     * The idempotency key carries this rather than the raw price: a stop that has genuinely
     * moved gets a new key and is sent, and one that has drifted by a rounding error keeps
     * the old key and is not.
     */
    private function bucket(float $price): string
    {
        return (string) (int) round($price * 100);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function queue(Trade $trade, BotHeartbeat $heartbeat, string $type, array $payload, string $key): bool
    {
        $existing = TradeCommand::where('idempotency_key', $key)->exists();

        if ($existing) {
            return false;
        }

        TradeCommand::enqueue(
            user: User::find($trade->user_id),
            type: $type,
            account: $heartbeat->brokerAccount,
            payload: $payload + ['symbol' => $trade->symbol, 'origin' => AiFund::ORIGIN],
            idempotencyKey: $key,
            expiresInSeconds: self::EXPIRY_SECONDS,
        );

        return true;
    }
}

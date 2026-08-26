<?php

namespace App\Services\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Candle;
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
        $risk = abs((float) $trade->entry_price - (float) $trade->sl_price);
        $best = $this->bestPriceSince($trade);

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
            $target = (float) $trade->entry_price;
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
    private function bestPriceSince(Trade $trade): ?float
    {
        if ($trade->opened_at === null) {
            return null;
        }

        $isBuy = strtolower((string) $trade->direction) === 'buy';

        $value = Candle::where('symbol', $trade->symbol)
            ->where('open_time', '>=', $trade->opened_at)
            ->{$isBuy ? 'max' : 'min'}($isBuy ? 'high' : 'low');

        return $value === null ? null : (float) $value;
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

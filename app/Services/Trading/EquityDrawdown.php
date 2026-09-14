<?php

namespace App\Services\Trading;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;

/**
 * Equity Drawdown
 *
 * How far the account is below its own high-water mark, and whether that is far enough to
 * stop opening anything new.
 *
 * ## Why this is not the daily loss limit
 *
 * `max_daily_loss_percentage` asks "has today gone badly", resets at midnight, and counts
 * only realised losses. It is the right instrument for a bad afternoon and blind to a bad
 * month: an account can bleed a quarter of itself over three weeks without one day
 * breaching a 3% limit. This asks the other question - "how far below its best is this
 * account" - and has no reset at all.
 *
 * ## Equity, not balance
 *
 * A position still open is money still at risk, and the heartbeat reports equity for
 * exactly that reason. `SignalGenerator::dailyLossBreached()` deliberately excludes
 * floating loss, and that reasoning is sound *there*: a daily limit that trips on an open
 * position would halt the day over a trade that recovers within the hour.
 *
 * It is not the reasoning here, because this halt is cheaper to be wrong about. It stops
 * new entries and nothing else - it never closes a position, never cancels a protection,
 * and lifts itself the moment equity recovers. Declining to add risk while the account is
 * deep underwater is the correct behaviour even if the open position later comes back.
 *
 * ## The peak is stored, not derived
 *
 * `broker_accounts.peak_equity` is written by the heartbeat. Deriving it from
 * `bot_heartbeats` instead would be wrong after `data:prune`: a peak reconstructed from
 * surviving rows is lower than the real one, which makes every drawdown look smaller than
 * it is, which is the one direction a risk limit must never fail in.
 *
 * A deposit raises the peak and a withdrawal reads as a loss - there is no deal history
 * here to separate them. The risk page shows the peak, the date it was set, and a reset,
 * because a halt nobody can unstick is a halt somebody switches off for good.
 */
final class EquityDrawdown
{
    /**
     * `drawdown_limit` when the account has fallen too far below its peak, or null.
     *
     * Null when no limit is configured, when there is no peak to measure against, and when
     * the account is at or above it. The gates before this one in `firstObjection()` have
     * already established that a heartbeat with a balance exists.
     */
    public function objection(?BotSettings $settings, ?BotHeartbeat $heartbeat, ?BrokerAccount $account): ?string
    {
        $limit = $settings?->max_drawdown_percentage !== null
            ? (float) $settings->max_drawdown_percentage
            : 0.0;

        if ($limit <= 0.0) {
            return null;
        }

        $percent = $this->percent($heartbeat, $account);

        if ($percent === null) {
            return null;
        }

        return $percent >= $limit ? 'drawdown_limit' : null;
    }

    /**
     * How far below the peak the account is, as a percentage, or null when it cannot be said.
     *
     * Also what the risk page renders, so the number a user reads is the number the gate
     * judged rather than a second calculation that can disagree with it.
     */
    public function percent(?BotHeartbeat $heartbeat, ?BrokerAccount $account): ?float
    {
        $peak = $account?->peak_equity !== null ? (float) $account->peak_equity : null;
        $equity = $this->equity($heartbeat, $account);

        if ($peak === null || $equity === null || $peak <= 0.0) {
            return null;
        }

        if ($equity >= $peak) {
            return 0.0;
        }

        return (($peak - $equity) / $peak) * 100.0;
    }

    /**
     * What the account is worth now.
     *
     * Equity when the terminal reports it, and balance when it does not - an older EA, or a
     * heartbeat that arrived before the field existed. Falling back to balance understates
     * the drawdown of an account holding a losing position, which is worth saying out loud;
     * the alternative is a limit that silently stops working against an old executor.
     */
    public function equity(?BotHeartbeat $heartbeat, ?BrokerAccount $account): ?float
    {
        foreach ([$heartbeat?->equity, $heartbeat?->balance, $account?->last_equity, $account?->last_balance] as $value) {
            if ($value !== null) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * Record a new high-water mark, if this reading is one.
     *
     * Called from the heartbeat, which is the only place the account's own number arrives.
     * An account with no peak yet takes its first reading as the peak: starting at zero
     * would report a 100% drawdown, and starting at "no limit" would leave the halt
     * switched off for an account that never happens to make a new high.
     */
    public function observe(BrokerAccount $account, ?float $equity): void
    {
        if ($equity === null || $equity <= 0.0) {
            return;
        }

        $peak = $account->peak_equity !== null ? (float) $account->peak_equity : null;

        if ($peak !== null && $equity <= $peak) {
            return;
        }

        $account->forceFill([
            'peak_equity' => $equity,
            'peak_equity_at' => now(),
        ])->save();
    }
}

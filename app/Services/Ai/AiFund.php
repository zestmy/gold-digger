<?php

namespace App\Services\Ai;

use App\Models\BotSettings;
use App\Models\SymbolSpec;
use App\Models\Trade;

/**
 * AI Fund
 *
 * How much the AI is still allowed to lose, and whether it may open anything at all.
 *
 * ## A sub-account, not a permission
 *
 * AI-initiated trading cannot be backtested; there are no historical model opinions to
 * replay. So the guarantee this system normally offers - that a setting can be measured
 * before it costs anything - is not available here, and a bounded loss replaces it.
 *
 * The cap behaves like capital allocated to a desk. Positions are sized off what remains
 * of the fund rather than off the account balance, realised losses deplete it, and when it
 * reaches zero the desk stops. Nothing about that touches the rest of the account, which
 * is the whole reason for expressing this as money rather than as a switch.
 *
 * ## Every existing gate still applies
 *
 * This is an additional constraint, never a replacement for one. The kill switch, the
 * session window, the news blackout, the daily loss limit and `max_concurrent_trades` all
 * bind the AI exactly as they bind the strategy. A fund with money left in it is
 * permission to consider a trade, not permission to take one.
 *
 * ## What is left is what is not already at risk
 *
 * Realised losses are the obvious depletion, and for a long time they were the only one.
 * That left a gap: three positions opened within a minute of each other were each sized
 * at five percent of the *same* remaining figure, because none of them had closed yet
 * and closing was the only thing that changed it. Stop all three out and the fund has
 * lost what it was told it could lose three times over - a cap that did not cap.
 *
 * So an open position commits its risk to the fund the moment it exists: the distance
 * from its entry to the stop it opened with, in money, comes off `remaining` before the
 * next position is sized. Floating profit and loss still do not count - a position that
 * is up or down is a position that has not yet resolved - but the amount it *could* lose
 * is already spoken for, and pretending otherwise is how a bound becomes advisory.
 *
 * Where that amount cannot be measured - no instrument specification, no recorded opening
 * stop - the position is charged one full stake rather than nothing. Unknown is not zero,
 * and the direction to be wrong in is the one that sizes the next trade smaller.
 */
final class AiFund
{
    public const ORIGIN = 'ai';

    /**
     * @return array{
     *     enabled: bool,
     *     configured: bool,
     *     cap: float,
     *     realised: float,
     *     committed: float,
     *     remaining: float,
     *     open_trades: int,
     *     max_concurrent: int,
     *     risk_percentage: float,
     *     risk_per_trade: float,
     *     exhausted: bool,
     *     blocked_reason: string|null,
     * }
     */
    public function state(?BotSettings $settings, int $userId): array
    {
        $cap = $settings?->ai_capital_cap === null ? 0.0 : (float) $settings->ai_capital_cap;
        $configured = $settings?->ai_capital_cap !== null && $cap > 0.0;
        $enabled = (bool) ($settings?->ai_trading_enabled ?? false);

        // Realised only. Floating loss on an open position is not spent money, and a fund
        // that halted on unrealised drawdown would stop itself over a position that
        // recovers within the hour - the same reasoning the daily loss limit uses.
        $realised = (float) Trade::where('user_id', $userId)
            ->where('origin', self::ORIGIN)
            ->whereIn('status', ['closed', 'stopped_out'])
            ->sum('net_pnl_money');

        // What the fund would hold if nothing were open: the figure a first position is
        // sized from, and the stake an unmeasurable open position is charged.
        $unspent = max(0.0, $cap + $realised);

        $maxConcurrent = (int) ($settings?->ai_max_concurrent_trades ?? 1);
        $riskPct = (float) ($settings?->ai_risk_percentage ?? 0.0);
        $stake = $unspent * $riskPct / 100;

        $open = Trade::where('user_id', $userId)
            ->where('origin', self::ORIGIN)
            ->whereIn('status', ['open', 'partially_closed'])
            ->get();

        $committed = (float) $open->sum(fn (Trade $trade) => $this->committedRisk($trade) ?? $stake);

        $remaining = max(0.0, $unspent - $committed);

        return [
            'enabled' => $enabled,
            'configured' => $configured,
            'cap' => $cap,
            'realised' => round($realised, 2),
            'committed' => round($committed, 2),
            'remaining' => round($remaining, 2),
            'open_trades' => $open->count(),
            'max_concurrent' => $maxConcurrent,
            'risk_percentage' => $riskPct,
            // Falls with the fund. A losing run shrinks its own stake rather than betting
            // the same amount into a smaller pot, which is how a capped fund reaches zero
            // slowly instead of in three trades.
            'risk_per_trade' => round($remaining * $riskPct / 100, 2),
            'exhausted' => $configured && $unspent <= 0.0,
            'blocked_reason' => $this->blockedReason($enabled, $configured, $unspent, $remaining, $open->count(), $maxConcurrent),
        ];
    }

    /**
     * What one open position stands to lose at the stop it opened with, in money.
     *
     * Measured from `initial_sl_price` rather than the live stop on purpose. The
     * protection may since have moved the stop to break-even, which makes the position
     * cheaper to hold but does not un-commit what the fund agreed to risk when it was
     * opened; and the reconciler writes the live stop from the broker, where a zero means
     * "none" and would read as the whole entry price being at risk.
     *
     * Null when it cannot be measured honestly - the caller decides what unknown costs.
     */
    private function committedRisk(Trade $trade): ?float
    {
        if ($trade->initial_sl_price === null || $trade->entry_price === null) {
            return null;
        }

        $spec = SymbolSpec::resolve($trade->broker_account_id, (string) $trade->symbol);

        if ($spec === null || ! $spec->isComplete()) {
            return null;
        }

        $distance = abs((float) $trade->entry_price - (float) $trade->initial_sl_price);

        if ($distance <= 0.0) {
            return null;
        }

        return $distance / (float) $spec->pip_size
            * (float) $trade->remaining_lot_size
            * (float) $spec->pip_value_per_lot;
    }

    /**
     * May the AI open a position right now?
     */
    public function canOpen(?BotSettings $settings, int $userId): bool
    {
        return $this->state($settings, $userId)['blocked_reason'] === null;
    }

    private function blockedReason(
        bool $enabled,
        bool $configured,
        float $unspent,
        float $remaining,
        int $openTrades,
        int $maxConcurrent,
    ): ?string {
        if (! $enabled) {
            return 'ai_trading_disabled';
        }

        // Absent is not zero. A cap that was never set means nobody has decided how much
        // this may lose, and defaulting that decision would be this system choosing for
        // them.
        if (! $configured) {
            return 'ai_fund_not_configured';
        }

        if ($unspent <= 0.0) {
            return 'ai_fund_exhausted';
        }

        if ($openTrades >= $maxConcurrent) {
            return 'ai_max_concurrent_reached';
        }

        // Distinct from exhausted: nothing has been lost, but everything that is left is
        // already riding on positions that have not resolved. The remedy is to wait, not
        // to raise the cap, and the message should send somebody to the right one.
        if ($remaining <= 0.0) {
            return 'ai_fund_committed';
        }

        return null;
    }

    /**
     * Human-readable, for the settings page and the skip reasons.
     */
    public function explain(string $reason): string
    {
        return match ($reason) {
            'ai_trading_disabled' => 'AI trading is switched off.',
            'ai_fund_not_configured' => 'No fund cap is set, so nothing has decided how much the AI may risk.',
            'ai_fund_exhausted' => 'The AI fund is spent. It will not open another position until the cap is raised.',
            'ai_fund_committed' => 'Everything left in the AI fund is already at risk on open positions. Nothing more opens until one of them resolves.',
            'ai_max_concurrent_reached' => 'The AI already holds its maximum number of open positions.',
            default => $reason,
        };
    }
}

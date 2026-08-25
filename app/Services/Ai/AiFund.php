<?php

namespace App\Services\Ai;

use App\Models\BotSettings;
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

        $remaining = max(0.0, $cap + $realised);

        $openTrades = Trade::where('user_id', $userId)
            ->where('origin', self::ORIGIN)
            ->whereIn('status', ['open', 'partially_closed'])
            ->count();

        $maxConcurrent = (int) ($settings?->ai_max_concurrent_trades ?? 1);
        $riskPct = (float) ($settings?->ai_risk_percentage ?? 0.0);

        return [
            'enabled' => $enabled,
            'configured' => $configured,
            'cap' => $cap,
            'realised' => round($realised, 2),
            'remaining' => round($remaining, 2),
            'open_trades' => $openTrades,
            'max_concurrent' => $maxConcurrent,
            'risk_percentage' => $riskPct,
            // Falls with the fund. A losing run shrinks its own stake rather than betting
            // the same amount into a smaller pot, which is how a capped fund reaches zero
            // slowly instead of in three trades.
            'risk_per_trade' => round($remaining * $riskPct / 100, 2),
            'exhausted' => $configured && $remaining <= 0.0,
            'blocked_reason' => $this->blockedReason($enabled, $configured, $remaining, $openTrades, $maxConcurrent),
        ];
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

        if ($remaining <= 0.0) {
            return 'ai_fund_exhausted';
        }

        if ($openTrades >= $maxConcurrent) {
            return 'ai_max_concurrent_reached';
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
            'ai_max_concurrent_reached' => 'The AI already holds its maximum number of open positions.',
            default => $reason,
        };
    }
}

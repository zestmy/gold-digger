<?php

namespace App\Services\Monitoring;

use App\Models\Alert;
use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradePartial;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Health Monitor
 *
 * Decides what is wrong right now, and keeps the record of it in `alerts`.
 *
 * Everything here is a condition that (a) means the bot is not doing what its owner thinks it
 * is doing, and (b) can be told apart from its own absence. That second half is what makes an
 * alert usable: each condition below has an explicit resolve rule, because an alert that never
 * clears trains you to ignore the channel it arrives on.
 *
 * ## When silence is correct
 *
 * A bot deliberately switched off is not a fault. Nothing here fires for a stopped bot unless
 * it is holding open positions - a dead executor with positions on the book is the risk worth
 * waking up for, and it is exactly the case a naive "is it running" check misses, because the
 * owner turned it off on purpose and forgot what it was still carrying.
 */
final class HealthMonitor
{
    /**
     * How far past a bar's own length the feed may drift before it counts as stalled.
     *
     * Three bars: one for the bar in progress, one for the push that carries it, and one for
     * a poll that was slow. Below that, a normal weekend gap or a quiet symbol would fire.
     */
    private const FEED_STALE_BARS = 3;

    /**
     * Evaluate every user and return the alerts that changed state.
     *
     * @return array{opened: array<int, Alert>, resolved: array<int, Alert>}
     */
    public function sweep(): array
    {
        $opened = [];
        $resolved = [];

        foreach (User::query()->cursor() as $user) {
            $conditions = $this->conditionsFor($user);

            foreach ($conditions as $condition) {
                $alert = $this->open($user, $condition);

                if ($alert->wasRecentlyCreated) {
                    $opened[] = $alert;
                }
            }

            $resolved = array_merge($resolved, $this->resolveAbsent($user, array_column($conditions, 'key')));
        }

        return ['opened' => $opened, 'resolved' => $resolved];
    }

    /**
     * Everything wrong for one user, as an unordered list of condition descriptions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conditionsFor(User $user): array
    {
        $settings = BotSettings::where('user_id', $user->id)->first();

        if ($settings === null) {
            return [];
        }

        $openPositions = Trade::where('user_id', $user->id)
            ->whereIn('status', ['open', 'partially_closed'])
            ->count();

        // Nothing is expected to be running, and nothing is at risk. Silence is the correct
        // output - the alternative is a permanent alert on every account not in use.
        if (! $settings->is_active && $openPositions === 0) {
            return [];
        }

        $heartbeat = BotHeartbeat::where('user_id', $user->id)
            ->orderByDesc('last_seen_at')
            ->first();

        $conditions = [];

        foreach ([
            $this->executorOffline($heartbeat, $openPositions),
            $this->algoTradingBlocked($heartbeat, $settings),
            $this->feedStalled($user, $heartbeat, $settings),
            $this->dailyLossLimit($user, $settings, $heartbeat),
        ] as $condition) {
            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    // =========================================================================
    // CONDITIONS
    // =========================================================================

    /**
     * The executor has stopped reporting.
     *
     * Critical when positions are open, because nothing is managing them - no ladder, no
     * reversal exit, and a queued close that will not be claimed. A warning otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function executorOffline(?BotHeartbeat $heartbeat, int $openPositions): ?array
    {
        if ($heartbeat === null) {
            return [
                'key' => 'executor_missing',
                'level' => $openPositions > 0 ? 'critical' : 'warning',
                'title' => 'No executor has ever reported in',
                'body' => 'The bot is switched on but nothing has ever sent a heartbeat. '
                    .'Check the Expert Advisor is attached to a chart and that its dashboard URL is whitelisted.',
                'context' => ['open_positions' => $openPositions],
            ];
        }

        if ($heartbeat->isOnline()) {
            return null;
        }

        $silentFor = $heartbeat->last_seen_at?->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE) ?? 'an unknown time';

        return [
            'key' => 'executor_offline',
            'level' => $openPositions > 0 ? 'critical' : 'warning',
            'title' => $openPositions > 0
                ? "Executor offline with {$openPositions} position(s) open"
                : 'Executor offline',
            'body' => "Nothing has reported for {$silentFor}. "
                .($openPositions > 0
                    ? 'Open positions are not being managed: no take-profit ladder, no reversal exit, and any queued close will not be claimed.'
                    : 'No new entries will be taken until it returns.'),
            'context' => [
                'last_seen_at' => $heartbeat->last_seen_at?->toIso8601String(),
                'open_positions' => $openPositions,
            ],
        ];
    }

    /**
     * The terminal is reporting but cannot trade.
     *
     * The failure the whole BotStatusCard design exists for: a healthy-looking heartbeat
     * whose orders would all come back 10027.
     *
     * @return array<string, mixed>|null
     */
    private function algoTradingBlocked(?BotHeartbeat $heartbeat, BotSettings $settings): ?array
    {
        if ($heartbeat === null || ! $heartbeat->isOnline() || ! $settings->is_active) {
            return null;
        }

        if (! $heartbeat->broker_connected) {
            return [
                'key' => 'broker_disconnected',
                'level' => 'critical',
                'title' => 'The terminal has lost its broker connection',
                'body' => 'The Expert Advisor is running but the terminal is not connected to the broker. '
                    .'Nothing can be executed until it reconnects.',
                'context' => ['resolved_symbol' => $heartbeat->resolved_symbol],
            ];
        }

        if (! $heartbeat->algo_trading_enabled) {
            return [
                'key' => 'algo_trading_disabled',
                'level' => 'critical',
                'title' => 'Algo Trading is switched off in the terminal',
                'body' => 'The executor is reporting normally, so nothing looks wrong - but every order '
                    .'would be refused with 10027. Both switches must be on: the toolbar button and the '
                    ."EA's own Allow Algo Trading checkbox.",
                'context' => ['resolved_symbol' => $heartbeat->resolved_symbol],
            ];
        }

        return null;
    }

    /**
     * Bars have stopped arriving.
     *
     * Worth its own alert rather than being folded into "offline", because the executor can be
     * heartbeating perfectly while the push fails - a whitelist that covers the heartbeat URL
     * but not this one, or a symbol whose history will not load. From the dashboard the two
     * look identical: no signals, no explanation.
     *
     * @return array<string, mixed>|null
     */
    private function feedStalled(User $user, ?BotHeartbeat $heartbeat, BotSettings $settings): ?array
    {
        if ($heartbeat === null || ! $heartbeat->isOnline() || ! $settings->is_active) {
            return null;
        }

        $strategy = Strategy::where('user_id', $user->id)->where('is_active', true)->first();

        if ($strategy === null || $heartbeat->broker_account_id === null) {
            return null;
        }

        $timeframe = strtoupper($strategy->timeframe_entry);
        $seconds = $this->timeframeSeconds($timeframe);

        $newest = Candle::query()
            ->where('broker_account_id', $heartbeat->broker_account_id)
            ->where('timeframe', $timeframe)
            ->max('open_time');

        // No bars at all is a different problem from bars that stopped, and the executor
        // may simply not have pushed its first window yet.
        if ($newest === null) {
            return null;
        }

        $newest = Carbon::parse($newest);
        $deadline = $newest->copy()->addSeconds($seconds * self::FEED_STALE_BARS);

        if ($deadline->isFuture()) {
            return null;
        }

        return [
            'key' => 'feed_stalled:'.$timeframe,
            'level' => 'warning',
            'title' => "No {$timeframe} bars for ".$newest->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE),
            'body' => 'The executor is reporting but has stopped pushing price bars, so no signal can be '
                .'generated. Check that the /candles URL is whitelisted for WebRequest and that Push Candles '
                .'is enabled. Markets being closed will also do this.',
            'context' => [
                'timeframe' => $timeframe,
                'newest_bar' => $newest->toIso8601String(),
            ],
        ];
    }

    /**
     * Today's realised losses have reached the configured limit.
     *
     * Not a fault - the limit working is the system doing its job - but the owner should know
     * their bot has stopped taking entries for the rest of the day rather than discovering it
     * from an empty signal list.
     *
     * @return array<string, mixed>|null
     */
    private function dailyLossLimit(User $user, BotSettings $settings, ?BotHeartbeat $heartbeat): ?array
    {
        $limitPct = (float) $settings->max_daily_loss_percentage;

        if ($limitPct <= 0.0 || $heartbeat?->balance === null) {
            return null;
        }

        $realisedToday = (float) TradePartial::query()
            ->whereBetween('closed_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereIn('trade_id', Trade::where('user_id', $user->id)->select('id'))
            ->sum('net_money_profit');

        if ($realisedToday >= 0.0) {
            return null;
        }

        $opening = (float) $heartbeat->balance - $realisedToday;

        if ($opening <= 0.0 || abs($realisedToday) < ($opening * ($limitPct / 100.0))) {
            return null;
        }

        return [
            'key' => 'daily_loss_limit',
            'level' => 'critical',
            'title' => 'Daily loss limit reached',
            'body' => sprintf(
                'Realised losses today are %s against a limit of %s%% of the opening balance. '
                .'No new entries will be taken until tomorrow; open positions are unaffected.',
                number_format($realisedToday, 2),
                number_format($limitPct, 2),
            ),
            'context' => [
                'realised_today' => round($realisedToday, 2),
                'opening_balance' => round($opening, 2),
                'limit_percentage' => $limitPct,
            ],
        ];
    }

    // =========================================================================
    // PERSISTENCE
    // =========================================================================

    /**
     * Start or refresh the open incident for a condition.
     *
     * @param  array<string, mixed>  $condition
     */
    private function open(User $user, array $condition): Alert
    {
        $existing = Alert::where('user_id', $user->id)
            ->where('key', $condition['key'])
            ->firing()
            ->first();

        if ($existing !== null) {
            // Same incident, still true. The body is refreshed because it carries live
            // readings - how long it has been silent, how much has been lost today.
            $existing->update([
                'level' => $condition['level'],
                'title' => $condition['title'],
                'body' => $condition['body'],
                'context' => $condition['context'] ?? null,
                'last_seen_at' => now(),
            ]);

            return $existing;
        }

        return Alert::create([
            'user_id' => $user->id,
            'key' => $condition['key'],
            'level' => $condition['level'],
            'title' => $condition['title'],
            'body' => $condition['body'],
            'context' => $condition['context'] ?? null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Resolve every open incident whose condition is no longer true.
     *
     * @param  array<int, string>  $stillFiring
     * @return array<int, Alert>
     */
    private function resolveAbsent(User $user, array $stillFiring): array
    {
        $stale = Alert::where('user_id', $user->id)
            ->firing()
            ->when($stillFiring !== [], fn ($q) => $q->whereNotIn('key', $stillFiring))
            ->get();

        foreach ($stale as $alert) {
            $alert->update(['resolved_at' => now()]);
        }

        return $stale->all();
    }

    private function timeframeSeconds(string $timeframe): int
    {
        $unit = substr($timeframe, 0, 1);
        $count = (int) substr($timeframe, 1);

        if ($count < 1) {
            return 300;
        }

        return match ($unit) {
            'M' => $count * 60,
            'H' => $count * 3600,
            'D' => $count * 86400,
            default => 300,
        };
    }
}

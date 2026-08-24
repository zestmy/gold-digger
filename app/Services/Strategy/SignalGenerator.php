<?php

namespace App\Services\Strategy;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\TradePartial;
use App\Services\News\NewsBlackout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Signal Generator
 *
 * The piece the handoff named as missing: "nothing enqueues `open` commands except by
 * hand". This is what enqueues them.
 *
 * Evaluate the strategy's rules against the stored series, apply the risk and session
 * filters, record the signal either way, and - only if nothing objected - size the
 * position and put an `open` command on the queue for the EA to claim.
 *
 * ## Every setup is recorded, including rejected ones
 *
 * A setup that a filter rejects is still written to `signals`, with `skip_reason`. That is
 * what the table was built for: "if we had taken that signal, what would have happened?"
 * cannot be asked of signals that were never written down. Only bars where the rules did
 * not fire at all produce no row - otherwise the table would gain one row per bar per
 * strategy forever, and the interesting rows would drown.
 *
 * ## was_executed means a position exists
 *
 * Enqueuing is not executing. A command can expire, or the broker can reject it. So an
 * accepted signal is written with `was_executed = false` and `skip_reason = null`, and it
 * is FillController - on the EA's report that a position actually opened - that flips
 * `was_executed` and links `resulting_trade_id`. A signal with no skip reason and no trade
 * is one still in flight, which is a state worth being able to see.
 *
 * ## Stops travel as pips, not prices
 *
 * The command carries `sl_pips` and `tp_pips` and leaves the absolute price columns empty,
 * so the EA places the stop relative to the tick it actually fills at. Sending the price
 * computed here would anchor the stop to a bar close that is already seconds stale, making
 * the real risk differ from the intended risk by the gap. The price levels are still
 * stored on the signal, because that is what the analytics pages chart.
 */
final class SignalGenerator
{
    public function __construct(
        private readonly StrategyEvaluator $evaluator = new StrategyEvaluator,
        private readonly PositionSizer $sizer = new PositionSizer,
        private readonly TradingSession $sessions = new TradingSession,
        private readonly SymbolResolver $symbols = new SymbolResolver,
        private readonly NewsBlackout $news = new NewsBlackout,
    ) {}

    /**
     * Evaluate one strategy and act on the result.
     *
     * Returns the signal that was recorded, or null when the rules did not fire, when the
     * series is too short, or when this bar has already been evaluated.
     */
    public function generate(Strategy $strategy, ?int $brokerAccountId = null): ?Signal
    {
        if (! $strategy->is_active) {
            return null;
        }

        $heartbeat = $this->heartbeat($strategy->user_id, $brokerAccountId);

        $accountId = $brokerAccountId ?? $heartbeat?->broker_account_id;

        // The strategy names an instrument in the abstract - XAUUSD - and the resolver says
        // what this broker publishes it as, along with its pip size and pip value. Reading
        // those off the heartbeat, as this used to, is what limited the system to one symbol:
        // the heartbeat has room for exactly one instrument's numbers.
        $spec = $this->symbols->for($accountId, $strategy->symbol, $heartbeat);
        $symbol = $spec['symbol'];

        $setup = $this->evaluator->evaluate(
            $strategy,
            Candle::recentSeries($accountId, $symbol, $strategy->timeframe_entry, StrategyEvaluator::LOOKBACK_BARS),
            Candle::recentSeries($accountId, $symbol, $strategy->timeframe_trend, StrategyEvaluator::LOOKBACK_BARS),
        );

        if ($setup === null) {
            return null;
        }

        // One signal per strategy per bar. Re-pushing a trailing window of candles
        // re-evaluates the same closed bar, and without this a self-healing push would
        // open a position per poll.
        $existing = Signal::where('strategy_id', $strategy->id)
            ->where('generated_at', $setup->barTime)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $settings = BotSettings::where('user_id', $strategy->user_id)->first();
        $levels = $this->levels($strategy, $setup, $spec['pip_size']);

        $skipReason = $this->firstObjection($strategy, $setup, $settings, $heartbeat, $levels);

        $lots = null;

        if ($skipReason === null) {
            $lots = $this->sizer->size(
                balance: (float) $heartbeat->balance,
                riskPercentage: (float) $settings->risk_percentage,
                stopPips: $levels['sl_pips'],
                pipValuePerLot: $spec['pip_value_per_lot'],
            );

            if ($lots === null) {
                $skipReason = 'lot_size_unavailable';
            }
        }

        return DB::transaction(function () use ($strategy, $setup, $levels, $skipReason, $lots, $symbol, $accountId, $heartbeat) {
            // createOrFirst, not create: the check above avoids the write on the ordinary
            // path, but two overlapping candle pushes can both pass it. The unique index
            // on (strategy_id, generated_at) turns the loser's insert into a lookup
            // instead of a second signal - and a second signal would mean a second
            // idempotency key, a second command, and a duplicate position.
            $signal = Signal::createOrFirst(
                [
                    'strategy_id' => $strategy->id,
                    'generated_at' => $setup->barTime,
                ],
                [
                    'symbol' => $symbol,
                    'timeframe' => $strategy->timeframe_entry,
                    'direction' => $setup->direction,
                    'entry_price' => $setup->entryPrice,
                    'sl_price' => $levels['sl_price'],
                    'tp1_price' => $levels['tp1_price'],
                    'tp2_price' => $levels['tp2_price'],
                    'tp3_price' => $levels['tp3_price'],
                    'suggested_lot_size' => $lots,
                    'confidence_score' => null,
                    'features' => $setup->features + [
                        'sl_pips' => $levels['sl_pips'],
                        'order_tp_pips' => $levels['order_tp_pips'],
                        'sessions_open' => $this->sessions->active($setup->barTime),
                        'balance' => $heartbeat?->balance !== null ? (float) $heartbeat->balance : null,
                        'pip_size' => $levels['pip_size'],
                    ],
                    'was_executed' => false,
                    'skip_reason' => $skipReason,
                ],
            );

            // Lost the race - the winner has already queued whatever this bar deserved.
            if (! $signal->wasRecentlyCreated) {
                return $signal;
            }

            if ($skipReason === null) {
                $this->enqueueOpen($strategy, $signal, $setup, $levels, $lots, $symbol, $accountId);
            }

            return $signal;
        });
    }

    /**
     * Evaluate every active strategy for every user that has one.
     *
     * @return array<int, Signal>
     */
    public function generateAll(?int $brokerAccountId = null): array
    {
        $signals = [];

        foreach (Strategy::where('is_active', true)->get() as $strategy) {
            $signal = $this->generate($strategy, $brokerAccountId);

            if ($signal !== null) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    // =========================================================================
    // LEVELS
    // =========================================================================

    /**
     * Stop and target levels for a setup.
     *
     * The stop is ATR-derived (a volatility-aware distance); the targets are the fixed pip
     * distances the strategy configures. `pip_size` is null until the terminal reports it,
     * and every derived value goes null with it rather than being computed from a guess.
     *
     * @return array{pip_size: float|null, sl_pips: float, sl_price: float, tp1_price: float|null, tp2_price: float|null, tp3_price: float|null, order_tp_pips: float|null}
     */
    private function levels(Strategy $strategy, Setup $setup, ?float $pipSize): array
    {
        $stopDistance = (float) $strategy->sl_atr_multiplier * $setup->atr;
        $sign = $setup->sign();

        // The order the EA actually submits carries the *final* target, not the first.
        // Partial closes at TP1 and TP2 need a trade-management loop that watches price,
        // and none exists yet - so putting TP1 on the order would close the whole position
        // at a level meant to take only half of it, and tp2/tp3 would never be reached.
        $finalTargetPips = $strategy->tp3_pips !== null
            ? (float) $strategy->tp3_pips
            : (float) $strategy->tp2_pips;

        // The stop survives an unknown pip size; the targets do not. ATR is already in
        // price units, so 1.5 ATR below entry is computable from the series alone. The
        // targets are configured in pips, and turning pips into a price without the
        // terminal's pip size is precisely the guess the pip trap punishes.
        $slPrice = round($setup->entryPrice - ($sign * $stopDistance), 5);

        if ($pipSize === null || $pipSize <= 0.0) {
            return [
                'pip_size' => null,
                'sl_pips' => 0.0,
                'sl_price' => $slPrice,
                'tp1_price' => null,
                'tp2_price' => null,
                'tp3_price' => null,
                'order_tp_pips' => null,
            ];
        }

        $target = static fn (?float $pips): ?float => $pips === null
            ? null
            : round($setup->entryPrice + ($sign * $pips * $pipSize), 5);

        return [
            'pip_size' => $pipSize,
            'sl_pips' => round($stopDistance / $pipSize, 2),
            'sl_price' => $slPrice,
            'tp1_price' => $target((float) $strategy->tp1_pips),
            'tp2_price' => $target((float) $strategy->tp2_pips),
            'tp3_price' => $target($strategy->tp3_pips !== null ? (float) $strategy->tp3_pips : null),
            'order_tp_pips' => $finalTargetPips,
        ];
    }

    // =========================================================================
    // FILTERS
    // =========================================================================

    /**
     * The first reason this setup must not be traded, or null if none applies.
     *
     * Ordered cheapest and most decisive first. Only one reason is recorded even when
     * several apply: `skip_reason` answers "what stopped this", and the first gate a
     * signal fails is the one that would have to change for it to trade.
     *
     * @param  array{pip_size: float|null, sl_pips: float, ...}  $levels
     */
    private function firstObjection(
        Strategy $strategy,
        Setup $setup,
        ?BotSettings $settings,
        ?BotHeartbeat $heartbeat,
        array $levels,
    ): ?string {
        if ($settings === null) {
            return 'no_bot_settings';
        }

        if (! $settings->is_active) {
            return 'bot_inactive';
        }

        // A terminal with Algo Trading switched off keeps heartbeating and keeps pushing
        // candles, so setups keep appearing - but every order it was sent would be
        // refused with retcode 10027. Queueing commands into that would fill the queue
        // with entries that expire unfilled and bury the actual cause. Recording the
        // reason instead is what turns "the bot just never trades" into a visible fault.
        if ($heartbeat !== null && ! $heartbeat->algo_trading_enabled) {
            return 'algo_trading_disabled';
        }

        if (! $this->sessions->isOpen($settings->allowed_sessions, $setup->barTime)) {
            return 'session_closed';
        }

        // Beside the session gate rather than further down, because both answer "not
        // allowed to trade at this moment" - which is more decisive than anything about
        // the quality of the setup. A signal blocked by NFP and also short of ADX should
        // report the release: lowering adx_threshold would not have got it traded.
        //
        // The strategy's configured symbol, not the resolved one: currenciesFor() reads
        // the pair off the name and a broker suffix would corrupt it.
        $newsObjection = $this->news->objection(
            $settings,
            $this->news->currenciesFor((string) $strategy->symbol),
            Carbon::parse($setup->barTime),
        );

        if ($newsObjection !== null) {
            return $newsObjection;
        }

        if ($setup->adx < (float) $strategy->adx_threshold) {
            return 'adx_below_threshold';
        }

        if ($settings->min_atr_threshold !== null && $setup->atr < (float) $settings->min_atr_threshold) {
            return 'atr_below_threshold';
        }

        // Without the terminal's pip size there is no stop distance, and without a stop
        // distance there is no position size. Guessing either is how the pip trap bites.
        if ($levels['pip_size'] === null || $levels['sl_pips'] <= 0.0) {
            return 'no_symbol_spec';
        }

        if ($heartbeat === null || $heartbeat->balance === null) {
            return 'no_account_snapshot';
        }

        $openTrades = Trade::where('user_id', $strategy->user_id)
            ->whereIn('status', ['open', 'partially_closed'])
            ->count();

        if ($openTrades >= (int) $settings->max_concurrent_trades) {
            return 'max_trades_reached';
        }

        if ($this->dailyLossBreached($strategy->user_id, $settings, (float) $heartbeat->balance)) {
            return 'daily_loss_limit';
        }

        return null;
    }

    /**
     * Has today's realised loss reached the configured share of the day's opening balance?
     *
     * The opening balance is reconstructed as "balance now, minus what today's closes did
     * to it". `broker_accounts.last_balance` is never written by anything yet, and storing
     * a daily opening snapshot is a reconciliation job that does not exist - reconstructing
     * it from realised P&L uses only numbers the EA already reports.
     *
     * Open positions are deliberately excluded: floating loss is not realised loss, and a
     * limit that trips on unrealised drawdown would halt trading over a position that
     * recovers within the hour.
     */
    private function dailyLossBreached(int $userId, BotSettings $settings, float $balance): bool
    {
        $limitPct = (float) $settings->max_daily_loss_percentage;

        if ($limitPct <= 0.0) {
            return false;
        }

        $realisedToday = (float) TradePartial::query()
            ->whereBetween('closed_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereIn('trade_id', Trade::where('user_id', $userId)->select('id'))
            ->sum('net_money_profit');

        if ($realisedToday >= 0.0) {
            return false;
        }

        $openingBalance = $balance - $realisedToday;

        if ($openingBalance <= 0.0) {
            // No sane denominator. An account at or below zero should not be opening
            // positions regardless of what the percentage would work out to.
            return true;
        }

        return abs($realisedToday) >= ($openingBalance * ($limitPct / 100.0));
    }

    // =========================================================================
    // EXECUTION
    // =========================================================================

    /**
     * Put the `open` command on the queue.
     *
     * `sl_price` and `tp_price` are deliberately absent from the payload: TradeCommand's
     * wire format reads exactly those keys, and supplying them would override the EA's
     * tick-relative placement with a stale bar-close level. The intended stop is carried
     * under a different key so FillController can still see what was asked for.
     */
    private function enqueueOpen(
        Strategy $strategy,
        Signal $signal,
        Setup $setup,
        array $levels,
        float $lots,
        string $symbol,
        ?int $accountId,
    ): void {
        TradeCommand::enqueue(
            user: $strategy->user,
            type: 'open',
            payload: [
                'symbol' => $symbol,
                'direction' => $setup->direction,
                'volume' => $lots,
                'sl_pips' => $levels['sl_pips'],
                'tp_pips' => $levels['order_tp_pips'],

                // Read by FillController when it writes the trade row.
                'strategy_id' => $strategy->id,
                'signal_id' => $signal->id,
                'intended_sl_price' => $levels['sl_price'],
                'tp1_price' => $levels['tp1_price'],
                'tp2_price' => $levels['tp2_price'],
                'tp3_price' => $levels['tp3_price'],

                // MT5 truncates order comments hard, so this is an identifier, not prose.
                'comment' => "GD-S{$signal->id}",
            ],
            account: $accountId !== null ? $strategy->user->brokerAccounts()->find($accountId) : null,
            // One signal can only ever produce one position, however often the bar that
            // produced it is re-evaluated.
            idempotencyKey: "signal:{$signal->id}",
            // A market entry is only valid for the bar that justified it. Past that the
            // price has moved on and the setup is no longer the one that was approved.
            expiresInSeconds: $this->timeframeSeconds($strategy->timeframe_entry),
        );
    }

    /**
     * Bar length in seconds, used as the command's time-to-live.
     *
     * Unknown timeframes fall back to five minutes rather than to "never expires": an
     * entry command with no expiry is the one that fills an hour late.
     */
    private function timeframeSeconds(string $timeframe): int
    {
        $timeframe = strtoupper($timeframe);
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

    private function heartbeat(int $userId, ?int $brokerAccountId): ?BotHeartbeat
    {
        return BotHeartbeat::query()
            ->where('user_id', $userId)
            ->when($brokerAccountId !== null, fn ($q) => $q->where('broker_account_id', $brokerAccountId))
            ->orderByDesc('last_seen_at')
            ->first();
    }
}

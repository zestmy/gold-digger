<?php

namespace App\Services\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\TelegramSignal;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Ai\AiFund;
use App\Services\Strategy\SymbolResolver;

/**
 * Signal Executor
 *
 * Turns an approved signal into a queued order, or explains why it did not.
 *
 * ## The gates are checked again, here
 *
 * `SignalReviewer` already checked them. That was minutes ago, and minutes are enough for
 * the session to close, a release to come into range, the fund to be spent by another
 * position, or the executor to go offline. An approval is a statement about the moment it
 * was made, not a licence that stays valid.
 *
 * Re-checking looks redundant and is not. The alternative is a queue of approvals that
 * execute on conditions nobody has verified since - which is how a system ends up entering
 * during the news blackout it correctly identified twenty minutes earlier.
 *
 * ## Sizing comes from the fund, never the balance
 *
 * The risk on an AI position is a percentage of what remains of `ai_capital_cap`, not of
 * the account. That is the whole meaning of the cap: the account balance is not this
 * feature's to spend, and a sizing bug that read the balance instead would quietly remove
 * the only bound on something that cannot be backtested.
 *
 * ## Nothing is executed twice
 *
 * The idempotency key is the signal's own id, so a retry, a double poll, or two workers
 * racing produce one command and one position.
 */
final class SignalExecutor
{
    public function __construct(
        private readonly AiFund $fund = new AiFund,
        private readonly SignalReviewer $reviewer = new SignalReviewer,
    ) {}

    /**
     * @return array{ok: bool, status: string, note: string}
     */
    public function execute(TelegramSignal $signal): array
    {
        if (! $signal->isActionable()) {
            return $this->blocked($signal, 'Not approved, or already acted on.');
        }

        $user = User::find($signal->user_id);
        $settings = BotSettings::where('user_id', $signal->user_id)->first();

        if ($user === null || $settings === null) {
            return $this->blocked($signal, 'No account settings for this signal.');
        }

        // Everything the reviewer checked, checked again against now. See the class note:
        // an approval describes the moment it was made.
        $objection = $this->reviewer->review($signal);

        if ($objection['status'] !== TelegramSignal::REVIEW_APPROVED) {
            return $this->blocked($signal, 'Conditions changed since approval: '.$objection['reasoning']);
        }

        $heartbeat = BotHeartbeat::where('user_id', $user->id)->orderByDesc('last_seen_at')->first();

        if ($heartbeat === null || ! $heartbeat->isOnline()) {
            return $this->blocked($signal, 'No executor is online to place the order.');
        }

        if (! $heartbeat->algo_trading_enabled) {
            return $this->blocked($signal, 'Algo Trading is off in the terminal; the order would be refused with 10027.');
        }

        $spec = app(SymbolResolver::class)->for(
            $heartbeat->broker_account_id,
            (string) $signal->symbol,
            $heartbeat,
        );

        $sizing = $this->size($signal, $settings, $spec);

        if ($sizing['lots'] === null) {
            return $this->blocked($signal, $sizing['why']);
        }

        // A signal naming an entry is asking to be filled there, not wherever the market
        // happens to be when the order arrives. "Set Sell Limit order di best entry" is an
        // instruction, and a market order ignores it - which on a zone signal means either
        // a worse fill than the one that was reviewed, or a decline for drift that would
        // not have applied to a resting order.
        $pending = $sizing['pending'];

        // A provider posting SELL gold while their BUY gold is still open has changed
        // their mind, and the position taken on the earlier view is now held on nobody's.
        // Off by default: a provider who hedges deliberately would find their two
        // positions collapsing into none.
        if ($settings->copier_close_on_opposite) {
            $this->closeOpposites($signal, $heartbeat);
        }

        $command = TradeCommand::enqueue(
            user: $user,
            type: $pending ? 'open_pending' : 'open',
            account: $heartbeat->brokerAccount,
            payload: [
                'symbol' => $spec['symbol'],
                'direction' => $signal->direction,
                'volume' => $sizing['lots'],
                // A resting order carries absolute levels: its stop belongs to its own
                // entry, not to a market it has not touched yet.
                'sl_pips' => $pending ? null : $sizing['sl_pips'],
                'tp_pips' => $pending ? null : $sizing['tp_pips'],
                'entry_price' => $pending ? $sizing['entry'] : null,
                'sl_price' => $pending ? $sizing['sl'] : null,
                'tp_price' => $pending ? $sizing['tp'] : null,
                'comment' => 'tg-'.$signal->id,
                // Read back by FillController. Without it the resulting position records
                // as `bot` and the fund never learns it spent anything.
                'origin' => AiFund::ORIGIN,
                'telegram_signal_id' => $signal->id,
            ],
            // One command per signal, whatever races to create it.
            idempotencyKey: "telegram:{$signal->id}",
            // How long the EA has to place it, not how long the order rests. A copied
            // instruction that sat unclaimed for ten minutes is no longer the instruction.
            expiresInSeconds: 120,
        );

        $signal->update([
            'execution_status' => TelegramSignal::EXEC_QUEUED,
            'execution_note' => $pending
                ? sprintf(
                    'Queued a resting order for %s lots at %s, stop %s (%s levels), risking %s of the fund.',
                    $sizing['lots'],
                    $sizing['entry'],
                    $sizing['sl'],
                    $sizing['source'],
                    round($sizing['risk'], 2),
                )
                : sprintf(
                    'Queued %s lots at market, stop %s pips (%s levels), risking %s of the fund.',
                    $sizing['lots'],
                    round($sizing['sl_pips'], 1),
                    $sizing['source'],
                    round($sizing['risk'], 2),
                ),
        ]);

        return [
            'ok' => true,
            'status' => TelegramSignal::EXEC_QUEUED,
            'note' => "Queued command #{$command->id} for {$sizing['lots']} lots.",
        ];
    }

    /**
     * Should this rest at the signal's entry rather than fill at market?
     *
     * Only when the entry is somewhere price is not. If the market is already at the
     * entry, a resting order and a market order are the same thing and the market order
     * is simpler - and a limit placed the wrong side of the current price is rejected by
     * the broker anyway.
     *
     * @param  array<string, mixed>  $sizing
     */
    private function pendingOrder(TelegramSignal $signal, array $sizing): bool
    {
        if ($sizing['entry'] === null) {
            return false;
        }

        $last = Candle::where('symbol', $signal->symbol)
            ->orderByDesc('open_time')
            ->value('close');

        if ($last === null) {
            return false;
        }

        // Within a tenth of the stop distance is "here". Resting an order a few cents away
        // just delays the same fill.
        $tolerance = abs($sizing['entry'] - $sizing['sl']) * 0.1;

        return abs((float) $last - $sizing['entry']) > $tolerance;
    }

    /**
     * Position size, from the fund and the instrument's own numbers.
     *
     * @param  array<string, mixed>  $spec
     * @return array{lots: float|null, entry: float|null, sl: float|null, tp: float|null, sl_pips: float, tp_pips: float|null, risk: float, source: string, why: string}
     */
    private function size(TelegramSignal $signal, BotSettings $settings, array $spec): array
    {
        $none = fn (string $why) => ['lots' => null, 'entry' => null, 'sl' => null, 'tp' => null, 'sl_pips' => 0.0, 'tp_pips' => null, 'risk' => 0.0, 'source' => '', 'pending' => false, 'why' => $why];

        $pipSize = $spec['pip_size'] ?? null;
        $pipValue = $spec['pip_value_per_lot'] ?? null;

        // The dashboard refuses to size rather than guess - the same rule the strategy
        // path follows, and the reason the pip trap never became a live bug.
        if ($pipSize === null || $pipSize <= 0.0) {
            return $none("No pip size known for {$spec['symbol']}, so no honest stop distance exists.");
        }

        if ($pipValue === null || $pipValue <= 0.0) {
            return $none("No pip value known for {$spec['symbol']}, so the position cannot be sized.");
        }

        // The same plan the reviewer judged. With `copier_levels = strategy` these are not
        // the numbers in the message, and sizing off the message while the reviewer
        // approved something else would open a trade nobody assessed.
        $plan = app(SignalPlan::class)->for($signal, $settings);

        // What the stop is measured from. A signal naming an entry is measured from it; one
        // at market is measured from the market, because that is where it will fill.
        //
        // This used to fall back to the stop itself, which made the distance exactly zero
        // and meant a market-order signal could never be sized at all. Every fixture
        // happened to carry an entry price, so it never showed.
        $reference = $plan['entry'] ?? $this->lastPrice($signal);

        if ($plan['sl'] === null) {
            return $none('The signal carries no stop, so it cannot be sized.');
        }

        if ($reference === null) {
            return $none("No stored price for {$spec['symbol']}, so a market entry cannot be measured against anything.");
        }

        $slPips = abs($reference - $plan['sl']) / $pipSize;

        // A buy fills at the ask and its stop is hit on the bid, so the market has to
        // travel the published distance minus the spread. On gold at two points that is a
        // large share of a five-point stop, and it makes the realised loss bigger than the
        // one that was sized.
        //
        // Added to the distance rather than to the stop itself: a wider distance sizes a
        // smaller position, which is the safe direction. Moving the stop would risk more.
        if ($settings?->copier_spread_buffer) {
            $slPips += $this->spreadPips($signal, $spec, $pipSize);
        }

        if ($slPips <= 0.0) {
            return $none('The stop distance works out to zero pips.');
        }

        $state = $this->fund->state($settings, $signal->user_id);
        $risk = $state['risk_per_trade'];

        // A channel may risk less - or more - than the account's default. Taken as a
        // percentage of what the fund has left, exactly as the account's own figure is, so
        // an override changes the share and not the thing it is a share of: the cap still
        // bounds the total however many channels are running.
        $channelRisk = $signal->channel?->risk_percentage;

        if ($channelRisk !== null && $channelRisk > 0.0) {
            $risk = round($state['remaining'] * $channelRisk / 100, 2);
        }

        if ($risk <= 0.0) {
            return $none('The fund has nothing left to risk on this trade.');
        }

        $lots = $risk / ($slPips * $pipValue);

        // Down onto the broker's grid, never up: rounding up takes more risk than the
        // fund allows, which is the one thing the cap exists to prevent.
        $step = (float) ($spec['volume_step'] ?? 0.01);
        $min = (float) ($spec['volume_min'] ?? 0.01);
        $lots = floor($lots / $step) * $step;

        if ($lots < $min) {
            return $none(sprintf(
                'Risking %s over a %s pip stop works out below the %s lot minimum. The fund is too small for this stop distance.',
                round($risk, 2),
                round($slPips, 1),
                $min,
            ));
        }

        $targets = $plan['tps'];
        $finalTarget = $targets === [] ? null : (float) end($targets);

        return [
            'lots' => round($lots, 2),
            // Absolute levels alongside the pip distances, so a resting order can carry
            // the levels its own entry implies.
            'entry' => $plan['entry'],
            'sl' => $plan['sl'],
            'tp' => $finalTarget,
            'pending' => $plan['pending'],
            'sl_pips' => $slPips,
            // The final rung, matching what the strategy path sends: an order stopped out
            // at TP1 would close the whole position at a level meant to take part of it.
            'tp_pips' => $finalTarget === null ? null : abs($finalTarget - $reference) / $pipSize,
            'risk' => $risk,
            'source' => $plan['source'],
            'why' => '',
        ];
    }

    /**
     * The most recent spread on this instrument, in pips.
     *
     * Taken from the stored bars, which carry the spread at their close in broker points.
     * A live quote would be better and is not something this side of the bridge has; the
     * last bar's spread is the closest honest answer, and it is only ever used to size a
     * position slightly smaller.
     *
     * @param  array<string, mixed>  $spec
     */
    private function spreadPips(TelegramSignal $signal, array $spec, float $pipSize): float
    {
        $points = Candle::where('symbol', $signal->symbol)
            ->orderByDesc('open_time')
            ->value('spread_points');

        $digits = (int) ($spec['digits'] ?? 0);

        if ($points === null || $digits <= 0 || $pipSize <= 0.0) {
            return 0.0;
        }

        // One point is 10^-digits of price; a pip is pip_size of price.
        $point = 10 ** (-$digits);

        return max(0.0, ((float) $points * $point) / $pipSize);
    }

    /**
     * Close anything this account holds on the same instrument in the other direction.
     *
     * Only positions the copier itself opened. A hedge somebody placed by hand, or one the
     * strategy owns, is not this feature's to close - it was taken on a different view for
     * different reasons.
     */
    private function closeOpposites(TelegramSignal $signal, BotHeartbeat $heartbeat): void
    {
        $opposite = strtolower((string) $signal->direction) === 'buy' ? 'sell' : 'buy';

        $held = Trade::where('user_id', $signal->user_id)
            ->where('origin', AiFund::ORIGIN)
            ->where('symbol', $signal->symbol)
            ->where('direction', $opposite)
            ->whereIn('status', ['open', 'partially_closed'])
            ->get();

        foreach ($held as $trade) {
            TradeCommand::enqueue(
                user: User::find($signal->user_id),
                type: 'close',
                account: $heartbeat->brokerAccount,
                payload: [
                    'symbol' => $trade->symbol,
                    'ticket' => $trade->mt5_ticket,
                    'volume' => round((float) $trade->remaining_lot_size, 2),
                    'reason' => 'opposite-signal',
                    'origin' => AiFund::ORIGIN,
                ],
                // Keyed on the position rather than the signal, so two opposite signals
                // arriving together do not queue two closes for one ticket.
                idempotencyKey: "opposite:{$trade->id}",
                expiresInSeconds: 120,
            );
        }
    }

    private function lastPrice(TelegramSignal $signal): ?float
    {
        $close = Candle::where('symbol', $signal->symbol)
            ->orderByDesc('open_time')
            ->value('close');

        return $close === null ? null : (float) $close;
    }

    /**
     * @return array{ok: false, status: string, note: string}
     */
    private function blocked(TelegramSignal $signal, string $note): array
    {
        $signal->update([
            'execution_status' => TelegramSignal::EXEC_BLOCKED,
            'execution_note' => mb_substr($note, 0, 250),
        ]);

        return ['ok' => false, 'status' => TelegramSignal::EXEC_BLOCKED, 'note' => $note];
    }
}

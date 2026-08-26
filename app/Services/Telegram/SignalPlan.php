<?php

namespace App\Services\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\SymbolSpec;
use App\Models\TelegramSignal;
use App\Services\Indicators\Indicators;

/**
 * Signal Plan
 *
 * The levels a copied signal would actually be traded with.
 *
 * ## One plan, used by everything downstream
 *
 * The reviewer judges reward against risk, and the executor sizes the position from the
 * stop distance. Both have to be looking at the same numbers, and with `copier_levels` set
 * to `strategy` those are not the numbers in the message.
 *
 * Computing this once and handing it to both is what stops the reviewer approving a 3:1
 * trade that the executor then opens at 1:1 - which would be a reviewer approving
 * something that never existed.
 *
 * ## What is kept, and what is replaced
 *
 * The entry is always the provider's. That is the part being copied: their read on where
 * to get in. Under `strategy`, the stop becomes `sl_atr_multiplier` times ATR and the
 * targets become the configured ladder - both measured against the provider's entry, so
 * the trade is their timing with this account's risk.
 */
final class SignalPlan
{
    public const SOURCE_PROVIDER = 'provider';

    public const SOURCE_STRATEGY = 'strategy';

    public function __construct(
        private readonly SignalSeries $series = new SignalSeries,
    ) {}

    /**
     * The provider's targets, nearest first.
     *
     * Providers do not reliably post them in order - one of this account's own gold
     * signals arrived as "TP1: 4622, TP2: 4620" on a buy, which lists the further target
     * first. Everything downstream assumes the list is ordered by distance: the executor
     * sends `end($targets)` to the broker as the position's take-profit, so on that signal
     * the order would have carried the *nearer* target and given up two dollars an ounce
     * that the provider had asked for.
     *
     * Sorted here rather than at each reader, because the plan is the one place both the
     * reviewer and the executor agree to look.
     *
     * @param  array<int, float>  $tps
     * @return array<int, float>
     */
    private function ordered(array $tps, ?float $entry, ?string $direction): array
    {
        if ($entry === null || $tps === []) {
            return array_values($tps);
        }

        // Behind the entry is not a target, it is a typo or a mis-parse. Dropping it is
        // safer than sorting it to the front and letting the executor aim there.
        $ahead = array_values(array_filter(
            $tps,
            fn (float $tp) => strtolower((string) $direction) === 'buy' ? $tp > $entry : $tp < $entry,
        ));

        usort($ahead, fn (float $a, float $b) => abs($a - $entry) <=> abs($b - $entry));

        return $ahead;
    }

    /**
     * Which end of an entry zone to ask for.
     *
     * "Entry: 4633 - 4637" names a range, and the parser already picks the end furthest
     * from the market - the better fill, and the one that sometimes does not happen. Some
     * providers write their zones the other way round, quoting the level they expect to
     * trade and a little room beyond it, and on those the far end simply misses.
     *
     * Per channel, because which of those two a provider is doing is a fact about them.
     */
    private function entryFor(TelegramSignal $signal): ?float
    {
        $entry = $signal->entry_price;
        $far = $signal->entry_zone_high;

        if ($entry === null || $far === null) {
            return $entry;
        }

        return match ($signal->channel?->entry_preference) {
            'near' => (float) $far,
            'average' => round(((float) $entry + (float) $far) / 2, 6),
            // The default and what this has always done: the better price.
            default => (float) $entry,
        };
    }

    /**
     * @return array{
     *     ok: bool,
     *     source: string,
     *     entry: float|null,
     *     sl: float|null,
     *     tps: array<int, float>,
     *     pending: bool,
     *     why: string|null,
     * }
     */
    public function for(TelegramSignal $signal, ?BotSettings $settings): array
    {
        // The channel's own choice first. Whether to trust a provider's stops is a
        // judgement about that provider, so it belongs beside them rather than as one
        // setting covering everybody.
        $source = $signal->channel?->policy($settings)['copier_levels']
            ?? $settings?->copier_levels
            ?? self::SOURCE_PROVIDER;

        $entryFor = $this->entryFor($signal);

        $provider = [
            'ok' => true,
            'source' => self::SOURCE_PROVIDER,
            'entry' => $entryFor,
            'sl' => $signal->sl_price,
            'tps' => $this->ordered(
                array_map('floatval', $signal->tp_prices ?? []),
                $entryFor,
                $signal->direction,
            ),
            'pending' => false,
            'why' => null,
        ];

        $provider['pending'] = $this->rests($signal, $provider['entry'], $provider['sl']);

        if ($source !== self::SOURCE_STRATEGY) {
            return $provider;
        }

        $strategy = Strategy::where('user_id', $signal->user_id)
            ->orderByDesc('is_active')->orderBy('id')->first();

        if ($strategy === null) {
            return $provider + ['why' => 'No strategy configured, so the provider\'s levels stand.'];
        }

        // Without an entry there is nothing to measure a stop from. A market order under
        // strategy levels would need the fill price, which does not exist yet.
        if ($signal->entry_price === null) {
            return array_merge($provider, [
                'why' => 'Signal is at market with no entry price, so strategy levels cannot be placed. Provider levels stand.',
            ]);
        }

        $atr = $this->atr($signal, $strategy);

        if ($atr === null || $atr <= 0.0) {
            // Refusing to invent a level, the same rule the strategy path follows.
            return array_merge($provider, [
                'why' => "Not enough stored bars for {$signal->symbol} to measure ATR, so the provider's levels stand.",
            ]);
        }

        $pipSize = $this->pipSize($signal);

        if ($pipSize === null || $pipSize <= 0.0) {
            return array_merge($provider, [
                'why' => "No pip size known for {$signal->symbol}, so the ladder cannot be placed in price. Provider's levels stand.",
            ]);
        }

        $sign = $signal->direction === 'buy' ? 1.0 : -1.0;
        $entry = $signal->entry_price;
        $stopDistance = $atr * (float) $strategy->sl_atr_multiplier;

        $tps = [];

        foreach (['tp1_pips', 'tp2_pips', 'tp3_pips'] as $rung) {
            $pips = $strategy->{$rung};

            if ($pips !== null && (float) $pips > 0.0) {
                $tps[] = round($entry + ($sign * (float) $pips * $pipSize), 6);
            }
        }

        $sl = round($entry - ($sign * $stopDistance), 6);

        return [
            'ok' => true,
            'source' => self::SOURCE_STRATEGY,
            'entry' => $entry,
            'sl' => $sl,
            'tps' => $tps,
            'pending' => $this->rests($signal, $entry, $sl),
            'why' => sprintf(
                'Provider entry, this account\'s stop (%.2f x ATR on %s) and ladder.',
                (float) $strategy->sl_atr_multiplier,
                $this->series->timeframes($strategy)['entry'],
            ),
        ];
    }

    /**
     * The plan in one line, for a reader looking at the card.
     *
     * Substituting the levels and then showing only the posted ones is how "why was this
     * declined" became unanswerable from the screen: the card said 5.00 of risk for 8.00
     * of reward while the verdict was written about 12.95 for 3.00. Both were true, about
     * different trades, and the page only ever showed one of them.
     *
     * @param  array<string, mixed>  $plan
     */
    public function summary(array $plan): ?string
    {
        if ($plan['source'] !== self::SOURCE_STRATEGY || $plan['entry'] === null || $plan['sl'] === null) {
            return $plan['why'];
        }

        $risk = abs((float) $plan['entry'] - (float) $plan['sl']);
        $tps = $plan['tps'];

        if ($risk <= 0.0 || $tps === []) {
            return $plan['why'];
        }

        $reward = abs((float) end($tps) - (float) $plan['entry']);

        return sprintf(
            '%s Risking %s to make %s at the exit - %.2f : 1.',
            $plan['why'],
            $this->price($risk),
            $this->price($reward),
            $reward / $risk,
        );
    }

    private function price(float $value): string
    {
        return rtrim(rtrim(number_format($value, 5, '.', ''), '0'), '.');
    }

    /**
     * Should this rest at its entry rather than fill at market?
     *
     * Only when the entry is somewhere price is not. If the market is already there, a
     * resting order and a market order are the same fill and the market order is simpler -
     * and a limit placed the wrong side of the current price is refused anyway.
     *
     * Decided here rather than in the executor because the reviewer needs the same answer:
     * the drift check means opposite things for the two. Price running away from a market
     * entry is a missed trade; price running away from a resting one is why you rested it.
     */
    private function rests(TelegramSignal $signal, ?float $entry, ?float $sl): bool
    {
        if ($entry === null || $sl === null) {
            return false;
        }

        $last = $this->series->lastClose($signal);

        if ($last === null) {
            return false;
        }

        // Within a tenth of the stop distance is "here".
        return abs($last - $entry) > abs($entry - $sl) * 0.1;
    }

    private function atr(TelegramSignal $signal, Strategy $strategy): ?float
    {
        $period = (int) $strategy->atr_period;

        // One timeframe, this account's own, under the broker's own name for the
        // instrument. See SignalSeries for what the unscoped version of this query was
        // measuring, and what it did to the stop it sized.
        $bars = $this->series->bars(
            $signal,
            $this->series->timeframes($strategy)['entry'],
            max(60, $period * 4),
        );

        if (count($bars) < $period * 2 + 1) {
            return null;
        }

        return Indicators::last(Indicators::atr(
            Candle::highs($bars),
            Candle::lows($bars),
            Candle::closes($bars),
            $period,
        ));
    }

    /**
     * Pip size for the instrument, from whatever the terminal has reported about it.
     */
    private function pipSize(TelegramSignal $signal): ?float
    {
        $spec = SymbolSpec::where('symbol', $signal->symbol)->first()
            ?? SymbolSpec::where('base_symbol', $signal->symbol)->first();

        if ($spec?->pip_size !== null) {
            return (float) $spec->pip_size;
        }

        $heartbeat = BotHeartbeat::where('user_id', $signal->user_id)
            ->orderByDesc('last_seen_at')
            ->first();

        return $heartbeat?->pip_size === null ? null : (float) $heartbeat->pip_size;
    }
}

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

        $provider = [
            'ok' => true,
            'source' => self::SOURCE_PROVIDER,
            'entry' => $this->entryFor($signal),
            'sl' => $signal->sl_price,
            'tps' => array_map('floatval', $signal->tp_prices ?? []),
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

        $atr = $this->atr($signal, (int) $strategy->atr_period);

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
                'Provider entry, this account\'s stop (%.2f x ATR) and ladder.',
                (float) $strategy->sl_atr_multiplier,
            ),
        ];
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

        $last = Candle::where('symbol', $signal->symbol)->orderByDesc('open_time')->value('close');

        if ($last === null) {
            return false;
        }

        // Within a tenth of the stop distance is "here".
        return abs((float) $last - $entry) > abs($entry - $sl) * 0.1;
    }

    private function atr(TelegramSignal $signal, int $period): ?float
    {
        $bars = Candle::where('symbol', $signal->symbol)
            ->orderByDesc('open_time')
            ->limit(max(60, $period * 4))
            ->get()
            ->reverse()
            ->values()
            ->all();

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

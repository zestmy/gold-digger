<?php

namespace App\Support;

/**
 * Starter Strategies
 *
 * The strategies an account begins with, in one place.
 *
 * ## Why this is not just inlined in the observer
 *
 * `UserObserver` only ever fires on registration. An account that already exists therefore
 * cannot gain a starter strategy by editing the observer, which is exactly the position
 * every deployment was in when gold was the only preset: the feed showed one instrument
 * because one strategy row existed, and no amount of changing the seed reached it.
 *
 * `strategies:add-starters` backfills those accounts, and it has to agree with the observer
 * about what a starter *is*. Two copies of these numbers would disagree within a release.
 *
 * ## Why the majors carry different pip fallbacks
 *
 * Targets are taken in R - multiples of the ATR-sized stop - for every preset here, so the
 * pip columns are dormant unless someone clears `tp1_r`. See `TargetLadder`. But dormant is
 * not the same as harmless: gold's 30/100/200 read as a sane ladder on gold and as an
 * unreachable one on a major, where a hundred pips is a long way. If those columns ever
 * become the live ladder, they should describe the instrument they sit on.
 *
 * A pip is not the same fraction of price on every major - USDJPY quotes to three decimals
 * where the rest quote to five - but a ladder written in pips is already in that instrument's
 * own units, so one set of figures is right for all of them. `SymbolResolver` supplies the
 * pip size, per broker and per symbol; nothing here needs to know it.
 *
 * ## Why every preset starts inactive
 *
 * A strategy that generated entries the moment it appeared would have an account trading an
 * instrument its owner never chose, on parameters nobody reviewed. `is_active` is the
 * deliberate second step, and the Strategies page is where it is taken.
 */
final class StarterStrategies
{
    /**
     * Every starter preset, as attributes ready for `Strategy::create()` bar `user_id`.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            self::goldTrendScalp(),
            self::majorTrendScalp('EURUSD', 'EUR/USD Trend Scalp'),
            self::majorTrendScalp('GBPUSD', 'GBP/USD Trend Scalp'),
            self::majorTrendScalp('USDJPY', 'USD/JPY Trend Scalp'),
            self::majorTrendScalp('AUDUSD', 'AUD/USD Trend Scalp'),
        ];
    }

    /**
     * The original preset, unchanged: "Fira-Style" gold scalping.
     *
     * Trend-following on an H1 bias with M5 entries, taking partial profit up a ladder.
     *
     * @return array<string, mixed>
     */
    private static function goldTrendScalp(): array
    {
        return [
            'name' => 'Fira-Style Gold Trend Scalp',
            'symbol' => 'XAUUSD',

            // Multi-timeframe: H1 for trend, M5 for entries
            'timeframe_entry' => 'M5',
            'timeframe_trend' => 'H1',

            // EMA crossover settings
            'ema_fast' => 20,
            'ema_slow' => 50,

            // ADX filter - only trade strong trends
            'adx_threshold' => 25.00,
            'atr_period' => 14,

            // Take profit levels with partial closes, as multiples of the stop distance:
            // TP1 at 1R closes 50%, TP2 at 2R closes 30%, TP3 at 3R closes the rest. The
            // first rung pays at least what a stop costs, which fixed pips against an
            // ATR stop did not guarantee - see the tp_r migration. The pip columns are
            // kept as the fallback for a strategy that clears its R values.
            'tp1_r' => 1.00,
            'tp2_r' => 2.00,
            'tp3_r' => 3.00,
            'tp1_pips' => 30.00,
            'tp1_close_pct' => 50.00,
            'tp2_pips' => 100.00,
            'tp2_close_pct' => 30.00,
            'tp3_pips' => 200.00,
            'tp3_close_pct' => 20.00,

            // Stop loss based on ATR
            'sl_atr_multiplier' => 1.50,

            // Exit on reversal signal
            'exit_on_reversal' => true,

            // Max 24 bars (2 hours on M5) before forced exit
            'max_holding_bars' => 24,

            // Start inactive - user activates after review
            'is_active' => false,
        ];
    }

    /**
     * The same trade, on a major.
     *
     * The rules are deliberately identical to gold's: same EMA pair, same ADX floor, same
     * ATR stop, same ladder in R. That is what taking targets in R buys - one description of
     * a trend-following scalp that means the same thing on an instrument moving two dollars
     * a day and one moving eighty. Tuning them apart is a job for the outcome reports, which
     * need signals on these pairs before they have anything to say.
     *
     * @return array<string, mixed>
     */
    private static function majorTrendScalp(string $symbol, string $name): array
    {
        return [
            'name' => $name,
            'symbol' => $symbol,

            'timeframe_entry' => 'M5',
            'timeframe_trend' => 'H1',

            'ema_fast' => 20,
            'ema_slow' => 50,

            'adx_threshold' => 25.00,
            'atr_period' => 14,

            // The live ladder, in multiples of the ATR stop.
            'tp1_r' => 1.00,
            'tp2_r' => 2.00,
            'tp3_r' => 3.00,

            // Dormant unless the R values are cleared, and sized for a major rather than
            // inherited from gold.
            'tp1_pips' => 10.00,
            'tp1_close_pct' => 50.00,
            'tp2_pips' => 20.00,
            'tp2_close_pct' => 30.00,
            'tp3_pips' => 30.00,
            'tp3_close_pct' => 20.00,

            'sl_atr_multiplier' => 1.50,

            'exit_on_reversal' => true,

            'max_holding_bars' => 24,

            'is_active' => false,
        ];
    }
}

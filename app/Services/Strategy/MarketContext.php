<?php

namespace App\Services\Strategy;

use App\Models\Candle;
use App\Models\Strategy;
use App\Services\Indicators\Indicators;

/**
 * Market Context
 *
 * One read-only snapshot of what the strategy currently sees: higher-timeframe trend,
 * entry-timeframe EMAs, trend strength, volatility, and how fresh the data is.
 *
 * ## Why this is a service and not a Livewire component
 *
 * Three things need the same answer - the trend card, the session card's "would a signal
 * be allowed right now", and the AI analyst, which must describe the state the strategy
 * is actually in rather than one it computed for itself. Three implementations of "is
 * gold trending up" would drift, and the first sign of the drift would be a dashboard
 * confidently contradicting the trades underneath it.
 *
 * Everything here is derived from stored candles through `StrategyEvaluator` and
 * `Indicators` - the same code path that decides entries. Nothing is computed a second
 * way, and nothing is fetched from anywhere new.
 */
final class MarketContext
{
    /** ADX conventions: below 20 is no trend, above 40 is a strong one. */
    private const ADX_ABSENT = 20.0;

    private const ADX_STRONG = 40.0;

    public function __construct(private readonly StrategyEvaluator $evaluator) {}

    /**
     * @return array{
     *     warm: bool,
     *     symbol: string,
     *     trend_timeframe: string,
     *     entry_timeframe: string,
     *     trend: 'buy'|'sell'|null,
     *     entry_bias: 'buy'|'sell'|null,
     *     aligned: bool,
     *     ema_fast: float|null,
     *     ema_slow: float|null,
     *     ema_gap_pct: float|null,
     *     adx: float|null,
     *     adx_label: string|null,
     *     plus_di: float|null,
     *     minus_di: float|null,
     *     atr: float|null,
     *     atr_pct: float|null,
     *     last_close: float|null,
     *     last_bar_at: \Illuminate\Support\Carbon|null,
     *     bars_entry: int,
     *     bars_trend: int,
     * }
     */
    public function for(Strategy $strategy, ?int $brokerAccountId, string $symbol): array
    {
        $entry = Candle::recentSeries($brokerAccountId, $symbol, $strategy->timeframe_entry, StrategyEvaluator::LOOKBACK_BARS);
        $trend = Candle::recentSeries($brokerAccountId, $symbol, $strategy->timeframe_trend, StrategyEvaluator::LOOKBACK_BARS);

        $emaFast = (int) $strategy->ema_fast;
        $emaSlow = (int) $strategy->ema_slow;
        $period = (int) $strategy->atr_period;

        $base = [
            'warm' => false,
            'symbol' => $symbol,
            'trend_timeframe' => (string) $strategy->timeframe_trend,
            'entry_timeframe' => (string) $strategy->timeframe_entry,
            'trend' => null,
            'entry_bias' => null,
            'aligned' => false,
            'ema_fast' => null,
            'ema_slow' => null,
            'ema_gap_pct' => null,
            'adx' => null,
            'adx_label' => null,
            'plus_di' => null,
            'minus_di' => null,
            'atr' => null,
            'atr_pct' => null,
            'last_close' => null,
            'last_bar_at' => null,
            'bars_entry' => count($entry),
            'bars_trend' => count($trend),
        ];

        // Same warm-up rule the evaluator applies. Reporting a trend off a series the
        // strategy considers too short to trade would show a direction it will not act on.
        $minEntry = max($emaSlow + 2, (2 * $period) + 1);

        if (count($entry) < $minEntry || count($trend) < $emaSlow + 1) {
            return $base;
        }

        $closes = Candle::closes($entry);
        $highs = Candle::highs($entry);
        $lows = Candle::lows($entry);

        $fast = Indicators::last(Indicators::ema($closes, $emaFast));
        $slow = Indicators::last(Indicators::ema($closes, $emaSlow));
        $atr = Indicators::last(Indicators::atr($highs, $lows, $closes, $period));
        $adxSeries = Indicators::adx($highs, $lows, $closes, $period);
        $adx = Indicators::last($adxSeries['adx']);

        $lastCandle = $entry[count($entry) - 1];
        $lastClose = (float) $lastCandle->close;

        $trendDirection = $this->evaluator->trendDirection($trend, $emaFast, $emaSlow);

        $entryBias = match (true) {
            $fast === null || $slow === null, $fast === $slow => null,
            $fast > $slow => 'buy',
            default => 'sell',
        };

        return [
            'warm' => true,
            'symbol' => $symbol,
            'trend_timeframe' => (string) $strategy->timeframe_trend,
            'entry_timeframe' => (string) $strategy->timeframe_entry,
            'trend' => $trendDirection,
            'entry_bias' => $entryBias,
            // The condition the strategy actually requires: a cross on the entry
            // timeframe is only taken when the higher timeframe agrees with it.
            'aligned' => $trendDirection !== null && $trendDirection === $entryBias,
            'ema_fast' => $fast,
            'ema_slow' => $slow,
            // As a percentage of price, so it is comparable across instruments and does
            // not need the pip size the dashboard is not allowed to guess.
            'ema_gap_pct' => ($fast !== null && $slow !== null && $lastClose > 0.0)
                ? abs($fast - $slow) / $lastClose * 100
                : null,
            'adx' => $adx,
            'adx_label' => $this->adxLabel($adx),
            'plus_di' => Indicators::last($adxSeries['plus_di']),
            'minus_di' => Indicators::last($adxSeries['minus_di']),
            'atr' => $atr,
            'atr_pct' => ($atr !== null && $lastClose > 0.0) ? $atr / $lastClose * 100 : null,
            'last_close' => $lastClose,
            'last_bar_at' => $lastCandle->open_time,
            'bars_entry' => count($entry),
            'bars_trend' => count($trend),
        ];
    }

    /**
     * Plain words for an ADX reading.
     *
     * These are the conventional bands, not a tuned parameter - the strategy's own
     * `adx_threshold` is what decides whether a setup is taken, and this only describes
     * the number for a reader.
     */
    private function adxLabel(?float $adx): ?string
    {
        return match (true) {
            $adx === null => null,
            $adx < self::ADX_ABSENT => 'ranging',
            $adx < self::ADX_STRONG => 'trending',
            default => 'strong trend',
        };
    }
}

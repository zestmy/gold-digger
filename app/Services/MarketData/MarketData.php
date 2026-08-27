<?php

namespace App\Services\MarketData;

use App\Models\Candle;

/**
 * Market Data
 *
 * The two doors onto a price series, and the reason there are exactly two.
 *
 * ## forTrading() cannot be configured onto a vendor
 *
 * Not "defaults to the broker" - cannot. There is no setting, no argument and no fallback
 * that makes this method return somebody else's bars. The reason is in the `candles`
 * migration and is worth repeating: indicators decide where the stop goes, and an ATR
 * computed from one vendor's gold series against a fill on the broker's is a stop sized
 * from prices the broker never quoted. A switch that could do that is a switch somebody
 * will eventually flip at three in the morning.
 *
 * ## forAnalysis() prefers the vendor, and says which it used
 *
 * These are the surfaces that describe rather than decide - the chart page, the timeframe
 * ladder, the market scan, the dashboard chart. Nothing they produce becomes an order
 * without a person or a separate gate acting on it, which is what makes a vendor's series
 * acceptable there.
 *
 * It falls back to stored bars when no vendor is configured, so a deployment that buys no
 * subscription behaves exactly as it did before this existed.
 *
 * ## Why this is worth the indirection
 *
 * Storing deep history so charts could be described put 80,000 bars against ten trades -
 * 91% of the database - and the growth was per broker account rather than shared. Reading
 * that history on demand and never persisting it removes the reason to keep it, which is
 * what lets retention drop to what the evaluator actually needs.
 */
final class MarketData
{
    public function __construct(
        private readonly BrokerSeries $broker = new BrokerSeries,
    ) {}

    /**
     * Bars that will decide a price. Always the broker's own.
     *
     * @return array<int, Candle>
     */
    public function forTrading(string $symbol, string $timeframe, int $limit, ?int $brokerAccountId = null): array
    {
        return $this->broker->series($symbol, $timeframe, $limit, $brokerAccountId);
    }

    /**
     * Bars that will describe a chart. The vendor when there is one, stored bars otherwise.
     *
     * Falls back on an empty vendor response too, not only on an unconfigured one: a rate
     * limit or an unmapped symbol should degrade to whatever is stored rather than blank a
     * page that had something to show.
     *
     * @return array<int, Candle>
     */
    public function forAnalysis(string $symbol, string $timeframe, int $limit, ?int $brokerAccountId = null): array
    {
        $vendor = $this->vendor();

        if ($vendor !== null && $vendor->available()) {
            $bars = $vendor->series($symbol, $timeframe, $limit, $brokerAccountId);

            if ($bars !== []) {
                return $bars;
            }
        }

        return $this->broker->series($symbol, $timeframe, $limit, $brokerAccountId);
    }

    /**
     * Deep history for a replay, and the name of whatever served it.
     *
     * ## Why only this path needed a vendor
     *
     * Measured against what each consumer asks for, the picture is lopsided. The evaluator
     * wants 300 bars, the dashboard chart 300, the timeframe ladder 260 a rung, the chart
     * analyst 120. The walk-forward wants **20,000**.
     *
     * So one consumer was responsible for storing two orders of magnitude more history
     * than everything else combined - and it is the one consumer that runs on request, on
     * a person's deliberate act, rather than on every bar. Fetching its history when asked
     * and dropping it afterwards is what lets the stored series shrink to what trading
     * needs, which was the whole object of the exercise.
     *
     * ## Stored bars still win when there are enough of them
     *
     * They are this broker's own prices, which is a better replay than a vendor's for the
     * same reason it is a better basis for a stop. The vendor is what happens when the
     * stored series is too short to answer the question - which, after retention, is most
     * of the time for a long backtest.
     *
     * @return array{bars: array<int, Candle>, source: string}
     */
    public function forBacktest(string $symbol, string $timeframe, int $limit, ?int $brokerAccountId = null): array
    {
        $stored = $this->broker->series($symbol, $timeframe, $limit, $brokerAccountId);

        if (count($stored) >= $limit) {
            return ['bars' => $stored, 'source' => $this->broker->name()];
        }

        $vendor = $this->vendor();

        if ($vendor === null || ! $vendor->available()) {
            // Short, and honestly short. A caller that wanted 20,000 and got 800 should be
            // told how many it has rather than handed a padded series.
            return ['bars' => $stored, 'source' => $this->broker->name()];
        }

        $fetched = $vendor->series($symbol, $timeframe, $limit, $brokerAccountId);

        // Deeper only. A vendor that returned less than is already stored has nothing to
        // add, and swapping this broker's prices for a stranger's to get fewer bars would
        // be a worse replay by both measures.
        return count($fetched) > count($stored)
            ? ['bars' => $fetched, 'source' => $vendor->name()]
            : ['bars' => $stored, 'source' => $this->broker->name()];
    }

    /**
     * What `forAnalysis()` would use for this series, by name.
     *
     * For anything that has to state its source - a backtest replayed over a vendor's bars
     * rather than this broker's is still worth running and is not the same claim.
     */
    public function analysisSource(string $symbol, string $timeframe, ?int $brokerAccountId = null): string
    {
        $vendor = $this->vendor();

        if ($vendor !== null && $vendor->available() && $vendor->series($symbol, $timeframe, 1, $brokerAccountId) !== []) {
            return $vendor->name();
        }

        return $this->broker->name();
    }

    /**
     * Is a vendor configured and usable at all?
     */
    public function hasVendor(): bool
    {
        $vendor = $this->vendor();

        return $vendor !== null && $vendor->available();
    }

    /**
     * The configured vendor, or null when the deployment has not bought one.
     */
    private function vendor(): ?SeriesProvider
    {
        $class = config('marketdata.provider');

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            return null;
        }

        $provider = app($class);

        return $provider instanceof SeriesProvider ? $provider : null;
    }
}

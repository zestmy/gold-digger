<?php

namespace App\Services\MarketData;

use App\Models\Candle;

/**
 * Series Provider
 *
 * Where a run of bars comes from.
 *
 * ## Why this seam exists, and where it must not be crossed
 *
 * Until now there was one answer: the terminal pushed its own bars and everything read
 * them out of `candles`. That is the correct answer for anything that decides a price, and
 * the migration for that table makes the argument better than this docblock could - if ATR
 * is computed from one vendor's gold series and the order is filled against the broker's,
 * the stop is sized from prices the broker never quoted.
 *
 * It is the wrong answer for everything else. Storing deep history so that a chart can be
 * described, or a walk-forward replayed, put 80,000 bars in a database where ten trades
 * lived - 91% of it - on a box with 2GB of RAM.
 *
 * So the split is by **what the bars are for**, not by what is convenient:
 *
 * | Purpose | Provider | Because |
 * |---|---|---|
 * | Deciding a price | Broker, always | The fill happens on the broker's series |
 * | Describing a chart | Vendor, when configured | Nothing here becomes an order |
 *
 * `MarketData::forTrading()` and `MarketData::forAnalysis()` are the two doors, and the
 * first one cannot be configured to open onto a vendor. That is deliberate: a setting that
 * could point the stop calculation at a third party is a setting somebody will eventually
 * point there.
 *
 * ## Bars come back unsaved
 *
 * A provider returns `Candle` instances that were never persisted. They carry the same
 * shape every indicator already reads - `Candle::closes()`, `highs()`, `lows()` - so
 * nothing downstream has to learn a second representation. And because they are never
 * saved, reading a year of history to answer one question costs nothing on disk, which is
 * the entire point of the exercise.
 */
interface SeriesProvider
{
    /**
     * The newest `$limit` closed bars, oldest-first.
     *
     * Oldest-first because every indicator in App\Services\Indicators expects it, and a
     * reversed series does not throw - it silently computes an EMA of the future.
     *
     * @return array<int, Candle> Possibly fewer than asked for, possibly empty
     */
    public function series(string $symbol, string $timeframe, int $limit, ?int $brokerAccountId = null): array;

    /**
     * Can this provider answer at all?
     *
     * A vendor with no API key is not an error - it is a deployment that has not bought
     * one, and the caller falls back to stored bars rather than failing.
     */
    public function available(): bool;

    /**
     * What served the bars, for anything that has to say so out loud.
     *
     * A backtest replayed over a vendor's series rather than this broker's is still worth
     * running and is not the same claim, so the report names the source rather than
     * implying one.
     */
    public function name(): string;
}

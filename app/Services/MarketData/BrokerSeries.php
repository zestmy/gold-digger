<?php

namespace App\Services\MarketData;

use App\Models\Candle;

/**
 * Broker Series
 *
 * The bars the terminal pushed, read out of `candles`. This is what every consumer used
 * before the seam existed, and it stays the only provider anything price-deciding is
 * allowed to use.
 *
 * It is also the fallback for the analysis surfaces when no vendor is configured, so a
 * deployment that buys no market-data subscription behaves exactly as it did.
 */
final class BrokerSeries implements SeriesProvider
{
    public function series(string $symbol, string $timeframe, int $limit, ?int $brokerAccountId = null): array
    {
        return Candle::recentSeries($brokerAccountId, $symbol, $timeframe, $limit);
    }

    /**
     * Always. Whether there are bars for a given series is a different question, and one
     * the caller answers by looking at what comes back.
     */
    public function available(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'broker';
    }
}

<?php

use App\Services\MarketData\TwelveDataSeries;

return [

    /*
    |--------------------------------------------------------------------------
    | Where chart data comes from
    |--------------------------------------------------------------------------
    |
    | Off unless a key is present, the same way the AI and the alert channel are. With no
    | vendor configured every surface reads the bars the terminal pushed, which is exactly
    | how this application behaved before the seam existed.
    |
    | ## What this does and does not change
    |
    | It changes where the *descriptive* surfaces get their bars: the chart page, the
    | timeframe ladder, the market scan, the dashboard chart. It cannot change where the
    | *price-deciding* ones get theirs. `MarketData::forTrading()` reads the broker's own
    | series and has no setting that says otherwise - see the class, and the `candles`
    | migration for why an ATR from somebody else's gold series is a stop sized from prices
    | the broker never quoted.
    |
    | ## Why it is worth having
    |
    | Storing history deep enough to describe a chart put 80,000 bars in a database holding
    | ten trades - 91% of it - and `candles` grows per broker account rather than being
    | shared, so it multiplies by tenant. Vendor bars are fetched, used and dropped. Nothing
    | is written, so nothing accumulates.
    |
    */

    'provider' => env('MARKETDATA_PROVIDER', TwelveDataSeries::class),

    'key' => env('MARKETDATA_KEY'),

    'base_url' => env('MARKETDATA_BASE_URL', 'https://api.twelvedata.com'),

    /*
    | Seconds to wait. Short, because every caller is a page somebody is looking at, and a
    | slow chart is worse than one that quietly fell back to stored bars.
    */
    'timeout' => (int) env('MARKETDATA_TIMEOUT', 15),

    /*
    | Most bars in one request. The vendor caps this and truncates silently, which would
    | read as a short series rather than a clipped one - so the cap is applied here, where
    | it can be seen.
    */
    'max_bars' => (int) env('MARKETDATA_MAX_BARS', 5000),

    /*
    |--------------------------------------------------------------------------
    | Symbol translation
    |--------------------------------------------------------------------------
    |
    | Brokers publish `XAUUSDm`, `XAUUSD.a`, `GOLD`; vendors want `XAU/USD`. Matched on the
    | longest configured prefix, because the suffix varies and the instrument does not.
    |
    | An unmapped symbol returns nothing rather than being guessed at. A chart of the wrong
    | instrument looks like an answer, which is worse than an empty one - and this is the
    | most fragile part of talking to a vendor, so it fails visibly.
    |
    | In config rather than in code so a deployment whose broker names things unusually can
    | correct it without a release.
    |
    */

    'symbols' => [
        'XAUUSD' => 'XAU/USD',
        'GOLD' => 'XAU/USD',
        'XAGUSD' => 'XAG/USD',
        'SILVER' => 'XAG/USD',

        'EURUSD' => 'EUR/USD',
        'GBPUSD' => 'GBP/USD',
        'USDJPY' => 'USD/JPY',
        'USDCHF' => 'USD/CHF',
        'USDCAD' => 'USD/CAD',
        'AUDUSD' => 'AUD/USD',
        'NZDUSD' => 'NZD/USD',
        'EURJPY' => 'EUR/JPY',
        'GBPJPY' => 'GBP/JPY',
        'EURGBP' => 'EUR/GBP',

        'BTCUSD' => 'BTC/USD',
        'ETHUSD' => 'ETH/USD',
    ],

];

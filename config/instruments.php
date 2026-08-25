<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Indices
    |--------------------------------------------------------------------------
    |
    | Prefix => the currency whose calendar moves it. Matched as a prefix because
    | brokers append their own wording: US30, US30Cash, US30.spot.
    |
    | The currency matters more here than it looks. An index has no currency pair to
    | read a news exposure off, so without this entry `NewsBlackout` would find no
    | currencies and never black out - and a US index through an FOMC decision is
    | precisely when you do not want to be entering.
    |
    */

    'indices' => [
        'US30' => 'USD',
        'DJ30' => 'USD',
        'WS30' => 'USD',
        'US500' => 'USD',
        'SPX' => 'USD',
        'SP500' => 'USD',
        'US100' => 'USD',
        'NAS' => 'USD',
        'USTEC' => 'USD',
        'US2000' => 'USD',
        'GER40' => 'EUR',
        'GER30' => 'EUR',
        'DE40' => 'EUR',
        'DAX' => 'EUR',
        'EU50' => 'EUR',
        'STOXX' => 'EUR',
        'FRA40' => 'EUR',
        'CAC' => 'EUR',
        'ESP35' => 'EUR',
        'UK100' => 'GBP',
        'FTSE' => 'GBP',
        'JP225' => 'JPY',
        'NIK' => 'JPY',
        'AUS200' => 'AUD',
        'HK50' => 'HKD',
        'CHINA50' => 'CNH',
    ],

    /*
    |--------------------------------------------------------------------------
    | Energy and softs
    |--------------------------------------------------------------------------
    |
    | Priced in dollars, so the US calendar is what moves them - plus their own
    | inventory releases, which the economic calendar feed does carry.
    |
    */

    'energy' => [
        'USOIL' => 'USD',
        'UKOIL' => 'USD',
        'WTI' => 'USD',
        'BRENT' => 'USD',
        'XTI' => 'USD',
        'XBR' => 'USD',
        'NGAS' => 'USD',
        'XNG' => 'USD',
    ],

    /*
    |--------------------------------------------------------------------------
    | Overrides
    |--------------------------------------------------------------------------
    |
    | For anything the rules get wrong. Broker naming is too varied to classify by
    | pattern alone, and a wrong classification is silent: an index treated as an FX
    | pair simply never blacks out for news.
    |
    | Shape: 'SYMBOL' => ['kind' => 'index', 'currencies' => ['USD']]
    | Valid kinds: fx, metal, index, energy, crypto
    |
    */

    'overrides' => [
        //
    ],

];

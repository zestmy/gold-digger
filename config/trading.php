<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queued strategy evaluation
    |--------------------------------------------------------------------------
    |
    | Whether a candle push evaluates strategies inside the request, or stores the bars,
    | answers, and hands the work to a queued job.
    |
    | Off by default, and the default is the safe one. WebRequest blocks the terminal's
    | event thread while the dashboard thinks, so at one symbol on M5 - a few hundred bars
    | of arithmetic once every five minutes - doing it inline costs nothing worth saving.
    |
    | Turning this on trades that latency for a dependency: **a queue worker must be
    | running**, or bars are stored, jobs pile up, and the bot silently stops trading. The
    | health monitor raises `queue_stalled` when that happens, which is the only reason
    | this switch is safe to offer at all.
    |
    |     php artisan queue:work --queue=strategy
    |
    | Turn it on when a single push starts doing real work: several symbols, several
    | strategies, or a faster entry timeframe.
    |
    */

    'queue_evaluation' => env('QUEUE_STRATEGY_EVALUATION', false),

    'queue' => env('STRATEGY_QUEUE', 'strategy'),

    /*
    |--------------------------------------------------------------------------
    | Queue backlog tolerance
    |--------------------------------------------------------------------------
    |
    | Seconds a job may sit unclaimed before the monitor calls the queue stalled. Needs to
    | exceed the entry timeframe comfortably, or an ordinary quiet spell between bars would
    | read as an outage.
    |
    */

    'queue_stale_after_seconds' => (int) env('STRATEGY_QUEUE_STALE_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | News blackout
    |--------------------------------------------------------------------------
    |
    | Which calendar entries are capable of stopping a trade. How *wide* the blackout is,
    | and whether it applies at all, are per-user settings on `bot_settings`; what is here
    | is the definition of an event worth caring about, which is a property of the
    | instrument rather than of the trader.
    |
    | `currencies` is USD because gold is priced in dollars: a euro-area release moves
    | XAUEUR, not XAUUSD. Add to this list before trading anything that is not.
    |
    | `impacts` is high only. Medium-impact releases are frequent enough that including
    | them turns a filter into a curfew - there are usually several a day - and the
    | evidence that they move gold beyond its ordinary noise is thin. Widen it and measure
    | with `php artisan backtest` rather than by argument; that is the whole reason the
    | filter was built to be replayable.
    |
    | See docs/NEWS_FILTER.md.
    |
    */

    'news' => [

        'currencies' => array_filter(array_map(
            'trim',
            explode(',', (string) env('NEWS_FILTER_CURRENCIES', 'USD')),
        )),

        'impacts' => array_filter(array_map(
            'trim',
            explode(',', (string) env('NEWS_FILTER_IMPACTS', 'high')),
        )),

        /*
        | Where the calendar comes from. The default feed is free, needs no key, and
        | publishes the current week; the importer is scheduled often enough that a revised
        | time is picked up before the event. A source that returns nothing leaves the
        | calendar as it was - see ImportMarketEvents for why that is the safe failure.
        */
        'calendar_url' => env('NEWS_CALENDAR_URL', 'https://nfs.faireconomy.media/ff_calendar_thisweek.json'),

        'calendar_timeout' => (int) env('NEWS_CALENDAR_TIMEOUT', 15),

        /*
        | Hours of *future* calendar the monitor expects to exist. Below this the filter is
        | silently doing nothing, which looks exactly like a market with no news in it - so
        | it is alerted on rather than inferred. Twelve hours survives a weekend of failed
        | imports without firing on the ordinary Friday-evening thinning of the feed.
        */
        'calendar_stale_after_hours' => (int) env('NEWS_CALENDAR_STALE_HOURS', 12),

    ],

];

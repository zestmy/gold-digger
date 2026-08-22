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

];

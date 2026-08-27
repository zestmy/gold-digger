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
    | The bar an entry has to clear
    |--------------------------------------------------------------------------
    |
    | Platform defaults for the three floors. Each is overridable per tenant in
    | `bot_settings`, and `min_confluence` is overridable again per provider in
    | `telegram_channels` - a strict account can still hold one channel to a stricter bar.
    |
    | These are the numbers that decide whether real money moves, so changing one is worth
    | measuring rather than arguing about: `php artisan backtest` replays a change over the
    | stored bars using the same evaluator that trades.
    |
    */

    /*
    | Weighted factors that must agree before an entry is taken - the SOP's "three
    | confluences" rule. Half-steps are meaningful: several factors carry a weight of 0.5
    | because they are half an observation rather than a whole one.
    */
    'min_confluence' => (float) env('MIN_CONFLUENCE', 3.0),

    /*
    | How much of that agreement has to be about *direction*.
    |
    | Without this the ambient factors alone - open session, no news due, ordinary
    | volatility, narrow bands - sum to exactly the confluence floor in a market where
    | nothing whatsoever agrees which way to trade. They are permission to trade, not a
    | reason to.
    */
    'min_directional' => (float) env('MIN_DIRECTIONAL', 1.5),

    /*
    | Minimum reward against risk, measured to the take-profit the order actually carries.
    |
    | Zero means no floor, and zero is the default. That is deliberate: switching one on
    | by default would start refusing trades that currently execute, on a live copier, on
    | the strength of a config change nobody read. Whether 1.5 is a sensible bar depends on
    | a win rate this project has not measured - a book winning 70% of the time is
    | profitable at 0.6R and a floor would simply stop it trading.
    |
    | Set it per tenant in `bot_settings.min_reward_ratio`, or here for the deployment.
    | See App\Services\Strategy\RewardFloor.
    */
    'min_reward_ratio' => (float) env('MIN_REWARD_RATIO', 0),

];

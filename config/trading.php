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

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | What `data:prune` deletes, and what it will not touch at any setting.
    |
    | Nothing here is pruned by default in the sense of "silently": the command runs on a
    | schedule, but every limit below is generous, and `--dry` reports exactly what would
    | go before anything does.
    |
    | ## Never pruned, at any setting
    |
    | `trades`, `trade_partials`, `trade_screenshots` and `daily_summaries` are the
    | financial record. `signals`, `telegram_signals` and `chart_analyses` are the evidence
    | for "was any of this any good" - which is the entire reason those three tables store
    | refusals as carefully as they store decisions. Deleting them to save disk would undo
    | the argument for having them.
    |
    */

    'retention' => [

        /*
        | Bars kept per series, where a series is one account's one symbol on one
        | timeframe.
        |
        | Counted in bars rather than in days, and that is the whole design. A 90-day
        | cutoff leaves M5 with 25,000 bars and H1 with 1,500 - the same policy starving
        | one timeframe while barely touching another. Bars are what the consumers actually
        | ask for, so bars are what is kept.
        |
        | The floor is set by the deepest consumer: `StrategyImprovement::DEFAULT_BARS` is
        | 20,000, and the walk-forward it feeds is the only evidence this project has about
        | whether any of its ideas make money. Trading itself needs 300. This default leaves
        | half again on top of the improver's window, which is about eight months of M5 or
        | three years of H1.
        |
        | Raise it to backtest over more history; it costs roughly 240 bytes a bar per
        | series. Set it to 0 to keep everything and accept unbounded growth - which is
        | where this table was before, at 91% of the database on a box with 2GB of RAM.
        */
        'candle_bars_per_series' => (int) env('RETAIN_CANDLE_BARS', 30000),

        /*
        | Executor and monitor output. High volume, no analytical value once read, and the
        | incidents themselves live in `alerts` regardless.
        */
        'bot_log_days' => (int) env('RETAIN_BOT_LOG_DAYS', 60),

        /*
        | Model spend. Long enough to reconcile an annual bill and then some, because this
        | is the table an invoice dispute would be settled from.
        */
        'ai_usage_days' => (int) env('RETAIN_AI_USAGE_DAYS', 400),

        /*
        | Only incidents that resolved. A firing alert is never pruned however old it is -
        | age is the most interesting thing about an outage nobody has fixed.
        */
        'resolved_alert_days' => (int) env('RETAIN_RESOLVED_ALERT_DAYS', 90),

        /*
        | Past economic releases. The blackout filter only looks forward and a little way
        | back, so an event from last year is inert weight.
        */
        'economic_event_days' => (int) env('RETAIN_ECONOMIC_EVENT_DAYS', 90),
    ],

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenRouter
    |--------------------------------------------------------------------------
    |
    | Off unless an API key is present, the same way alerting is. An unconfigured
    | analyst is not an error - the card says so and the rest of the dashboard is
    | unaffected.
    |
    | OpenRouter rather than a provider SDK because it puts every model behind one key
    | and one wire format, so changing model is an .env edit rather than a deploy. The
    | API is OpenAI-shaped; `response_format: json_schema` is what keeps the analysis
    | in two separate fields instead of one paragraph.
    |
    | Note what this sends: indicator readings, the symbol, recent skip reasons, and the
    | balance the position sizer works from. No broker credentials and no account number,
    | but it is not nothing, and it leaves your server.
    |
    */

    'key' => env('OPENROUTER_API_KEY'),

    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),

    /*
    | Model for the written analysis. Summarising numbers the dashboard already computed
    | is not a reasoning problem, so a mid-tier model is the right default; the strategy
    | proposer below is a different matter and has its own setting.
    */
    'model' => env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-5'),

    /*
    | Reading a signal off a screenshot. A separate key because transcription and
    | judgement are different jobs and the cheaper model is often better at the first.
    */
    'vision_model' => env('OPENROUTER_VISION_MODEL', 'anthropic/claude-sonnet-5'),

    /*
    | Model for proposing strategy parameters. This one is genuinely reasoning about
    | indicator behaviour and trade statistics, and its output is expensive to get wrong
    | in wasted backtest time, so it defaults to the same capable model rather than a
    | cheaper one.
    */
    'proposer_model' => env('OPENROUTER_PROPOSER_MODEL', 'anthropic/claude-sonnet-5'),

    /*
    | Model for judging somebody else's signal: the copier's reviewer, the follow-up
    | interpreter, the edit interpreter.
    |
    | It exists as its own key because these three are the only callers that read a
    | stranger's words and then move a real position, which is a different job from
    | summarising numbers this system already computed. They shared `model` with the
    | analysis cards by accident rather than by decision.
    |
    | It defaults to `model`, so naming it changes nothing until somebody chooses to
    | point it somewhere more capable - `anthropic/claude-opus-5` is the intended
    | upgrade path, and it costs more per call. Making that a deliberate .env edit is
    | the point of the key.
    */
    'reviewer_model' => env('OPENROUTER_REVIEWER_MODEL', env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-5')),

    /*
    | Sent as attribution headers. OpenRouter shows these on the activity page, which is
    | how you tell the dashboard's spend apart from anything else on the same key.
    */
    'referer' => env('OPENROUTER_REFERER', env('APP_URL', 'https://fxsignal.pro')),

    'title' => env('OPENROUTER_TITLE', 'FXSignalPro'),

    'timeout' => (int) env('OPENROUTER_TIMEOUT', 90),

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Analyses are cached against the newest bar, so a dashboard left open all day
    | costs one call per bar at most rather than one per poll. Refresh on the card
    | bypasses it.
    |
    */

    'cache_minutes' => (int) env('AI_CACHE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Spend limits
    |--------------------------------------------------------------------------
    |
    | Every model call in this application is made on one platform API key, so every
    | tenant's analysis is billed to whoever runs the deployment. For one operator that
    | is simply their own bill. For a product it is unbounded cost of goods, and this is
    | the ceiling on it.
    |
    | Counted per tenant per day, and per request rather than per dollar - a call's price
    | is not knowable until after the model has decided how much to write, which is too
    | late to refuse it. The recorded cost in `ai_usage` is what turns these counts into
    | money afterwards; see App\Services\Ai\AiSpend.
    |
    | A tenant can be given their own ceiling in `bot_settings.ai_daily_call_limit`, which
    | is where a paid plan's entitlement belongs. This is the default for everyone else.
    |
    | Sizing note: the copier's reviewer is the hungry one. It runs on every captured
    | signal, on a per-minute schedule, so a tenant following several busy channels can
    | reach three figures in a day without ever opening the dashboard.
    |
    */

    'limits' => [
        'daily_calls' => (int) env('AI_DAILY_CALL_LIMIT', 200),
    ],

];

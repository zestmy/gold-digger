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
    'model' => env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-4.5'),

    /*
    | Model for proposing strategy parameters. This one is genuinely reasoning about
    | indicator behaviour and trade statistics, and its output is expensive to get wrong
    | in wasted backtest time, so it defaults to the same capable model rather than a
    | cheaper one.
    */
    /*
    | Reading a signal off a screenshot. A separate key because transcription and
    | judgement are different jobs and the cheaper model is often better at the first.
    */
    'vision_model' => env('OPENROUTER_VISION_MODEL', 'anthropic/claude-sonnet-4.5'),

    'proposer_model' => env('OPENROUTER_PROPOSER_MODEL', 'anthropic/claude-sonnet-4.5'),

    /*
    | Sent as attribution headers. OpenRouter shows these on the activity page, which is
    | how you tell the dashboard's spend apart from anything else on the same key.
    */
    'referer' => env('OPENROUTER_REFERER', env('APP_URL', 'https://fx.affandy.com')),

    'title' => env('OPENROUTER_TITLE', 'Gold Digger'),

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

];

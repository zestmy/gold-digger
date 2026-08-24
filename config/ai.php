<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Claude
    |--------------------------------------------------------------------------
    |
    | Off unless an API key is present, the same way alerting is. An unconfigured
    | analyst is not an error - the card says so and the rest of the dashboard is
    | unaffected.
    |
    | Note what this sends to Anthropic: indicator readings, the symbol, recent skip
    | reasons, and the account balance the position sizer works from. No broker
    | credentials and no account number, but it is not nothing.
    |
    */

    'key' => env('ANTHROPIC_API_KEY'),

    'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

    /*
    |--------------------------------------------------------------------------
    | Effort
    |--------------------------------------------------------------------------
    |
    | How hard the model works on each analysis. This is a summary of numbers the
    | dashboard has already computed rather than a research problem, so `medium` is
    | the default rather than the API's `high`: the reading is largely mechanical and
    | only the outlook calls for judgement.
    |
    | Raise it if the analyses read as shallow. `low` is defensible if you are
    | generating them frequently.
    |
    */

    'effort' => env('ANTHROPIC_EFFORT', 'medium'),

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

    'cache_minutes' => (int) env('ANTHROPIC_CACHE_MINUTES', 15),

];

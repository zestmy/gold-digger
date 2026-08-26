<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform MTProto application
    |--------------------------------------------------------------------------
    |
    | One application, registered once at https://my.telegram.org, shared by every
    | tenant. Asking each of them to register their own was the largest single drop-off
    | in onboarding: it needs a Telegram account, a web form and a wait, all before the
    | product has done anything for them.
    |
    | The trade is that Telegram rate-limits and bans per application, not per user. If
    | this one is throttled, every tenant is throttled at once - so it is worth watching,
    | and worth keeping the per-account override below as the escape hatch.
    */

    'app_id' => env('TELEGRAM_APP_ID'),

    'app_hash' => env('TELEGRAM_APP_HASH'),

    /*
    |--------------------------------------------------------------------------
    | Session worker credential
    |--------------------------------------------------------------------------
    |
    | The worker signs tenants in and holds their sessions, so this token reaches every
    | hosted account at once. That makes it infrastructure - closer to the database
    | password than to a bot token. It is set by whoever deploys the platform, is never
    | issued through the dashboard, and is never shown to a user.
    |
    | Unset means no worker: the endpoints refuse everything rather than falling open.
    */

    'worker_token' => env('TELEGRAM_WORKER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Hosting
    |--------------------------------------------------------------------------
    |
    | Whether a newly added account is signed in by the platform's worker or by a
    | collector the tenant runs themselves. Hosted is the SaaS path and the default;
    | self-hosted remains for anyone unwilling to hand over a session, which is a
    | reasonable thing to be unwilling to do.
    */

    'hosted_by_default' => (bool) env('TELEGRAM_HOSTED_BY_DEFAULT', true),

    /*
    |--------------------------------------------------------------------------
    | Bot API sources
    |--------------------------------------------------------------------------
    |
    | Chat id => the address a forwarded message must have come from, for the Bot API
    | feeder. Unrelated to the MTProto settings above and read by SignalIngest, which
    | defaults to an empty list - so this being empty means "accept none", not "accept
    | all".
    */

    'sources' => [
        //
    ],

];

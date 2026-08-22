<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    |
    | Where alerts go. Off unless both values are present - an unconfigured channel is
    | not an error, it just means incidents are recorded in `alerts` and on `/logs`
    | without reaching anyone.
    |
    | To set it up: message @BotFather to create a bot and get the token, then send your
    | new bot a message and read the chat id from
    | https://api.telegram.org/bot<TOKEN>/getUpdates
    |
    | Note that this sends the alert text to Telegram's servers. The messages carry
    | balances, symbols and P&L figures - nothing that identifies an account to a broker,
    | but not nothing either.
    |
    */

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Repeat interval
    |--------------------------------------------------------------------------
    |
    | Minutes before a still-firing incident is sent again. A condition true for a day
    | should not produce a message a minute; one that produces a single message on day
    | one is easy to miss. So it repeats, slowly.
    |
    */

    'repeat_after_minutes' => (int) env('ALERT_REPEAT_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Request timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait on the notification API. Deliberately short: the monitor runs on a
    | schedule and a hanging channel must not hold it open.
    |
    */

    'timeout' => (int) env('ALERT_TIMEOUT', 8),

];

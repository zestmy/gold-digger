<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Signal sources
    |--------------------------------------------------------------------------
    |
    | Chats the copier will accept signals from, and the account each trades for.
    |
    | This is a security boundary, not a convenience. A Telegram bot is publicly
    | reachable - anyone who finds it can send it a message - so a copier that traded
    | whatever arrived would be a remote trade-execution endpoint on a live account,
    | authenticated by nothing.
    |
    | Messages from any other chat are still recorded, because somebody talking to the
    | bot is worth being able to see, but they are never parsed, reviewed or executed.
    |
    | The operator's own alert chat (TELEGRAM_CHAT_ID) is accepted without appearing
    | here, being the one chat already known to belong to them.
    |
    | Shape: '-1001234567890' => 'you@example.com'
    |
    */

    'sources' => [
        //
    ],

];

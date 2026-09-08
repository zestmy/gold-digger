<?php

/*
|--------------------------------------------------------------------------
| Signal outcome tracking
|--------------------------------------------------------------------------
|
| Every signal - the strategy's own and every parsed copied one, traded or not - has its
| forward path measured from the bars the terminal pushes afterward. See
| docs/SIGNAL_OUTCOMES.md.
|
*/

return [
    // How many bars of the signal's timeframe a signal is followed before it is called
    // "expired" with neither level touched. A hundred M5 bars is about eight trading
    // hours: long enough for a scalp to resolve, short enough that a level touched the
    // next day is not credited to a setup that had long since stopped being about it.
    'horizon_bars' => (int) env('OUTCOME_HORIZON_BARS', 100),

    // A copied signal names no timeframe, so it is measured on this one. The instrument's
    // bars have to be stored for the subscriber's account on this timeframe or the signal
    // cannot be followed at all - which is recorded, not guessed around.
    'copied_timeframe' => env('OUTCOME_COPIED_TIMEFRAME', 'M5'),

    // How far back the tracker looks for signals that were never opened for tracking -
    // rows that predate this feature, or that were written while it was off.
    'backfill_days' => (int) env('OUTCOME_BACKFILL_DAYS', 30),
];

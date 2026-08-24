<?php

namespace App\Services\Strategy;

/**
 * Timeframe
 *
 * How long an MT5 timeframe name is, in seconds.
 *
 * Extracted because two callers now need it for different reasons and a second copy would
 * drift: `HealthMonitor` uses it to decide when a feed has stalled, and `NewsBlackout` uses it
 * to turn a bar's open time into the interval the bar actually covers. A disagreement between
 * those two about how long M15 is would be invisible until it mattered.
 */
final class Timeframe
{
    /**
     * Seconds in one bar of `$timeframe`, defaulting to five minutes on anything unparseable.
     *
     * The default is deliberately a real timeframe rather than zero or an exception. Every
     * caller is in a position where the answer is used to size a comparison window, and a
     * window of zero silently disables the check that asked for it - which is the failure
     * mode worth avoiding, given the callers are a stall detector and a trading filter.
     */
    public static function seconds(string $timeframe): int
    {
        $timeframe = strtoupper(trim($timeframe));

        $unit = substr($timeframe, 0, 1);
        $count = (int) substr($timeframe, 1);

        if ($count < 1) {
            return 300;
        }

        return match ($unit) {
            'M' => $count * 60,
            'H' => $count * 3600,
            'D' => $count * 86400,
            'W' => $count * 604800,
            default => 300,
        };
    }
}

<?php

namespace App\Support;

/**
 * The length of one bar.
 *
 * MetaTrader names timeframes as a unit letter and a count - M5, H1, D1. Three services
 * had grown their own copy of this arithmetic; this is the one they should share.
 */
final class Timeframe
{
    public static function seconds(string $timeframe): int
    {
        $timeframe = strtoupper(trim($timeframe));
        $count = (int) substr($timeframe, 1);

        if ($count < 1) {
            return 300;
        }

        return match (substr($timeframe, 0, 1)) {
            'M' => $count * 60,
            'H' => $count * 3600,
            'D' => $count * 86400,
            'W' => $count * 604800,
            default => 300,
        };
    }
}

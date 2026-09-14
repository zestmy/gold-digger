<?php

namespace App\Services\Trading;

use App\Models\BotSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Rollover Window
 *
 * The minutes before the broker's daily rollover, in which nothing new is opened and what
 * is open is closed.
 *
 * ## What it is for
 *
 * Three costs a position pays for being carried through the rollover, none of which a
 * backtest of this system has ever charged it:
 *
 * - **Swap**, which `docs/BACKTESTING.md` lists among the things it does not model. A
 *   strategy that holds overnight has therefore always looked better here than it traded.
 * - **The spread**, which on gold widens to several times its daytime figure for the
 *   minutes around the break and is exactly when a stop is most likely to be taken out by
 *   a price nobody traded at.
 * - **The gap**, because the bar after a break is the one place "the next bar's open" can
 *   be a long way from the last close.
 *
 * Closing before it is the cheapest of the available answers: it costs one spread, it is
 * decided by the clock rather than by a model, and it needs nothing from the executor that
 * a time exit does not already need.
 *
 * ## The time is the broker's, and has no default
 *
 * `rollover_at` is configured, not inferred. Elev8 rolls at 21:00 UTC; another broker does
 * not, and `HealthMonitor` already declines to hardcode a break window for that reason.
 * Guessing it would close positions at an hour nobody chose, which is worse than not
 * closing them at all. Null means the whole behaviour is off, and so does a zero window -
 * the hour can be recorded before the policy is adopted.
 *
 * ## The window wraps
 *
 * A 21:00 rollover with a 15-minute window runs 20:45-21:00. A 00:15 rollover with a
 * 30-minute window runs 23:45-00:15, which is yesterday for most of its length. Both are
 * the same arithmetic once the rollover is placed on the same day as the instant being
 * judged and then moved back a day when that puts it in the future.
 */
final class RolloverWindow
{
    /**
     * Is this instant inside the window before the next rollover?
     *
     * The instant is normalised to UTC, because `rollover_at` is stored in UTC: the broker's
     * server day is not the user's, and a window that moved with the reader's timezone
     * would close positions at a different hour for every one of them.
     */
    public function isOpen(?BotSettings $settings, CarbonInterface $at): bool
    {
        $minutes = (int) ($settings?->flat_before_rollover_minutes ?? 0);

        if ($minutes <= 0) {
            return false;
        }

        $rollover = $this->rolloverFor($settings, $at);

        if ($rollover === null) {
            return false;
        }

        $moment = Carbon::instance($at)->utc();

        return $moment->greaterThanOrEqualTo($rollover->copy()->subMinutes($minutes))
            && $moment->lessThan($rollover);
    }

    /**
     * The next rollover at or after this instant, or null when none is configured.
     *
     * Today's rollover if it has not happened yet, tomorrow's if it has. The window is
     * measured backwards from whichever that is.
     */
    public function rolloverFor(?BotSettings $settings, CarbonInterface $at): ?Carbon
    {
        $time = $this->parse($settings?->rollover_at);

        if ($time === null) {
            return null;
        }

        $moment = Carbon::instance($at)->utc();

        $rollover = $moment->copy()->setTime($time[0], $time[1], 0);

        return $rollover->lessThanOrEqualTo($moment)
            ? $rollover->addDay()
            : $rollover;
    }

    /**
     * "21:00" as [21, 0], or null if it is not a time.
     *
     * @return array{0: int, 1: int}|null
     */
    private function parse(?string $value): ?array
    {
        if ($value === null || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($value), $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }
}

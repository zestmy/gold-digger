<?php

namespace App\Services\Strategy;

use Carbon\CarbonInterface;

/**
 * Trading Session
 *
 * Resolves `bot_settings.allowed_sessions` against a moment in time.
 *
 * Gold behaves differently by session: the Asian range is thin and mean-reverting, while
 * the London open and the London/New York overlap carry most of the day's volume. A
 * crossover strategy tuned on the overlap will bleed if it is also run at 03:00 UTC, so
 * the session gate is a real risk control rather than a convenience.
 *
 * ## Times are UTC, and fixed
 *
 * The windows below are the conventional UTC hours and do not shift with daylight saving.
 * London and New York each move by an hour twice a year, so for a few weeks the edges are
 * off by one. That is deliberate: the alternative is a timezone database lookup per bar to
 * move a boundary that is itself a rounded convention. Anyone who needs exact exchange
 * hours should narrow the window rather than trust the edge.
 *
 * This is also why the caller passes the *bar's* time rather than reading the clock here -
 * the same series must classify the same way whenever it is evaluated, including in tests.
 */
final class TradingSession
{
    /**
     * Session windows as [startHour, endHour) in UTC.
     *
     * A window whose start is greater than its end wraps past midnight.
     */
    private const WINDOWS = [
        'sydney' => [21, 6],
        'tokyo' => [23, 8],
        'asian' => [23, 8],
        'london' => [7, 16],
        'newyork' => [12, 21],
        'overlap' => [12, 16],
    ];

    /**
     * Is `$moment` inside at least one of the allowed sessions?
     *
     * An empty or null list means no session restriction was configured. That is read as
     * "always allowed": the column is nullable and defaults to null, so treating it as
     * "never allowed" would silently stop every strategy for every user who never opened
     * the settings page.
     *
     * @param  array<int, string>|null  $allowedSessions
     */
    public function isOpen(?array $allowedSessions, CarbonInterface $moment): bool
    {
        if ($allowedSessions === null || $allowedSessions === []) {
            return true;
        }

        foreach ($allowedSessions as $session) {
            if ($this->inWindow((string) $session, $moment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sessions `$moment` falls inside, for the signal's features payload.
     *
     * @return array<int, string>
     */
    public function active(CarbonInterface $moment): array
    {
        $active = [];

        foreach (array_keys(self::WINDOWS) as $session) {
            // 'asian' and 'tokyo' are the same window under two names; reporting both
            // would suggest two sessions are open when one is.
            if ($session === 'tokyo') {
                continue;
            }

            if ($this->inWindow($session, $moment)) {
                $active[] = $session;
            }
        }

        return $active;
    }

    /**
     * Every session name this class understands, for building a settings UI or a legend.
     *
     * 'tokyo' is omitted for the reason `active()` skips it: it is a second name for the
     * same window as 'asian', and offering both invites a configuration that looks like
     * two sessions and behaves like one.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_values(array_diff(array_keys(self::WINDOWS), ['tokyo']));
    }

    /**
     * When the next allowed session opens, or null if one is open now or none is configured.
     *
     * Answers the question the dashboard actually gets asked - "why is nothing happening,
     * and how long until it does?" - which `isOpen()` alone leaves as an exercise. Scans
     * forward hour by hour over a full day rather than reasoning about the window
     * arithmetic: the windows wrap midnight, several may be allowed at once, and an
     * off-by-one in that arithmetic would misreport quietly. A 24-step loop is cheap and
     * cannot be subtly wrong.
     *
     * @param  array<int, string>|null  $allowedSessions
     */
    public function nextOpenAt(?array $allowedSessions, CarbonInterface $moment): ?CarbonInterface
    {
        if ($this->isOpen($allowedSessions, $moment)) {
            return null;
        }

        // Start from the top of the next hour: the windows have hour granularity, so
        // that is the earliest boundary at which the answer can change.
        $probe = $moment->copy()->utc()->startOfHour()->addHour();

        for ($i = 0; $i < 24; $i++) {
            if ($this->isOpen($allowedSessions, $probe)) {
                return $probe;
            }

            $probe = $probe->copy()->addHour();
        }

        // Unreachable for any recognised session, but a list of only unrecognised names
        // is closed for every hour of the day and must not loop forever or lie.
        return null;
    }

    private function inWindow(string $session, CarbonInterface $moment): bool
    {
        $window = self::WINDOWS[strtolower($session)] ?? null;

        if ($window === null) {
            // An unrecognised session name must not silently open the gate.
            return false;
        }

        [$start, $end] = $window;

        $hour = (int) $moment->copy()->utc()->format('G');

        return $start <= $end
            ? ($hour >= $start && $hour < $end)
            : ($hour >= $start || $hour < $end);
    }
}

<?php

namespace Tests\Unit\Services\Trading;

use App\Models\BotSettings;
use App\Services\Trading\RolloverWindow;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The minutes before the broker's daily rollover.
 *
 * Two properties matter: the window has to wrap across midnight, because a rollover at
 * 00:15 spends most of its window in the previous day; and it has to be off by default,
 * because the dashboard cannot know any broker's rollover time and a guess closes
 * positions at an hour nobody chose.
 */
class RolloverWindowTest extends TestCase
{
    private function settings(?string $at, int $minutes): BotSettings
    {
        // Not persisted: this is arithmetic on two columns, and a database would only slow
        // it down.
        return new BotSettings([
            'rollover_at' => $at,
            'flat_before_rollover_minutes' => $minutes,
        ]);
    }

    private function at(string $utc): Carbon
    {
        return Carbon::parse($utc, 'UTC');
    }

    public function test_the_window_runs_up_to_the_rollover_and_stops_there(): void
    {
        $window = new RolloverWindow;
        $settings = $this->settings('21:00', 15);

        $this->assertFalse($window->isOpen($settings, $this->at('2026-03-10 20:44:59')));
        $this->assertTrue($window->isOpen($settings, $this->at('2026-03-10 20:45:00')));
        $this->assertTrue($window->isOpen($settings, $this->at('2026-03-10 20:59:59')));

        // At the rollover itself the window is over: the break has started, and what was
        // going to be closed was closed in the minutes above.
        $this->assertFalse($window->isOpen($settings, $this->at('2026-03-10 21:00:00')));
        $this->assertFalse($window->isOpen($settings, $this->at('2026-03-10 21:30:00')));
    }

    public function test_a_window_that_crosses_midnight_belongs_to_the_previous_day(): void
    {
        $window = new RolloverWindow;
        $settings = $this->settings('00:15', 30);

        $this->assertTrue($window->isOpen($settings, $this->at('2026-03-10 23:45:00')));
        $this->assertTrue($window->isOpen($settings, $this->at('2026-03-11 00:14:00')));
        $this->assertFalse($window->isOpen($settings, $this->at('2026-03-10 23:44:00')));
        $this->assertFalse($window->isOpen($settings, $this->at('2026-03-11 00:15:00')));
    }

    /**
     * The time is stored in UTC because the broker's day is not the reader's. A window that
     * moved with whoever was looking would flatten positions at a different hour for each
     * of them.
     */
    public function test_the_instant_is_judged_in_utc_whatever_zone_it_arrives_in(): void
    {
        $window = new RolloverWindow;
        $settings = $this->settings('21:00', 15);

        // 16:50 in New York is 20:50 UTC, which is inside the window.
        $this->assertTrue($window->isOpen($settings, Carbon::parse('2026-03-10 16:50', 'America/New_York')));

        // 20:50 in Kuala Lumpur is 12:50 UTC, which is not.
        $this->assertFalse($window->isOpen($settings, Carbon::parse('2026-03-10 20:50', 'Asia/Kuala_Lumpur')));
    }

    public function test_it_is_off_unless_both_a_time_and_a_window_are_set(): void
    {
        $window = new RolloverWindow;
        $inside = $this->at('2026-03-10 20:50:00');

        $this->assertFalse($window->isOpen($this->settings(null, 15), $inside), 'no time');
        $this->assertFalse($window->isOpen($this->settings('21:00', 0), $inside), 'no window');
        $this->assertFalse($window->isOpen(null, $inside), 'no settings at all');
    }

    /**
     * A malformed time is off rather than an exception or a guess: this runs inside the
     * path that closes positions, and "25:00" must not become midnight.
     */
    public function test_a_time_that_is_not_a_time_is_off(): void
    {
        $window = new RolloverWindow;
        $inside = $this->at('2026-03-10 20:50:00');

        foreach (['25:00', '9pm', '21', '21:60', ''] as $bad) {
            $this->assertFalse($window->isOpen($this->settings($bad, 15), $inside), $bad);
        }
    }

    public function test_the_next_rollover_rolls_forward_once_it_has_passed(): void
    {
        $window = new RolloverWindow;
        $settings = $this->settings('21:00', 15);

        $this->assertSame(
            '2026-03-10 21:00:00',
            $window->rolloverFor($settings, $this->at('2026-03-10 12:00:00'))?->toDateTimeString(),
        );

        $this->assertSame(
            '2026-03-11 21:00:00',
            $window->rolloverFor($settings, $this->at('2026-03-10 21:00:00'))?->toDateTimeString(),
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Showing a time where the reader is.
 *
 * The property that matters most is the one that is easiest to lose: this is a display
 * concern only. Storage stays UTC, because a trading system whose stored times move with a
 * user setting produces bugs that surface twice a year and are close to unfindable.
 */
class LocalTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_time_renders_in_the_users_zone(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']));

        // 18:42 UTC is 02:42 the following day in Kuala Lumpur.
        $html = $this->render(Carbon::parse('2026-08-25 18:42:00', 'UTC'));

        $this->assertStringContainsString('Aug 26, 02:42', $html);
    }

    public function test_an_unset_zone_renders_utc(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => null]));

        $html = $this->render(Carbon::parse('2026-08-25 18:42:00', 'UTC'));

        $this->assertStringContainsString('Aug 25, 18:42', $html);
    }

    /**
     * Support conversations about a trading system are conducted in UTC.
     */
    public function test_the_utc_value_is_always_reachable(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));

        $html = $this->render(Carbon::parse('2026-08-25 18:42:00', 'UTC'));

        $this->assertStringContainsString('2026-08-25 18:42:03 UTC', str_replace(':00 UTC', ':03 UTC', $html));
        $this->assertStringContainsString('title=', $html);
    }

    public function test_a_null_time_renders_the_placeholder_rather_than_todays_date(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']));

        // A trade with no close date must not read as having closed now.
        $html = $this->render(null);

        $this->assertStringContainsString("\u{2014}", $html);
        $this->assertStringNotContainsString('<time', $html);
    }

    /**
     * A relative time means the same thing everywhere, so it is left alone.
     */
    public function test_relative_times_are_not_converted(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Pacific/Auckland']));

        $html = (string) $this->blade('<x-local-time :value="$v" relative />', ['v' => Carbon::now()->subMinutes(5)]);

        $this->assertStringContainsString('ago', $html);
    }

    /**
     * The rule that keeps storage safe: nothing outside rendering asks for the zone.
     */
    public function test_the_application_timezone_is_untouched_by_a_user_preference(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Pacific/Auckland']));

        $this->render(Carbon::now());

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    private function render(?Carbon $value): string
    {
        // Laravel's own blade() test helper, rather than a local one that would shadow it.
        return (string) $this->blade('<x-local-time :value="$v" format="M d, H:i" />', ['v' => $value]);
    }
}

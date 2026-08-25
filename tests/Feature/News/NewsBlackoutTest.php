<?php

namespace Tests\Feature\News;

use App\Models\BotSettings;
use App\Models\EconomicEvent;
use App\Models\User;
use App\Services\News\CalendarFeed;
use App\Services\News\NewsBlackout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The news filter.
 *
 * `bot_settings.news_filter_enabled` shipped in the first migration, defaulted to true,
 * and was displayed as an enabled toggle while nothing enforced it. These pin down that
 * it now does, and - more importantly - that it fails in the safe direction when the
 * calendar is not there.
 */
class NewsBlackoutTest extends TestCase
{
    use RefreshDatabase;

    private NewsBlackout $blackout;

    private BotSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->blackout = new NewsBlackout;

        $user = User::factory()->create();
        $this->settings = BotSettings::where('user_id', $user->id)->firstOrFail();
        $this->settings->update([
            'news_filter_enabled' => true,
            'news_blackout_before_minutes' => 15,
            'news_blackout_after_minutes' => 15,
        ]);
    }

    private function event(array $overrides = []): EconomicEvent
    {
        return EconomicEvent::create($overrides + [
            'external_id' => bin2hex(random_bytes(16)),
            'title' => 'Non-Farm Employment Change',
            'currency' => 'USD',
            'impact' => 'high',
            'scheduled_at' => Carbon::parse('2026-09-04 12:30:00', 'UTC'),
            'fetched_at' => now(),
        ]);
    }

    public function test_a_high_impact_release_blacks_out_the_window_around_it(): void
    {
        $this->event();

        foreach (['12:16', '12:30', '12:44'] as $time) {
            $this->assertSame(
                NewsBlackout::REASON_BLACKOUT,
                $this->blackout->objection($this->settings, ['XAU', 'USD'], Carbon::parse("2026-09-04 {$time}", 'UTC')),
                "{$time} should be inside the blackout",
            );
        }
    }

    public function test_the_window_has_edges(): void
    {
        $this->event();

        foreach (['12:14', '12:46'] as $time) {
            $this->assertNull(
                $this->blackout->objection($this->settings, ['XAU', 'USD'], Carbon::parse("2026-09-04 {$time}", 'UTC')),
                "{$time} should be outside the blackout",
            );
        }
    }

    public function test_only_the_instruments_own_currencies_matter(): void
    {
        // A Japanese release does not move gold's dollar leg the way an FOMC decision does,
        // and blacking out on every currency's calendar would close most of the day.
        $this->event(['currency' => 'JPY']);

        $this->assertNull(
            $this->blackout->objection($this->settings, ['XAU', 'USD'], Carbon::parse('2026-09-04 12:30', 'UTC')),
        );
    }

    public function test_medium_impact_releases_do_not_gate_trading(): void
    {
        $this->event(['impact' => 'medium']);

        $this->assertNull(
            $this->blackout->objection($this->settings, ['XAU', 'USD'], Carbon::parse('2026-09-04 12:30', 'UTC')),
        );
    }

    public function test_the_filter_does_nothing_when_switched_off(): void
    {
        $this->event();
        $this->settings->update(['news_filter_enabled' => false]);

        $this->assertNull(
            $this->blackout->objection($this->settings, ['XAU', 'USD'], Carbon::parse('2026-09-04 12:30', 'UTC')),
        );
    }

    /**
     * The decision this whole class turns on.
     */
    public function test_it_fails_closed_when_the_calendar_is_missing(): void
    {
        // No events at all: the filter is on and cannot be evaluated. Waving the trade
        // through would make an advertised risk control silently absent, which is the
        // failure that gets budgeted for.
        $this->assertSame(
            NewsBlackout::REASON_STALE,
            $this->blackout->objection($this->settings, ['XAU', 'USD'], Carbon::parse('2026-09-04 12:30', 'UTC')),
        );
    }

    public function test_it_fails_closed_when_the_calendar_has_gone_stale(): void
    {
        $this->event(['fetched_at' => now()->subHours(NewsBlackout::STALE_AFTER_HOURS + 1)]);
        Cache::flush();

        $this->assertSame(
            NewsBlackout::REASON_STALE,
            $this->blackout->objection($this->settings, ['XAU', 'USD'], Carbon::parse('2026-09-04 20:00', 'UTC')),
        );
    }

    public function test_stale_is_reported_separately_from_a_blackout(): void
    {
        // Identical consequence, completely different remedy. Collapsing them into one
        // reason would send someone to wait out a release that is not happening.
        $this->assertNotSame(NewsBlackout::REASON_BLACKOUT, NewsBlackout::REASON_STALE);
    }

    public function test_currencies_are_read_off_the_symbol_including_broker_suffixes(): void
    {
        // XAU is no longer returned alongside USD. The metal has no economic calendar, so
        // querying for it was always querying for nothing - the result set is identical
        // and the intent is now legible. InstrumentProfile owns this since the same
        // question has to be answerable for indices, which have no pair to read at all.
        $this->assertSame(['USD'], $this->blackout->currenciesFor('XAUUSD'));
        $this->assertSame(['USD'], $this->blackout->currenciesFor('XAUUSDm'));
        $this->assertSame(['USD'], $this->blackout->currenciesFor('XAUUSD.a'));
        $this->assertSame(['EUR', 'USD'], $this->blackout->currenciesFor('EURUSD'));
        $this->assertSame([], $this->blackout->currenciesFor('GOLD'));
    }

    public function test_a_zero_width_window_blocks_nothing(): void
    {
        $this->event();
        $this->settings->update([
            'news_blackout_before_minutes' => 0,
            'news_blackout_after_minutes' => 0,
        ]);

        $this->assertNull(
            $this->blackout->objection($this->settings, ['XAU', 'USD'], Carbon::parse('2026-09-04 12:30', 'UTC')),
        );
    }

    public function test_a_failed_fetch_leaves_the_stored_calendar_alone(): void
    {
        $existing = $this->event();

        Http::fake([CalendarFeed::URL => Http::response('', 429)]);

        $result = (new CalendarFeed)->refresh();

        $this->assertFalse($result['ok']);
        $this->assertDatabaseHas('economic_events', ['id' => $existing->id]);
        $this->assertSame(1, EconomicEvent::count());
    }

    public function test_an_empty_response_is_treated_as_a_failure_not_an_empty_week(): void
    {
        // Otherwise fetched_at advances on nothing and stale data reads as fresh, which
        // is the one way this filter could silently stop protecting anything.
        Http::fake([CalendarFeed::URL => Http::response([], 200)]);

        $this->assertFalse((new CalendarFeed)->refresh()['ok']);
    }

    public function test_it_imports_the_feed_and_normalises_it(): void
    {
        Http::fake([CalendarFeed::URL => Http::response([
            ['title' => 'Non-Farm Employment Change', 'country' => 'USD', 'date' => '2026-09-04T08:30:00-04:00', 'impact' => 'High', 'forecast' => '165K', 'previous' => '142K'],
            ['title' => 'Bank Holiday', 'country' => 'EUR', 'date' => '2026-09-04T03:00:00-04:00', 'impact' => 'Holiday'],
            ['title' => 'Broken row with no date', 'country' => 'GBP'],
        ], 200)]);

        $result = (new CalendarFeed)->refresh();

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['skipped'], 'The row with no date should be skipped, not fatal.');

        $nfp = EconomicEvent::where('currency', 'USD')->firstOrFail();
        $this->assertSame('high', $nfp->impact);
        // -04:00 offset normalised to UTC on the way in.
        $this->assertSame('2026-09-04 12:30:00', $nfp->scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($nfp->actual, 'actual is absent until a release prints.');
    }

    public function test_refetching_the_same_week_updates_rather_than_duplicates(): void
    {
        $feed = ['title' => 'Non-Farm Employment Change', 'country' => 'USD', 'date' => '2026-09-04T08:30:00-04:00', 'impact' => 'High', 'forecast' => '165K'];

        // One sequence for both fetches: Http::fake() appends stubs and the first match
        // wins, so a second fake() for the same URL would never be reached.
        Http::fakeSequence(CalendarFeed::URL)
            ->push([$feed], 200)
            // The same event an hour later, now with an actual printed.
            ->push([$feed + ['actual' => '150K']], 200);

        (new CalendarFeed)->refresh();
        (new CalendarFeed)->refresh();

        $this->assertSame(1, EconomicEvent::count(), 'A revision must not appear as a second event.');
        $this->assertSame('150K', EconomicEvent::first()->actual);
    }

    public function test_an_unrecognised_impact_never_becomes_high(): void
    {
        Http::fake([CalendarFeed::URL => Http::response([
            ['title' => 'Something new', 'country' => 'USD', 'date' => '2026-09-04T08:30:00-04:00', 'impact' => 'Critical'],
        ], 200)]);

        (new CalendarFeed)->refresh();

        $this->assertSame('low', EconomicEvent::first()->impact,
            'An unknown impact must not be able to invent a blackout nobody configured.');
    }
}

<?php

namespace Tests\Feature\News;

use App\Models\MarketEvent;
use App\Services\News\CalendarSource;
use App\Services\News\ForexFactoryCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Importing the economic calendar.
 *
 * The filter that consumes this fails open, so every way the importer can quietly produce an
 * empty or wrong calendar is a way the bot quietly trades through NFP. That is what these
 * cover: a feed outage must leave the previous calendar alone rather than wipe it, a re-import
 * must update rather than duplicate, and a row whose time cannot be parsed must be dropped
 * rather than stored at some default that blacks out the wrong minute.
 */
class CalendarImportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://calendar.test/thisweek.json';

    /**
     * The day the fixture event falls on, as 'Y-m-d'.
     *
     * Relative to now rather than a literal date, because `news:import` prunes history older
     * than ninety days as part of the same run - a fixture pinned to a calendar date passes
     * until that date drifts out of the window and then fails for a reason that has nothing to
     * do with what it is testing.
     */
    private string $day;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('trading.news.calendar_url', self::URL);

        $this->day = now()->addDays(2)->format('Y-m-d');
    }

    // =====================================================================
    // PARSING THE FEED
    // =====================================================================

    public function test_it_stores_events_with_times_converted_to_utc(): void
    {
        Http::fake([self::URL => Http::response([
            [
                'title' => 'Non-Farm Employment Change',
                'country' => 'USD',
                // The feed states its own offset. 08:30 at -04:00 is 12:30 UTC.
                'date' => $this->day.'T08:30:00-04:00',
                'impact' => 'High',
                'forecast' => '190K',
                'previous' => '175K',
            ],
        ])]);

        $this->artisan('news:import')->assertSuccessful();

        $event = MarketEvent::sole();

        $this->assertSame('Non-Farm Employment Change', $event->title);
        $this->assertSame('USD', $event->currency);
        $this->assertSame('high', $event->impact);
        $this->assertSame($this->day.' 12:30:00', $event->scheduled_at->toDateTimeString());
        $this->assertSame('190K', $event->forecast);
        $this->assertSame('forexfactory', $event->source);
    }

    /**
     * The feed's impact vocabulary is not the enum's. An unrecognised word has to land on the
     * level the filter ignores - a new word from the feed must not start blocking trades.
     */
    public function test_an_unknown_impact_becomes_low(): void
    {
        Http::fake([self::URL => Http::response([
            $this->row(['impact' => 'Catastrophic']),
        ])]);

        $this->artisan('news:import')->assertSuccessful();

        $this->assertSame('low', MarketEvent::sole()->impact);
    }

    public function test_rows_missing_a_time_currency_or_title_are_dropped(): void
    {
        Http::fake([self::URL => Http::response([
            $this->row(['date' => '']),
            $this->row(['country' => '']),
            $this->row(['title' => '']),
            $this->row(['date' => 'not a date']),
            $this->row(['title' => 'Usable']),
        ])]);

        $this->artisan('news:import')->assertSuccessful();

        $this->assertSame(1, MarketEvent::count());
        $this->assertSame('Usable', MarketEvent::sole()->title);
    }

    /**
     * The feed sends "" for a figure it does not have yet, and the whole `actual` column is
     * empty on a forward-looking week.
     */
    public function test_empty_figures_are_stored_as_null(): void
    {
        Http::fake([self::URL => Http::response([
            $this->row(['forecast' => '', 'previous' => '  ']),
        ])]);

        $this->artisan('news:import')->assertSuccessful();

        $event = MarketEvent::sole();

        $this->assertNull($event->forecast);
        $this->assertNull($event->previous);
        $this->assertNull($event->actual);
    }

    // =====================================================================
    // RE-IMPORTING
    // =====================================================================

    public function test_reimporting_the_same_week_updates_rather_than_duplicates(): void
    {
        // A sequence, not two Http::fake() calls: faking the same URL twice does not replace
        // the first stub, it queues behind it, and the first match wins every time. A test
        // written that way silently re-serves the first response and proves nothing.
        Http::fakeSequence(self::URL)
            ->push([$this->row(['forecast' => '190K'])])
            // The same event, now with an actual figure published.
            ->push([$this->row(['forecast' => '190K', 'actual' => '256K'])]);

        $this->artisan('news:import')->assertSuccessful();
        $this->assertSame(1, MarketEvent::count());

        $this->artisan('news:import')->assertSuccessful();

        $this->assertSame(1, MarketEvent::count());
        $this->assertSame('256K', MarketEvent::sole()->actual);
    }

    // =====================================================================
    // FAILURE
    // =====================================================================

    /**
     * The important one. Wiping the calendar on a failed request disables the filter at the
     * moment nobody would think to check it, and the filter fails open - so a wiped calendar
     * trades straight through the release it was meant to avoid.
     */
    public function test_an_unreachable_feed_leaves_the_stored_calendar_alone(): void
    {
        Http::fakeSequence(self::URL)
            ->push([$this->row([])])
            ->pushStatus(503);

        $this->artisan('news:import')->assertSuccessful();
        $this->assertSame(1, MarketEvent::count());

        $this->artisan('news:import')->assertSuccessful();

        $this->assertSame(1, MarketEvent::count(), 'A failed fetch must not empty the calendar.');
    }

    public function test_a_non_array_response_is_survivable(): void
    {
        Http::fake([self::URL => Http::response('nope, have some html')]);

        $this->artisan('news:import')->assertSuccessful();

        $this->assertSame(0, MarketEvent::count());
    }

    public function test_a_connection_exception_is_survivable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->artisan('news:import')->assertSuccessful();

        $this->assertSame(0, MarketEvent::count());
    }

    // =====================================================================
    // PRUNING
    // =====================================================================

    /**
     * History has to outlive the week it happened in, or a backtest over last month measures a
     * system with no news filter in it.
     */
    public function test_pruning_keeps_recent_history_and_drops_the_rest(): void
    {
        MarketEvent::create([
            'source' => 'test', 'title' => 'Ancient', 'currency' => 'USD',
            'impact' => 'high', 'scheduled_at' => now()->subDays(200),
        ]);

        MarketEvent::create([
            'source' => 'test', 'title' => 'Recent', 'currency' => 'USD',
            'impact' => 'high', 'scheduled_at' => now()->subDays(30),
        ]);

        Http::fake([self::URL => Http::response([$this->row([])])]);

        $this->artisan('news:import', ['--prune-days' => 90])->assertSuccessful();

        $this->assertNull(MarketEvent::where('title', 'Ancient')->first());
        $this->assertNotNull(MarketEvent::where('title', 'Recent')->first());
    }

    // =====================================================================
    // THE BINDING
    // =====================================================================

    public function test_the_calendar_source_is_swappable(): void
    {
        $day = $this->day;

        $this->app->bind(CalendarSource::class, fn () => new class($day) implements CalendarSource
        {
            public function __construct(private readonly string $day) {}

            public function name(): string
            {
                return 'fixture';
            }

            public function fetch(): array
            {
                return [[
                    'title' => 'Hand-entered',
                    'currency' => 'USD',
                    'impact' => 'high',
                    'scheduled_at' => Carbon::parse($this->day.' 12:30:00', 'UTC'),
                    'forecast' => null,
                    'previous' => null,
                    'actual' => null,
                ]];
            }
        });

        $this->artisan('news:import')->assertSuccessful();

        $this->assertSame('fixture', MarketEvent::sole()->source);
    }

    public function test_the_default_binding_is_the_forexfactory_feed(): void
    {
        $this->assertInstanceOf(ForexFactoryCalendar::class, app(CalendarSource::class));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides): array
    {
        return $overrides + [
            'title' => 'Non-Farm Employment Change',
            'country' => 'USD',
            'date' => $this->day.'T08:30:00-04:00',
            'impact' => 'High',
            'forecast' => '190K',
            'previous' => '175K',
        ];
    }
}

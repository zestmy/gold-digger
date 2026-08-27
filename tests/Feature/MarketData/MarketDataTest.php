<?php

namespace Tests\Feature\MarketData;

use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\User;
use App\Services\MarketData\MarketData;
use App\Services\MarketData\TwelveDataSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Where a run of bars comes from.
 *
 * The load-bearing test in this file is the first one. `forTrading()` has no setting, no
 * argument and no fallback that makes it return a vendor's bars, and that is deliberate
 * rather than incidental: indicators decide where the stop goes, and an ATR computed from
 * one vendor's gold series against a fill on the broker's is a stop sized from prices the
 * broker never quoted. A switch that could do that is one somebody eventually flips.
 *
 * The rest is about the reason the seam exists at all. One consumer - the walk-forward -
 * asked for 20,000 bars where the next deepest asked for 300, so storing history deep
 * enough for it put 80,000 bars in a database holding ten trades. Fetching that on demand
 * and never persisting it is what lets the stored series shrink.
 */
class MarketDataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'marketdata.provider' => TwelveDataSeries::class,
            'marketdata.key' => null,
            'marketdata.base_url' => 'https://api.twelvedata.com',
        ]);

        $this->user = User::factory()->create();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo', 'is_demo' => true, 'is_active' => true,
        ]);
    }

    // =====================================================================
    // THE LINE THAT CANNOT BE CROSSED
    // =====================================================================

    /**
     * A configured, working, deeper vendor. `forTrading()` still returns the broker's bars.
     */
    public function test_trading_bars_are_never_the_vendors_however_it_is_configured(): void
    {
        $this->seedStored('M5', 10);
        $this->fakeVendor(500);

        $bars = app(MarketData::class)->forTrading('XAUUSD', 'M5', 300, $this->account->id);

        $this->assertCount(10, $bars, 'the ten stored bars, not the five hundred offered');
        $this->assertSame('mql5_ea', $bars[0]->source);
        Http::assertNothingSent();
    }

    // =====================================================================
    // DEEP HISTORY, ON DEMAND
    // =====================================================================

    public function test_a_backtest_reaches_for_the_vendor_when_stored_history_is_short(): void
    {
        $this->seedStored('M5', 50);
        $this->fakeVendor(400);

        $result = app(MarketData::class)->forBacktest('XAUUSD', 'M5', 400, $this->account->id);

        $this->assertCount(400, $result['bars']);
        $this->assertSame('twelvedata', $result['source']);
    }

    /**
     * Stored bars are this broker's own prices, which is a better replay than a vendor's
     * for the same reason they are a better basis for a stop.
     */
    public function test_stored_bars_win_when_there_are_enough_of_them(): void
    {
        $this->seedStored('M5', 400);
        $this->fakeVendor(400);

        $result = app(MarketData::class)->forBacktest('XAUUSD', 'M5', 400, $this->account->id);

        $this->assertSame('broker', $result['source']);
        Http::assertNothingSent();
    }

    /**
     * Swapping this broker's prices for a stranger's in order to get *fewer* bars would be
     * a worse replay by both measures.
     */
    public function test_a_shallower_vendor_answer_is_not_taken(): void
    {
        $this->seedStored('M5', 200);
        $this->fakeVendor(50);

        $result = app(MarketData::class)->forBacktest('XAUUSD', 'M5', 1000, $this->account->id);

        $this->assertCount(200, $result['bars']);
        $this->assertSame('broker', $result['source']);
    }

    public function test_with_no_vendor_a_backtest_gets_what_is_stored_and_says_so(): void
    {
        $this->seedStored('M5', 50);

        $result = app(MarketData::class)->forBacktest('XAUUSD', 'M5', 20000, $this->account->id);

        // Honestly short rather than padded. A caller that wanted 20,000 and has 50 should
        // be told how many it has.
        $this->assertCount(50, $result['bars']);
        $this->assertSame('broker', $result['source']);
    }

    // =====================================================================
    // NOTHING FETCHED IS EVER STORED
    // =====================================================================

    /**
     * The entire point of the exercise. If vendor bars landed in `candles` this would be a
     * more elaborate way of causing the problem it exists to solve.
     */
    public function test_vendor_bars_are_never_written_to_the_database(): void
    {
        $this->fakeVendor(300);

        $before = Candle::acrossTenants()->count();

        $result = app(MarketData::class)->forBacktest('XAUUSD', 'M5', 300, $this->account->id);

        $this->assertCount(300, $result['bars']);
        $this->assertSame($before, Candle::acrossTenants()->count());
        $this->assertFalse($result['bars'][0]->exists, 'a fetched bar must not look like a persisted one');
    }

    // =====================================================================
    // TALKING TO A VENDOR
    // =====================================================================

    public function test_bars_come_back_oldest_first_whatever_the_vendor_sent(): void
    {
        $this->fakeVendor(5);

        $bars = app(MarketData::class)->forBacktest('XAUUSD', 'M5', 5, $this->account->id)['bars'];

        $times = array_map(fn (Candle $c) => $c->open_time->getTimestamp(), $bars);
        $sorted = $times;
        sort($sorted);

        // Reversed order is not an error that throws - it silently computes an EMA of the
        // future, which is why this is asserted rather than assumed.
        $this->assertSame($sorted, $times);
    }

    /**
     * A chart of the wrong instrument looks like an answer, which is worse than an empty
     * one. So an unmapped symbol fetches nothing at all.
     */
    public function test_an_unmapped_symbol_is_refused_rather_than_guessed_at(): void
    {
        config(['marketdata.key' => 'test-key']);
        Http::fake();

        $bars = (new TwelveDataSeries)->series('WHATEVER99', 'M5', 100);

        $this->assertSame([], $bars);
        Http::assertNothingSent();
    }

    public function test_a_broker_suffix_still_finds_the_instrument(): void
    {
        $this->fakeVendor(3);

        // XAUUSDm, XAUUSD.a, GOLD - the suffix varies, the instrument does not.
        $this->assertCount(3, (new TwelveDataSeries)->series('XAUUSDm', 'M5', 3));
    }

    /**
     * Every caller is a page or a report. A vendor being down should degrade to stored
     * bars, not 500 a dashboard somebody is watching a live account on.
     */
    public function test_a_vendor_failure_falls_back_to_stored_bars(): void
    {
        $this->seedStored('M5', 20);
        config(['marketdata.key' => 'test-key']);
        Http::fake(['api.twelvedata.com/*' => Http::response(['status' => 'error', 'message' => 'rate limit'], 200)]);

        $result = app(MarketData::class)->forBacktest('XAUUSD', 'M5', 500, $this->account->id);

        $this->assertCount(20, $result['bars']);
        $this->assertSame('broker', $result['source']);
    }

    public function test_repeated_requests_inside_one_bar_are_served_from_cache(): void
    {
        $this->fakeVendor(10);

        $md = app(MarketData::class);
        $md->forBacktest('XAUUSD', 'M5', 10, $this->account->id);
        $md->forBacktest('XAUUSD', 'M5', 10, $this->account->id);

        Http::assertSentCount(1);
    }

    public function test_no_vendor_is_reported_as_no_vendor(): void
    {
        $this->assertFalse(app(MarketData::class)->hasVendor());

        config(['marketdata.key' => 'test-key']);

        $this->assertTrue(app(MarketData::class)->hasVendor());
    }

    // =====================================================================
    // FIXTURES
    // =====================================================================

    private function seedStored(string $timeframe, int $count): void
    {
        $start = Carbon::parse('2026-01-01 00:00:00');
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'user_id' => $this->user->id,
                'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD', 'timeframe' => $timeframe,
                'open_time' => $start->copy()->addMinutes($i * 5),
                'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000,
                'tick_volume' => 10, 'source' => 'mql5_ea',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        Candle::insert($rows);
    }

    /**
     * A vendor holding `$count` bars, newest first - which is how it answers a limited
     * request.
     */
    private function fakeVendor(int $count): void
    {
        config(['marketdata.key' => 'test-key']);

        $values = [];
        $start = Carbon::parse('2026-06-01 00:00:00');

        for ($i = 0; $i < $count; $i++) {
            $values[] = [
                'datetime' => $start->copy()->subMinutes($i * 5)->toDateTimeString(),
                'open' => '2500.00', 'high' => '2501.00',
                'low' => '2499.00', 'close' => '2500.50', 'volume' => '100',
            ];
        }

        Http::fake(['api.twelvedata.com/*' => Http::response(['status' => 'ok', 'values' => $values], 200)]);
    }
}

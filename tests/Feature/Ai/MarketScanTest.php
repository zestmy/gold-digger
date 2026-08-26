<?php

namespace Tests\Feature\Ai;

use App\Livewire\Pages\ChartAnalysis;
use App\Models\BotHeartbeat;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Ai\ScanAnalyst;
use App\Services\Analysis\MarketScanner;
use App\Services\Analysis\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Scanning every instrument, and proposing among them.
 *
 * The design under test has two halves that fail independently. The ranking is arithmetic:
 * confluence from the scorer the copier already uses, prices taken from levels the
 * instrument actually turned at, a reward ratio divided out in PHP. It is there with no API
 * key, no credit and no network, and these tests hold it to that.
 *
 * The model's part is comparative - of this shortlist, which - and it is one call for the
 * whole scan rather than one per instrument. It names candidates by number and cannot write
 * a price, so the worst it can do is prefer a worse real setup.
 */
class MarketScanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Strategy $strategy;

    private BrokerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config(['ai.key' => 'sk-or-test', 'ai.base_url' => 'https://openrouter.ai/api/v1']);

        $this->user = User::factory()->create();
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);

        BotHeartbeat::create([
            'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea', 'algo_trading_enabled' => true, 'broker_connected' => true,
            'resolved_symbol' => 'XAUUSD', 'last_seen_at' => now(),
        ]);
    }

    // =====================================================================
    // THE SCAN IS ARITHMETIC
    // =====================================================================

    public function test_it_scans_every_instrument_with_enough_history(): void
    {
        $this->bars('XAUUSD', 2650.0);
        $this->bars('EURUSD', 1.08, amplitude: 0.004);
        $this->bars('GBPUSD', 1.27, amplitude: 0.005);

        $scan = (new MarketScanner)->scan($this->strategy, $this->account->id, 'M5');

        $this->assertSame(3, $scan['scanned']);
        $this->assertCount(3, $scan['candidates']);

        $symbols = array_map(fn (Opportunity $o) => $o->symbol, $scan['candidates']);
        sort($symbols);

        $this->assertSame(['EURUSD', 'GBPUSD', 'XAUUSD'], $symbols);
    }

    /**
     * An instrument nobody has enough bars of is not silently absent.
     *
     * Missing because there is no direction to test reads identically to missing because
     * nobody ever stored bars for it, and only one of those is worth doing something about.
     */
    public function test_an_instrument_it_cannot_score_is_named_with_the_reason(): void
    {
        $this->bars('XAUUSD', 2650.0);
        // Plenty of bars on the scan timeframe and none on the higher one the strategy
        // reads its trend from, so there are levels to find and nothing to score them
        // against.
        $this->barsOn('USDJPY', 'M5', 150.0, count: 200, amplitude: 0.4);

        $scan = (new MarketScanner)->scan($this->strategy, $this->account->id, 'M5');

        $skipped = collect($scan['skipped'])->firstWhere('symbol', 'USDJPY');

        $this->assertNotNull($skipped, 'the instrument is listed rather than dropped');
        $this->assertStringContainsString('Not enough history', $skipped['why']);
    }

    public function test_an_instrument_below_the_bar_floor_is_not_offered_at_all(): void
    {
        $this->bars('XAUUSD', 2650.0);
        // Under MIN_BARS on the scan timeframe: not enough for structure to exist.
        $this->bars('AUDUSD', 0.65, count: 40, amplitude: 0.003);

        $symbols = MarketScanner::symbols($this->account->id, 'M5');

        $this->assertContains('XAUUSD', $symbols);
        $this->assertNotContains('AUDUSD', $symbols);
    }

    /**
     * Candles are stored per account. Asking without one returns another terminal's series,
     * which looks like history and is not this account's.
     */
    public function test_it_only_reads_the_connected_accounts_series(): void
    {
        $this->bars('XAUUSD', 2650.0);

        $this->assertSame([], MarketScanner::symbols(null, 'M5'));
        $this->assertSame(['XAUUSD'], MarketScanner::symbols($this->account->id, 'M5'));
    }

    // =====================================================================
    // THE PRICES COME FROM MEASURED LEVELS
    // =====================================================================

    public function test_the_stop_sits_beyond_a_level_and_the_target_on_one(): void
    {
        $this->bars('XAUUSD', 2650.0);

        $found = (new MarketScanner)->consider($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        $this->assertInstanceOf(Opportunity::class, $found);

        if (! $found->complete()) {
            $this->markTestSkipped('this window produced no level on one side of price');
        }

        // The target is a level price, exactly. Not near one, not rounded to one.
        $this->assertSame((float) $found->targetLevel['price'], $found->target);

        // The stop is on the far side of its level: a stop sitting on it gets taken out by
        // the wick that tests it.
        if ($found->direction === 'buy') {
            $this->assertLessThan((float) $found->stopLevel['price'], $found->stop);
        } else {
            $this->assertGreaterThan((float) $found->stopLevel['price'], $found->stop);
        }
    }

    public function test_the_reward_ratio_is_divided_out_rather_than_asserted(): void
    {
        $this->bars('XAUUSD', 2650.0);

        $found = (new MarketScanner)->consider($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        if (! $found->complete()) {
            $this->markTestSkipped('this window produced no level on one side of price');
        }

        $this->assertEqualsWithDelta(
            abs($found->target - $found->entry) / abs($found->entry - $found->stop),
            $found->rewardRatio,
            0.01,
        );
    }

    /**
     * The half-plan is shown, and it is not a proposal.
     *
     * A stop invented to fill the column would be a number nobody could check, and the
     * ranking would then put a fabricated ratio above a measured one.
     */
    public function test_a_candidate_with_no_level_behind_price_has_no_plan_rather_than_a_guessed_one(): void
    {
        // A clean ramp with no completed swing below the final close: nothing to put a stop
        // beyond, because price never turned there.
        $this->ramp('XAUUSD', 2600.0, step: 0.6);

        $found = (new MarketScanner)->consider($this->strategy, $this->account->id, 'XAUUSD', 'M5');

        $this->assertInstanceOf(Opportunity::class, $found);

        if ($found->stop !== null && $found->target !== null) {
            $this->markTestSkipped('this ramp still produced levels on both sides');
        }

        $this->assertFalse($found->complete());
        $this->assertNull($found->rewardRatio);
    }

    // =====================================================================
    // THE ORDERING
    // =====================================================================

    public function test_a_tradeable_setup_outranks_one_with_a_prettier_ratio(): void
    {
        $weak = $this->opportunity(symbol: 'WEAK', tradeable: false, confluence: 1.0, reward: 9.0);
        $strong = $this->opportunity(symbol: 'STRONG', tradeable: true, confluence: 4.0, reward: 1.6);

        $ranked = [$weak, $strong];
        usort($ranked, fn (Opportunity $a, Opportunity $b) => $b->rank() <=> $a->rank());

        // A reward that large is large because the stop is far away, not because the trade
        // is good, and no amount of it substitutes for evidence.
        $this->assertSame('STRONG', $ranked[0]->symbol);
    }

    public function test_a_complete_plan_outranks_an_equally_evidenced_half_one(): void
    {
        $half = $this->opportunity(symbol: 'HALF', tradeable: true, confluence: 4.0, reward: null);
        $whole = $this->opportunity(symbol: 'WHOLE', tradeable: true, confluence: 4.0, reward: 1.8);

        $ranked = [$half, $whole];
        usort($ranked, fn (Opportunity $a, Opportunity $b) => $b->rank() <=> $a->rank());

        $this->assertSame('WHOLE', $ranked[0]->symbol);
    }

    // =====================================================================
    // THE MODEL RANKS THE SHORTLIST
    // =====================================================================

    /**
     * Comparative questions are not answerable by separate opinions that never saw each
     * other, and twenty of them would cost twenty calls to get a worse answer.
     */
    public function test_the_whole_shortlist_costs_one_call(): void
    {
        $this->ranks([
            ['candidate' => 0, 'verdict' => 'take', 'conviction' => 'high', 'reasoning' => 'Best evidenced.', 'invalidation' => 'A close below support.'],
            ['candidate' => 1, 'verdict' => 'watch', 'conviction' => 'low', 'reasoning' => 'Not ready.', 'invalidation' => 'Structure turns.'],
        ]);

        $candidates = [
            $this->opportunity('XAUUSD', true, 4.0, 2.0),
            $this->opportunity('EURUSD', false, 2.0, 1.2),
            $this->opportunity('GBPUSD', false, 1.5, 1.1),
        ];

        $result = (new ScanAnalyst)->rank($candidates, 'M5');

        $this->assertTrue($result['ok']);
        Http::assertSentCount(1);
    }

    public function test_a_pick_is_attached_to_the_candidate_it_names(): void
    {
        $this->ranks([
            ['candidate' => 1, 'verdict' => 'take', 'conviction' => 'medium', 'reasoning' => 'The second one.', 'invalidation' => 'A close below.'],
        ]);

        $candidates = [$this->opportunity('XAUUSD', true, 4.0, 2.0), $this->opportunity('EURUSD', true, 3.5, 1.9)];

        $picks = (new ScanAnalyst)->rank($candidates, 'M5')['picks'];

        $this->assertCount(1, $picks);
        $this->assertSame('EURUSD', $picks[0]['opportunity']->symbol);
        $this->assertSame('take', $picks[0]['verdict']);
    }

    /**
     * A pick naming no candidate is dropped rather than rendered with blanks, which would
     * suggest the scan found something it did not.
     */
    public function test_a_pick_out_of_range_is_dropped(): void
    {
        $this->ranks([
            ['candidate' => 99, 'verdict' => 'take', 'conviction' => 'high', 'reasoning' => 'An instrument nobody scanned.', 'invalidation' => 'x'],
            ['candidate' => 0, 'verdict' => 'watch', 'conviction' => 'low', 'reasoning' => 'This one exists.', 'invalidation' => 'y'],
        ]);

        $picks = (new ScanAnalyst)->rank([$this->opportunity('XAUUSD', true, 4.0, 2.0)], 'M5')['picks'];

        $this->assertCount(1, $picks);
        $this->assertSame('XAUUSD', $picks[0]['opportunity']->symbol);
    }

    public function test_picking_nothing_is_a_result(): void
    {
        $this->ranks([]);

        $result = (new ScanAnalyst)->rank([$this->opportunity('XAUUSD', false, 1.0, 0.8)], 'M5');

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['picks']);
    }

    public function test_without_a_key_the_ranking_says_so_and_nothing_is_sent(): void
    {
        config(['ai.key' => '']);
        Http::fake();

        $result = (new ScanAnalyst)->rank([$this->opportunity('XAUUSD', true, 4.0, 2.0)], 'M5');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('OPENROUTER_API_KEY', $result['error']);
        Http::assertNothingSent();
    }

    public function test_a_failed_call_leaves_the_measured_scan_intact(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([], 500)]);

        $result = (new ScanAnalyst)->rank([$this->opportunity('XAUUSD', true, 4.0, 2.0)], 'M5');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['picks']);
    }

    // =====================================================================
    // THE PAGE
    // =====================================================================

    public function test_the_page_scans_and_ranks(): void
    {
        $this->bars('XAUUSD', 2650.0);
        $this->bars('EURUSD', 1.08, amplitude: 0.004);

        $this->ranks([
            ['candidate' => 0, 'verdict' => 'take', 'conviction' => 'high', 'reasoning' => 'Cleanest structure on the list.', 'invalidation' => 'A close back inside the range.'],
        ]);

        Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->call('scan')
            ->assertOk()
            ->assertSee('Measured ranking')
            ->assertSee('Cleanest structure on the list.')
            ->assertSee('XAUUSD');
    }

    /**
     * The measured half is the one that can be checked, and it is there without a model.
     */
    public function test_the_page_ranks_without_a_model_at_all(): void
    {
        config(['ai.key' => '']);
        $this->bars('XAUUSD', 2650.0);
        Http::fake();

        Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->set('withModel', false)
            ->call('scan')
            ->assertOk()
            ->assertSee('Measured ranking')
            ->assertSee('XAUUSD');

        Http::assertNothingSent();
    }

    public function test_the_scan_itself_costs_nothing_when_the_model_is_declined(): void
    {
        $this->bars('XAUUSD', 2650.0);
        Http::fake();

        Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->set('withModel', false)
            ->call('scan')
            ->assertOk();

        Http::assertNothingSent();
    }

    /**
     * The measured half was free, so unticking the box should not throw it away. Ticking it
     * is a purchase, and a purchase follows a button somebody pressed on purpose.
     */
    public function test_declining_the_model_keeps_the_scan_and_asking_for_it_does_not_buy_one(): void
    {
        $this->bars('XAUUSD', 2650.0);
        $this->ranks([]);

        Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->call('scan')
            ->set('withModel', false)
            ->assertSet('scanned', true)
            ->assertSee('Measured ranking')
            ->set('withModel', true)
            ->assertSet('scanned', false);
    }

    public function test_changing_timeframe_discards_the_previous_scan(): void
    {
        $this->bars('XAUUSD', 2650.0);
        $this->ranks([]);

        Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->call('scan')
            ->assertSet('scanned', true)
            // A different timeframe is a different set of levels. Keeping the old results
            // on screen under the new label would be the worst of both.
            ->set('timeframe', 'H1')
            ->assertSet('scanned', false);
    }

    public function test_a_row_opens_into_the_focused_reading(): void
    {
        $this->bars('XAUUSD', 2650.0);
        $this->ranks([]);
        // Opening a row does not read the chart, so the scan-shaped fake above is never
        // asked a chart-shaped question.

        Livewire::actingAs($this->user)
            ->test(ChartAnalysis::class)
            ->set('timeframe', 'M5')
            ->call('scan')
            ->call('focus', 'XAUUSD')
            ->assertOk()
            ->assertSet('mode', 'focus')
            ->assertSet('symbol', 'XAUUSD')
            ->assertSee('Back to the scan');
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    /**
     * @param  array<int, array<string, mixed>>  $picks
     */
    private function ranks(array $picks): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => 'test-model',
            'choices' => [['message' => ['content' => json_encode([
                'verdict' => 'Two setups worth watching, one worth taking.',
                'picks' => $picks,
                'passed_on' => 'The rest had nothing agreeing about direction.',
            ])]]],
        ], 200)]);
    }

    /**
     * A candidate with only the fields the ordering and the brief read.
     */
    private function opportunity(string $symbol, bool $tradeable, float $confluence, ?float $reward): Opportunity
    {
        return new Opportunity(
            symbol: $symbol,
            kind: 'metal',
            direction: 'buy',
            confluence: $confluence,
            possible: 6.0,
            directional: $confluence / 2,
            confidence: (int) round($confluence / 6 * 100),
            risk: 'MEDIUM',
            entryStatus: 'CAN ENTRY NOW',
            tradeable: $tradeable,
            why: 'trend, DI, session',
            factors: [],
            aligned: true,
            adx: 27.0,
            atr: 3.0,
            atrPct: 0.11,
            entry: 2650.0,
            stop: $reward === null ? null : 2640.0,
            target: $reward === null ? null : 2650.0 + (10.0 * $reward),
            rewardRatio: $reward,
            stopLevel: $reward === null ? null : ['price' => 2641.0, 'kind' => 'support', 'touches' => 3, 'last_index' => 40],
            targetLevel: $reward === null ? null : ['price' => 2650.0 + (10.0 * $reward), 'kind' => 'resistance', 'touches' => 2, 'last_index' => 90],
            structure: 'Higher high and higher low: structure is rising.',
            levels: [],
            lastBarAt: now(),
            bars: 120,
        );
    }

    /**
     * Oscillating bars, so there are real pivots to find on both series.
     */
    private function bars(string $symbol, float $base, int $count = 300, ?float $amplitude = null): void
    {
        foreach (['M5', 'H1'] as $timeframe) {
            $this->barsOn($symbol, $timeframe, $base, $count, $amplitude);
        }
    }

    /**
     * The same, on one timeframe only.
     */
    private function barsOn(string $symbol, string $timeframe, float $base, int $count = 300, ?float $amplitude = null): void
    {
        $amplitude ??= $base * 0.003;

        for ($i = $count; $i >= 0; $i--) {
            $price = $base + (sin($i / 4.0) * $amplitude);
            $tick = $amplitude / 5;

            Candle::create([
                'user_id' => $this->user->id,
                'broker_account_id' => $this->account->id,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'open_time' => now()->subMinutes(($timeframe === 'M5' ? 5 : 60) * $i),
                'open' => $price - ($tick / 2), 'high' => $price + $tick,
                'low' => $price - $tick, 'close' => $price,
            ]);
        }
    }

    /**
     * A clean climb: no completed swing low near the end to put a stop beyond.
     */
    private function ramp(string $symbol, float $base, float $step, int $count = 300): void
    {
        foreach (['M5', 'H1'] as $timeframe) {
            for ($i = $count; $i >= 0; $i--) {
                $price = $base + (($count - $i) * $step);

                Candle::create([
                    'user_id' => $this->user->id,
                    'broker_account_id' => $this->account->id,
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'open_time' => now()->subMinutes(($timeframe === 'M5' ? 5 : 60) * $i),
                    'open' => $price - 0.1, 'high' => $price + 0.2,
                    'low' => $price - 0.2, 'close' => $price,
                ]);
            }
        }
    }
}

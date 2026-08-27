<?php

namespace Tests\Feature\Analysis;

use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Analysis\TimeframeSummary;
use App\Services\Strategy\StrategyEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reading one instrument across several timeframes.
 *
 * The failure this guards against is a confident summary built from charts that are not
 * there. A freshly connected terminal has bars on the entry timeframe and none on the
 * daily, and a ladder that silently reported the missing rungs as "neutral" would put a
 * grey pill next to three real readings and invite somebody to trade the alignment.
 */
class TimeframeSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo', 'is_demo' => true, 'is_active' => true,
        ]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update([
            'timeframe_entry' => 'M15',
            'timeframe_trend' => 'H1',
            'ema_fast' => 20,
            'ema_slow' => 50,
        ]);
    }

    // =========================================================================
    // THE LADDER
    // =========================================================================

    public function test_the_ladder_is_built_around_the_timeframes_the_strategy_trades(): void
    {
        $ladder = app(TimeframeSummary::class)->ladderFor($this->strategy);

        // One rung wider than H1 for regime, one finer than M15 for timing.
        $this->assertSame(['H4', 'H1', 'M30', 'M15', 'M5'], $ladder);
    }

    /**
     * An M1 scalper and an H4 swing trader should not be handed the same ladder. The rungs
     * are derived from what the strategy trades rather than fixed at D1/H4/H1/M15.
     */
    public function test_a_slower_strategy_gets_a_slower_ladder(): void
    {
        $this->strategy->update(['timeframe_entry' => 'H4', 'timeframe_trend' => 'D1']);

        $ladder = app(TimeframeSummary::class)->ladderFor($this->strategy);

        $this->assertSame(['W1', 'D1', 'H4', 'H1'], $ladder);
    }

    public function test_an_unrecognised_timeframe_falls_back_to_what_the_strategy_names(): void
    {
        $this->strategy->update(['timeframe_entry' => 'M7', 'timeframe_trend' => 'H1']);

        $this->assertSame(['H1', 'M7'], app(TimeframeSummary::class)->ladderFor($this->strategy));
    }

    // =========================================================================
    // READING
    // =========================================================================

    public function test_a_rising_series_reads_bullish_on_every_timeframe_that_has_bars(): void
    {
        foreach (['H4', 'H1', 'M30', 'M15', 'M5'] as $timeframe) {
            $this->seedBars($timeframe, rising: true);
        }

        $summary = app(TimeframeSummary::class)->of($this->strategy, $this->account->id, 'XAUUSD');

        $this->assertSame(5, $summary['read']);
        $this->assertTrue($summary['aligned']);
        $this->assertSame('bullish', $summary['bias']);

        foreach ($summary['timeframes'] as $timeframe => $reading) {
            $this->assertSame('bullish', $reading['trend'], "{$timeframe} should read bullish");
        }
    }

    public function test_disagreeing_timeframes_are_reported_as_disagreeing(): void
    {
        $this->seedBars('H4', rising: true);
        $this->seedBars('H1', rising: true);
        $this->seedBars('M30', rising: false);
        $this->seedBars('M15', rising: false);
        $this->seedBars('M5', rising: false);

        $summary = app(TimeframeSummary::class)->of($this->strategy, $this->account->id, 'XAUUSD');

        $this->assertFalse($summary['aligned']);
        $this->assertSame('bearish', $summary['bias'], 'three against two');
        $this->assertStringContainsString('disagree', $summary['agreement']);
    }

    /**
     * A tie is not a bias. Picking one because something has to be picked is how a coin
     * flip acquires an explanation.
     */
    public function test_an_even_split_reports_no_bias_at_all(): void
    {
        $this->seedBars('H1', rising: true);
        $this->seedBars('M15', rising: false);

        $summary = app(TimeframeSummary::class)->of($this->strategy, $this->account->id, 'XAUUSD', ['H1', 'M15']);

        $this->assertNull($summary['bias']);
        $this->assertFalse($summary['aligned']);
    }

    // =========================================================================
    // MISSING DATA
    // =========================================================================

    public function test_a_timeframe_with_no_bars_is_omitted_rather_than_called_neutral(): void
    {
        $this->seedBars('H1', rising: true);
        $this->seedBars('M15', rising: true);

        $summary = app(TimeframeSummary::class)->of($this->strategy, $this->account->id, 'XAUUSD');

        $this->assertSame(['H1', 'M15'], array_keys($summary['timeframes']));
        $this->assertSame(2, $summary['read']);
        $this->assertArrayNotHasKey('H4', $summary['timeframes']);
    }

    public function test_a_series_shorter_than_its_own_slow_ema_is_not_reported(): void
    {
        // 30 bars against an EMA 50. A trend read from this is arithmetic on a warm-up.
        $this->seedBars('H1', rising: true, bars: 30);

        $summary = app(TimeframeSummary::class)->of($this->strategy, $this->account->id, 'XAUUSD', ['H1']);

        $this->assertSame(0, $summary['read']);
        $this->assertNull($summary['bias']);
    }

    public function test_nothing_at_all_is_reported_as_nothing_rather_than_as_undecided(): void
    {
        $summary = app(TimeframeSummary::class)->of($this->strategy, $this->account->id, 'XAUUSD');

        $this->assertSame(0, $summary['read']);
        $this->assertFalse($summary['aligned']);
        $this->assertNull($summary['bias']);
    }

    // =========================================================================
    // WHAT EACH RUNG IS FOR
    // =========================================================================

    public function test_each_rung_is_labelled_with_the_job_it_does(): void
    {
        foreach (['H4', 'H1', 'M15', 'M5'] as $timeframe) {
            $this->seedBars($timeframe, rising: true);
        }

        $summary = app(TimeframeSummary::class)->of($this->strategy, $this->account->id, 'XAUUSD');

        $this->assertSame('market context', $summary['timeframes']['H4']['role']);
        $this->assertSame('primary trend', $summary['timeframes']['H1']['role']);
        $this->assertSame('setup structure', $summary['timeframes']['M15']['role']);
        $this->assertSame('entry timing', $summary['timeframes']['M5']['role']);
    }

    /**
     * One definition of "bullish", shared with the strategy that trades it. A summary that
     * disagreed with the strategy's own trend filter would be worse than no summary.
     */
    public function test_the_trend_matches_the_definition_the_strategy_itself_uses(): void
    {
        $this->seedBars('H1', rising: true);

        $candles = Candle::recentSeries($this->account->id, 'XAUUSD', 'H1', 260);
        $evaluator = app(StrategyEvaluator::class);
        $direction = $evaluator->trendDirection($candles, 20, 50);

        $summary = app(TimeframeSummary::class)->of($this->strategy, $this->account->id, 'XAUUSD', ['H1']);

        $this->assertSame(
            $direction === 'buy' ? 'bullish' : 'bearish',
            $summary['timeframes']['H1']['trend'],
        );
    }

    public function test_a_reading_carries_the_measurements_behind_it(): void
    {
        $this->seedBars('H1', rising: true);

        $reading = app(TimeframeSummary::class)
            ->of($this->strategy, $this->account->id, 'XAUUSD', ['H1'])['timeframes']['H1'];

        $this->assertIsInt($reading['strength']);
        $this->assertGreaterThanOrEqual(0, $reading['strength']);
        $this->assertLessThanOrEqual(100, $reading['strength']);
        $this->assertNotNull($reading['rsi']);
        $this->assertGreaterThan(0, $reading['atr']);
        $this->assertContains($reading['structure'], ['bullish', 'bearish', 'ranging']);
    }

    // =========================================================================
    // FIXTURE
    // =========================================================================

    private function seedBars(string $timeframe, bool $rising, int $bars = 200): void
    {
        $minutes = match ($timeframe) {
            'H4' => 240, 'H1' => 60, 'M30' => 30, 'M15' => 15, 'M5' => 5, default => 60,
        };

        $start = Carbon::parse('2026-01-01 00:00:00');
        $rows = [];

        for ($i = 0; $i < $bars; $i++) {
            // A drift with a small oscillation on it, so pivots exist and ADX has
            // something to measure rather than a perfectly straight line.
            $drift = $rising ? $i * 0.8 : -$i * 0.8;
            $wobble = sin($i / 4) * 1.5;
            $close = 2000 + $drift + $wobble;

            $rows[] = [
                'user_id' => $this->user->id,
                'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD',
                'timeframe' => $timeframe,
                'open_time' => $start->copy()->addMinutes($i * $minutes),
                'open' => $close - 0.2,
                'high' => $close + 0.6,
                'low' => $close - 0.6,
                'close' => $close,
                'tick_volume' => 100,
                'source' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        Candle::insert($rows);
    }
}

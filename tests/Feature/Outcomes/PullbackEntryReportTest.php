<?php

namespace Tests\Feature\Outcomes;

use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\SignalOutcome;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Outcomes\PullbackEntryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The pullback-entry report.
 *
 * Does waiting for a pullback turn the entry into a better trade? The rules that decide
 * the answer: the stop stays where the signal put it, the ladder is measured off the
 * shorter risk, a limit that never fills is a missed trade worth nothing, and a fill bar
 * that also reaches the stop is a loss.
 */
class PullbackEntryReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private Carbon $start;

    private const SYMBOL = 'XAUUSDm';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->start = Carbon::parse('2026-03-10 13:05:00', 'UTC');
    }

    /**
     * A buy from 2000 with the stop at 1995. Price dips to 1997 and then runs to 2004.
     * As traded (TP1 at 2005) it reaches nothing. A limit half a stop back fills at
     * 1997.50 with 2.50 of risk, so TP1 sits at 2000 and the run reaches it: a win, on
     * half the risk.
     */
    public function test_a_pullback_entry_shortens_the_risk_and_the_ladder_with_it(): void
    {
        $this->outcome('buy', reference: 2000.0, stop: 1995.0);
        $this->bars([
            [2000.0, 2000.5, 1997.0, 1998.0],
            [1998.0, 2004.0, 1997.5, 2003.0],
            [2003.0, 2003.0, 2001.0, 2002.0],
        ]);

        $report = (new PullbackEntryReport)->forUser($this->user->id, depths: [0.0, 0.5]);

        [$asTraded, $half] = $report['rows'];

        $this->assertSame(1, $asTraded['expired']);
        $this->assertSame(1, $half['filled']);
        $this->assertSame(1, $half['won']);
        $this->assertSame(1.0, $half['expectancy_r']);
        $this->assertSame(0.5, $half['avg_risk_share']);
    }

    /**
     * A limit that never fills is a missed trade. It is worth nothing, and it counts: the
     * per-signal expectancy is what the strategy earns per signal it publishes, not per
     * trade it happens to get.
     */
    public function test_a_limit_price_never_returns_to_is_a_missed_trade_worth_nothing(): void
    {
        $this->outcome('buy', reference: 2000.0, stop: 1995.0);
        $this->bars([
            [2000.0, 2006.0, 1999.5, 2005.0],
            [2005.0, 2011.0, 2004.0, 2010.0],
        ]);

        $report = (new PullbackEntryReport)->forUser($this->user->id, depths: [0.0, 0.5]);

        [$asTraded, $half] = $report['rows'];

        $this->assertSame(1, $asTraded['won']);
        $this->assertSame(0, $half['filled']);
        $this->assertSame(1, $half['unfilled']);
        $this->assertSame(0.0, $half['fill_rate']);
        $this->assertSame(0.0, $half['expectancy_r']);
        $this->assertNull($half['expectancy_filled_r']);
    }

    /**
     * A pullback that comes after the window is not one the strategy would have taken.
     */
    public function test_a_limit_only_waits_as_long_as_it_is_told(): void
    {
        $this->outcome('buy', reference: 2000.0, stop: 1995.0);
        $this->bars([
            [2000.0, 2001.0, 1999.5, 2000.5],
            [2000.5, 2001.0, 1999.5, 2000.5],
            [2000.5, 2001.0, 1997.0, 1998.0],
            [1998.0, 2003.0, 1997.5, 2002.5],
        ]);

        $waits = (new PullbackEntryReport)->forUser($this->user->id, depths: [0.5], waitBars: 3);
        $gaveUp = (new PullbackEntryReport)->forUser($this->user->id, depths: [0.5], waitBars: 2);

        $this->assertSame(1, $waits['rows'][0]['filled']);
        $this->assertSame(0, $gaveUp['rows'][0]['filled']);
    }

    /**
     * The fill bar is scored in full: a bar that reaches the limit and the stop is a loss,
     * not a fill that got lucky.
     */
    public function test_a_fill_bar_that_also_reaches_the_stop_is_a_loss(): void
    {
        $this->outcome('sell', reference: 2000.0, stop: 2005.0);
        $this->bars([
            [2000.0, 2006.0, 1999.0, 2004.0],
        ]);

        $report = (new PullbackEntryReport)->forUser($this->user->id, depths: [0.5]);

        $this->assertSame(1, $report['rows'][0]['lost']);
    }

    public function test_the_signals_own_zone_is_scored_when_it_recorded_one(): void
    {
        $this->outcome('buy', reference: 2000.0, stop: 1995.0, zone: [1998.0, 2000.0]);
        $this->bars([
            [2000.0, 2000.5, 1997.5, 1998.5],
            [1998.5, 2003.0, 1998.0, 2002.5],
        ]);

        $report = (new PullbackEntryReport)->forUser($this->user->id, depths: [PullbackEntryReport::ZONE]);

        $row = $report['rows'][0];

        // Filled at 1998 with 3.00 of risk: TP1 at 2001, reached on the second bar.
        $this->assertSame(1, $row['filled']);
        $this->assertSame(1, $row['won']);
        $this->assertSame(0.6, $row['avg_risk_share']);
    }

    public function test_a_signal_without_a_zone_is_left_out_of_the_zone_row(): void
    {
        $this->outcome('buy', reference: 2000.0, stop: 1995.0);
        $this->bars([[2000.0, 2001.0, 1999.0, 2000.0]]);

        $report = (new PullbackEntryReport)->forUser($this->user->id, depths: [PullbackEntryReport::ZONE]);

        $this->assertSame(0, $report['rows'][0]['n']);
        $this->assertNull($report['rows'][0]['expectancy_r']);
    }

    public function test_the_command_and_the_performance_page_show_the_rows(): void
    {
        $this->outcome('buy', reference: 2000.0, stop: 1995.0);
        $this->bars([
            [2000.0, 2000.5, 1997.0, 1998.0],
            [1998.0, 2004.0, 1997.5, 2003.0],
        ]);

        $this->artisan('signals:pullback-entry --depths=0,0.5')
            ->expectsOutputToContain('1 scorable')
            ->expectsOutputToContain('none (as traded)')
            ->expectsOutputToContain('0.50 of stop')
            ->assertSuccessful();

        $this->actingAs($this->user)
            ->get(route('analytics', ['period' => 'all']))
            ->assertOk()
            ->assertSee('If the entry had waited for a pullback');
    }

    /**
     * @param  array{0: float, 1: float}|null  $zone
     */
    private function outcome(string $direction, float $reference, float $stop, ?array $zone = null): SignalOutcome
    {
        $sign = $direction === 'buy' ? 1.0 : -1.0;
        $risk = abs($reference - $stop);

        $signal = Signal::create([
            'strategy_id' => $this->strategy->id,
            'symbol' => self::SYMBOL, 'timeframe' => 'M5', 'direction' => $direction,
            'entry_price' => $reference, 'sl_price' => $stop,
            'tp1_price' => $reference + $sign * $risk,
            'tp2_price' => $reference + $sign * 2 * $risk,
            'tp3_price' => $reference + $sign * 3 * $risk,
            'generated_at' => $this->start->copy()->subMinutes(5),
            'features' => $zone === null ? [] : ['entry_zone_low' => $zone[0], 'entry_zone_high' => $zone[1]],
        ]);

        return SignalOutcome::create([
            'user_id' => $this->user->id,
            'subject_type' => Signal::class, 'subject_id' => $signal->id,
            'source' => SignalOutcome::SOURCE_AI,
            'broker_account_id' => $this->account->id,
            'symbol' => self::SYMBOL, 'timeframe' => 'M5', 'direction' => $direction,
            'reference_price' => $reference, 'stop_price' => $stop,
            'tp1_price' => $reference + $sign * $risk,
            'tp2_price' => $reference + $sign * 2 * $risk,
            'tp3_price' => $reference + $sign * 3 * $risk,
            'risk' => $risk,
            'started_at' => $this->start, 'activated_at' => $this->start,
            'horizon_bars' => 10, 'bars_seen' => 0,
            'status' => SignalOutcome::LOST, 'first_hit' => 'sl', 'resolved_at' => $this->start,
            'context' => ['scoring_version' => 2],
        ]);
    }

    /**
     * @param  array<int, array{0: float, 1: float, 2: float, 3: float}>  $ohlc
     */
    private function bars(array $ohlc): void
    {
        foreach ($ohlc as $i => [$open, $high, $low, $close]) {
            Candle::create([
                'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
                'symbol' => self::SYMBOL, 'timeframe' => 'M5',
                'open_time' => $this->start->copy()->addMinutes(5 * $i),
                'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close,
            ]);
        }
    }
}

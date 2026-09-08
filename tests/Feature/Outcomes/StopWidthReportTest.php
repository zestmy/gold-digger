<?php

namespace Tests\Feature\Outcomes;

use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\SignalOutcome;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Outcomes\StopWidthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The stop-width report.
 *
 * Is the stop too tight, or the entry too early? The summary numbers cannot say, because
 * they do not record which came first. This re-walks the bars with the stop at other
 * widths, and the one thing it must get right is that the ladder moves with the stop -
 * a wider stop scored against the old target would flatter every row.
 */
class StopWidthReportTest extends TestCase
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
     * A buy from 2000 with a 5.00 stop. The first bar dips to 1993 - past the stop as
     * traded, inside a stop twice as wide - then price runs to 2012. At 1x it is a loss;
     * at 2x the stop holds and the first target (2010) is reached: a win.
     */
    public function test_a_wider_stop_turns_a_noise_stop_out_into_a_win(): void
    {
        $this->outcome('buy', reference: 2000.0, stop: 1995.0);
        $this->bars([
            [2000.0, 2001.0, 1993.0, 1994.0],
            [1994.0, 2012.0, 1994.0, 2011.0],
            [2011.0, 2011.0, 2005.0, 2006.0],
        ]);

        $report = (new StopWidthReport)->forUser($this->user->id, multiples: [1.0, 2.0]);

        $this->assertSame(1, $report['scorable']);

        [$asTraded, $doubled] = $report['rows'];

        $this->assertSame('lost', $asTraded['lost'] === 1 ? 'lost' : 'won');
        $this->assertSame(0.0, $asTraded['win_rate']);
        $this->assertSame(-1.0, $asTraded['expectancy_r']);

        $this->assertSame(1, $doubled['won']);
        $this->assertSame(100.0, $doubled['win_rate']);
        $this->assertSame(1.0, $doubled['expectancy_r']);
        $this->assertSame(2.0, $doubled['avg_tp1_bars']);
    }

    /**
     * The ladder moves with the stop. A bar that reaches 2005 is TP1 at 1x and nothing at
     * 2x, where TP1 sits at 2010 - so the wider stop is not credited with a target it
     * never reached.
     */
    public function test_the_targets_move_with_the_stop(): void
    {
        $this->outcome('buy', reference: 2000.0, stop: 1995.0);
        $this->bars([
            [2000.0, 2005.5, 1999.0, 2004.0],
            [2004.0, 2004.0, 1998.0, 1999.0],
        ]);

        $report = (new StopWidthReport)->forUser($this->user->id, multiples: [1.0, 2.0]);
        [$asTraded, $doubled] = $report['rows'];

        $this->assertSame(1, $asTraded['won']);
        $this->assertSame(0, $doubled['won']);
        $this->assertSame(1, $doubled['expired']);
    }

    /**
     * A bar spanning both the stop and the target is a loss at every width - the same
     * pessimism the tracker applies, or the report would disagree with the numbers it is
     * meant to explain.
     */
    public function test_a_bar_through_both_levels_is_a_loss(): void
    {
        $this->outcome('sell', reference: 2000.0, stop: 2005.0);
        $this->bars([
            [2000.0, 2006.0, 1994.0, 1995.0],
        ]);

        $report = (new StopWidthReport)->forUser($this->user->id, multiples: [1.0]);

        $this->assertSame(1, $report['rows'][0]['lost']);
    }

    public function test_a_signal_whose_bars_were_pruned_is_counted_not_guessed(): void
    {
        $this->outcome('buy', reference: 2000.0, stop: 1995.0);

        $report = (new StopWidthReport)->forUser($this->user->id, multiples: [1.0, 1.5]);

        $this->assertSame(0, $report['scorable']);
        $this->assertSame(1, $report['unscorable']);
        $this->assertNull($report['rows'][0]['win_rate']);
    }

    public function test_the_command_prints_a_row_per_width(): void
    {
        $this->outcome('buy', reference: 2000.0, stop: 1995.0);
        $this->bars([
            [2000.0, 2001.0, 1993.0, 1994.0],
            [1994.0, 2012.0, 1994.0, 2011.0],
        ]);

        $this->artisan('signals:stop-width --multiples=1,2')
            ->expectsOutputToContain('1 scorable')
            ->expectsOutputToContain('1.00 (as traded)')
            ->expectsOutputToContain('2.00')
            ->assertSuccessful();
    }

    public function test_the_performance_page_shows_the_table(): void
    {
        $this->outcome('buy', reference: 2000.0, stop: 1995.0);
        $this->bars([
            [2000.0, 2001.0, 1993.0, 1994.0],
            [1994.0, 2012.0, 1994.0, 2011.0],
        ]);

        // The fixture is dated in March; the page defaults to the last thirty days.
        $this->actingAs($this->user)
            ->get(route('analytics', ['period' => 'all']))
            ->assertOk()
            ->assertSee('If the stop had been wider')
            ->assertSee('as traded');
    }

    private function outcome(string $direction, float $reference, float $stop): SignalOutcome
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

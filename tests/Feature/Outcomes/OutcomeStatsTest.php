<?php

namespace Tests\Feature\Outcomes;

use App\Models\BrokerAccount;
use App\Models\SignalOutcome;
use App\Models\User;
use App\Services\Outcomes\OutcomeStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the outcomes add up to. The arithmetic is simple; what these pin is the
 * definitions - what counts as decided, what expectancy is measured on, and that every
 * group carries its sample size.
 */
class OutcomeStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);
    }

    public function test_the_win_rate_counts_decided_signals_only_and_expectancy_is_on_tp1(): void
    {
        // Two won at 0.6R each, one lost, one expired, one still open.
        $this->outcome(['status' => 'won', 'first_hit' => 'tp1', 'tp1_bars' => 3, 'mfe_r' => 1.2, 'mae_r' => -0.3]);
        $this->outcome(['status' => 'won', 'first_hit' => 'tp1', 'tp1_bars' => 5, 'mfe_r' => 0.8, 'mae_r' => -0.5]);
        $this->outcome(['status' => 'lost', 'first_hit' => 'sl', 'sl_bars' => 2, 'mfe_r' => 0.2, 'mae_r' => -1.0]);
        $this->outcome(['status' => 'expired', 'mfe_r' => 0.4, 'mae_r' => -0.4]);
        $this->outcome(['status' => 'open', 'bars_seen' => 0]);

        $stats = app(OutcomeStats::class)->forUser($this->user->id);

        $this->assertSame(5, $stats['tracked']);
        $this->assertSame(1, $stats['open']);
        $this->assertSame(2, $stats['won']);
        $this->assertSame(1, $stats['lost']);
        $this->assertSame(1, $stats['expired']);
        // 2 of 3 decided.
        $this->assertEqualsWithDelta(66.7, $stats['win_rate'], 0.05);
        // (0.6 + 0.6 - 1) / 3
        $this->assertEqualsWithDelta(0.07, $stats['expectancy_r'], 0.005);
        $this->assertEqualsWithDelta(0.65, $stats['avg_mfe_r'], 0.005);
        $this->assertEqualsWithDelta(4.0, $stats['avg_tp1_bars'], 1e-6);
        $this->assertEqualsWithDelta(2.0, $stats['avg_sl_bars'], 1e-6);
        $this->assertTrue($stats['thin']);
    }

    public function test_breakdowns_carry_their_own_counts(): void
    {
        $this->outcome(['status' => 'won', 'first_hit' => 'tp1', 'source' => 'ai', 'context' => ['confidence' => 80, 'sessions' => ['London'], 'instrument' => 'gold', 'hour_utc' => 9]]);
        $this->outcome(['status' => 'lost', 'first_hit' => 'sl', 'source' => 'ai', 'context' => ['confidence' => 60, 'sessions' => ['London', 'New York'], 'instrument' => 'gold', 'hour_utc' => 13]]);
        $this->outcome(['status' => 'won', 'first_hit' => 'tp1', 'source' => 'copied', 'context' => ['sessions' => [], 'instrument' => 'major', 'hour_utc' => 22]]);

        $stats = app(OutcomeStats::class)->forUser($this->user->id);

        $this->assertSame(2, $stats['by_source']['ai']['won'] + $stats['by_source']['ai']['lost']);
        $this->assertEqualsWithDelta(50.0, $stats['by_source']['ai']['win_rate'], 1e-6);
        $this->assertEqualsWithDelta(100.0, $stats['by_source']['copied']['win_rate'], 1e-6);

        $this->assertArrayHasKey('70% and up', $stats['by_confidence']);
        $this->assertArrayHasKey('55–69%', $stats['by_confidence']);
        $this->assertArrayHasKey('unscored', $stats['by_confidence']);

        $this->assertArrayHasKey('London', $stats['by_session']);
        $this->assertArrayHasKey('overlap', $stats['by_session']);
        $this->assertArrayHasKey('none open', $stats['by_session']);

        $this->assertSame(2, $stats['by_instrument']['gold']['tracked']);
    }

    public function test_another_users_outcomes_are_not_counted(): void
    {
        $other = User::factory()->create();
        $this->outcome(['status' => 'won', 'first_hit' => 'tp1', 'user_id' => $other->id]);

        $this->assertSame(0, app(OutcomeStats::class)->forUser($this->user->id)['tracked']);
    }

    private function outcome(array $overrides = []): SignalOutcome
    {
        static $n = 0;
        $n++;

        return SignalOutcome::create(array_merge([
            'user_id' => $this->user->id,
            'subject_type' => 'App\Models\Signal',
            'subject_id' => $n,
            'source' => 'ai',
            'broker_account_id' => $this->account->id,
            'symbol' => 'XAUUSDm',
            'timeframe' => 'M5',
            'direction' => 'buy',
            'reference_price' => 2000.0,
            'stop_price' => 1995.0,
            'tp1_price' => 2003.0,
            'risk' => 5.0,
            'started_at' => now()->subHours(2),
            'bars_seen' => 5,
            'horizon_bars' => 100,
            'status' => 'open',
            'context' => ['confidence' => 78, 'sessions' => ['London'], 'instrument' => 'gold', 'hour_utc' => 9],
        ], $overrides));
    }
}

<?php

namespace Tests\Feature\Signals;

use App\Livewire\Pages\Settings;
use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\User;
use App\Services\Trading\EquityDrawdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The peak-to-trough halt.
 *
 * `max_daily_loss_percentage` asks whether today went badly and forgets at midnight. This
 * asks how far the account is below its own best and never forgets, which is the only one
 * of the two that can see an account bleeding a quarter of itself over three weeks without
 * any single day breaching a 3% limit.
 *
 * The peak has to come from the heartbeat and has to be stored: derived from
 * `bot_heartbeats` it would be wrong the moment `data:prune` ran, and wrong in the
 * direction that makes a drawdown look smaller than it is.
 */
class DrawdownHaltTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Elev8 Demo',
            'broker_name' => 'Elev8',
            'account_number' => '1',
            'server' => 'Elev8-Demo',
            'is_demo' => true,
            'is_active' => true,
        ]);

        [$this->token] = BotToken::generate($this->user, 'Terminal', $this->account);
    }

    // =====================================================================
    // THE PEAK
    // =====================================================================

    public function test_the_heartbeat_records_the_accounts_high_water_mark(): void
    {
        $this->beat(balance: 10000, equity: 10000)->assertOk();

        $this->assertEqualsWithDelta(10000, (float) $this->account->fresh()->peak_equity, 0.001);
        $this->assertNotNull($this->account->fresh()->peak_equity_at);

        $this->beat(balance: 10500, equity: 10800)->assertOk();

        // Equity, not balance: a position still open is money still at risk, and it is the
        // number the halt below measures against.
        $this->assertEqualsWithDelta(10800, (float) $this->account->fresh()->peak_equity, 0.001);
    }

    public function test_a_lower_reading_does_not_move_the_peak(): void
    {
        $this->beat(balance: 10000, equity: 10000)->assertOk();
        $setAt = $this->account->fresh()->peak_equity_at;

        $this->beat(balance: 9000, equity: 8500)->assertOk();

        $this->assertEqualsWithDelta(10000, (float) $this->account->fresh()->peak_equity, 0.001);
        $this->assertEquals($setAt, $this->account->fresh()->peak_equity_at, 'the date the peak was set should not move with a loss');
    }

    /**
     * An older executor that reports no equity still marks a peak, from the balance. The
     * alternative is a limit that silently stops working against an EA that predates the
     * field.
     */
    public function test_a_heartbeat_without_equity_falls_back_to_the_balance(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/bot/heartbeat', [
            'source' => 'mql5_ea',
            'algo_trading_enabled' => true,
            'broker_connected' => true,
            'balance' => 7500,
        ])->assertOk();

        $this->assertEqualsWithDelta(7500, (float) $this->account->fresh()->peak_equity, 0.001);
    }

    // =====================================================================
    // THE HALT
    // =====================================================================

    public function test_an_account_past_its_limit_is_halted(): void
    {
        $settings = $this->settings(['max_drawdown_percentage' => 10.00]);

        // 10,000 at its best, 8,900 now: 11% down.
        $this->account->forceFill(['peak_equity' => 10000, 'peak_equity_at' => now()])->save();
        $heartbeat = $this->heartbeat(equity: 8900);

        $drawdown = new EquityDrawdown;

        $this->assertEqualsWithDelta(11.0, $drawdown->percent($heartbeat, $this->account), 0.01);
        $this->assertSame('drawdown_limit', $drawdown->objection($settings, $heartbeat, $this->account));
    }

    public function test_an_account_inside_its_limit_is_not(): void
    {
        $settings = $this->settings(['max_drawdown_percentage' => 10.00]);

        $this->account->forceFill(['peak_equity' => 10000, 'peak_equity_at' => now()])->save();

        $this->assertNull((new EquityDrawdown)->objection($settings, $this->heartbeat(equity: 9500), $this->account));
    }

    /**
     * The halt lifts itself. It blocks new entries and nothing else - it never closes a
     * position - so there is no state to unwind when equity comes back.
     */
    public function test_the_halt_lifts_when_equity_recovers(): void
    {
        $settings = $this->settings(['max_drawdown_percentage' => 10.00]);
        $this->account->forceFill(['peak_equity' => 10000, 'peak_equity_at' => now()])->save();

        $drawdown = new EquityDrawdown;

        $this->assertSame('drawdown_limit', $drawdown->objection($settings, $this->heartbeat(equity: 8000), $this->account));
        $this->assertNull($drawdown->objection($settings, $this->heartbeat(equity: 9600), $this->account));
    }

    /**
     * Nothing starts halting because a migration ran. An account that has never been given
     * a limit trades exactly as it did before.
     */
    public function test_no_limit_configured_is_no_halt(): void
    {
        $settings = $this->settings(['max_drawdown_percentage' => null]);
        $this->account->forceFill(['peak_equity' => 10000, 'peak_equity_at' => now()])->save();

        $this->assertNull((new EquityDrawdown)->objection($settings, $this->heartbeat(equity: 1), $this->account));
    }

    public function test_an_account_with_no_peak_yet_cannot_be_in_drawdown(): void
    {
        $settings = $this->settings(['max_drawdown_percentage' => 10.00]);

        $this->assertNull((new EquityDrawdown)->percent($this->heartbeat(equity: 8000), $this->account));
        $this->assertNull((new EquityDrawdown)->objection($settings, $this->heartbeat(equity: 8000), $this->account));
    }

    // =====================================================================
    // THE RESET
    // =====================================================================

    /**
     * The footgun this exists to defuse: a withdrawal is indistinguishable from a loss, so
     * without a reset, taking money out of an account halts it for good.
     */
    public function test_the_peak_can_be_taken_again_from_current_equity(): void
    {
        $this->beat(balance: 10000, equity: 10000)->assertOk();

        // Two thousand withdrawn. Nothing here can tell that from a loss.
        $this->beat(balance: 8000, equity: 8000)->assertOk();

        $settings = $this->settings(['max_drawdown_percentage' => 10.00]);
        $heartbeat = BotHeartbeat::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame('drawdown_limit', (new EquityDrawdown)->objection($settings, $heartbeat, $this->account->fresh()));

        Livewire::actingAs($this->user)
            ->test(Settings::class)
            ->call('resetPeakEquity');

        $this->assertEqualsWithDelta(8000, (float) $this->account->fresh()->peak_equity, 0.001);
        $this->assertNull((new EquityDrawdown)->objection($settings, $heartbeat, $this->account->fresh()));
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function settings(array $attributes): BotSettings
    {
        $settings = BotSettings::firstOrCreate(['user_id' => $this->user->id]);
        $settings->update($attributes);

        return $settings->fresh();
    }

    private function heartbeat(float $equity): BotHeartbeat
    {
        return BotHeartbeat::updateOrCreate(
            ['user_id' => $this->user->id, 'broker_account_id' => $this->account->id, 'source' => 'mql5_ea'],
            [
                'algo_trading_enabled' => true,
                'broker_connected' => true,
                'balance' => $equity,
                'equity' => $equity,
                'last_seen_at' => now(),
            ],
        );
    }

    private function beat(float $balance, float $equity)
    {
        return $this->withToken($this->token)->postJson('/api/v1/bot/heartbeat', [
            'source' => 'mql5_ea',
            'algo_trading_enabled' => true,
            'broker_connected' => true,
            'balance' => $balance,
            'equity' => $equity,
        ]);
    }
}

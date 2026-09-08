<?php

namespace Tests\Feature\Outcomes;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Signal;
use App\Models\SignalOutcome;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesPriceSeries;
use Tests\TestCase;

/**
 * The two triggers and the two places outcomes show.
 */
class OutcomeSurfacesTest extends TestCase
{
    use MakesPriceSeries;
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private string $token;

    private Carbon $bar;

    private const SYMBOL = 'XAUUSDm';

    protected function setUp(): void
    {
        parent::setUp();

        config(['outcomes.horizon_bars' => 10]);

        $this->user = User::factory()->create();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);
        [$this->token] = BotToken::generate($this->user, 'Terminal', $this->account);

        BotHeartbeat::create([
            'user_id' => $this->user->id, 'broker_account_id' => $this->account->id, 'source' => 'mql5_ea',
            'resolved_symbol' => self::SYMBOL, 'pip_size' => 0.10, 'last_seen_at' => now(),
        ]);

        // Nothing here is about the strategy firing; keep it quiet.
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update(['is_active' => false]);
        BotSettings::where('user_id', $this->user->id)->update(['is_active' => false]);

        // Recent, because the tracker only opens signals inside its backfill window - and
        // on a five-minute boundary, because that is where M5 bars open.
        $this->bar = now()->utc()->subHours(2)->startOfMinute();
        $this->bar->subMinutes($this->bar->minute % 5);
    }

    /**
     * The prompt trigger: bars pushed by the terminal score the signals waiting on them.
     */
    public function test_a_candle_push_advances_the_outcomes_on_that_series(): void
    {
        $signal = $this->signal();
        $this->artisan('signals:track')->assertSuccessful();

        // Three bars after the signal bar; the second reaches TP1 at 2003.
        $this->postJson('/api/v1/bot/candles', [
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'bars' => $this->barPayloads([2001.0, 2002.5, 2001.0], 'M5', $this->bar->copy()->addMinutes(15)),
        ], ['Authorization' => 'Bearer '.$this->token])->assertCreated();

        $outcome = $signal->fresh()->outcome;

        $this->assertSame(SignalOutcome::WON, $outcome->status);
        $this->assertSame(2, $outcome->tp1_bars);
    }

    public function test_the_tracking_command_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'signals:track'));

        $this->assertNotNull($event, 'signals:track is not scheduled.');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_performance_tab_shows_signal_outcomes_with_their_sample_size(): void
    {
        $this->actingAs($this->user);

        $signal = $this->signal();
        $this->artisan('signals:track');
        SignalOutcome::sole()->update(['status' => 'won', 'first_hit' => 'tp1', 'tp1_bars' => 3, 'mfe_r' => 1.1, 'mae_r' => -0.2, 'bars_seen' => 3]);

        $this->get(route('analytics'))
            ->assertOk()
            ->assertSee('Signal outcomes')
            ->assertSee('100.0%')
            ->assertSee('Fewer than 30 decided signals');
    }

    public function test_the_signals_feed_says_what_became_of_each_signal(): void
    {
        $this->actingAs($this->user);

        $this->signal();
        $this->artisan('signals:track');
        SignalOutcome::sole()->update(['status' => 'lost', 'first_hit' => 'sl', 'sl_bars' => 2, 'bars_seen' => 2, 'mfe_r' => 0.1, 'mae_r' => -1.0]);

        $this->get(route('signals'))
            ->assertOk()
            ->assertSee('Stopped in 2 bars');
    }

    private function signal(): Signal
    {
        return Signal::create([
            'strategy_id' => $this->strategy->id,
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'direction' => 'buy',
            'entry_price' => 2000.00,
            'sl_price' => 1995.00,
            'tp1_price' => 2003.00,
            'tp2_price' => 2010.00,
            'tp3_price' => 2020.00,
            'features' => ['adx' => 28.0, 'atr' => 3.2],
            'was_executed' => false,
            'skip_reason' => 'bot_inactive',
            'generated_at' => $this->bar,
        ]);
    }
}

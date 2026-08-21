<?php

namespace Tests\Feature\Bot;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\TradeCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\MakesPriceSeries;
use Tests\TestCase;

/**
 * Candle ingestion, and the signal generation it triggers.
 *
 * This is the entry point of the strategy layer: bars arrive here, and a genuinely new bar
 * is what makes the strategy run. The EA cannot be exercised in CI, so the protocol it
 * depends on is pinned here.
 */
class CandleApiTest extends TestCase
{
    use MakesPriceSeries;
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private string $plaintext;

    private Carbon $lastBar;

    private const SYMBOL = 'XAUUSDm';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Octa Demo',
            'broker_name' => 'Octa',
            'account_number' => '12345678',
            'server' => 'OctaFX-Demo',
            'is_demo' => true,
            'is_active' => true,
        ]);

        [$this->plaintext] = BotToken::generate($this->user, 'Test VPS', $this->account);

        BotSettings::where('user_id', $this->user->id)->update([
            'is_active' => true,
            'allowed_sessions' => null,
            'min_atr_threshold' => null,
        ]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update(['is_active' => true, 'adx_threshold' => 0]);

        BotHeartbeat::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea',
            'algo_trading_enabled' => true,
            'broker_connected' => true,
            'resolved_symbol' => self::SYMBOL,
            'pip_size' => 0.10,
            'digits' => 2,
            'pip_value_per_lot' => 10.0,
            'balance' => 10000.00,
            'last_seen_at' => now(),
        ]);

        $this->lastBar = Carbon::parse('2026-03-10 13:00:00', 'UTC');
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->plaintext];
    }

    private function push(array $closes, string $timeframe): TestResponse
    {
        return $this->postJson('/api/v1/bot/candles', [
            'symbol' => self::SYMBOL,
            'timeframe' => $timeframe,
            'bars' => $this->barPayloads($closes, $timeframe, $this->lastBar),
        ], $this->auth());
    }

    // =====================================================================
    // AUTHENTICATION
    // =====================================================================

    public function test_candles_require_a_token(): void
    {
        $this->postJson('/api/v1/bot/candles', [])->assertStatus(401);
    }

    /**
     * The unique index that stops a re-pushed bar being stored twice cannot fire on a NULL
     * account id, so an unbound token would quietly accumulate duplicates.
     */
    public function test_a_token_not_bound_to_an_account_is_refused(): void
    {
        [$plaintext] = BotToken::generate($this->user, 'Unbound', null);

        $this->postJson('/api/v1/bot/candles', [
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'bars' => $this->barPayloads([2000.0, 2001.0], 'M5', $this->lastBar),
        ], ['Authorization' => 'Bearer '.$plaintext])->assertStatus(422);

        $this->assertSame(0, Candle::count());
    }

    // =====================================================================
    // STORAGE
    // =====================================================================

    public function test_bars_are_stored_against_the_tokens_account(): void
    {
        $response = $this->push([2000.0, 2001.0, 2002.0], 'M5');

        $response->assertStatus(201)->assertJson(['stored' => 3, 'new_bars' => 3]);

        $this->assertSame(3, Candle::count());
        $this->assertSame($this->account->id, Candle::first()->broker_account_id);
    }

    /**
     * The EA sends UTC unix timestamps because MT5's own iTime() is broker-server time,
     * which is usually UTC+2 or UTC+3. Storing that unconverted would place every bar in
     * an hour it did not happen in and gate the session filter against the wrong window.
     */
    public function test_bar_times_round_trip_as_utc(): void
    {
        $this->push([2000.0], 'M5');

        $this->assertSame(
            $this->lastBar->toDateTimeString(),
            Candle::first()->open_time->utc()->toDateTimeString(),
        );
    }

    public function test_timeframes_are_normalised_to_upper_case(): void
    {
        $this->postJson('/api/v1/bot/candles', [
            'symbol' => self::SYMBOL,
            'timeframe' => 'm5',
            'bars' => $this->barPayloads([2000.0], 'M5', $this->lastBar),
        ], $this->auth())->assertStatus(201);

        $this->assertSame('M5', Candle::first()->timeframe);
    }

    /**
     * The EA re-pushes a trailing window on every poll so a dropped request self-heals.
     * That window overlaps what is already stored, and a duplicated bar would corrupt
     * every indicator computed over it.
     */
    public function test_re_pushing_the_same_window_overwrites_rather_than_duplicates(): void
    {
        $this->push([2000.0, 2001.0, 2002.0], 'M5')->assertJson(['new_bars' => 3]);
        $this->push([2000.0, 2001.0, 2002.0], 'M5')->assertJson(['new_bars' => 0]);

        $this->assertSame(3, Candle::count());
    }

    public function test_a_corrected_bar_replaces_the_stored_one(): void
    {
        $this->push([2000.0], 'M5');

        $this->postJson('/api/v1/bot/candles', [
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'bars' => [[
                'time' => $this->lastBar->getTimestamp(),
                'open' => 2000.0,
                'high' => 2050.0,
                'low' => 1990.0,
                'close' => 2040.0,
            ]],
        ], $this->auth())->assertStatus(201);

        $this->assertSame(1, Candle::count());
        $this->assertEqualsWithDelta(2040.0, Candle::first()->close, 1e-9);
    }

    // =====================================================================
    // VALIDATION
    // =====================================================================

    public function test_a_bar_missing_its_prices_is_rejected(): void
    {
        $this->postJson('/api/v1/bot/candles', [
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'bars' => [['time' => $this->lastBar->getTimestamp(), 'open' => 2000.0]],
        ], $this->auth())->assertStatus(422);
    }

    public function test_an_empty_push_is_rejected(): void
    {
        $this->postJson('/api/v1/bot/candles', [
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'bars' => [],
        ], $this->auth())->assertStatus(422);
    }

    // =====================================================================
    // SIGNAL GENERATION
    // =====================================================================

    /**
     * The end-to-end path: a trend series, then an entry series whose last bar crosses.
     * The push that carries the crossing bar is the one that must produce the entry.
     */
    public function test_a_new_entry_bar_generates_a_signal_and_queues_an_open_command(): void
    {
        $this->push($this->trendCloses(80, rising: true), 'H1');
        $response = $this->push($this->crossCloses('buy'), 'M5');

        $response->assertStatus(201);

        $signal = Signal::first();

        $this->assertNotNull($signal, 'A crossing bar arriving by API must produce a signal.');
        $this->assertSame('buy', $signal->direction);
        $this->assertNull($signal->skip_reason);

        $this->assertSame(1, TradeCommand::where('type', 'open')->count());
        $response->assertJsonPath('signals.0.id', $signal->id);
    }

    /**
     * The trend series is an input to the next entry bar, not a trigger of its own. Its
     * own bar closing says nothing about whether an entry condition exists.
     */
    public function test_a_trend_timeframe_push_does_not_generate_signals(): void
    {
        $this->push($this->crossCloses('buy'), 'M5');
        Signal::query()->delete();
        TradeCommand::query()->delete();

        $this->push($this->trendCloses(80, rising: true), 'H1')
            ->assertStatus(201)
            ->assertJson(['signals' => []]);

        $this->assertSame(0, Signal::count());
    }

    /**
     * Re-pushing a window whose bars are all known must not re-run the strategy. Even
     * though Signal creation is keyed on the bar time, running the indicator stack every
     * poll for a bar already decided is work with no possible outcome.
     */
    public function test_a_push_with_no_new_bars_does_not_re_evaluate(): void
    {
        $this->push($this->trendCloses(80, rising: true), 'H1');
        $this->push($this->crossCloses('buy'), 'M5');

        $this->assertSame(1, Signal::count());

        $this->push($this->crossCloses('buy'), 'M5')
            ->assertJson(['new_bars' => 0, 'signals' => []]);

        $this->assertSame(1, Signal::count());
        $this->assertSame(1, TradeCommand::where('type', 'open')->count());
    }

    /**
     * Series belong to an account. Another user's token must not be able to read or extend
     * this user's series, and must not trigger this user's strategies.
     */
    public function test_another_users_token_writes_a_separate_series(): void
    {
        $other = User::factory()->create();

        $otherAccount = BrokerAccount::create([
            'user_id' => $other->id,
            'label' => 'Other',
            'broker_name' => 'Octa',
            'account_number' => '999',
            'server' => 'OctaFX-Demo',
            'is_demo' => true,
            'is_active' => true,
        ]);

        [$otherToken] = BotToken::generate($other, 'Other VPS', $otherAccount);

        $this->push([2000.0, 2001.0], 'M5');

        $this->postJson('/api/v1/bot/candles', [
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'bars' => $this->barPayloads([2000.0, 2001.0], 'M5', $this->lastBar),
        ], ['Authorization' => 'Bearer '.$otherToken])->assertStatus(201);

        $this->assertSame(2, Candle::where('broker_account_id', $this->account->id)->count());
        $this->assertSame(2, Candle::where('broker_account_id', $otherAccount->id)->count());
    }

    // =====================================================================
    // CLOSING THE LOOP
    // =====================================================================

    /**
     * `was_executed` means a position exists, not that a command was queued - a command can
     * still expire or be rejected. The broker confirming the fill is the first moment the
     * signal has actually been traded.
     */
    public function test_a_fill_marks_the_signal_executed_and_links_the_trade(): void
    {
        $this->push($this->trendCloses(80, rising: true), 'H1');
        $this->push($this->crossCloses('buy'), 'M5');

        $signal = Signal::firstOrFail();
        $command = TradeCommand::where('type', 'open')->firstOrFail();

        $this->assertFalse($signal->was_executed, 'A queued command is an intention, not an execution.');

        $this->postJson('/api/v1/bot/fills', [
            'event' => 'opened',
            'command_id' => $command->id,
            'ticket' => 555001,
            'symbol' => self::SYMBOL,
            'direction' => 'buy',
            'volume' => 0.10,
            'price' => 2300.00,
            'sl' => 2290.00,
        ], $this->auth())->assertStatus(201);

        $signal->refresh();

        $this->assertTrue($signal->was_executed);
        $this->assertNotNull($signal->resulting_trade_id);
    }
}

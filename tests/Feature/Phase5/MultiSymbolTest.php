<?php

namespace Tests\Feature\Phase5;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\SymbolSpec;
use App\Models\User;
use App\Services\Strategy\SymbolResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Per-symbol specifications, and what they unlock.
 *
 * The single-symbol limit was never a decision - it was `pip_size` and `pip_value_per_lot`
 * living on `bot_heartbeats`, a table with room for exactly one instrument's figures, read
 * directly by three separate parts of the strategy layer. These tests are mostly about the
 * consequences of moving them: that a second instrument now works, that an upgrade does not
 * break the first, and that nothing borrows one symbol's numbers for another.
 */
class MultiSymbolTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private string $plaintext;

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

        [$this->plaintext] = BotToken::generate($this->user, 'Test VPS', $this->account);

        BotSettings::where('user_id', $this->user->id)->update(['is_active' => true]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->plaintext];
    }

    private function push(string $symbol, string $base, array $spec = []): TestResponse
    {
        return $this->postJson('/api/v1/bot/candles', [
            'symbol' => $symbol,
            'base_symbol' => $base,
            'timeframe' => 'M5',
            'spec' => array_merge([
                'pip_size' => 0.10,
                'digits' => 2,
                'pip_value_per_lot' => 10.0,
                'volume_min' => 0.01,
                'volume_step' => 0.01,
            ], $spec),
            'bars' => [[
                'time' => Carbon::parse('2026-03-10 13:00:00', 'UTC')->getTimestamp(),
                'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000,
            ]],
        ], $this->auth());
    }

    // =====================================================================
    // INGESTION
    // =====================================================================

    /**
     * The spec arrives with the bars it describes, so a symbol with price history always has
     * one and the two cannot drift apart.
     */
    public function test_a_candle_push_records_the_symbol_specification(): void
    {
        $this->push('XAUUSDm', 'XAUUSD')->assertStatus(201);

        $spec = SymbolSpec::where('broker_account_id', $this->account->id)->firstOrFail();

        $this->assertSame('XAUUSD', $spec->base_symbol);
        $this->assertSame('XAUUSDm', $spec->symbol);
        $this->assertEqualsWithDelta(0.10, (float) $spec->pip_size, 1e-9);
        $this->assertEqualsWithDelta(10.0, (float) $spec->pip_value_per_lot, 1e-9);
    }

    /**
     * The whole point. Two instruments, two sets of numbers, on one account.
     */
    public function test_two_instruments_keep_separate_specifications(): void
    {
        $this->push('XAUUSDm', 'XAUUSD', ['pip_size' => 0.10, 'pip_value_per_lot' => 10.0]);
        $this->push('EURUSDm', 'EURUSD', ['pip_size' => 0.0001, 'pip_value_per_lot' => 10.0, 'digits' => 5]);

        $this->assertSame(2, SymbolSpec::count());

        $gold = SymbolSpec::resolve($this->account->id, 'XAUUSD');
        $euro = SymbolSpec::resolve($this->account->id, 'EURUSD');

        $this->assertEqualsWithDelta(0.10, (float) $gold->pip_size, 1e-9);
        $this->assertEqualsWithDelta(0.0001, (float) $euro->pip_size, 1e-12);
    }

    public function test_re_pushing_updates_rather_than_duplicates(): void
    {
        $this->push('XAUUSDm', 'XAUUSD', ['pip_value_per_lot' => 10.0]);
        $this->push('XAUUSDm', 'XAUUSD', ['pip_value_per_lot' => 9.5]);

        $this->assertSame(1, SymbolSpec::count());
        $this->assertEqualsWithDelta(
            9.5,
            (float) SymbolSpec::resolve($this->account->id, 'XAUUSD')->pip_value_per_lot,
            1e-9,
        );
    }

    /**
     * An executor built before this existed sends no spec. It has to keep working rather than
     * failing the push.
     */
    public function test_a_push_without_a_spec_is_still_accepted(): void
    {
        $this->postJson('/api/v1/bot/candles', [
            'symbol' => 'XAUUSDm',
            'timeframe' => 'M5',
            'bars' => [[
                'time' => Carbon::parse('2026-03-10 13:00:00', 'UTC')->getTimestamp(),
                'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000,
            ]],
        ], $this->auth())->assertStatus(201);

        $this->assertSame(0, SymbolSpec::count());
    }

    /**
     * A broker with no suffix sends one name. The base defaults to it rather than being left
     * null, or nothing could ever look the spec up.
     */
    public function test_a_symbol_with_no_suffix_records_itself_as_its_own_base(): void
    {
        $this->postJson('/api/v1/bot/candles', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'M5',
            'spec' => ['pip_size' => 0.10, 'pip_value_per_lot' => 10.0],
            'bars' => [[
                'time' => Carbon::parse('2026-03-10 13:00:00', 'UTC')->getTimestamp(),
                'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000,
            ]],
        ], $this->auth())->assertStatus(201);

        $spec = SymbolSpec::firstOrFail();

        $this->assertSame('XAUUSD', $spec->base_symbol);
        $this->assertSame('XAUUSD', $spec->symbol);
    }

    // =====================================================================
    // RESOLUTION
    // =====================================================================

    /**
     * A strategy names the instrument in the abstract. The resolver says what this broker
     * calls it, which is what keeps a strategy portable between brokers.
     */
    public function test_a_generic_name_resolves_to_the_brokers_own(): void
    {
        $this->push('XAUUSDm', 'XAUUSD');

        $resolved = app(SymbolResolver::class)->for($this->account->id, 'XAUUSD');

        $this->assertSame('XAUUSDm', $resolved['symbol']);
        $this->assertSame('symbol_spec', $resolved['source']);
    }

    /**
     * Configuring a strategy with the broker's suffixed name is a reasonable thing to do and
     * would otherwise silently find nothing.
     */
    public function test_the_brokers_own_name_also_resolves(): void
    {
        $this->push('XAUUSDm', 'XAUUSD');

        $this->assertSame('XAUUSDm', app(SymbolResolver::class)->for($this->account->id, 'XAUUSDm')['symbol']);
    }

    /**
     * A deployment upgrading from before symbol_specs existed has its numbers on the heartbeat
     * and nowhere else. Without this fallback every signal would refuse itself until the
     * executor next pushed bars.
     */
    public function test_the_heartbeat_still_answers_for_its_own_symbol(): void
    {
        $heartbeat = BotHeartbeat::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea',
            'resolved_symbol' => 'XAUUSDm',
            'pip_size' => 0.10,
            'pip_value_per_lot' => 10.0,
            'volume_min' => 0.01,
            'last_seen_at' => now(),
        ]);

        $resolved = app(SymbolResolver::class)->for($this->account->id, 'XAUUSDm', $heartbeat);

        $this->assertSame('heartbeat', $resolved['source']);
        $this->assertEqualsWithDelta(10.0, $resolved['pip_value_per_lot'], 1e-9);
    }

    /**
     * The most important negative case. The heartbeat holds one instrument's figures, and
     * lending them to another would size a position from a pip value that belongs to a
     * different market - a silently wrong trade rather than a visible failure.
     */
    public function test_the_heartbeat_never_answers_for_a_different_symbol(): void
    {
        $heartbeat = BotHeartbeat::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea',
            'resolved_symbol' => 'XAUUSDm',
            'pip_size' => 0.10,
            'pip_value_per_lot' => 10.0,
            'last_seen_at' => now(),
        ]);

        $resolved = app(SymbolResolver::class)->for($this->account->id, 'EURUSD', $heartbeat);

        $this->assertSame('unknown', $resolved['source']);
        $this->assertNull($resolved['pip_value_per_lot']);
    }

    /**
     * A stored spec is the authority. The heartbeat is a compatibility shim, not a rival
     * source of truth.
     */
    public function test_a_stored_spec_wins_over_the_heartbeat(): void
    {
        $this->push('XAUUSDm', 'XAUUSD', ['pip_value_per_lot' => 7.5]);

        $heartbeat = BotHeartbeat::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea',
            'resolved_symbol' => 'XAUUSDm',
            'pip_size' => 0.10,
            'pip_value_per_lot' => 10.0,
            'last_seen_at' => now(),
        ]);

        $resolved = app(SymbolResolver::class)->for($this->account->id, 'XAUUSD', $heartbeat);

        $this->assertSame('symbol_spec', $resolved['source']);
        $this->assertEqualsWithDelta(7.5, $resolved['pip_value_per_lot'], 1e-9);
    }

    public function test_an_unknown_symbol_yields_nothing_rather_than_a_guess(): void
    {
        $resolved = app(SymbolResolver::class)->for($this->account->id, 'GBPJPY');

        $this->assertSame('unknown', $resolved['source']);
        $this->assertNull($resolved['pip_size']);
        $this->assertNull($resolved['pip_value_per_lot']);
    }

    /**
     * Specs belong to an account. Two brokers quote the same instrument with different
     * contract sizes, and borrowing across them would size positions from the wrong numbers.
     */
    public function test_specifications_do_not_cross_accounts(): void
    {
        $this->push('XAUUSDm', 'XAUUSD');

        $other = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Second',
            'broker_name' => 'Exness',
            'account_number' => '2',
            'server' => 'Exness-Real',
            'is_demo' => false,
            'is_active' => false,
        ]);

        $this->assertNull(SymbolSpec::resolve($other->id, 'XAUUSD'));
        $this->assertSame('unknown', app(SymbolResolver::class)->for($other->id, 'XAUUSD')['source']);
    }

    // =====================================================================
    // COMPLETENESS
    // =====================================================================

    public function test_a_spec_missing_its_pip_value_is_not_complete(): void
    {
        $spec = new SymbolSpec(['pip_size' => 0.10, 'pip_value_per_lot' => null]);

        $this->assertFalse($spec->isComplete());
    }

    public function test_a_full_spec_is_complete(): void
    {
        $spec = new SymbolSpec(['pip_size' => 0.10, 'pip_value_per_lot' => 10.0]);

        $this->assertTrue($spec->isComplete());
    }

    // =====================================================================
    // END TO END
    // =====================================================================

    /**
     * Two strategies on two instruments, each sized from its own numbers. This is what the
     * whole change was for.
     */
    public function test_two_strategies_on_two_instruments_use_their_own_specifications(): void
    {
        $this->push('XAUUSDm', 'XAUUSD', ['pip_size' => 0.10, 'pip_value_per_lot' => 10.0]);
        $this->push('EURUSDm', 'EURUSD', ['pip_size' => 0.0001, 'pip_value_per_lot' => 9.2, 'digits' => 5]);

        $gold = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $gold->update(['symbol' => 'XAUUSD', 'is_active' => true]);

        $euro = $gold->replicate();
        $euro->name = 'Euro scalp';
        $euro->symbol = 'EURUSD';
        $euro->save();

        $resolver = app(SymbolResolver::class);

        $a = $resolver->for($this->account->id, $gold->symbol);
        $b = $resolver->for($this->account->id, $euro->symbol);

        $this->assertSame('XAUUSDm', $a['symbol']);
        $this->assertSame('EURUSDm', $b['symbol']);
        $this->assertNotEqualsWithDelta($a['pip_value_per_lot'], $b['pip_value_per_lot'], 0.001);
        $this->assertNotEqualsWithDelta($a['pip_size'], $b['pip_size'], 1e-6);
    }
}

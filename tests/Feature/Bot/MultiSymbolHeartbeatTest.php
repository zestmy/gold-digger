<?php

namespace Tests\Feature\Bot;

use App\Models\BotHeartbeat;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A terminal that carries more than one instrument.
 *
 * The contract worth pinning: the list is additive. `resolved_symbol` still means the
 * primary and everything reading it is untouched, so an EA that predates this keeps
 * working and one that postdates it does not need a wire version to say more.
 */
class MultiSymbolHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        [$this->token] = BotToken::generate($this->user, 'Terminal');
    }

    public function test_the_terminal_reports_every_instrument_it_carries(): void
    {
        $this->beat([
            'resolved_symbol' => 'XAUUSDm',
            'symbols' => [
                ['base' => 'XAUUSD', 'resolved' => 'XAUUSDm'],
                ['base' => 'EURUSD', 'resolved' => 'EURUSDm'],
            ],
        ])->assertOk();

        $heartbeat = BotHeartbeat::first();

        $this->assertCount(2, $heartbeat->symbols);
        $this->assertSame('EURUSD', $heartbeat->symbols[1]['base']);
        // Unchanged meaning: the primary.
        $this->assertSame('XAUUSDm', $heartbeat->resolved_symbol);
    }

    /**
     * The property that made this need no wire-version bump.
     */
    public function test_an_older_ea_that_sends_no_list_still_beats(): void
    {
        $this->beat(['resolved_symbol' => 'XAUUSDm'])->assertOk();

        $this->assertNull(BotHeartbeat::first()->symbols);
        $this->assertSame('XAUUSDm', BotHeartbeat::first()->resolved_symbol);
    }

    public function test_a_malformed_list_is_refused_rather_than_half_stored(): void
    {
        $this->beat([
            'resolved_symbol' => 'XAUUSDm',
            'symbols' => [['base' => 'XAUUSD']],
        ])->assertStatus(422);
    }

    // =====================================================================
    // MORE THAN ONE EXECUTOR UNDER ONE USER
    // =====================================================================

    /**
     * The row was keyed on user + source, so two terminals for one user overwrote each
     * other on every poll and the strategy layer's lookup by account found no row for
     * whichever had lost the race - `no_account_snapshot` on every other signal.
     */
    public function test_two_accounts_under_one_user_each_keep_their_own_row(): void
    {
        [$elev8, $elev8Token] = $this->boundToken('Elev8', '11111111');
        [$exness, $exnessToken] = $this->boundToken('Exness', '22222222');

        $this->beat(['resolved_symbol' => 'XAUUSDm', 'pip_size' => 0.10, 'open_positions' => 2, 'balance' => 5000], $elev8Token)->assertOk();
        $this->beat(['resolved_symbol' => 'EURUSD.a', 'pip_size' => 0.0001, 'open_positions' => 0, 'balance' => 700], $exnessToken)->assertOk();
        // The first executor polls again. It must land on its own row, not the newer one.
        $this->beat(['resolved_symbol' => 'XAUUSDm', 'pip_size' => 0.10, 'open_positions' => 3, 'balance' => 5100], $elev8Token)->assertOk();

        $this->assertSame(2, BotHeartbeat::count());

        $first = BotHeartbeat::where('broker_account_id', $elev8->id)->firstOrFail();
        $second = BotHeartbeat::where('broker_account_id', $exness->id)->firstOrFail();

        $this->assertSame('XAUUSDm', $first->resolved_symbol);
        $this->assertSame(3, $first->open_positions);
        $this->assertSame('EURUSD.a', $second->resolved_symbol);
        $this->assertSame(0, $second->open_positions);
        $this->assertEqualsWithDelta(0.0001, (float) $second->pip_size, 1e-9);

        // Each account's cached balance is its own, too.
        $this->assertEqualsWithDelta(5100, (float) $elev8->fresh()->last_balance, 0.001);
        $this->assertEqualsWithDelta(700, (float) $exness->fresh()->last_balance, 0.001);
    }

    /**
     * A token with no binding still heartbeats, and still overwrites itself rather than
     * appending: NULL is distinct from every other NULL in a unique index, so this is the
     * upsert finding its own row by IS NULL rather than the index enforcing anything.
     */
    public function test_an_unbound_token_still_overwrites_its_own_row(): void
    {
        $this->beat(['resolved_symbol' => 'XAUUSDm', 'open_positions' => 1])->assertOk();
        $this->beat(['resolved_symbol' => 'XAUUSDm', 'open_positions' => 2])->assertOk();

        $this->assertSame(1, BotHeartbeat::count());
        $this->assertNull(BotHeartbeat::first()->broker_account_id);
        $this->assertSame(2, BotHeartbeat::first()->open_positions);
    }

    /**
     * An unbound token may say which account it describes - but only one of its owner's.
     */
    public function test_an_unbound_token_may_name_its_own_account_but_not_somebody_elses(): void
    {
        [$mine] = $this->boundToken('Elev8', '11111111');
        $theirs = BrokerAccount::create([
            'user_id' => User::factory()->create()->id,
            'label' => 'Not mine', 'broker_name' => 'Exness', 'account_number' => '99999999',
            'server' => 'Exness-Real', 'is_demo' => false, 'is_active' => true,
        ]);

        $this->beat(['resolved_symbol' => 'XAUUSDm', 'broker_account_id' => $theirs->id])->assertStatus(422);
        $this->beat(['resolved_symbol' => 'XAUUSDm', 'broker_account_id' => $mine->id])->assertOk();

        $this->assertSame($mine->id, BotHeartbeat::firstOrFail()->broker_account_id);
    }

    /**
     * @return array{0: BrokerAccount, 1: string}
     */
    private function boundToken(string $label, string $number): array
    {
        $account = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => $label,
            'broker_name' => $label,
            'account_number' => $number,
            'server' => "{$label}-Demo",
            'is_demo' => true,
            'is_active' => true,
        ]);

        [$plaintext] = BotToken::generate($this->user, "{$label} terminal", $account);

        return [$account, $plaintext];
    }

    private function beat(array $payload, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->postJson('/api/v1/bot/heartbeat', $payload + [
            'source' => 'mql5_ea',
            'algo_trading_enabled' => true,
            'broker_connected' => true,
        ]);
    }
}

<?php

namespace Tests\Feature\Bot;

use App\Models\BotHeartbeat;
use App\Models\BotToken;
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

    private function beat(array $payload)
    {
        return $this->withToken($this->token)->postJson('/api/v1/bot/heartbeat', $payload + [
            'source' => 'mql5_ea',
            'algo_trading_enabled' => true,
            'broker_connected' => true,
        ]);
    }
}

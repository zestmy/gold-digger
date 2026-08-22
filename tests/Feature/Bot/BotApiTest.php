<?php

namespace Tests\Feature\Bot;

use App\Models\BotHeartbeat;
use App\Models\BotLog;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\TradePartial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bot API contract tests.
 *
 * These cover the surface the MQL5 EA depends on. The EA cannot be exercised in CI -
 * it needs a Windows terminal - so this is where the protocol is pinned down. If the
 * wire format changes here without the EA changing, these tests are the thing that
 * notices.
 */
class BotApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private string $plaintext;

    protected function setUp(): void
    {
        parent::setUp();

        // UserObserver creates default BotSettings and a Strategy on create.
        $this->user = User::factory()->create();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Elev8 Demo',
            'broker_name' => 'Elev8',
            'account_number' => '12345678',
            'server' => 'Elev8-Demo',
            'is_demo' => true,
            'is_active' => true,
        ]);

        [$this->plaintext] = BotToken::generate($this->user, 'Test VPS', $this->account);
    }

    private function auth(array $headers = []): array
    {
        return array_merge(['Authorization' => 'Bearer '.$this->plaintext], $headers);
    }

    // =====================================================================
    // AUTHENTICATION
    // =====================================================================

    public function test_endpoints_reject_requests_without_a_token(): void
    {
        $this->getJson('/api/v1/bot/commands')->assertStatus(401);
        $this->postJson('/api/v1/bot/heartbeat')->assertStatus(401);
    }

    public function test_endpoints_reject_an_unknown_token(): void
    {
        $this->getJson('/api/v1/bot/commands', ['Authorization' => 'Bearer gd_nope'])
            ->assertStatus(401);
    }

    public function test_revoked_and_expired_tokens_are_rejected(): void
    {
        [$revoked, $revokedToken] = BotToken::generate($this->user, 'revoked');
        $revokedToken->update(['revoked_at' => now()]);

        [$expired, $expiredToken] = BotToken::generate($this->user, 'expired');
        $expiredToken->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/v1/bot/commands', ['Authorization' => 'Bearer '.$revoked])->assertStatus(401);
        $this->getJson('/api/v1/bot/commands', ['Authorization' => 'Bearer '.$expired])->assertStatus(401);
    }

    public function test_only_the_token_hash_is_stored(): void
    {
        $this->assertDatabaseMissing('bot_tokens', ['token_hash' => $this->plaintext]);
        $this->assertDatabaseHas('bot_tokens', ['token_hash' => hash('sha256', $this->plaintext)]);
    }

    // =====================================================================
    // CLAIMING COMMANDS
    // =====================================================================

    public function test_claiming_returns_pending_commands_and_marks_them_claimed(): void
    {
        $command = TradeCommand::enqueue($this->user, 'open', [
            'symbol' => 'XAUUSD', 'direction' => 'buy', 'volume' => 0.05,
        ], $this->account);

        $response = $this->getJson('/api/v1/bot/commands', $this->auth())->assertOk();

        $response->assertJsonPath('commands.0.id', $command->id);
        $response->assertJsonPath('commands.0.type', 'open');

        $this->assertSame('claimed', $command->fresh()->status);
        $this->assertSame(1, $command->fresh()->attempts);
    }

    public function test_a_claimed_command_is_not_handed_out_twice(): void
    {
        TradeCommand::enqueue($this->user, 'stop', [], $this->account);

        $this->getJson('/api/v1/bot/commands', $this->auth())->assertJsonCount(1, 'commands');
        $this->getJson('/api/v1/bot/commands', $this->auth())->assertJsonCount(0, 'commands');
    }

    public function test_expired_commands_are_never_claimed(): void
    {
        // A market order that waited out its window is not the trade the strategy
        // intended; filling it late is worse than not filling it.
        TradeCommand::enqueue($this->user, 'open', ['symbol' => 'XAUUSD'], $this->account, null, -1);

        $this->getJson('/api/v1/bot/commands', $this->auth())->assertJsonCount(0, 'commands');
    }

    public function test_a_token_bound_to_an_account_does_not_see_another_accounts_commands(): void
    {
        $other = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Elev8 Live',
            'broker_name' => 'Elev8',
            'account_number' => '87654321',
            'server' => 'Elev8-Real',
            'is_demo' => false,
            'is_active' => true,
        ]);

        TradeCommand::enqueue($this->user, 'open', ['symbol' => 'XAUUSD'], $other);
        $mine = TradeCommand::enqueue($this->user, 'open', ['symbol' => 'XAUUSD'], $this->account);
        $global = TradeCommand::enqueue($this->user, 'stop');

        $response = $this->getJson('/api/v1/bot/commands', $this->auth())->assertOk();

        // Account-agnostic commands (start/stop) still reach every executor.
        $ids = collect($response->json('commands'))->pluck('id')->all();
        sort($ids);
        $this->assertSame([$mine->id, $global->id], $ids);
    }

    public function test_a_token_cannot_see_another_users_commands(): void
    {
        $stranger = User::factory()->create();
        TradeCommand::enqueue($stranger, 'open', ['symbol' => 'XAUUSD']);

        $this->getJson('/api/v1/bot/commands', $this->auth())->assertJsonCount(0, 'commands');
    }

    public function test_enqueue_collapses_duplicates_on_the_idempotency_key(): void
    {
        // A double-clicked button must not open two positions.
        $a = TradeCommand::enqueue($this->user, 'close_all', [], $this->account, 'flatten-now');
        $b = TradeCommand::enqueue($this->user, 'close_all', [], $this->account, 'flatten-now');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, TradeCommand::count());
    }

    // =====================================================================
    // WIRE FORMAT (what the EA actually parses)
    // =====================================================================

    public function test_text_plain_returns_the_versioned_tab_separated_wire_format(): void
    {
        TradeCommand::enqueue($this->user, 'open', [
            'symbol' => 'XAUUSD', 'direction' => 'buy', 'volume' => 0.05,
            'sl_pips' => 30, 'tp_pips' => 15,
        ], $this->account);

        $response = $this->get('/api/v1/bot/commands', $this->auth(['Accept' => 'text/plain']))->assertOk();

        $lines = array_values(array_filter(explode("\n", $response->getContent())));

        $this->assertSame(TradeCommand::WIRE_VERSION, $lines[0]);
        $this->assertCount(count(TradeCommand::WIRE_COLUMNS), explode("\t", $lines[1]));

        $fields = explode("\t", $lines[1]);
        $this->assertSame('open', $fields[1]);
        $this->assertSame('XAUUSD', $fields[2]);
        $this->assertSame('buy', $fields[3]);
    }

    public function test_wire_format_keeps_a_constant_column_count_when_fields_are_absent(): void
    {
        // The EA splits on tabs and indexes by position; a missing field must be an
        // empty column, never a dropped one.
        TradeCommand::enqueue($this->user, 'stop', [], $this->account);

        $response = $this->get('/api/v1/bot/commands', $this->auth(['Accept' => 'text/plain']));
        $lines = array_values(array_filter(explode("\n", $response->getContent())));

        $this->assertCount(count(TradeCommand::WIRE_COLUMNS), explode("\t", $lines[1]));
    }

    public function test_wire_format_strips_tabs_and_newlines_from_free_text(): void
    {
        // A stray tab in a comment would shift every later column on the EA side.
        TradeCommand::enqueue($this->user, 'open', [
            'symbol' => 'XAUUSD', 'comment' => "bad\tcomment\nhere",
        ], $this->account);

        $response = $this->get('/api/v1/bot/commands', $this->auth(['Accept' => 'text/plain']));
        $lines = array_values(array_filter(explode("\n", $response->getContent())));

        $this->assertCount(count(TradeCommand::WIRE_COLUMNS), explode("\t", $lines[1]));
        $this->assertStringContainsString('bad comment here', $lines[1]);
    }

    // =====================================================================
    // REPORTING RESULTS
    // =====================================================================

    public function test_reporting_success_marks_the_command_done(): void
    {
        $command = TradeCommand::enqueue($this->user, 'open', ['symbol' => 'XAUUSD'], $this->account);

        $this->postJson("/api/v1/bot/commands/{$command->id}/result", [
            'ok' => true, 'retcode' => 10009, 'ticket' => 987654, 'price' => 2400.25,
        ], $this->auth())->assertOk()->assertJsonPath('status', 'done');

        $fresh = $command->fresh();
        $this->assertSame('done', $fresh->status);
        $this->assertSame(987654, $fresh->result['ticket']);
    }

    public function test_reporting_a_rejection_marks_it_failed_and_writes_a_bot_log(): void
    {
        $command = TradeCommand::enqueue($this->user, 'open', ['symbol' => 'XAUUSD'], $this->account);

        $this->postJson("/api/v1/bot/commands/{$command->id}/result", [
            'ok' => false,
            'retcode' => 10027,
            'error' => 'Algo trading disabled in the terminal',
        ], $this->auth())->assertOk()->assertJsonPath('status', 'failed');

        $this->assertSame('failed', $command->fresh()->status);

        // The whole point: a rejection lands on /logs instead of vanishing into a
        // terminal log on a VPS nobody is watching.
        $log = BotLog::latest('id')->first();
        $this->assertSame('error', $log->level);
        $this->assertStringContainsString('10027', json_encode($log->context));
    }

    public function test_a_token_cannot_complete_another_users_command(): void
    {
        $stranger = User::factory()->create();
        $command = TradeCommand::enqueue($stranger, 'open', ['symbol' => 'XAUUSD']);

        $this->postJson("/api/v1/bot/commands/{$command->id}/result", ['ok' => true], $this->auth())
            ->assertStatus(404);

        $this->assertSame('pending', $command->fresh()->status);
    }

    // =====================================================================
    // HEARTBEAT
    // =====================================================================

    public function test_heartbeat_upserts_one_row_and_returns_the_kill_switch_state(): void
    {
        $payload = [
            'source' => 'mql5_ea',
            'version' => '1.0.0',
            'terminal_build' => 4620,
            'algo_trading_enabled' => true,
            'broker_connected' => true,
            'resolved_symbol' => 'XAUUSDm',
            'balance' => 1000.50,
            'equity' => 1002.75,
            'open_positions' => 2,
        ];

        $this->postJson('/api/v1/bot/heartbeat', $payload, $this->auth())
            ->assertOk()
            ->assertJsonPath('trading_enabled', false); // BotSettings defaults to inactive

        $this->postJson('/api/v1/bot/heartbeat', $payload, $this->auth())->assertOk();

        $this->assertSame(1, BotHeartbeat::count(), 'heartbeats must overwrite, not append');

        $beat = BotHeartbeat::first();
        $this->assertTrue($beat->isOnline());
        $this->assertSame('XAUUSDm', $beat->resolved_symbol);

        // The cached columns on broker_accounts finally get written.
        $this->assertEquals(1000.50, $this->account->fresh()->last_balance);
        $this->assertNotNull($this->account->fresh()->last_synced_at);
    }

    public function test_heartbeat_reflects_the_kill_switch_when_trading_is_enabled(): void
    {
        $this->user->botSettings->update(['is_active' => true]);

        $this->postJson('/api/v1/bot/heartbeat', ['source' => 'mql5_ea'], $this->auth())
            ->assertOk()
            ->assertJsonPath('trading_enabled', true);
    }

    public function test_a_reachable_terminal_with_algo_trading_off_is_reported_as_blocked(): void
    {
        $this->postJson('/api/v1/bot/heartbeat', [
            'algo_trading_enabled' => false,
            'broker_connected' => true,
        ], $this->auth())->assertOk();

        $beat = BotHeartbeat::first();

        // Online but unable to trade is the state that otherwise presents as
        // "the bot just never trades".
        $this->assertTrue($beat->isOnline());
        $this->assertTrue($beat->isOnlineButBlocked());
        $this->assertStringContainsString('Algo Trading', $beat->blockedReason());
    }

    // =====================================================================
    // FILLS
    // =====================================================================

    public function test_reporting_an_open_creates_a_trade(): void
    {
        $command = TradeCommand::enqueue($this->user, 'open', ['symbol' => 'XAUUSD'], $this->account);

        $this->postJson('/api/v1/bot/fills', [
            'event' => 'opened',
            'command_id' => $command->id,
            'ticket' => 987654,
            'symbol' => 'XAUUSDm',
            'direction' => 'buy',
            'volume' => 0.05,
            'price' => 2400.25,
            'sl' => 2397.25,
            'tp1' => 2401.75,
            'spread_pips' => 2.5,
            'slippage_pips' => 0.1,
        ], $this->auth())->assertStatus(201);

        $trade = Trade::first();
        $this->assertSame('open', $trade->status);
        $this->assertEquals(0.05, $trade->remaining_lot_size);
        $this->assertSame($trade->id, $command->fresh()->trade_id);

        // tp2 was never supplied and must stay null rather than be invented.
        $this->assertNull($trade->tp2_price);
    }

    public function test_reporting_the_same_open_twice_does_not_duplicate_the_trade(): void
    {
        // The EA may retry after a network timeout without knowing the first landed.
        $payload = [
            'event' => 'opened', 'ticket' => 987654, 'symbol' => 'XAUUSDm',
            'direction' => 'buy', 'volume' => 0.05, 'price' => 2400.25,
        ];

        $this->postJson('/api/v1/bot/fills', $payload, $this->auth())->assertStatus(201);
        $this->postJson('/api/v1/bot/fills', $payload, $this->auth())->assertStatus(201);

        $this->assertSame(1, Trade::count());
    }

    public function test_a_partial_close_records_a_partial_and_decrements_remaining_volume(): void
    {
        $this->postJson('/api/v1/bot/fills', [
            'event' => 'opened', 'ticket' => 987654, 'symbol' => 'XAUUSDm',
            'direction' => 'buy', 'volume' => 0.10, 'price' => 2400.00,
        ], $this->auth())->assertStatus(201);

        $this->postJson('/api/v1/bot/fills', [
            'event' => 'partial', 'ticket' => 987654, 'deal_ticket' => 11111,
            'volume' => 0.05, 'price' => 2401.50, 'reason' => 'tp1',
            'pips_profit' => 15.0, 'profit' => 7.50, 'commission' => -0.10,
        ], $this->auth())->assertOk()->assertJsonPath('status', 'partially_closed');

        $trade = Trade::first();
        $this->assertEquals(0.05, $trade->remaining_lot_size);
        $this->assertEquals(7.40, $trade->net_pnl_money);
        $this->assertSame(1, TradePartial::count());
    }

    public function test_a_stop_out_is_recorded_distinctly_from_a_target_exit(): void
    {
        $this->postJson('/api/v1/bot/fills', [
            'event' => 'opened', 'ticket' => 987654, 'symbol' => 'XAUUSDm',
            'direction' => 'buy', 'volume' => 0.05, 'price' => 2400.00,
        ], $this->auth())->assertStatus(201);

        $this->postJson('/api/v1/bot/fills', [
            'event' => 'closed', 'ticket' => 987654, 'deal_ticket' => 22222,
            'volume' => 0.05, 'price' => 2397.00, 'reason' => 'sl',
            'pips_profit' => -30.0, 'profit' => -15.00,
        ], $this->auth())->assertOk()->assertJsonPath('status', 'stopped_out');

        $trade = Trade::first();
        $this->assertSame('stopped_out', $trade->status);
        $this->assertSame('sl', $trade->closure_reason);
        $this->assertNotNull($trade->closed_at);
        $this->assertEquals(0, $trade->remaining_lot_size);
    }

    public function test_closing_an_unknown_ticket_is_a_404(): void
    {
        $this->postJson('/api/v1/bot/fills', [
            'event' => 'closed', 'ticket' => 404404, 'volume' => 0.01,
            'price' => 2400.00, 'pips_profit' => 0,
        ], $this->auth())->assertStatus(404);
    }

    public function test_a_close_must_report_pips_computed_by_the_terminal(): void
    {
        // Only the terminal knows the symbol's point size. Deriving pips here would
        // mean guessing the multiplier that causes the 10016 class of bugs.
        $this->postJson('/api/v1/bot/fills', [
            'event' => 'closed', 'ticket' => 987654, 'volume' => 0.01, 'price' => 2400.00,
        ], $this->auth())->assertStatus(422)->assertJsonValidationErrors('pips_profit');
    }

    // =====================================================================
    // LOGS
    // =====================================================================

    public function test_logs_accept_a_batch(): void
    {
        $this->postJson('/api/v1/bot/logs', [
            'entries' => [
                ['level' => 'info', 'message' => 'EA started'],
                ['level' => 'error', 'message' => 'order rejected', 'context' => ['retcode' => 10030]],
            ],
        ], $this->auth())->assertStatus(201)->assertJsonPath('written', 2);

        $this->assertSame(2, BotLog::count());
        $this->assertSame('mql5_ea', BotLog::first()->source);
    }

    public function test_logs_accept_a_single_entry(): void
    {
        $this->postJson('/api/v1/bot/logs', [
            'level' => 'warning', 'message' => 'spread too wide',
        ], $this->auth())->assertStatus(201)->assertJsonPath('written', 1);
    }

    public function test_logs_reject_an_invalid_level(): void
    {
        $this->postJson('/api/v1/bot/logs', [
            'entries' => [['level' => 'shouting', 'message' => 'nope']],
        ], $this->auth())->assertStatus(422);
    }
}

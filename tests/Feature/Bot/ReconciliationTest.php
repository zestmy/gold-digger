<?php

namespace Tests\Feature\Bot;

use App\Models\BotSettings;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Strategy\TradeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Position reconciliation.
 *
 * Making `trades` agree with the account. The stakes are asymmetric here: failing to adopt a
 * position leaves it invisible, which is bad, while wrongly closing a row - or worse,
 * adopting a manual position into a strategy that then closes it - destroys something a
 * person was relying on. The tests are weighted accordingly.
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private string $plaintext;

    private const SYMBOL = 'XAUUSDm';

    private const MAGIC = 20240101;

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

        BotSettings::where('user_id', $this->user->id)->update(['is_active' => true]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update(['is_active' => true]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->plaintext];
    }

    private function snapshot(array $positions, ?int $magic = self::MAGIC): TestResponse
    {
        return $this->postJson('/api/v1/bot/positions', [
            'magic' => $magic,
            'positions' => $positions,
        ], $this->auth());
    }

    private function position(int $ticket, array $overrides = []): array
    {
        return array_merge([
            'ticket' => $ticket,
            'symbol' => self::SYMBOL,
            'direction' => 'buy',
            'volume' => 0.50,
            'entry_price' => 2000.00,
            'sl' => 1995.00,
            'tp' => 2020.00,
            'profit' => 25.00,
            'opened_at' => Carbon::parse('2026-03-10 12:00:00', 'UTC')->getTimestamp(),
        ], $overrides);
    }

    private function botTrade(int $ticket, array $overrides = []): Trade
    {
        return Trade::create(array_merge([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => $ticket,
            'magic_number' => self::MAGIC,
            'origin' => 'bot',
            'symbol' => self::SYMBOL,
            'direction' => 'buy',
            'initial_lot_size' => 0.50,
            'remaining_lot_size' => 0.50,
            'entry_price' => 2000.00,
            'sl_price' => 1995.00,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ], $overrides));
    }

    // =====================================================================
    // AUTH AND SHAPE
    // =====================================================================

    public function test_positions_require_a_token(): void
    {
        $this->postJson('/api/v1/bot/positions', [])->assertStatus(401);
    }

    public function test_an_unbound_token_cannot_reconcile(): void
    {
        [$plaintext] = BotToken::generate($this->user, 'Unbound', null);

        $this->postJson('/api/v1/bot/positions', [
            'magic' => self::MAGIC,
            'positions' => [],
        ], ['Authorization' => 'Bearer '.$plaintext])->assertStatus(422);
    }

    /**
     * An account with nothing open sends an empty array, and that is exactly the report
     * that closes rows for positions which have gone. It must not be rejected as missing.
     */
    public function test_an_empty_snapshot_is_a_valid_report(): void
    {
        $this->snapshot([])->assertOk();
    }

    // =====================================================================
    // ADOPTION
    // =====================================================================

    public function test_an_unknown_position_is_adopted(): void
    {
        $this->snapshot([$this->position(500001)])
            ->assertOk()
            ->assertJson(['adopted' => 1]);

        $trade = Trade::where('mt5_ticket', 500001)->firstOrFail();

        $this->assertSame('adopted', $trade->origin);
        $this->assertSame('open', $trade->status);
        $this->assertEqualsWithDelta(0.50, (float) $trade->remaining_lot_size, 1e-9);
        $this->assertSame($this->account->id, $trade->broker_account_id);
    }

    /**
     * The single most important property here. An adopted position belongs to no strategy;
     * handing it to the ladder would let `max_holding_bars` close a position somebody opened
     * by hand.
     */
    public function test_an_adopted_position_is_never_managed_by_a_strategy(): void
    {
        $this->strategy->update(['max_holding_bars' => 1, 'exit_on_reversal' => true]);

        $this->snapshot([$this->position(500002)]);

        app(TradeManager::class)->manage($this->strategy->fresh(), $this->account->id);

        $this->assertSame(0, TradeCommand::count());
    }

    public function test_a_bot_position_is_still_managed_after_reconciliation_runs(): void
    {
        $this->botTrade(500003);

        $this->snapshot([$this->position(500003)]);

        $this->assertSame('bot', Trade::where('mt5_ticket', 500003)->firstOrFail()->origin);
    }

    /**
     * MT5 reports an unset stop as 0.0, which is not a level at zero. Storing the zero would
     * chart a stop the position does not have.
     */
    public function test_a_position_with_no_stop_records_no_stop(): void
    {
        $this->snapshot([$this->position(500004, ['sl' => 0.0, 'tp' => 0.0])])->assertOk();

        $trade = Trade::where('mt5_ticket', 500004)->firstOrFail();

        $this->assertNull($trade->sl_price);
        $this->assertNull($trade->tp1_price);
    }

    public function test_adopting_is_idempotent(): void
    {
        $this->snapshot([$this->position(500005)]);
        $this->snapshot([$this->position(500005)])->assertJson(['adopted' => 0]);

        $this->assertSame(1, Trade::where('mt5_ticket', 500005)->count());
    }

    // =====================================================================
    // REFRESHING WHAT IS ALREADY KNOWN
    // =====================================================================

    /**
     * Less open than recorded means something closed part of the position and the report
     * never arrived. The row must stop claiming lots the account does not hold.
     */
    public function test_a_smaller_volume_at_the_broker_corrects_the_row(): void
    {
        $this->botTrade(500006);

        $this->snapshot([$this->position(500006, ['volume' => 0.20])])
            ->assertJson(['updated' => 1]);

        $trade = Trade::where('mt5_ticket', 500006)->firstOrFail();

        $this->assertEqualsWithDelta(0.20, (float) $trade->remaining_lot_size, 1e-9);
        $this->assertSame('partially_closed', $trade->status);
    }

    public function test_a_stop_moved_by_hand_at_the_terminal_is_picked_up(): void
    {
        $this->botTrade(500007);

        $this->snapshot([$this->position(500007, ['sl' => 1998.50])]);

        $this->assertEqualsWithDelta(
            1998.50,
            (float) Trade::where('mt5_ticket', 500007)->firstOrFail()->sl_price,
            1e-9,
        );
    }

    public function test_an_unchanged_position_is_not_reported_as_updated(): void
    {
        $this->botTrade(500008, ['gross_pnl_money' => 25.00]);

        $this->snapshot([$this->position(500008)])->assertJson(['updated' => 0]);
    }

    // =====================================================================
    // CLOSING WHAT HAS GONE
    // =====================================================================

    /**
     * A row claiming a position that no longer exists makes TradeManager issue close
     * commands against a dead ticket for ever.
     */
    public function test_a_trade_missing_from_the_snapshot_is_closed(): void
    {
        $this->botTrade(500009);

        $this->snapshot([])->assertJson(['closed' => 1]);

        $trade = Trade::where('mt5_ticket', 500009)->firstOrFail();

        $this->assertSame('fully_closed', $trade->status);
        $this->assertSame('reconciled_closed', $trade->closure_reason);
        $this->assertEqualsWithDelta(0.0, (float) $trade->remaining_lot_size, 1e-9);
    }

    public function test_a_trade_present_in_the_snapshot_is_left_open(): void
    {
        $this->botTrade(500010);

        $this->snapshot([$this->position(500010)])->assertJson(['closed' => 0]);

        $this->assertSame('open', Trade::where('mt5_ticket', 500010)->firstOrFail()->status);
    }

    /**
     * A snapshot of one EA's positions says nothing about another's. Concluding from
     * absence outside the reported magic would silently erase a second bot's trades.
     */
    public function test_another_magic_numbers_positions_are_left_alone(): void
    {
        $this->botTrade(500011, ['magic_number' => 999999]);

        $this->snapshot([])->assertJson(['closed' => 0]);

        $this->assertSame('open', Trade::where('mt5_ticket', 500011)->firstOrFail()->status);
    }

    /**
     * Without a magic the snapshot still adopts and refreshes what it names, but its silence
     * means nothing - it may be a partial view of the account.
     */
    public function test_a_snapshot_without_a_magic_concludes_nothing_from_absence(): void
    {
        $this->botTrade(500012);

        $this->snapshot([], magic: null)->assertJson(['closed' => 0]);

        $this->assertSame('open', Trade::where('mt5_ticket', 500012)->firstOrFail()->status);
    }

    public function test_another_accounts_trades_are_left_alone(): void
    {
        $other = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Second',
            'broker_name' => 'Octa',
            'account_number' => '999',
            'server' => 'OctaFX-Demo',
            'is_demo' => true,
            'is_active' => true,
        ]);

        $this->botTrade(500013, ['broker_account_id' => $other->id]);

        $this->snapshot([])->assertJson(['closed' => 0]);

        $this->assertSame('open', Trade::where('mt5_ticket', 500013)->firstOrFail()->status);
    }

    public function test_already_closed_trades_are_not_touched_again(): void
    {
        $this->botTrade(500014, [
            'status' => 'fully_closed',
            'closure_reason' => 'tp3',
            'closed_at' => now()->subMinutes(5),
        ]);

        $this->snapshot([])->assertJson(['closed' => 0]);

        $this->assertSame('tp3', Trade::where('mt5_ticket', 500014)->firstOrFail()->closure_reason);
    }

    /**
     * Rows written before magic numbers were recorded belong to this bot, so a snapshot
     * carrying a magic still covers them.
     */
    public function test_trades_with_no_recorded_magic_are_covered(): void
    {
        $this->botTrade(500015, ['magic_number' => null]);

        $this->snapshot([])->assertJson(['closed' => 1]);
    }

    // =====================================================================
    // A FULL CORRECTION
    // =====================================================================

    public function test_one_snapshot_adopts_refreshes_and_closes_together(): void
    {
        $this->botTrade(500016);                          // still there, smaller
        $this->botTrade(500017);                          // gone

        $response = $this->snapshot([
            $this->position(500016, ['volume' => 0.30]),
            $this->position(500018),                      // never seen before
        ]);

        $response->assertOk()->assertJson([
            'adopted' => 1,
            'updated' => 1,
            'closed' => 1,
        ]);

        $this->assertSame('partially_closed', Trade::where('mt5_ticket', 500016)->firstOrFail()->status);
        $this->assertSame('fully_closed', Trade::where('mt5_ticket', 500017)->firstOrFail()->status);
        $this->assertSame('adopted', Trade::where('mt5_ticket', 500018)->firstOrFail()->origin);
    }
}

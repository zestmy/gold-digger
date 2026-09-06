<?php

namespace Tests\Feature\Bot;

use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradePartial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A close the copier commanded has to be recordable.
 *
 * The EA echoes the reason a close command carried, so a fill can be filed as the ladder
 * rung it was. That contract was written for the strategy, whose reasons are the enum
 * `trade_partials.close_reason` can hold. The copier then learned to close positions of
 * its own - on an opposite signal, a profit lock, a provider saying "close" - with reasons
 * of its own, and every one of those fills was refused with a 422. The EA discards any 4xx,
 * so the position stayed open in `trades` and kept counting against the AI fund until the
 * snapshot marked it closed with no price, no pips and no money.
 */
class CommandedCloseReasonTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Trade $trade;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $strategy = Strategy::where('user_id', $user->id)->firstOrFail();

        $account = BrokerAccount::create([
            'user_id' => $user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);

        [$this->token] = BotToken::generate($user, 'Terminal', $account);

        $this->trade = Trade::create([
            'user_id' => $user->id, 'strategy_id' => $strategy->id, 'broker_account_id' => $account->id,
            'mt5_ticket' => 55001, 'symbol' => 'XAUUSD', 'direction' => 'buy',
            'initial_lot_size' => 0.02, 'remaining_lot_size' => 0.02,
            'entry_price' => 4000.00, 'sl_price' => 3995.00,
            'status' => 'open', 'origin' => 'ai', 'opened_at' => now()->subHour(),
        ]);
    }

    /**
     * Every reason a copier code path puts on the wire today. Each one is a string the EA
     * sends back verbatim on the resulting deal.
     */
    public static function copierReasons(): array
    {
        return [
            'opposite signal' => ['opposite-signal'],
            'profit lock' => ['copier-profit-lock'],
            'follow-up partial' => ['tg-followup-partial'],
            'follow-up close' => ['tg-followup-close'],
            'follow-up stop' => ['tg-followup-stop'],
        ];
    }

    #[DataProvider('copierReasons')]
    public function test_a_close_commanded_by_the_copier_is_recorded(string $reason): void
    {
        $this->close(reason: $reason, note: "closed by dashboard command ({$reason})")
            ->assertOk();

        $trade = $this->trade->fresh();
        $partial = TradePartial::sole();

        $this->assertSame('fully_closed', $trade->status);
        $this->assertEqualsWithDelta(0.0, (float) $trade->remaining_lot_size, 0.0001);
        $this->assertEqualsWithDelta(6.20, (float) $trade->net_pnl_money, 0.001);

        // The enum holds what it can; the raw reason survives where the trade page reads.
        $this->assertSame('manual', $partial->close_reason);
        $this->assertStringContainsString($reason, $trade->closure_reason);
    }

    public function test_a_client_that_sends_no_note_still_keeps_the_raw_reason(): void
    {
        $this->close(reason: 'copier-profit-lock', note: null)->assertOk();

        $this->assertSame('copier-profit-lock', $this->trade->fresh()->closure_reason);
    }

    /**
     * The strategy's reasons are the enum, and must keep arriving as themselves - a TP1
     * flattened to `manual` would make the ladder invisible and re-fire the rung.
     */
    public function test_a_strategy_rung_is_still_filed_as_the_rung(): void
    {
        $this->close(reason: 'tp1', note: 'closed by dashboard command (tp1)', volume: 0.01)->assertOk();

        $this->assertSame('tp1', TradePartial::sole()->close_reason);
        $this->assertSame('partially_closed', $this->trade->fresh()->status);
    }

    public function test_a_broker_stop_is_still_a_stop_out(): void
    {
        $this->close(reason: 'sl', note: 'stop loss hit')->assertOk();

        $this->assertSame('stopped_out', $this->trade->fresh()->status);
    }

    private function close(string $reason, ?string $note, float $volume = 0.02)
    {
        return $this->postJson('/api/v1/bot/fills', array_filter([
            'event' => 'closed',
            'ticket' => $this->trade->mt5_ticket,
            'deal_ticket' => 77001,
            'volume' => $volume,
            'price' => 4003.10,
            'pips_profit' => 31.0,
            'profit' => 6.20,
            'reason' => $reason,
            'closure_note' => $note,
        ], fn ($v) => $v !== null), ['Authorization' => 'Bearer '.$this->token]);
    }
}

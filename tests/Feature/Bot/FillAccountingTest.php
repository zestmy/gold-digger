<?php

namespace Tests\Feature\Bot;

use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradePartial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a position actually made.
 *
 * The bug this exists for was silent and doubled money in the flattering direction. Partial
 * rows are keyed on the broker's deal ticket and so were always idempotent; the trade's
 * totals were accumulated, and every re-delivery of a deal - a retried report, or the
 * replay the EA performs on attach - added the profit again. A position closed in two
 * deals showed exactly twice what it made, and nothing about the figure looked wrong.
 */
class FillAccountingTest extends TestCase
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

        // The position from the report that found this: 0.02 lots of gold, closed in two
        // deals for 5.02 and -0.18, which the terminal totalled as 4.84.
        $this->trade = Trade::create([
            'user_id' => $user->id, 'strategy_id' => $strategy->id, 'broker_account_id' => $account->id,
            'mt5_ticket' => 89795022, 'symbol' => 'XAUUSD', 'direction' => 'buy',
            'initial_lot_size' => 0.02, 'remaining_lot_size' => 0.02,
            'entry_price' => 4658.41, 'sl_price' => 4658.41,
            'status' => 'open', 'origin' => 'bot', 'opened_at' => now()->subHour(),
        ]);
    }

    public function test_two_deals_total_what_the_terminal_totalled(): void
    {
        $this->fill(deal: 86016866, volume: 0.01, price: 4663.43, pips: 50.20, profit: 5.02, reason: 'tp1');
        $this->fill(deal: 86022835, volume: 0.01, price: 4658.23, pips: -1.80, profit: -0.18, reason: 'sl');

        $trade = $this->trade->fresh();

        $this->assertEqualsWithDelta(4.84, (float) $trade->net_pnl_money, 0.001);
        $this->assertEqualsWithDelta(4.84, (float) $trade->gross_pnl_money, 0.001);
        $this->assertEqualsWithDelta(48.40, (float) $trade->gross_pnl_pips, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $trade->remaining_lot_size, 0.0001);
    }

    /**
     * The report queue may resend, and the EA replays closing deals whenever it attaches.
     */
    public function test_the_same_deal_reported_twice_does_not_double_the_profit(): void
    {
        $this->fill(deal: 86016866, volume: 0.01, price: 4663.43, pips: 50.20, profit: 5.02, reason: 'tp1');
        $this->fill(deal: 86016866, volume: 0.01, price: 4663.43, pips: 50.20, profit: 5.02, reason: 'tp1');

        $trade = $this->trade->fresh();

        $this->assertSame(1, TradePartial::count());
        $this->assertEqualsWithDelta(5.02, (float) $trade->net_pnl_money, 0.001);
        // And the size left is still right, which accumulation also got wrong.
        $this->assertEqualsWithDelta(0.01, (float) $trade->remaining_lot_size, 0.0001);
    }

    /**
     * This test used to replay the live figures back at the endpoint and pass, which is
     * why the loss below went unnoticed for as long as it did. FXSReplayClosedDeals does
     * not send what the live close sent: it walks a date-ranged history selection it
     * cannot re-query per position, so it has no entry price and sends 0.00 pips, and it
     * reads DEAL_REASON, which cannot name a ladder rung and so says `manual`.
     */
    public function test_a_whole_position_replayed_on_attach_changes_nothing(): void
    {
        $this->fill(deal: 86016866, volume: 0.01, price: 4663.43, pips: 50.20, profit: 5.02, reason: 'tp1');
        $this->fill(deal: 86022835, volume: 0.01, price: 4658.23, pips: -1.80, profit: -0.18, reason: 'sl');

        $before = $this->trade->fresh()->only(['net_pnl_money', 'gross_pnl_pips', 'remaining_lot_size', 'status']);

        $this->replay(deal: 86016866, volume: 0.01, price: 4663.43, profit: 5.02, reason: 'manual');
        $this->replay(deal: 86022835, volume: 0.01, price: 4658.23, profit: -0.18, reason: 'sl');

        $this->assertSame($before, $this->trade->fresh()->only(['net_pnl_money', 'gross_pnl_pips', 'remaining_lot_size', 'status']));
    }

    /**
     * The reported symptom: real money on both partials, 0.00 pips, and a commanded TP1
     * close filed as a manual one.
     */
    public function test_a_replay_does_not_erase_what_the_live_close_knew(): void
    {
        $this->fill(deal: 86016866, volume: 0.01, price: 4663.43, pips: 50.20, profit: 5.02, reason: 'tp1');

        $this->replay(deal: 86016866, volume: 0.01, price: 4663.43, profit: 5.02, reason: 'manual');

        $partial = TradePartial::sole();

        $this->assertEqualsWithDelta(50.20, (float) $partial->pips_profit, 0.001);
        $this->assertSame('tp1', $partial->close_reason);
        $this->assertEqualsWithDelta(50.20, (float) $this->trade->fresh()->gross_pnl_pips, 0.001);
    }

    /**
     * The guard runs one way only. Replaying remains how a close that happened while the
     * terminal was shut gets recorded at all, and 0.00 pips is better than no row.
     */
    public function test_a_replay_still_records_a_deal_nobody_reported_live(): void
    {
        $this->replay(deal: 86022835, volume: 0.01, price: 4658.23, profit: -0.18, reason: 'sl');

        $partial = TradePartial::sole();

        $this->assertEqualsWithDelta(0.0, (float) $partial->pips_profit, 0.001);
        $this->assertSame('sl', $partial->close_reason);
        $this->assertEqualsWithDelta(-0.18, (float) $this->trade->fresh()->net_pnl_money, 0.001);
    }

    /**
     * And a report that does know the figure still corrects a stored zero - which is how
     * the rows already flattened on production heal, once the EA computes pips on replay.
     */
    public function test_a_later_report_that_knows_the_pips_corrects_a_stored_zero(): void
    {
        $this->replay(deal: 86016866, volume: 0.01, price: 4663.43, profit: 5.02, reason: 'manual');

        $this->fill(deal: 86016866, volume: 0.01, price: 4663.43, pips: 50.20, profit: 5.02, reason: 'tp1');

        $partial = TradePartial::sole();

        $this->assertEqualsWithDelta(50.20, (float) $partial->pips_profit, 0.001);
        $this->assertSame('tp1', $partial->close_reason);
    }

    /**
     * A deal closes once. The replay stamped its own arrival over the close time, which
     * moved the TP1 partial from 02:40 to 18:18 - when the terminal reconnected.
     */
    public function test_a_re_report_does_not_move_when_the_deal_closed(): void
    {
        $this->fill(deal: 86016866, volume: 0.01, price: 4663.43, pips: 50.20, profit: 5.02, reason: 'tp1');

        $closedAt = TradePartial::sole()->closed_at;

        $this->travel(8)->hours();
        $this->replay(deal: 86016866, volume: 0.01, price: 4663.43, profit: 5.02, reason: 'manual');

        $this->assertTrue($closedAt->equalTo(TradePartial::sole()->closed_at));
    }

    public function test_costs_are_totalled_from_the_deals_that_carried_them(): void
    {
        $this->fill(deal: 1, volume: 0.01, price: 4663.43, pips: 50.20, profit: 5.02, reason: 'tp1', commission: -0.20, swap: -0.05);
        $this->fill(deal: 2, volume: 0.01, price: 4658.23, pips: -1.80, profit: -0.18, reason: 'sl', commission: -0.20, swap: -0.03);

        $trade = $this->trade->fresh();

        $this->assertEqualsWithDelta(-0.40, (float) $trade->commission_money, 0.001);
        $this->assertEqualsWithDelta(-0.08, (float) $trade->swap_money, 0.001);
        // Net is after costs, and can only ever be at or below gross.
        $this->assertEqualsWithDelta(4.36, (float) $trade->net_pnl_money, 0.001);
        $this->assertLessThanOrEqual((float) $trade->gross_pnl_money, (float) $trade->net_pnl_money);
    }

    /**
     * What FXSReplayClosedDeals actually sends on attach: no pips, and a reason derived
     * from DEAL_REASON rather than from the command that asked for the close.
     */
    private function replay(int $deal, float $volume, float $price, float $profit, string $reason): void
    {
        $this->withToken($this->token)->postJson('/api/v1/bot/fills', [
            'event' => 'closed',
            'ticket' => $this->trade->mt5_ticket,
            'deal_ticket' => $deal,
            'volume' => $volume,
            'price' => $price,
            'pips_profit' => 0.0,
            'profit' => $profit,
            'reason' => $reason,
            'closure_note' => 'replayed from history on attach',
        ])->assertSuccessful();
    }

    private function fill(int $deal,
        float $volume,
        float $price,
        float $pips,
        float $profit,
        string $reason,
        float $commission = 0.0,
        float $swap = 0.0,
    ): void {
        $this->withToken($this->token)->postJson('/api/v1/bot/fills', [
            'event' => 'closed',
            'ticket' => $this->trade->mt5_ticket,
            'deal_ticket' => $deal,
            'volume' => $volume,
            'price' => $price,
            'pips_profit' => $pips,
            'profit' => $profit,
            'commission' => $commission,
            'swap' => $swap,
            'reason' => $reason,
        ])->assertSuccessful();
    }
}

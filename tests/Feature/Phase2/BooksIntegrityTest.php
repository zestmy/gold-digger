<?php

namespace Tests\Feature\Phase2;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradePartial;
use App\Models\User;
use App\Services\Monitoring\HealthMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two records of the same money, checked against each other.
 *
 * A trade's totals and its closing deals are supposed to be the same number. They came
 * apart once, silently, in the flattering direction. The arithmetic that caused it is
 * fixed; this exists because it was undetectable, which is a different problem from it
 * being possible.
 */
class BooksIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Trade $trade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);

        BotSettings::where('user_id', $this->user->id)->update(['is_active' => true]);

        BotHeartbeat::create([
            'user_id' => $this->user->id, 'broker_account_id' => $account->id,
            'source' => 'mql5_ea', 'algo_trading_enabled' => true, 'broker_connected' => true,
            'resolved_symbol' => 'XAUUSD', 'last_seen_at' => now(),
        ]);

        $this->trade = Trade::create([
            'user_id' => $this->user->id, 'strategy_id' => $strategy->id, 'broker_account_id' => $account->id,
            'mt5_ticket' => 89795022, 'symbol' => 'XAUUSD', 'direction' => 'buy',
            'initial_lot_size' => 0.02, 'remaining_lot_size' => 0,
            'entry_price' => 4658.41, 'sl_price' => 4658.41,
            'gross_pnl_pips' => 48.40, 'gross_pnl_money' => 4.84, 'net_pnl_money' => 4.84,
            'status' => 'fully_closed', 'origin' => 'bot',
            'opened_at' => now()->subHour(), 'closed_at' => now(),
        ]);

        $this->partial(86016866, 0.01, 5.02);
        $this->partial(86022835, 0.01, -0.18);
    }

    public function test_books_that_agree_raise_nothing(): void
    {
        $this->assertNull($this->condition());
    }

    /**
     * The exact shape of the fault that shipped: totals at twice the deals.
     */
    public function test_a_total_at_twice_its_deals_is_reported(): void
    {
        $this->trade->update(['net_pnl_money' => 9.68]);

        $condition = $this->condition();

        $this->assertNotNull($condition);
        $this->assertSame('critical', $condition['level']);
        $this->assertStringContainsString('9.68', $condition['body']);
        $this->assertStringContainsString('4.84', $condition['body']);
    }

    /**
     * Each partial rounds to two places and the total rounds once, so exact equality would
     * fire on arithmetic rather than on a fault.
     */
    public function test_a_cent_of_rounding_is_not_a_discrepancy(): void
    {
        $this->trade->update(['net_pnl_money' => 4.85]);

        $this->assertNull($this->condition());
    }

    /**
     * A figure that corrects itself while nobody watches is worse than one that is wrong
     * and says so - self-healing would erase the evidence that something upstream broke.
     */
    public function test_it_reports_rather_than_repairs(): void
    {
        $this->trade->update(['net_pnl_money' => 9.68]);

        $this->condition();

        $this->assertEqualsWithDelta(9.68, (float) $this->trade->fresh()->net_pnl_money, 0.001);
    }

    public function test_a_trade_with_no_deals_recorded_is_not_a_discrepancy(): void
    {
        // An open position that has closed nothing has no deals to disagree with.
        TradePartial::query()->delete();

        $this->assertNull($this->condition());
    }

    private function condition(): ?array
    {
        $conditions = app(HealthMonitor::class)->conditionsFor($this->user);

        return collect($conditions)->firstWhere('key', 'books_disagree');
    }

    private function partial(int $deal, float $lots, float $net): void
    {
        TradePartial::create([
            'trade_id' => $this->trade->id,
            'mt5_deal_ticket' => $deal,
            'closed_lot_size' => $lots,
            'close_price' => 4660.00,
            'close_reason' => 'tp1',
            'pips_profit' => 0,
            'gross_money_profit' => $net,
            'commission_money' => 0,
            'swap_money' => 0,
            'net_money_profit' => $net,
            'closed_at' => now(),
        ]);
    }
}

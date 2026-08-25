<?php

namespace Tests\Feature\Ai;

use App\Livewire\Pages\Settings;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\User;
use App\Services\Ai\AiFund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The AI trading fund.
 *
 * AI-initiated trading is the one thing in this system that cannot be backtested, so the
 * usual guarantee - that a setting can be measured before it costs anything - is
 * unavailable. A bounded loss replaces it, and these are the tests that make the bound
 * real rather than decorative.
 */
class AiFundTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BotSettings $settings;

    private BrokerAccount $account;

    private AiFund $fund;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fund = new AiFund;
        $this->user = User::factory()->create();
        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Elev8 Demo',
            'broker_name' => 'Elev8',
            'account_number' => '230070844',
            'server' => 'Elev8-Demo2',
            'is_demo' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->user);
    }

    private function fund(array $overrides = []): void
    {
        $this->settings->update($overrides + [
            'ai_trading_enabled' => true,
            'ai_capital_cap' => 200.00,
            'ai_risk_percentage' => 1.00,
            'ai_max_concurrent_trades' => 1,
        ]);
    }

    private function trade(array $overrides = []): Trade
    {
        return Trade::create($overrides + [
            'user_id' => $this->user->id,
            'strategy_id' => Strategy::where('user_id', $this->user->id)->value('id'),
            'broker_account_id' => $this->account->id,
            'origin' => AiFund::ORIGIN,
            'symbol' => 'XAUUSD',
            'direction' => 'buy',
            'initial_lot_size' => 0.01,
            'remaining_lot_size' => 0.01,
            'entry_price' => 2000,
            'status' => 'closed',
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
        ]);
    }

    private function state(): array
    {
        return $this->fund->state($this->settings->fresh(), $this->user->id);
    }

    // =====================================================================
    // NOTHING RUNS UNTIL SOMEBODY DECIDES
    // =====================================================================

    public function test_it_is_off_by_default(): void
    {
        $state = $this->state();

        $this->assertFalse($state['enabled']);
        $this->assertFalse($state['configured']);
        $this->assertSame('ai_trading_disabled', $state['blocked_reason']);
    }

    /**
     * A default cap would be this system deciding how much of someone's money an
     * unmeasurable feature may lose.
     */
    public function test_an_unset_cap_blocks_trading_even_when_enabled(): void
    {
        $this->settings->update(['ai_trading_enabled' => true, 'ai_capital_cap' => null]);

        $this->assertSame('ai_fund_not_configured', $this->state()['blocked_reason']);
        $this->assertFalse($this->fund->canOpen($this->settings->fresh(), $this->user->id));
    }

    public function test_a_configured_fund_permits_trading(): void
    {
        $this->fund();

        $this->assertNull($this->state()['blocked_reason']);
        $this->assertTrue($this->fund->canOpen($this->settings->fresh(), $this->user->id));
    }

    // =====================================================================
    // THE BOUND
    // =====================================================================

    public function test_losses_deplete_the_fund(): void
    {
        $this->fund();
        $this->trade(['net_pnl_money' => -50.00]);

        $state = $this->state();

        $this->assertSame(-50.0, $state['realised']);
        $this->assertSame(150.0, $state['remaining']);
    }

    public function test_profits_extend_it(): void
    {
        $this->fund();
        $this->trade(['net_pnl_money' => 30.00]);

        $this->assertSame(230.0, $this->state()['remaining']);
    }

    /**
     * The bound, doing the one job it exists for.
     */
    public function test_an_exhausted_fund_stops_trading(): void
    {
        $this->fund();
        $this->trade(['net_pnl_money' => -200.00]);

        $state = $this->state();

        $this->assertTrue($state['exhausted']);
        $this->assertSame(0.0, $state['remaining']);
        $this->assertSame('ai_fund_exhausted', $state['blocked_reason']);
        $this->assertFalse($this->fund->canOpen($this->settings->fresh(), $this->user->id));
    }

    public function test_remaining_never_goes_negative(): void
    {
        // An overshoot past the cap - slippage on the last stop - must not read as a
        // negative fund that a later profit could quietly climb out of.
        $this->fund();
        $this->trade(['net_pnl_money' => -500.00]);

        $this->assertSame(0.0, $this->state()['remaining']);
    }

    public function test_the_stake_shrinks_with_the_fund(): void
    {
        // A losing run that kept betting the same amount into a smaller pot would reach
        // zero in a handful of trades.
        $this->fund(['ai_risk_percentage' => 10.00]);
        $this->assertSame(20.0, $this->state()['risk_per_trade']);

        $this->trade(['net_pnl_money' => -100.00]);
        $this->assertSame(10.0, $this->state()['risk_per_trade']);
    }

    /**
     * The fund is the AI's own money, not the account's.
     */
    public function test_the_strategys_trades_do_not_touch_the_fund(): void
    {
        $this->fund();
        $this->trade(['origin' => 'bot', 'net_pnl_money' => -500.00]);
        $this->trade(['origin' => 'adopted', 'net_pnl_money' => -500.00]);

        $state = $this->state();

        $this->assertSame(0.0, $state['realised'], 'Only AI-origin trades deplete the AI fund.');
        $this->assertSame(200.0, $state['remaining']);
    }

    /**
     * Floating loss is not spent money - the same reasoning the daily loss limit uses.
     */
    public function test_an_open_position_does_not_deplete_the_fund(): void
    {
        $this->fund();
        $this->trade(['status' => 'open', 'closed_at' => null, 'net_pnl_money' => -150.00]);

        $this->assertSame(0.0, $this->state()['realised']);
    }

    public function test_it_stops_at_its_own_concurrency_limit(): void
    {
        $this->fund(['ai_max_concurrent_trades' => 1]);
        $this->trade(['status' => 'open', 'closed_at' => null]);

        $this->assertSame('ai_max_concurrent_reached', $this->state()['blocked_reason']);
    }

    // =====================================================================
    // THE DASHBOARD CONTROL
    // =====================================================================

    public function test_the_cap_is_set_from_the_dashboard(): void
    {
        Livewire::test(Settings::class)
            ->set('ai_trading_enabled', true)
            ->set('ai_capital_cap', '250.50')
            ->set('ai_risk_percentage', '2.5')
            ->set('ai_max_concurrent_trades', 2)
            ->call('save')
            ->assertHasNoErrors();

        $saved = $this->settings->fresh();

        $this->assertTrue((bool) $saved->ai_trading_enabled);
        $this->assertEquals(250.50, $saved->ai_capital_cap);
        $this->assertEquals(2.5, $saved->ai_risk_percentage);
        $this->assertSame(2, (int) $saved->ai_max_concurrent_trades);
    }

    /**
     * "No cap set" and "a cap of nothing" are different states.
     */
    public function test_an_empty_cap_box_stays_unconfigured_rather_than_becoming_zero(): void
    {
        $this->fund();

        Livewire::test(Settings::class)
            ->set('ai_capital_cap', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($this->settings->fresh()->ai_capital_cap);
        // Unconfigured, not exhausted - the remedy is different and so is the message.
        $this->assertSame('ai_fund_not_configured', $this->state()['blocked_reason']);
    }

    public function test_the_settings_page_shows_what_is_left(): void
    {
        $this->fund();
        $this->trade(['net_pnl_money' => -152.80]);

        Livewire::test(Settings::class)
            ->assertSee('AI Trading Fund')
            ->assertSee('47.20');
    }
}

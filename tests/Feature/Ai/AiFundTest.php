<?php

namespace Tests\Feature\Ai;

use App\Livewire\Pages\Settings;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\SymbolSpec;
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

    // =====================================================================
    // WHAT IS LEFT IS WHAT IS NOT ALREADY AT RISK
    // =====================================================================

    /**
     * A position that has not resolved has not lost anything, but what it could lose is
     * already spoken for. The next position is sized from what is left after that.
     */
    public function test_an_open_position_commits_its_risk_to_the_fund(): void
    {
        $this->fund(['ai_max_concurrent_trades' => 3]);
        $this->spec();

        // 5.00 from entry to the opening stop is 50 pips at 0.10; 0.02 lots at 10 a pip
        // per lot puts 10.00 of the 200 at risk.
        $this->trade([
            'status' => 'open', 'closed_at' => null,
            'entry_price' => 2000.0, 'initial_sl_price' => 1995.0,
            'initial_lot_size' => 0.02, 'remaining_lot_size' => 0.02,
        ]);

        $state = $this->state();

        $this->assertSame(0.0, $state['realised']);
        $this->assertSame(10.0, $state['committed']);
        $this->assertSame(190.0, $state['remaining']);
        $this->assertSame(1.9, $state['risk_per_trade']);
    }

    /**
     * The stop that protection has since moved does not un-commit what was agreed to when
     * the position opened.
     */
    public function test_committed_risk_is_measured_from_the_opening_stop_not_the_live_one(): void
    {
        $this->fund(['ai_max_concurrent_trades' => 3]);
        $this->spec();

        $this->trade([
            'status' => 'open', 'closed_at' => null,
            'entry_price' => 2000.0, 'initial_sl_price' => 1995.0, 'sl_price' => 2000.0,
            'initial_lot_size' => 0.02, 'remaining_lot_size' => 0.02,
        ]);

        $this->assertSame(10.0, $this->state()['committed']);
    }

    /**
     * Unknown is not zero. The direction to be wrong in is the one that sizes the next
     * trade smaller.
     */
    public function test_a_position_whose_risk_cannot_be_measured_costs_a_full_stake(): void
    {
        $this->fund(['ai_max_concurrent_trades' => 3, 'ai_risk_percentage' => 5.00]);

        // No SymbolSpec for this account, and no opening stop recorded.
        $this->trade(['status' => 'open', 'closed_at' => null, 'initial_sl_price' => null]);

        $state = $this->state();

        // One stake at 5% of the unspent 200.
        $this->assertSame(10.0, $state['committed']);
        $this->assertSame(190.0, $state['remaining']);
    }

    public function test_a_position_with_a_stop_but_no_instrument_specification_costs_a_full_stake(): void
    {
        $this->fund(['ai_max_concurrent_trades' => 3, 'ai_risk_percentage' => 5.00]);

        $this->trade([
            'status' => 'open', 'closed_at' => null,
            'entry_price' => 2000.0, 'initial_sl_price' => 1995.0,
        ]);

        $this->assertSame(10.0, $this->state()['committed']);
    }

    /**
     * The gap the old arithmetic left: N positions each sized at pct% of the same
     * remaining figure, because nothing changed it until something closed.
     */
    public function test_concurrent_positions_cannot_commit_more_than_the_fund_holds(): void
    {
        $this->fund(['ai_max_concurrent_trades' => 4, 'ai_risk_percentage' => 25.00]);
        $this->spec();

        // Three open at 50.00 each: 5.00 of stop over 0.10 lots at 10 a pip.
        foreach ([1, 2, 3] as $n) {
            $this->trade([
                'status' => 'open', 'closed_at' => null, 'mt5_ticket' => 9000 + $n,
                'entry_price' => 2000.0, 'initial_sl_price' => 1995.0,
                'initial_lot_size' => 0.10, 'remaining_lot_size' => 0.10,
            ]);
        }

        $state = $this->state();

        $this->assertSame(150.0, $state['committed']);
        $this->assertSame(50.0, $state['remaining']);
        // A fourth is sized from the 50 that is actually left, not from 200 again.
        $this->assertSame(12.5, $state['risk_per_trade']);
        $this->assertNull($state['blocked_reason']);
    }

    /**
     * Nothing lost, nothing available. The remedy is to wait, not to raise the cap, and
     * the reason should say which.
     */
    public function test_a_fully_committed_fund_is_not_reported_as_spent(): void
    {
        $this->fund(['ai_max_concurrent_trades' => 4, 'ai_risk_percentage' => 25.00]);
        $this->spec();

        foreach ([1, 2] as $n) {
            $this->trade([
                'status' => 'open', 'closed_at' => null, 'mt5_ticket' => 9000 + $n,
                'entry_price' => 2000.0, 'initial_sl_price' => 1990.0,
                'initial_lot_size' => 0.10, 'remaining_lot_size' => 0.10,
            ]);
        }

        $state = $this->state();

        $this->assertSame(0.0, $state['remaining']);
        $this->assertFalse($state['exhausted']);
        $this->assertSame('ai_fund_committed', $state['blocked_reason']);
        $this->assertFalse($this->fund->canOpen($this->settings->fresh(), $this->user->id));
    }

    // =====================================================================
    // THE STAKE TIMES THE NUMBER OF STAKES
    // =====================================================================

    public function test_the_stake_times_the_open_limit_may_not_exceed_the_fund(): void
    {
        Livewire::test(Settings::class)
            ->set('ai_risk_percentage', '40')
            ->set('ai_max_concurrent_trades', 3)
            ->call('save')
            ->assertHasErrors(['ai_risk_percentage']);

        $this->assertNotEquals(40.0, (float) $this->settings->fresh()->ai_risk_percentage);
    }

    public function test_a_stake_that_fits_the_open_limit_is_accepted(): void
    {
        Livewire::test(Settings::class)
            ->set('ai_risk_percentage', '33.3')
            ->set('ai_max_concurrent_trades', 3)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(33.3, $this->settings->fresh()->ai_risk_percentage);
    }

    /**
     * A user created outside registration - a seeder, an import - has no settings row
     * until something writes one. The page has to be able to be that something.
     */
    public function test_saving_settings_creates_the_row_when_a_user_has_none(): void
    {
        BotSettings::where('user_id', $this->user->id)->delete();

        Livewire::test(Settings::class)
            ->set('ai_capital_cap', '125')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(125.0, BotSettings::where('user_id', $this->user->id)->sole()->ai_capital_cap);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function spec(): void
    {
        SymbolSpec::updateOrCreate(
            ['broker_account_id' => $this->account->id, 'symbol' => 'XAUUSD'],
            ['base_symbol' => 'XAUUSD', 'pip_size' => 0.10, 'digits' => 2,
                'pip_value_per_lot' => 10.0, 'volume_min' => 0.01, 'volume_step' => 0.01],
        );
    }
}

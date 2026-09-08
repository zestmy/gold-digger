<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\DailyChartCard;
use App\Livewire\Dashboard\TodaySignalsCard;
use App\Livewire\Dashboard\TodayStatsCard;
use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Home: four tiles, today's signals from both sources, the month, and the terminal.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-day, so "today" has room on both sides of now.
        $this->travelTo(Carbon::parse('2026-03-10 13:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id,
            'label' => 'Elev8 Demo',
            'broker_name' => 'Elev8',
            'account_number' => '230070844',
            'server' => 'Elev8-Demo2',
            'is_demo' => true,
            'is_active' => true,
            'account_currency' => 'USD',
            'leverage' => 1000,
        ]);
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $this->actingAs($this->user);
    }

    private function aiSignal(array $overrides = []): Signal
    {
        return Signal::create(array_merge([
            'strategy_id' => $this->strategy->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'M5',
            'direction' => 'buy',
            'entry_price' => 2000.00,
            'sl_price' => 1995.00,
            'tp1_price' => 2003.00,
            'tp2_price' => 2010.00,
            'tp3_price' => 2020.00,
            'features' => [
                'entry_zone_low' => 1999.20, 'entry_zone_high' => 2000.30,
                'valid_until' => now()->addMinutes(5)->toIso8601String(),
                'quality' => ['confidence' => 78, 'grade' => 'B', 'risk' => 'MEDIUM', 'entry_status' => 'CAN ENTRY NOW',
                    'tradeable' => true, 'confluence' => 5.0, 'possible' => 6.5, 'directional' => 3.5, 'why' => '', 'factors' => []],
            ],
            'was_executed' => false,
            'generated_at' => now()->subMinutes(10),
        ], $overrides));
    }

    private function copiedSignal(array $overrides = []): TelegramSignal
    {
        static $n = 0;
        $n++;

        return TelegramSignal::create(array_merge([
            'user_id' => $this->user->id,
            'source' => TelegramChannel::SOURCE_ACCOUNT,
            'external_id' => "tg:5001:{$n}",
            'chat_id' => '5001',
            'chat_title' => 'Gold Desk VIP',
            'raw_text' => 'XAUUSD sell 2650',
            'posted_at' => now()->subMinutes(3),
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => 'XAUUSD',
            'direction' => 'sell',
            'entry_price' => 2650.0,
            'entry_zone_high' => 2653.0,
            'sl_price' => 2660.0,
            'tp_prices' => [2640.0, 2630.0],
            'review_status' => TelegramSignal::REVIEW_APPROVED,
            'execution_status' => TelegramSignal::EXEC_EXECUTED,
        ], $overrides));
    }

    /**
     * A terminal that has checked in, and a last close to read the card against.
     */
    private function feedAt(float $close): void
    {
        BotHeartbeat::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'source' => 'mql5_ea',
            'algo_trading_enabled' => true,
            'broker_connected' => true,
            'resolved_symbol' => 'XAUUSDm',
            'symbols' => [['base' => 'XAUUSD', 'resolved' => 'XAUUSDm'], ['base' => 'EURUSD', 'resolved' => 'EURUSDm']],
            'last_seen_at' => now(),
        ]);

        Candle::create([
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'M5',
            'open_time' => now()->subMinutes(5),
            'open' => $close,
            'high' => $close + 1,
            'low' => $close - 1,
            'close' => $close,
        ]);
    }

    private function trade(array $overrides = []): Trade
    {
        return Trade::create(array_merge([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'symbol' => 'XAUUSD',
            'direction' => 'buy',
            'initial_lot_size' => 0.10,
            'remaining_lot_size' => 0.10,
            'entry_price' => 2000,
            'sl_price' => 1995,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ], $overrides));
    }

    // =====================================================================
    // THE PAGE
    // =====================================================================

    public function test_home_renders_for_an_account_with_nothing_in_it(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Signals today')
            ->assertSee('Open positions')
            ->assertSee('nothing at risk')
            ->assertSee('Net P&amp;L today', false)
            ->assertSee('AI fund')
            ->assertSee('No signals yet today.')
            ->assertSee('Last 30 days')
            ->assertSee('Execution')
            ->assertSee('Resume auto-trade')
            ->assertSee('Close all');
    }

    /**
     * The composition is a glance. The market-context cards and the trades table moved
     * to the destinations that own them.
     */
    public function test_home_no_longer_carries_the_context_cards(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Trading Session')
            ->assertDontSee('Economic Calendar')
            ->assertDontSee('Recent Trades')
            ->assertDontSee('Quick Actions');
    }

    // =====================================================================
    // THE TILES
    // =====================================================================

    public function test_the_tiles_count_signals_from_both_sources(): void
    {
        $this->aiSignal();
        $this->aiSignal(['generated_at' => now()->subMinutes(20)]);
        $this->copiedSignal();

        // Yesterday's rows are the archive's, not today's - a copied one by when the
        // provider posted it, even if it was only captured today.
        $this->aiSignal(['generated_at' => now()->subDay()]);
        $this->copiedSignal(['posted_at' => now()->subDay()]);

        Livewire::test(TodayStatsCard::class)
            ->assertSet('aiSignals', 2)
            ->assertSet('copiedSignals', 1)
            ->assertSee('2 AI')
            ->assertSee('1 copied');
    }

    public function test_the_tiles_report_open_positions_and_the_day_against_the_month(): void
    {
        $this->trade(['symbol' => 'XAUUSD']);
        $this->trade(['symbol' => 'EURUSD', 'status' => 'partially_closed']);

        $this->trade(['status' => 'fully_closed', 'net_pnl_money' => 120.50, 'closed_at' => now()->subHour()]);
        $this->trade(['status' => 'stopped_out', 'net_pnl_money' => -40.00, 'closed_at' => now()->subHours(2)]);
        $this->trade(['status' => 'fully_closed', 'net_pnl_money' => 200.00, 'closed_at' => now()->subDays(5)]);

        // Outside the window and still open: neither counts toward the money figures.
        $this->trade(['status' => 'fully_closed', 'net_pnl_money' => 999.00, 'closed_at' => now()->subDays(45)]);

        Livewire::test(TodayStatsCard::class)
            ->assertSet('openPositions', 2)
            ->assertSet('netPnl', 80.5)
            ->assertSet('netPnl30d', 280.5)
            ->assertSee('XAUUSD · EURUSD')
            ->assertSee('$80.50')
            ->assertSee('+$280.50')
            ->assertDontSee('nothing at risk');
    }

    public function test_the_fund_tile_reads_ai_fund_rather_than_inventing_a_figure(): void
    {
        BotSettings::where('user_id', $this->user->id)->update([
            'ai_trading_enabled' => true,
            'ai_capital_cap' => 500,
            'ai_risk_percentage' => 5,
        ]);

        // A realised loss depletes the fund; a settled win refills it.
        $this->trade(['origin' => 'ai', 'status' => 'stopped_out', 'net_pnl_money' => -50, 'closed_at' => now()->subHour()]);

        Livewire::test(TodayStatsCard::class)
            ->assertSet('fundConfigured', true)
            ->assertSet('fundCap', 500.0)
            ->assertSet('fundRemaining', 450.0)
            ->assertSee('$450')
            ->assertSee('of $500')
            ->assertSee('committed');
    }

    public function test_the_fund_tile_says_no_cap_rather_than_zero(): void
    {
        Livewire::test(TodayStatsCard::class)
            ->assertSet('fundConfigured', false)
            ->assertSee('No cap set')
            ->assertDontSee('of $0');
    }

    // =====================================================================
    // TODAY'S SIGNALS
    // =====================================================================

    public function test_an_ai_signal_is_read_for_entry_against_the_last_close(): void
    {
        // Price at 2001, a buy zone at 1999.20-2000.30: the move has gone, set a limit.
        $this->feedAt(2001.00);
        $this->aiSignal();

        Livewire::test(TodaySignalsCard::class)
            ->assertSet('aiCount', 1)
            ->assertSee('XAUUSD')
            ->assertSee('M5')
            ->assertSee('AI')
            ->assertSee('BUY')
            ->assertSee('1,999.20')
            ->assertSee('2,000.30')
            ->assertSee('1,995.00')
            ->assertSee('2,003.00 / 2,010.00 / 2,020.00')
            ->assertSee('1:4')
            ->assertSee('78%')
            ->assertSee('SET LIMIT ORDER');
    }

    public function test_a_traded_signal_says_so_instead_of_advising_an_entry(): void
    {
        $this->feedAt(2001.00);
        $this->aiSignal(['was_executed' => true]);

        Livewire::test(TodaySignalsCard::class)
            ->assertSee('TRADED')
            ->assertDontSee('SET LIMIT ORDER');
    }

    public function test_a_held_signal_names_the_reason_it_was_held(): void
    {
        $this->feedAt(2001.00);
        $this->aiSignal(['skip_reason' => 'news_blackout']);

        Livewire::test(TodaySignalsCard::class)
            ->assertSee('HELD: News blackout')
            ->assertDontSee('SET LIMIT ORDER');
    }

    public function test_a_signal_with_no_price_to_read_against_says_so(): void
    {
        // No terminal has checked in and no bar exists, so there is nothing to compare
        // the zone with. The chip must not invent a guidance.
        $this->aiSignal();

        Livewire::test(TodaySignalsCard::class)
            ->assertSee('NO LIVE PRICE');
    }

    public function test_a_copied_signal_shows_its_provider_levels_and_where_it_got_to(): void
    {
        $this->copiedSignal();
        $this->copiedSignal(['review_status' => TelegramSignal::REVIEW_DECLINED, 'execution_status' => TelegramSignal::EXEC_NONE]);
        $this->copiedSignal(['review_status' => TelegramSignal::REVIEW_PENDING, 'execution_status' => TelegramSignal::EXEC_NONE]);

        Livewire::test(TodaySignalsCard::class)
            ->assertSet('copiedCount', 3)
            ->assertSee('Gold Desk VIP')
            ->assertSee('SELL')
            ->assertSee('2,650.00')
            ->assertSee('2,653.00')
            ->assertSee('2,660.00')
            ->assertSee('2,640.00 / 2,630.00')
            // (2650 - 2630) / (2660 - 2650), on the final rung as SignalCard judges it.
            ->assertSee('1:2')
            ->assertSee('EXECUTED')
            ->assertSee('DECLINED')
            ->assertSee('AWAITING REVIEW');
    }

    /**
     * DECLINED answers "did it trade"; the reader's question is "why not". The reviewer's
     * reasoning and the executor's note were on the Copied page only, so from here a
     * declined signal looked like a bug.
     */
    public function test_a_refused_copied_signal_says_why_on_the_dashboard(): void
    {
        $this->copiedSignal([
            'review_status' => TelegramSignal::REVIEW_DECLINED,
            'execution_status' => TelegramSignal::EXEC_NONE,
            'review_reasoning' => 'The stop sits inside the zone. A resting order would be stopped before it fills.',
        ]);
        $this->copiedSignal([
            'review_status' => TelegramSignal::REVIEW_APPROVED,
            'execution_status' => TelegramSignal::EXEC_BLOCKED,
            'review_reasoning' => 'Approved on the levels as posted.',
            'execution_note' => 'No executor is online to place the order.',
        ]);

        Livewire::test(TodaySignalsCard::class)
            ->assertSee('DECLINED')
            ->assertSee('The stop sits inside the zone.')
            ->assertSee('BLOCKED')
            ->assertSee('No executor is online to place the order.')
            // A blocked signal's note is the executor's, not the stale approval.
            ->assertDontSee('Approved on the levels as posted.');
    }

    public function test_an_executed_copied_signal_carries_no_refusal_note(): void
    {
        $this->copiedSignal(['review_reasoning' => 'Clean levels, taken.']);

        $rows = Livewire::test(TodaySignalsCard::class)->get('rows');

        $this->assertNull($rows[0]['note']);
    }

    public function test_the_list_merges_both_sources_newest_first_and_stops_at_eight(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->aiSignal(['generated_at' => now()->subMinutes(60 - $i * 5), 'symbol' => "AI{$i}"]);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->copiedSignal(['posted_at' => now()->subMinutes(58 - $i * 5), 'symbol' => "TG{$i}"]);
        }

        $rows = Livewire::test(TodaySignalsCard::class)->get('rows');

        $this->assertCount(TodaySignalsCard::LIMIT, $rows);

        $times = array_map(fn ($row) => Carbon::parse($row['at'])->timestamp, $rows);
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times, 'rows are newest first');

        // The two oldest - AI0 at -60 and TG0 at -58 - fall off the end.
        $symbols = array_column($rows, 'symbol');
        $this->assertNotContains('AI0', $symbols);
        $this->assertNotContains('TG0', $symbols);
        $this->assertContains('AI5', $symbols);
        $this->assertContains('TG3', $symbols);
    }

    public function test_follow_ups_are_not_signals(): void
    {
        $this->copiedSignal(['kind' => TelegramSignal::KIND_FOLLOW_UP, 'symbol' => 'FOLLOWUP']);

        Livewire::test(TodaySignalsCard::class)
            ->assertSet('copiedCount', 0)
            ->assertDontSee('FOLLOWUP')
            ->assertSee('No signals yet today.');
    }

    public function test_another_tenants_signals_are_not_on_my_home(): void
    {
        $other = User::factory()->create();
        $otherStrategy = Strategy::acrossTenants()->where('user_id', $other->id)->firstOrFail();

        Signal::create([
            'strategy_id' => $otherStrategy->id,
            'symbol' => 'THEIRS',
            'timeframe' => 'M5',
            'direction' => 'sell',
            'entry_price' => 1.1,
            'sl_price' => 1.2,
            'generated_at' => now(),
        ]);

        Livewire::test(TodaySignalsCard::class)
            ->assertSet('aiCount', 0)
            ->assertDontSee('THEIRS');
    }

    // =====================================================================
    // LAST 30 DAYS
    // =====================================================================

    public function test_the_month_card_carries_trades_win_rate_and_profit_factor(): void
    {
        $this->trade(['status' => 'fully_closed', 'gross_pnl_money' => 100, 'net_pnl_money' => 90, 'closed_at' => now()->subDays(3)]);
        $this->trade(['status' => 'fully_closed', 'gross_pnl_money' => 60, 'net_pnl_money' => 50, 'closed_at' => now()->subDays(2)]);
        $this->trade(['status' => 'stopped_out', 'gross_pnl_money' => -80, 'net_pnl_money' => -85, 'closed_at' => now()->subDay()]);

        // Still open: not a result yet.
        $this->trade();

        Livewire::test(DailyChartCard::class)
            ->assertViewHas('summary', ['trades' => 3, 'win_rate' => 66.7, 'profit_factor' => 2.0])
            ->assertSee('66.7%')
            ->assertSee('2.00');
    }

    // =====================================================================
    // EXECUTION
    // =====================================================================

    public function test_the_execution_card_lists_the_instruments_carried_and_the_calendar(): void
    {
        $this->feedAt(2001.00);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('ONLINE')
            ->assertSee('XAUUSD · EURUSD')
            ->assertSee('Elev8 Demo')
            // The default settings have the filter on and no calendar has been fetched,
            // which holds entries - and that has to be said here, not only on Signals.
            ->assertSee('No calendar');
    }
}

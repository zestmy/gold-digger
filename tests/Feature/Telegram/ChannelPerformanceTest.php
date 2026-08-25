<?php

namespace Tests\Feature\Telegram;

use App\Livewire\Pages\SignalChannels;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\SymbolSpec;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Models\Trade;
use App\Models\User;
use App\Services\Telegram\ChannelPerformance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What each channel has been worth.
 *
 * The properties that make the page trustworthy rather than merely populated: a rate is
 * never reported without the count behind it, an unknown denominator is null rather than
 * zero, and the funnel is visible next to the money so a good-looking channel with two
 * trades looks like a channel with two trades.
 */
class ChannelPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ChannelPerformance $performance;

    private Strategy $strategy;

    private BrokerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->performance = new ChannelPerformance;

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $this->account = $account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);

        SymbolSpec::create([
            'broker_account_id' => $account->id,
            'base_symbol' => 'XAUUSD', 'symbol' => 'XAUUSD', 'pip_size' => 0.1,
            'digits' => 2, 'pip_value_per_lot' => 1.0, 'volume_min' => 0.01, 'volume_step' => 0.01,
        ]);
    }

    // =====================================================================
    // THE FUNNEL
    // =====================================================================

    public function test_each_channel_is_reported_separately(): void
    {
        $a = $this->channel('Fira');
        $b = $this->channel('Other');

        $this->signal($a, net: 100.0);
        $this->signal($b, net: -50.0);

        $rows = $this->performance->forUser($this->user->id);

        $this->assertCount(2, $rows);
        $this->assertSame(100.0, $rows->firstWhere('label', 'Fira')['net_money']);
        $this->assertSame(-50.0, $rows->firstWhere('label', 'Other')['net_money']);
    }

    /**
     * The number that distinguishes a quiet week from a provider changing their format.
     */
    public function test_unparsed_messages_are_counted_not_dropped(): void
    {
        $channel = $this->channel('Fira');

        $this->signal($channel);
        $this->rawSignal($channel, ['parse_status' => TelegramSignal::PARSE_FAILED, 'parse_error' => 'No stop loss']);
        $this->rawSignal($channel, ['parse_status' => TelegramSignal::PARSE_FAILED, 'parse_error' => 'No stop loss']);

        $row = $this->performance->forUser($this->user->id)->first();

        $this->assertSame(3, $row['messages']);
        $this->assertSame(1, $row['parsed']);
        $this->assertSame(33.3, $row['parse_rate']);
    }

    public function test_the_decline_rate_is_of_reviewed_signals_not_of_everything(): void
    {
        $channel = $this->channel('Fira');

        // Two reviewed, one of them declined. The unparsed one was never reviewed, so
        // counting it in the denominator would understate how picky the reviewer is.
        $this->rawSignal($channel, ['review_status' => TelegramSignal::REVIEW_APPROVED]);
        $this->rawSignal($channel, ['review_status' => TelegramSignal::REVIEW_DECLINED]);
        $this->rawSignal($channel, [
            'parse_status' => TelegramSignal::PARSE_FAILED,
            'review_status' => TelegramSignal::REVIEW_SKIPPED,
        ]);

        $this->assertSame(50.0, $this->performance->forUser($this->user->id)->first()['decline_rate']);
    }

    // =====================================================================
    // THE MONEY
    // =====================================================================

    public function test_open_trades_are_not_counted_as_results(): void
    {
        $channel = $this->channel('Fira');

        $this->signal($channel, net: 100.0);
        $this->signal($channel, net: 999.0, status: 'open');

        $row = $this->performance->forUser($this->user->id)->first();

        // A floating profit is not a result. Including it would let a channel look
        // profitable purely by never closing anything.
        $this->assertSame(1, $row['closed']);
        $this->assertSame(1, $row['open']);
        $this->assertSame(100.0, $row['net_money']);
    }

    public function test_profit_factor_is_null_rather_than_infinite(): void
    {
        $channel = $this->channel('Fira');
        $this->signal($channel, net: 100.0);

        $row = $this->performance->forUser($this->user->id)->first();

        // Two wins and no losses is not an infinitely good channel; it is a channel that
        // has not lost yet, and the page has to be able to say so.
        $this->assertNull($row['profit_factor']);
        $this->assertSame(100.0, $row['win_rate']);
        $this->assertSame(1, $row['closed']);
    }

    public function test_a_win_rate_is_never_reported_without_its_count(): void
    {
        $channel = $this->channel('Fira');
        $this->signal($channel, net: 10.0);

        $row = $this->performance->forUser($this->user->id)->first();

        $this->assertSame(100.0, $row['win_rate']);
        $this->assertSame(1, $row['wins']);
        $this->assertSame(0, $row['losses']);
    }

    // =====================================================================
    // R
    // =====================================================================

    /**
     * 30 pips made against a 10-pip stop is 3R, whatever the account was worth.
     */
    public function test_r_is_measured_against_the_stop_that_was_used(): void
    {
        $channel = $this->channel('Fira');

        // pip_size 0.1, entry 2650, stop 2649 -> 1.0 of price = 10 pips risked.
        $this->signal($channel, net: 300.0, pips: 30.0, entry: 2650.0, sl: 2649.0);

        $this->assertSame(3.0, $this->performance->forUser($this->user->id)->first()['avg_r']);
    }

    public function test_a_trade_with_no_stop_is_ungraded_rather_than_zero(): void
    {
        $channel = $this->channel('Fira');

        $this->signal($channel, net: 300.0, pips: 30.0, entry: 2650.0, sl: 2649.0);
        $this->signal($channel, net: 100.0, pips: 10.0, entry: 2650.0, sl: null);

        $row = $this->performance->forUser($this->user->id)->first();

        // Averaging 3R with a 0R that nobody measured would report 1.5R, which is a
        // statement about a trade that was never graded.
        $this->assertSame(3.0, $row['avg_r']);
        $this->assertSame(1, $row['graded']);
        $this->assertSame(2, $row['closed']);
    }

    // =====================================================================
    // DECLINE REASONS
    // =====================================================================

    public function test_decline_reasons_are_grouped_by_their_first_sentence(): void
    {
        $channel = $this->channel('Fira');

        $this->rawSignal($channel, [
            'review_status' => TelegramSignal::REVIEW_DECLINED,
            'review_reasoning' => 'Stop already passed. Price is 2661 against a stop at 2660.',
        ]);
        $this->rawSignal($channel, [
            'review_status' => TelegramSignal::REVIEW_DECLINED,
            'review_reasoning' => 'Stop already passed. The market moved 14 points since posting.',
        ]);

        $reasons = $this->performance->declineReasons($this->user->id, $channel->id);

        // Grouping on the whole paragraph gives a list where every entry has a count of
        // one, which answers nothing.
        $this->assertSame(['Stop already passed.' => 2], $reasons);
    }

    // =====================================================================
    // THE PAGE
    // =====================================================================

    public function test_the_page_is_the_only_thing_that_arms_a_channel(): void
    {
        $channel = $this->channel('Fira', enabled: false);

        Livewire::actingAs($this->user)->test(SignalChannels::class)->call('toggle', $channel->id);

        $this->assertTrue($channel->fresh()->is_enabled);
    }

    public function test_another_users_channel_cannot_be_toggled(): void
    {
        $other = User::factory()->create();

        $channel = TelegramChannel::create([
            'user_id' => $other->id, 'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => '9999', 'title' => 'Theirs', 'is_enabled' => false,
        ]);

        Livewire::actingAs($this->user)->test(SignalChannels::class)->call('toggle', $channel->id);

        $this->assertFalse($channel->fresh()->is_enabled);
    }

    public function test_a_thin_sample_is_labelled(): void
    {
        $channel = $this->channel('Fira');
        $this->signal($channel, net: 100.0);

        Livewire::actingAs($this->user)->test(SignalChannels::class)
            ->assertSee('Fira')
            ->assertSee('Too few to rank');
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function channel(string $title, bool $enabled = true): TelegramChannel
    {
        return TelegramChannel::create([
            'user_id' => $this->user->id,
            'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => (string) random_int(1000, 999999),
            'title' => $title,
            'is_enabled' => $enabled,
        ]);
    }

    private function signal(
        TelegramChannel $channel,
        ?float $net = null,
        float $pips = 0.0,
        float $entry = 2650.0,
        ?float $sl = 2640.0,
        string $status = 'fully_closed',
    ): TelegramSignal {
        $trade = null;

        if ($net !== null) {
            $trade = Trade::create([
                'user_id' => $this->user->id,
                'strategy_id' => $this->strategy->id,
                'broker_account_id' => $this->account->id,
                'mt5_ticket' => random_int(100000, 999999),
                'symbol' => 'XAUUSD',
                'direction' => 'buy',
                'initial_lot_size' => 0.01,
                'remaining_lot_size' => $status === 'open' ? 0.01 : 0,
                'entry_price' => $entry,
                'sl_price' => $sl,
                'gross_pnl_pips' => $pips,
                'gross_pnl_money' => $net,
                'net_pnl_money' => $net,
                'status' => $status,
                'origin' => 'bot',
                'opened_at' => now(),
            ]);
        }

        return $this->rawSignal($channel, [
            'trade_id' => $trade?->id,
            'execution_status' => $trade ? TelegramSignal::EXEC_EXECUTED : TelegramSignal::EXEC_NONE,
            'review_status' => TelegramSignal::REVIEW_APPROVED,
        ]);
    }

    private function rawSignal(TelegramChannel $channel, array $attributes = []): TelegramSignal
    {
        return TelegramSignal::create($attributes + [
            'user_id' => $this->user->id,
            'source' => TelegramChannel::SOURCE_ACCOUNT,
            'external_id' => 'tg:'.$channel->chat_id.':'.random_int(1, 999999999),
            'chat_id' => $channel->chat_id,
            'telegram_channel_id' => $channel->id,
            'chat_title' => $channel->title,
            'raw_text' => 'XAUUSD BUY',
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => 'XAUUSD',
            'direction' => 'buy',
        ]);
    }
}

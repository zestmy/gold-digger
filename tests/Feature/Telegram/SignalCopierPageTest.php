<?php

namespace Tests\Feature\Telegram;

use App\Livewire\Pages\SignalCopier;
use App\Models\BotSettings;
use App\Models\TelegramSignal;
use App\Models\TradeCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The copier page.
 *
 * The decline rate is the point. A copier approving most of what it sees is
 * indistinguishable from having no reviewer, and that failure is invisible in a list of
 * individual verdicts which each read perfectly sensibly - so the rate is surfaced, and
 * flagged when it falls.
 */
class SignalCopierPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->user = User::factory()->create();
        BotSettings::where('user_id', $this->user->id)->update([
            'is_active' => true,
            'ai_trading_enabled' => true,
            'ai_capital_cap' => 500.00,
            'ai_risk_percentage' => 2.00,
        ]);

        $this->actingAs($this->user);
    }

    private function signal(array $overrides = []): TelegramSignal
    {
        return TelegramSignal::create($overrides + [
            'user_id' => $this->user->id,
            'source' => 'bot_api',
            'external_id' => 'bot:'.random_int(1, 999999999),
            'chat_id' => '316745398',
            'raw_text' => 'XAUUSD BUY 2650 SL 2640 TP 2680',
            'posted_at' => now()->subMinutes(2),
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => 'XAUUSD',
            'direction' => 'buy',
            'entry_price' => 2650.0,
            'sl_price' => 2640.0,
            'tp_prices' => [2680.0],
            'review_status' => TelegramSignal::REVIEW_PENDING,
        ]);
    }

    // =====================================================================
    // THE NUMBER THAT MATTERS
    // =====================================================================

    public function test_it_shows_the_decline_rate_over_reviewed_signals_only(): void
    {
        // Three reviewed, one approved. Unreviewed chatter must not pad the denominator -
        // counting it as a decline would flatter the rate.
        $this->signal(['review_status' => TelegramSignal::REVIEW_APPROVED]);
        $this->signal(['review_status' => TelegramSignal::REVIEW_DECLINED]);
        $this->signal(['review_status' => TelegramSignal::REVIEW_DECLINED]);
        $this->signal(['parse_status' => TelegramSignal::PARSE_FAILED, 'review_status' => TelegramSignal::REVIEW_SKIPPED]);

        Livewire::test(SignalCopier::class)
            ->assertViewHas('declineRate', 67)
            ->assertSee('Decline rate');
    }

    public function test_a_low_decline_rate_is_called_out(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->signal(['review_status' => TelegramSignal::REVIEW_APPROVED]);
        }
        $this->signal(['review_status' => TelegramSignal::REVIEW_DECLINED]);

        Livewire::test(SignalCopier::class)
            ->assertViewHas('declineRate', 20)
            ->assertSee('approving most of what it sees');
    }

    public function test_a_healthy_decline_rate_is_not_flagged(): void
    {
        $this->signal(['review_status' => TelegramSignal::REVIEW_APPROVED]);
        $this->signal(['review_status' => TelegramSignal::REVIEW_DECLINED]);
        $this->signal(['review_status' => TelegramSignal::REVIEW_DECLINED]);

        Livewire::test(SignalCopier::class)->assertDontSee('approving most of what it sees');
    }

    public function test_no_reviews_yet_shows_no_rate_rather_than_zero(): void
    {
        $this->signal();

        // Zero percent would read as "it approves everything", which is the opposite of
        // what an empty sample means.
        Livewire::test(SignalCopier::class)->assertViewHas('declineRate', null);
    }

    // =====================================================================
    // THE PIPELINE IS VISIBLE AT EVERY STAGE
    // =====================================================================

    public function test_unparsed_messages_are_shown_with_the_reason(): void
    {
        // How a provider changing format announces itself.
        $this->signal([
            'parse_status' => TelegramSignal::PARSE_FAILED,
            'parse_error' => 'No stop loss found.',
            'raw_text' => 'GOLD BUY NOW!!',
            'symbol' => null,
            'direction' => null,
        ]);

        Livewire::test(SignalCopier::class)
            ->assertSee('not parsed')
            ->assertSee('No stop loss found.')
            ->assertSee('GOLD BUY NOW!!');
    }

    public function test_a_verdict_is_shown_with_its_reasoning(): void
    {
        $this->signal([
            'review_status' => TelegramSignal::REVIEW_DECLINED,
            'review_reasoning' => 'Reward to risk of 0.4:1 is unacceptable.',
        ]);

        Livewire::test(SignalCopier::class)->assertSee('Reward to risk of 0.4:1 is unacceptable.');
    }

    public function test_filters_narrow_the_list(): void
    {
        $this->signal(['review_status' => TelegramSignal::REVIEW_APPROVED, 'symbol' => 'XAUUSD']);
        $this->signal(['review_status' => TelegramSignal::REVIEW_DECLINED, 'symbol' => 'EURUSD']);

        Livewire::test(SignalCopier::class)
            ->set('filter', 'approved')
            ->assertSee('XAUUSD')
            ->assertDontSee('EURUSD');
    }

    // =====================================================================
    // EXECUTE IS NOT A CASUAL BUTTON
    // =====================================================================

    public function test_execute_is_offered_only_on_approved_unacted_signals(): void
    {
        $this->signal(['review_status' => TelegramSignal::REVIEW_DECLINED]);

        Livewire::test(SignalCopier::class)->assertDontSee('executeNow');
    }

    public function test_execute_states_what_it_will_risk_before_you_press_it(): void
    {
        $this->signal(['review_status' => TelegramSignal::REVIEW_APPROVED]);

        // 2% of a 500 fund.
        Livewire::test(SignalCopier::class)
            ->assertSee('executeNow')
            ->assertSee('10.00');
    }

    /**
     * The page must not be a way around the gates.
     */
    public function test_executing_from_the_page_still_runs_every_gate(): void
    {
        Http::fake();
        // Kill switch off: the executor should refuse regardless of what the page offers.
        BotSettings::where('user_id', $this->user->id)->update(['is_active' => false]);

        $signal = $this->signal(['review_status' => TelegramSignal::REVIEW_APPROVED]);

        Livewire::test(SignalCopier::class)->call('executeNow', $signal->id);

        $this->assertSame(0, TradeCommand::count());
        $this->assertSame(TelegramSignal::EXEC_BLOCKED, $signal->fresh()->execution_status);
    }

    public function test_it_will_not_act_on_another_users_signal(): void
    {
        Http::fake();
        $other = User::factory()->create();

        $theirs = TelegramSignal::create([
            'user_id' => $other->id, 'source' => 'bot_api', 'external_id' => 'bot:999',
            'chat_id' => '1', 'raw_text' => 'x', 'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => 'XAUUSD', 'direction' => 'buy', 'sl_price' => 2640.0,
            'review_status' => TelegramSignal::REVIEW_APPROVED,
        ]);

        Livewire::test(SignalCopier::class)->call('executeNow', $theirs->id);

        $this->assertSame(TelegramSignal::EXEC_NONE, $theirs->fresh()->execution_status);
        $this->assertSame(0, TradeCommand::count());
    }

    public function test_the_page_renders(): void
    {
        $this->signal();

        $this->get(route('signals.copier'))->assertOk()->assertSee('Signal Copier');
    }
}

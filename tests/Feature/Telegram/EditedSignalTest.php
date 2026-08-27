<?php

namespace Tests\Feature\Telegram;

use App\Models\BotToken;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Providers correcting their own messages.
 *
 * The bug this covers was quiet rather than loud: ingest is idempotent on external_id, so
 * an edit arrived with the same identity and different content and was simply re-parsed.
 * Nothing traded twice - execution is guarded separately - but a signal whose entry had
 * been corrected would have had its stored levels rewritten underneath a position already
 * open at the old ones, and the analytics would then grade that trade against levels it
 * was never taken at.
 */
class EditedSignalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'alerts.telegram.token' => 'bot-token',
            'alerts.telegram.chat_id' => '316745398',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        // An edit to a position already open is the copier's most time-critical message,
        // so it goes to the tenant holding the position rather than to the operator.
        $this->user = User::factory()->create(['telegram_chat_id' => '316745398']);
        [$this->token] = BotToken::generate($this->user, 'Collector');

        TelegramChannel::create([
            'user_id' => $this->user->id, 'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => '5001', 'title' => 'FTC 2026', 'is_enabled' => true,
        ]);
    }

    // =====================================================================
    // BEFORE ANYTHING WAS DONE
    // =====================================================================

    public function test_an_edit_is_not_a_second_signal(): void
    {
        $this->send("XAUUSD BUY\nEntry 2650\nSL 2645\nTP 2680");
        $this->send("XAUUSD BUY\nEntry 2660\nSL 2645\nTP 2680");

        $this->assertSame(1, TelegramSignal::count());
        $this->assertSame(1, TelegramSignal::first()->edit_count);
    }

    public function test_an_untouched_signal_is_re_parsed_from_the_corrected_text(): void
    {
        $this->send("XAUUSD BUY\nEntry 2650\nSL 2645\nTP 2680");
        $this->send("XAUUSD BUY\nEntry 2660\nSL 2648\nTP 2680");

        $signal = TelegramSignal::first();

        $this->assertEqualsWithDelta(2660.0, $signal->entry_price, 1e-9);
        $this->assertEqualsWithDelta(2648.0, $signal->sl_price, 1e-9);
    }

    /**
     * An approval was of the old text.
     */
    public function test_an_edit_sends_an_approved_signal_back_for_review(): void
    {
        $this->send("XAUUSD BUY\nEntry 2650\nSL 2645\nTP 2680");

        TelegramSignal::first()->update([
            'review_status' => TelegramSignal::REVIEW_APPROVED,
            'review_reasoning' => 'Looked fine at the time.',
            'review_confidence' => 80,
        ]);

        $this->send("XAUUSD BUY\nEntry 2660\nSL 2645\nTP 2680");

        $signal = TelegramSignal::first();

        $this->assertSame(TelegramSignal::REVIEW_PENDING, $signal->review_status);
        $this->assertNull($signal->review_reasoning);
        $this->assertNull($signal->reviewed_at);
    }

    // =====================================================================
    // AFTER A POSITION IS OPEN
    // =====================================================================

    /**
     * The levels the order carries cannot be un-sent.
     */
    public function test_an_edit_after_execution_leaves_the_traded_levels_alone(): void
    {
        $this->send("XAUUSD BUY\nEntry 2650\nSL 2645\nTP 2680");

        TelegramSignal::first()->update(['execution_status' => TelegramSignal::EXEC_EXECUTED]);

        $this->send("XAUUSD BUY\nEntry 2900\nSL 2890\nTP 2950");

        $signal = TelegramSignal::first();

        // Grading this trade against 2900 would be measuring a position nobody took.
        $this->assertEqualsWithDelta(2650.0, $signal->entry_price, 1e-9);
        $this->assertEqualsWithDelta(2645.0, $signal->sl_price, 1e-9);

        // But the record shows both.
        $this->assertStringContainsString('2900', $signal->raw_text);
        $this->assertStringContainsString('2650', $signal->original_text);
    }

    public function test_an_edit_after_execution_is_announced(): void
    {
        $this->send("XAUUSD BUY\nEntry 2650\nSL 2645\nTP 2680");
        TelegramSignal::first()->update(['execution_status' => TelegramSignal::EXEC_EXECUTED]);

        $this->send("XAUUSD BUY\nEntry 2900\nSL 2890\nTP 2950");

        // Nothing downstream can act on this - the order has gone - so a person has to.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'already acted on'));
    }

    // =====================================================================
    // AND A RETRY IS STILL A RETRY
    // =====================================================================

    public function test_identical_content_is_not_counted_as_an_edit(): void
    {
        $text = "XAUUSD BUY\nEntry 2650\nSL 2645\nTP 2680";

        $this->send($text);
        $this->send($text);

        $signal = TelegramSignal::first();

        $this->assertSame(0, $signal->edit_count);
        $this->assertNull($signal->edited_at);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }

    private function send(string $text): void
    {
        $this->withToken($this->token)->postJson('/api/v1/telegram/messages', [
            'messages' => [[
                'chat_id' => '5001',
                // The same message throughout. An edit keeps its id, which is exactly why
                // it has to be told apart from a fresh post.
                'message_id' => 100,
                'text' => $text,
                'chat_title' => 'FTC 2026',
                'date' => now()->timestamp,
            ]],
        ])->assertOk();
    }
}

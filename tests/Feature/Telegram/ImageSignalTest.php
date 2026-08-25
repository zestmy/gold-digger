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
 * Signals posted as a picture.
 *
 * Reading digits off an image has a failure mode text does not: it can be confidently
 * wrong. A misread digit turns 2650 into 2050 - a well-formed price, passing every sanity
 * check, describing a completely different trade. So the tests that matter most here are
 * the ones about refusing.
 */
class ImageSignalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.key' => 'sk-or-test', 'ai.base_url' => 'https://openrouter.ai/api/v1']);

        $this->user = User::factory()->create();
        [$this->token] = BotToken::generate($this->user, 'Collector');

        TelegramChannel::create([
            'user_id' => $this->user->id, 'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => '5001', 'title' => 'FTC 2026', 'is_enabled' => true,
        ]);
    }

    public function test_a_readable_screenshot_becomes_a_signal(): void
    {
        $this->vision(true, "XAUUSD SELL\nEntry 2650\nSL 2660\nTP 2630");

        $this->send(text: '', image: 'fake-jpeg-bytes');

        $signal = TelegramSignal::first();

        $this->assertSame(TelegramSignal::PARSE_OK, $signal->parse_status);
        $this->assertSame('XAUUSD', $signal->symbol);
        $this->assertSame('sell', $signal->direction);
        $this->assertTrue($signal->from_image);
    }

    /**
     * Both kinds of evidence are kept, because "did we read that right" needs both.
     */
    public function test_the_caption_and_the_transcription_are_stored_separately(): void
    {
        $this->vision(true, "XAUUSD SELL\nEntry 2650\nSL 2660\nTP 2630");

        $this->send(text: 'Gold setup for today', image: 'fake-jpeg-bytes');

        $signal = TelegramSignal::first();

        $this->assertSame('Gold setup for today', $signal->raw_text);
        $this->assertStringContainsString('2650', $signal->transcribed_text);
    }

    // =====================================================================
    // REFUSING
    // =====================================================================

    public function test_an_unreadable_image_is_recorded_and_not_traded(): void
    {
        $this->vision(false, null, 'The stop loss digits are cropped.');

        $this->send(text: '', image: 'fake-jpeg-bytes');

        $signal = TelegramSignal::first();

        $this->assertSame(TelegramSignal::PARSE_FAILED, $signal->parse_status);
        $this->assertStringContainsString('cropped', $signal->parse_error);
        $this->assertNull($signal->symbol);
        $this->assertSame(TelegramSignal::REVIEW_SKIPPED, $signal->review_status);
    }

    /**
     * The image path is held to the same rules as the text path, by using them.
     */
    public function test_a_transcription_with_no_stop_is_refused_like_any_other_signal(): void
    {
        $this->vision(true, "XAUUSD SELL\nEntry 2650\nTP 2630");

        $this->send(text: '', image: 'fake-jpeg-bytes');

        $signal = TelegramSignal::first();

        $this->assertSame(TelegramSignal::PARSE_FAILED, $signal->parse_status);
        $this->assertStringContainsString('does not hold together', $signal->parse_error);
        // Kept, so the reading can be checked against the picture.
        $this->assertStringContainsString('2650', $signal->transcribed_text);
    }

    /**
     * Reading digits off a picture when somebody typed them is strictly worse.
     */
    public function test_a_caption_that_parses_is_used_and_the_image_is_never_read(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([], 500)]);

        $this->send(text: "XAUUSD BUY\nEntry 2650\nSL 2645\nTP 2680", image: 'fake-jpeg-bytes');

        $signal = TelegramSignal::first();

        $this->assertSame(TelegramSignal::PARSE_OK, $signal->parse_status);
        $this->assertFalse($signal->from_image);
        Http::assertNothingSent();
    }

    public function test_a_message_with_neither_text_nor_image_is_not_recorded(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/telegram/messages', [
            'messages' => [[
                'chat_id' => '5001', 'message_id' => 1, 'text' => '',
            ]],
        ])->assertOk();

        // Nothing to parse and nothing to look at. Storing it would only add noise to the
        // parse rate.
        $this->assertSame(1, TelegramSignal::count());
        $this->assertSame(TelegramSignal::PARSE_FAILED, TelegramSignal::first()->parse_status);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function vision(bool $readable, ?string $transcription, ?string $reason = null): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'model' => 'test-vision',
            'choices' => [['message' => ['content' => json_encode([
                'readable' => $readable,
                'transcription' => $transcription,
                'reason' => $reason,
            ])]]],
        ], 200)]);
    }

    private function send(string $text, ?string $image = null): void
    {
        $this->withToken($this->token)->postJson('/api/v1/telegram/messages', [
            'messages' => [[
                'chat_id' => '5001',
                'message_id' => 100,
                'text' => $text,
                'chat_title' => 'FTC 2026',
                'date' => now()->timestamp,
                'image' => $image === null ? null : base64_encode($image),
                'image_mime' => 'image/jpeg',
            ]],
        ])->assertOk();
    }
}

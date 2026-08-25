<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramSignal;
use App\Models\User;
use App\Services\Telegram\SignalIngest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Capturing signals off the Telegram bot.
 *
 * The allow-list is the point. A bot is publicly reachable, so a copier that traded
 * whatever arrived would be a remote trade-execution endpoint on a live account,
 * authenticated by nothing at all.
 */
class SignalIngestTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.telegram.org/*';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'alerts.telegram.token' => '123:TESTTOKEN',
            'alerts.telegram.chat_id' => '316745398',
            'telegram.sources' => [],
        ]);

        $this->user = User::factory()->create();
    }

    private function updates(array $messages): void
    {
        $result = [];

        foreach ($messages as $i => [$chatId, $text]) {
            $result[] = [
                'update_id' => 1000 + $i,
                'message' => [
                    'message_id' => 50 + $i,
                    'date' => now()->timestamp,
                    'chat' => ['id' => $chatId, 'title' => 'Signals'],
                    'text' => $text,
                ],
            ];
        }

        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'result' => $result])]);
    }

    private const GOOD = "XAUUSD BUY @ 2650.50\nSL: 2645.00\nTP1: 2655.00";

    // =====================================================================
    // THE SECURITY BOUNDARY
    // =====================================================================

    public function test_it_captures_from_the_operators_own_chat(): void
    {
        $this->updates([['316745398', self::GOOD]]);

        $result = (new SignalIngest)->poll();

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['parsed']);

        $signal = TelegramSignal::first();
        $this->assertSame(TelegramSignal::PARSE_OK, $signal->parse_status);
        $this->assertSame('XAUUSD', $signal->symbol);
        $this->assertSame(TelegramSignal::REVIEW_PENDING, $signal->review_status);
    }

    /**
     * The test this whole class exists for.
     */
    public function test_a_stranger_messaging_the_bot_can_never_trade(): void
    {
        // A perfectly well-formed signal from a chat nobody allow-listed.
        $this->updates([['999999999', self::GOOD]]);

        $result = (new SignalIngest)->poll();

        $signal = TelegramSignal::first();

        $this->assertSame(1, $result['ignored']);
        $this->assertSame(TelegramSignal::PARSE_FAILED, $signal->parse_status);
        // Wording follows the control: the switch now lives on the channel row, not in config.
        $this->assertStringContainsString('not enabled', $signal->parse_error);
        $this->assertSame(TelegramSignal::REVIEW_SKIPPED, $signal->review_status);
        $this->assertNull($signal->symbol, 'An un-allow-listed message must never be parsed into a tradeable signal.');
        $this->assertFalse($signal->isActionable());
    }

    public function test_an_unknown_chat_is_still_recorded(): void
    {
        // Dropping it silently would hide the fact that somebody is talking to the bot.
        $this->updates([['999999999', 'hello?']]);

        (new SignalIngest)->poll();

        $this->assertSame(1, TelegramSignal::count());
    }

    public function test_a_configured_source_trades_for_its_configured_account(): void
    {
        $other = User::factory()->create(['email' => 'desk@example.com']);
        config(['telegram.sources' => ['-1001234567890' => 'desk@example.com']]);

        $this->updates([['-1001234567890', self::GOOD]]);
        (new SignalIngest)->poll();

        $this->assertSame($other->id, TelegramSignal::first()->user_id);
    }

    // =====================================================================
    // CAPTURE BEHAVIOUR
    // =====================================================================

    public function test_unparseable_messages_are_kept_with_the_reason(): void
    {
        // A provider changing format is otherwise silent: messages keep arriving, nothing
        // trades, and it looks like a quiet week.
        $this->updates([['316745398', 'Good morning traders! Big moves coming 🚀']]);

        (new SignalIngest)->poll();

        $signal = TelegramSignal::first();
        $this->assertSame(TelegramSignal::PARSE_FAILED, $signal->parse_status);
        $this->assertNotNull($signal->parse_error);
        $this->assertSame('Good morning traders! Big moves coming 🚀', $signal->raw_text);
    }

    public function test_re_polling_the_same_update_does_not_duplicate_it(): void
    {
        // The column standing between a retry and two positions.
        $this->updates([['316745398', self::GOOD]]);

        (new SignalIngest)->poll();
        Cache::forget('telegram.ingest.offset');
        (new SignalIngest)->poll();

        $this->assertSame(1, TelegramSignal::count());
    }

    public function test_the_offset_advances_so_messages_are_not_refetched(): void
    {
        $this->updates([['316745398', self::GOOD]]);

        (new SignalIngest)->poll();

        // getUpdates confirms as it reads, so the offset has to survive a restart.
        $this->assertSame(1001, Cache::get('telegram.ingest.offset'));
    }

    public function test_the_offset_does_not_advance_when_the_fetch_fails(): void
    {
        Http::fake([self::ENDPOINT => Http::response([], 500)]);

        $result = (new SignalIngest)->poll();

        $this->assertFalse($result['ok']);
        $this->assertNull(Cache::get('telegram.ingest.offset'));
    }

    public function test_non_text_updates_are_skipped_without_error(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'result' => [
            ['update_id' => 2000, 'message' => ['chat' => ['id' => '316745398'], 'photo' => []]],
        ]])]);

        $result = (new SignalIngest)->poll();

        $this->assertTrue($result['ok']);
        $this->assertSame(0, TelegramSignal::count());
        // Still confirmed, or it would be refetched for ever.
        $this->assertSame(2001, Cache::get('telegram.ingest.offset'));
    }

    public function test_it_is_off_without_a_token(): void
    {
        config(['alerts.telegram.token' => null]);
        Http::fake();

        $this->assertFalse((new SignalIngest)->configured());
        $this->assertFalse((new SignalIngest)->poll()['ok']);
        Http::assertNothingSent();
    }
}

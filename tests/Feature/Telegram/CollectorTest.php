<?php

namespace Tests\Feature\Telegram;

use App\Models\BotToken;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading a channel with an account rather than a bot.
 *
 * The properties worth holding onto: registering a channel grants it nothing, a collector
 * retry cannot become a second trade, and the pipeline downstream cannot tell which
 * collector brought a message.
 */
class CollectorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        [$this->token] = BotToken::generate($this->user, 'Collector');
    }

    // =====================================================================
    // REGISTRATION IS NOT PERMISSION
    // =====================================================================

    /**
     * The property that keeps "my account is in this channel" from meaning "trade it".
     */
    public function test_an_announced_channel_arrives_disabled(): void
    {
        $this->announce([['chat_id' => '2001', 'title' => 'Fira Smart Desk']])
            ->assertOk()
            ->assertJson(['registered' => 1]);

        $this->assertFalse(TelegramChannel::where('chat_id', '2001')->first()->is_enabled);
    }

    public function test_re_announcing_cannot_disable_a_live_channel(): void
    {
        $this->announce([['chat_id' => '2001', 'title' => 'Fira']]);

        TelegramChannel::where('chat_id', '2001')->update(['is_enabled' => true]);

        // A collector restart re-announces everything it can see. That must refresh the
        // name and nothing else - turning a live source off silently would be as bad as
        // turning one on.
        $this->announce([['chat_id' => '2001', 'title' => 'Fira Smart Desk']]);

        $channel = TelegramChannel::where('chat_id', '2001')->first();

        $this->assertTrue($channel->is_enabled);
        $this->assertSame('Fira Smart Desk', $channel->title);
    }

    public function test_a_message_from_a_disabled_channel_is_recorded_but_never_parsed(): void
    {
        $this->send('3001', 1, "XAUUSD SELL\nEntry 2650\nSL 2660\nTP 2630");

        $signal = TelegramSignal::first();

        // Stored, because something arriving and being ignored is worth being able to see.
        $this->assertNotNull($signal);
        $this->assertSame(TelegramSignal::PARSE_FAILED, $signal->parse_status);
        $this->assertSame(TelegramSignal::REVIEW_SKIPPED, $signal->review_status);
        $this->assertNull($signal->symbol);
    }

    public function test_an_enabled_channel_is_parsed(): void
    {
        $this->enable('3001');

        $this->send('3001', 1, "XAUUSD SELL\nEntry 2650\nSL 2660\nTP 2630");

        $signal = TelegramSignal::first();

        $this->assertSame(TelegramSignal::PARSE_OK, $signal->parse_status);
        $this->assertSame('XAUUSD', $signal->symbol);
        $this->assertSame($this->user->id, $signal->user_id);
    }

    // =====================================================================
    // A RETRY IS NOT A SECOND TRADE
    // =====================================================================

    public function test_the_same_message_posted_twice_is_one_signal(): void
    {
        $this->enable('3001');

        $this->send('3001', 77, "XAUUSD SELL\nEntry 2650\nSL 2660\nTP 2630");
        $this->send('3001', 77, "XAUUSD SELL\nEntry 2650\nSL 2660\nTP 2630");

        $this->assertSame(1, TelegramSignal::count());
    }

    /**
     * Message ids restart per chat, so the chat has to be part of the identity.
     */
    public function test_the_same_message_id_in_two_chats_is_two_signals(): void
    {
        $this->enable('3001');
        $this->enable('3002');

        $this->send('3001', 5, "XAUUSD SELL\nEntry 2650\nSL 2660\nTP 2630");
        $this->send('3002', 5, "XAUUSD BUY\nEntry 2650\nSL 2640\nTP 2670");

        $this->assertSame(2, TelegramSignal::count());
    }

    public function test_a_signal_is_attributed_to_its_channel(): void
    {
        $channel = $this->enable('3001');

        $this->send('3001', 1, "XAUUSD SELL\nEntry 2650\nSL 2660\nTP 2630");

        // The join that survives a provider renaming themselves.
        $this->assertSame($channel->id, TelegramSignal::first()->telegram_channel_id);
    }

    // =====================================================================
    // THE WATCH LIST
    // =====================================================================

    public function test_the_collector_is_told_only_what_is_enabled(): void
    {
        $this->announce([['chat_id' => '4001', 'title' => 'Off'], ['chat_id' => '4002', 'title' => 'On']]);
        TelegramChannel::where('chat_id', '4002')->update(['is_enabled' => true]);

        // Filtering at the collector is what keeps unrelated private conversations from
        // reaching a web server at all.
        $this->withToken($this->token)->getJson('/api/v1/telegram/channels')
            ->assertOk()
            ->assertJsonPath('watch', ['4002']);
    }

    public function test_the_endpoints_require_a_token(): void
    {
        $this->postJson('/api/v1/telegram/messages', ['messages' => []])->assertStatus(401);
        $this->getJson('/api/v1/telegram/channels')->assertStatus(401);
    }

    private function enable(string $chatId): TelegramChannel
    {
        return TelegramChannel::create([
            'user_id' => $this->user->id,
            'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => $chatId,
            'title' => "Channel {$chatId}",
            'is_enabled' => true,
        ]);
    }

    private function announce(array $channels)
    {
        return $this->withToken($this->token)
            ->postJson('/api/v1/telegram/channels', ['channels' => $channels]);
    }

    private function send(string $chatId, int $messageId, string $text)
    {
        return $this->withToken($this->token)->postJson('/api/v1/telegram/messages', [
            'messages' => [[
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'chat_title' => "Channel {$chatId}",
                'date' => now()->timestamp,
            ]],
        ])->assertOk();
    }
}

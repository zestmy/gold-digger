<?php

namespace Tests\Feature\Telegram;

use App\Models\BotToken;
use App\Models\TelegramChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading signals from bots, not only from channels.
 *
 * A lot of providers deliver by bot in a private chat. Refusing to read those meant
 * refusing a large part of what people actually subscribe to - but the reason private
 * chats were excluded in the first place still holds for people, and holds harder now
 * that the dashboard is operated on a tenant's behalf rather than by them.
 */
class ChatKindTest extends TestCase
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

    public function test_a_bot_can_be_registered_and_is_labelled_as_one(): void
    {
        $this->announce([
            ['chat_id' => '55', 'title' => 'FX Signal Delivery', 'username' => 'fxdeliverybot', 'kind' => 'bot'],
        ])->assertOk();

        $channel = TelegramChannel::firstOrFail();

        $this->assertSame(TelegramChannel::KIND_BOT, $channel->kind);
        // Registration is still not permission.
        $this->assertFalse($channel->is_enabled);
    }

    public function test_kinds_are_told_apart(): void
    {
        $this->announce([
            ['chat_id' => '1', 'title' => 'Broadcast', 'kind' => 'channel'],
            ['chat_id' => '2', 'title' => 'Chatroom', 'kind' => 'group'],
            ['chat_id' => '3', 'title' => 'Delivery', 'kind' => 'bot'],
        ])->assertOk();

        $this->assertSame([
            '1' => TelegramChannel::KIND_CHANNEL,
            '2' => TelegramChannel::KIND_GROUP,
            '3' => TelegramChannel::KIND_BOT,
        ], TelegramChannel::pluck('kind', 'chat_id')->all());
    }

    public function test_a_collector_that_does_not_send_a_kind_still_announces(): void
    {
        // An older collector against a newer dashboard. A deploy must not stop one
        // that is already running from reporting what it can see.
        $this->announce([['chat_id' => '9', 'title' => 'Legacy']])->assertOk();

        $this->assertSame(TelegramChannel::KIND_CHANNEL, TelegramChannel::firstOrFail()->kind);
    }

    public function test_a_private_person_is_not_an_acceptable_kind(): void
    {
        // The collector never sends these. Refused here as well, so the one place a
        // tenant's correspondents could be enumerated stays closed at both ends.
        $this->announce([['chat_id' => '7', 'title' => 'Someone', 'kind' => 'user']])
            ->assertStatus(422);

        $this->assertSame(0, TelegramChannel::count());
    }

    private function announce(array $channels)
    {
        return $this->postJson('/api/v1/telegram/channels', ['channels' => $channels], [
            'Authorization' => "Bearer {$this->token}",
        ]);
    }
}

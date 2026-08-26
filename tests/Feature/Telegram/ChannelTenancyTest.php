<?php

namespace Tests\Feature\Telegram;

use App\Models\BotToken;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two customers subscribed to the same signal channel.
 *
 * Every key here used to be global, which was correct for one trader and quietly wrong
 * for two: the first tenant to announce a popular channel owned the only row there could
 * be, the second could never enable it, and the second to receive any given message was
 * told it was an edit of somebody else's signal.
 */
class ChannelTenancyTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private string $aliceToken;

    private string $bobToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();

        [$this->aliceToken] = BotToken::generate($this->alice, 'Alice collector');
        [$this->bobToken] = BotToken::generate($this->bob, 'Bob collector');
    }

    public function test_both_tenants_get_their_own_row_for_a_shared_channel(): void
    {
        $this->announce($this->aliceToken, '4001', 'Gold Signals VIP');
        $this->announce($this->bobToken, '4001', 'Gold Signals VIP');

        $this->assertSame(2, TelegramChannel::where('chat_id', '4001')->count());
        $this->assertEqualsCanonicalizing(
            [$this->alice->id, $this->bob->id],
            TelegramChannel::where('chat_id', '4001')->pluck('user_id')->all(),
        );
    }

    public function test_the_second_tenant_can_enable_what_the_first_already_owns(): void
    {
        $this->announce($this->aliceToken, '4001', 'Gold Signals VIP');
        $this->announce($this->bobToken, '4001', 'Gold Signals VIP');

        $bobs = TelegramChannel::where('user_id', $this->bob->id)->firstOrFail();
        $bobs->update(['is_enabled' => true]);

        // Alice's switch is untouched by Bob's.
        $this->assertFalse(TelegramChannel::where('user_id', $this->alice->id)->firstOrFail()->is_enabled);
        $this->assertSame(
            ['4001'],
            $this->watch($this->bobToken),
        );
        $this->assertSame([], $this->watch($this->aliceToken));
    }

    public function test_a_collector_is_not_shown_another_tenants_channels(): void
    {
        $this->announce($this->aliceToken, '4001', 'Alice Only');

        $known = $this->getJson('/api/v1/telegram/channels', [
            'Authorization' => "Bearer {$this->bobToken}",
        ])->assertOk()->json('known');

        $this->assertSame([], $known);
    }

    public function test_the_same_message_reaching_both_tenants_is_two_signals(): void
    {
        foreach ([$this->alice, $this->bob] as $user) {
            $channel = TelegramChannel::register(
                TelegramChannel::SOURCE_ACCOUNT, '4001', 'Shared', null, $user->id,
            );
            $channel->update(['is_enabled' => true]);
        }

        foreach ([$this->aliceToken, $this->bobToken] as $token) {
            $this->postJson('/api/v1/telegram/messages', ['messages' => [[
                'chat_id' => '4001',
                'message_id' => 88,
                'text' => 'XAUUSD BUY @ 2400 SL 2390 TP 2420',
            ]]], ['Authorization' => "Bearer {$token}"])->assertOk();
        }

        // Globally unique external_id made the second one an edit of the first, and the
        // second tenant simply never received the signal.
        $this->assertSame(2, TelegramSignal::where('external_id', 'tg:4001:88')->count());
        $this->assertEqualsCanonicalizing(
            [$this->alice->id, $this->bob->id],
            TelegramSignal::where('external_id', 'tg:4001:88')->pluck('user_id')->all(),
        );
    }

    private function announce(string $token, string $chatId, string $title): void
    {
        $this->postJson('/api/v1/telegram/channels', [
            'channels' => [['chat_id' => $chatId, 'title' => $title, 'kind' => 'channel']],
        ], ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    private function watch(string $token): array
    {
        return $this->getJson('/api/v1/telegram/channels', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->json('watch');
    }
}

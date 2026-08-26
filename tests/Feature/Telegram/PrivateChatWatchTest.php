<?php

namespace Tests\Feature\Telegram;

use App\Livewire\Pages\SignalChannels;
use App\Models\BotToken;
use App\Models\TelegramChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Watching a provider who delivers by direct message.
 *
 * These never appear in `announce()` and are not going to: inventorying a tenant's private
 * correspondents into a database somebody else operates is not a thing to do because it
 * would be convenient. So one is named by its owner, and a signed-in client - the only
 * thing that can - turns the name into an id.
 */
class PrivateChatWatchTest extends TestCase
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

    public function test_naming_one_creates_a_request_not_a_subscription(): void
    {
        $this->nameChat('@goldprovider');

        $row = TelegramChannel::firstOrFail();

        $this->assertSame(TelegramChannel::KIND_USER, $row->kind);
        $this->assertSame(TelegramChannel::RESOLVE_PENDING, $row->resolve_state);
        $this->assertFalse($row->is_enabled);
        // No incoming message can match a placeholder, so a pending row cannot trade
        // even if something enabled it.
        $this->assertSame('pending:goldprovider', $row->chat_id);
    }

    public function test_a_pending_request_is_given_to_the_collector_to_answer(): void
    {
        $this->nameChat('goldprovider');

        $this->assertSame(['goldprovider'], $this->listing()['resolve']);
        // Not offered as a known chat: it is not one yet.
        $this->assertSame([], $this->listing()['known']);
    }

    public function test_resolving_fills_in_the_id_and_still_does_not_enable_it(): void
    {
        $this->nameChat('goldprovider');

        $this->resolve(['username' => 'goldprovider', 'chat_id' => '778899', 'title' => 'Gold Provider'])
            ->assertOk()->assertJson(['resolved' => true]);

        $row = TelegramChannel::firstOrFail();

        $this->assertSame('778899', $row->chat_id);
        $this->assertNull($row->resolve_state);
        $this->assertFalse($row->is_enabled, 'Resolving a name must not be the same gesture as trading it.');
        $this->assertSame(['778899'], $this->listing()['known'] ? array_column($this->listing()['known'], 'chat_id') : []);
    }

    public function test_a_username_telegram_does_not_know_is_reported_not_retried_forever(): void
    {
        $this->nameChat('nosuchperson');

        $this->resolve(['username' => 'nosuchperson', 'error' => 'No user has "nosuchperson" as username'])
            ->assertOk()->assertJson(['resolved' => false]);

        $row = TelegramChannel::firstOrFail();

        $this->assertSame(TelegramChannel::RESOLVE_FAILED, $row->resolve_state);
        $this->assertStringContainsString('nosuchperson', (string) $row->resolve_error);
        // Off the work list, so the collector stops asking Telegram about a typo.
        $this->assertSame([], $this->listing()['resolve']);
    }

    public function test_another_tenants_request_cannot_be_resolved(): void
    {
        $this->nameChat('goldprovider');

        $other = User::factory()->create();
        [$otherToken] = BotToken::generate($other, 'Theirs');

        $this->postJson('/api/v1/telegram/channels/resolve', [
            'username' => 'goldprovider', 'chat_id' => '778899',
        ], ['Authorization' => "Bearer {$otherToken}"])->assertStatus(404);

        $this->assertSame(TelegramChannel::RESOLVE_PENDING, TelegramChannel::firstOrFail()->resolve_state);
    }

    public function test_naming_the_same_provider_twice_does_not_duplicate_it(): void
    {
        $this->nameChat('goldprovider');
        $this->nameChat('goldprovider');

        $this->assertSame(1, TelegramChannel::count());
    }

    public function test_a_username_that_is_not_a_username_is_refused(): void
    {
        Livewire::actingAs($this->user)->test(SignalChannels::class)
            ->set('privateUsername', 'no spaces allowed!')
            ->call('watchPrivate')
            ->assertHasErrors('privateUsername');

        $this->assertSame(0, TelegramChannel::count());
    }

    private function nameChat(string $username): void
    {
        Livewire::actingAs($this->user)->test(SignalChannels::class)
            ->set('privateUsername', $username)
            ->call('watchPrivate')
            ->assertHasNoErrors();
    }

    private function resolve(array $payload)
    {
        return $this->postJson('/api/v1/telegram/channels/resolve', $payload, [
            'Authorization' => "Bearer {$this->token}",
        ]);
    }

    private function listing(): array
    {
        return $this->getJson('/api/v1/telegram/channels', [
            'Authorization' => "Bearer {$this->token}",
        ])->assertOk()->json();
    }
}

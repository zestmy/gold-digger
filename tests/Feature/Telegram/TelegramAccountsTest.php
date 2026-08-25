<?php

namespace Tests\Feature\Telegram;

use App\Livewire\Pages\TelegramAccounts;
use App\Models\BotToken;
use App\Models\TelegramAccount;
use App\Models\TelegramChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Running more than one Telegram account.
 *
 * The boundary worth pinning: this page issues tokens and never touches a sign-in. MTProto
 * authentication needs a code sent to a phone, and a session that can read every chat on
 * the account - neither belongs on a web server.
 */
class TelegramAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_adding_an_account_issues_it_its_own_token(): void
    {
        $component = Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('label', 'Personal (home VPS)')
            ->call('add');

        $account = TelegramAccount::firstOrFail();

        $this->assertSame('Personal (home VPS)', $account->label);
        $this->assertNotNull($account->bot_token_id);
        $component->assertSet('issuedToken', fn ($t) => is_string($t) && $t !== '');
    }

    /**
     * One shared token would make "stop that collector" impossible to do precisely.
     */
    public function test_each_account_gets_a_different_token(): void
    {
        $page = Livewire::actingAs($this->user)->test(TelegramAccounts::class);

        $page->set('label', 'One')->call('add');
        $first = $page->get('issuedToken');

        $page->set('label', 'Two')->call('add');
        $second = $page->get('issuedToken');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, BotToken::count());
    }

    public function test_reissuing_revokes_the_previous_token(): void
    {
        $page = Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('label', 'Personal')->call('add');

        $old = $page->get('issuedToken');
        $account = TelegramAccount::firstOrFail();

        $page->call('reissue', $account->id);

        // Two working tokens for one collector is a credential nobody is tracking.
        $this->assertNull(BotToken::resolve($old));
        $this->assertNotNull(BotToken::resolve($page->get('issuedToken')));
    }

    public function test_removing_an_account_keeps_its_channels_and_their_history(): void
    {
        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('label', 'Personal')->call('add');

        $account = TelegramAccount::firstOrFail();

        $channel = TelegramChannel::create([
            'user_id' => $this->user->id, 'telegram_account_id' => $account->id,
            'source' => TelegramChannel::SOURCE_ACCOUNT, 'chat_id' => '5001',
            'title' => 'FTC 2026', 'is_enabled' => true,
        ]);

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)->call('remove', $account->id);

        // What was traded is history worth keeping; tidying a machine list must not delete it.
        $this->assertNotNull($channel->fresh());
        $this->assertNull($channel->fresh()->telegram_account_id);
        $this->assertSame(0, TelegramAccount::count());
    }

    /**
     * The token is the identity, so a collector cannot claim to be an account it was not
     * issued a token for.
     */
    public function test_a_collector_identifies_itself_by_its_token(): void
    {
        $page = Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('label', 'Personal')->call('add');

        $this->withToken($page->get('issuedToken'))
            ->postJson('/api/v1/telegram/channels', [
                'channels' => [['chat_id' => '5001', 'title' => 'FTC 2026']],
                'me' => ['username' => 'affandy', 'name' => 'Affandy'],
            ])->assertOk();

        $account = TelegramAccount::firstOrFail();

        $this->assertSame('affandy', $account->telegram_username);
        $this->assertSame('Affandy', $account->display_name);
        $this->assertTrue($account->isConnected());

        // And the channel now belongs to the account that can see it.
        $this->assertSame($account->id, TelegramChannel::firstOrFail()->telegram_account_id);
    }

    public function test_another_users_account_cannot_be_touched(): void
    {
        $other = User::factory()->create();

        $theirs = TelegramAccount::create(['user_id' => $other->id, 'label' => 'Theirs']);

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)->call('remove', $theirs->id);

        $this->assertNotNull($theirs->fresh());
    }

    /**
     * Never signed in and stopped an hour ago are both not-connected, and they mean
     * completely different things.
     */
    public function test_a_never_seen_account_is_distinguished_from_a_stopped_one(): void
    {
        $never = TelegramAccount::create(['user_id' => $this->user->id, 'label' => 'Fresh']);
        $stopped = TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Was running',
            'last_seen_at' => now()->subHour(),
        ]);
        $live = TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Running',
            'last_seen_at' => now(),
        ]);

        $this->assertFalse($never->isConnected());
        $this->assertFalse($stopped->isConnected());
        $this->assertTrue($live->isConnected());

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->assertSee('NOT SIGNED IN')
            ->assertSee('STOPPED')
            ->assertSee('CONNECTED');
    }
}

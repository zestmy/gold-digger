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
     * Four states, and they need different actions.
     *
     * Not signed in wants a phone number; signed in but not running wants the collector
     * started; connected wants nothing. Collapsing them into "offline" would send somebody
     * to re-authenticate an account whose sign-in is perfectly good.
     */
    public function test_signed_in_is_distinguished_from_running(): void
    {
        TelegramAccount::create(['user_id' => $this->user->id, 'label' => 'Fresh']);

        TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Signed in, stopped',
            'login_state' => TelegramAccount::ACTIVE, 'last_seen_at' => now()->subHour(),
        ]);

        $live = TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Running',
            'login_state' => TelegramAccount::ACTIVE, 'last_seen_at' => now(),
        ]);

        $this->assertTrue($live->isConnected());

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->assertSee('NOT SIGNED IN')
            ->assertSee('SIGNED IN, NOT RUNNING')
            ->assertSee('CONNECTED');
    }

    // =====================================================================
    // SIGNING IN FROM THE DASHBOARD
    // =====================================================================

    public function test_a_phone_number_starts_a_sign_in(): void
    {
        $account = TelegramAccount::create(['user_id' => $this->user->id, 'label' => 'Personal']);

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('phone', '+60123456789')
            ->call('beginLogin', $account->id);

        $account->refresh();

        $this->assertSame(TelegramAccount::REQUESTED, $account->login_state);
        $this->assertSame('+60123456789', $account->login_phone);
    }

    /**
     * The property that keeps this better than storing a session here.
     */
    public function test_a_code_is_relayed_once_and_never_stored(): void
    {
        $account = TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Personal',
            'bot_token_id' => null, 'login_state' => TelegramAccount::CODE_SENT,
            'login_phone' => '+60123456789',
        ]);

        [$plaintext, $token] = BotToken::generate($this->user, 'Collector');
        $account->update(['bot_token_id' => $token->id]);

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('code', '54321')
            ->call('submitCode', $account->id);

        // Not in any column.
        $this->assertStringNotContainsString('54321', json_encode($account->fresh()->toArray()));

        // Delivered to the collector exactly once...
        $first = $this->withToken($plaintext)->getJson('/api/v1/telegram/login');
        $first->assertOk()->assertJsonPath('code', '54321');

        // ...and gone afterwards, so a stolen token cannot replay it.
        $this->withToken($plaintext)->getJson('/api/v1/telegram/login')->assertJsonPath('code', null);
    }

    public function test_the_collector_reports_the_sign_in_as_finished(): void
    {
        $account = TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Personal',
            'login_state' => TelegramAccount::CODE_SUBMITTED, 'login_phone' => '+60123456789',
        ]);

        [$plaintext, $token] = BotToken::generate($this->user, 'Collector');
        $account->update(['bot_token_id' => $token->id]);

        $this->withToken($plaintext)->postJson('/api/v1/telegram/login', [
            'state' => 'active', 'username' => 'affandy', 'name' => 'Affandy',
        ])->assertOk();

        $account->refresh();

        $this->assertSame(TelegramAccount::ACTIVE, $account->login_state);
        $this->assertSame('affandy', $account->telegram_username);
        // The number is not kept once it has served its purpose.
        $this->assertNull($account->login_phone);
    }

    /**
     * "Failed" alone sends people to the wrong place.
     */
    public function test_a_failure_keeps_telegrams_own_words(): void
    {
        $account = TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Personal',
            'login_state' => TelegramAccount::CODE_SUBMITTED,
        ]);

        [$plaintext, $token] = BotToken::generate($this->user, 'Collector');
        $account->update(['bot_token_id' => $token->id]);

        $this->withToken($plaintext)->postJson('/api/v1/telegram/login', [
            'state' => 'failed', 'message' => 'The confirmation code has expired',
        ])->assertOk();

        $this->assertStringContainsString('expired', $account->fresh()->login_message);
    }

    public function test_a_stalled_sign_in_is_recognised(): void
    {
        $stalled = TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Personal',
            'login_state' => TelegramAccount::CODE_SENT,
            'login_updated_at' => now()->subHour(),
        ]);

        // Otherwise the page waits for ever on a conversation nothing will continue.
        $this->assertTrue($stalled->loginStalled());

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)->assertSee('stalled');
    }
}

<?php

namespace Tests\Feature\Telegram;

use App\Http\Controllers\Api\Telegram\LoginController;
use App\Livewire\Pages\TelegramAccounts;
use App\Models\TelegramAccount;
use App\Models\TelegramSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adding a Telegram account without touching a machine.
 *
 * The self-hosted collector kept the session where the reading happened, which is the
 * safer place for it. It also asked a new customer for Python, a file on disk and an
 * application registered at my.telegram.org before the product had done anything for
 * them, and a signup funnel does not survive that.
 *
 * So the platform signs them in and keeps the session. These tests pin the two things
 * that have to stay true once it does: the session never reaches a browser, and the
 * credential that reaches every session is not one a tenant can be issued.
 */
class HostedSignInTest extends TestCase
{
    use RefreshDatabase;

    private const WORKER_TOKEN = 'worker-secret-for-tests';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // A fully configured deployment is the baseline; the tests that care about a
        // half-configured one unset a piece themselves.
        config([
            'telegram.hosted_by_default' => true,
            'telegram.worker_token' => self::WORKER_TOKEN,
            'telegram.app_id' => '38334417',
            'telegram.app_hash' => 'not-a-real-hash',
        ]);
    }

    // =====================================================================
    // ONBOARDING
    // =====================================================================

    public function test_a_new_account_is_hosted_and_needs_no_token(): void
    {
        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('label', 'Personal')
            ->call('add')
            // Showing a secret that nothing consumes is how people learn to ignore secrets.
            ->assertSet('issuedToken', null);

        $account = TelegramAccount::firstOrFail();

        $this->assertTrue($account->is_hosted);
        $this->assertNull($account->bot_token_id);
    }

    public function test_self_hosting_remains_available(): void
    {
        config(['telegram.hosted_by_default' => false]);

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('label', 'Own VPS')
            ->call('add');

        $account = TelegramAccount::firstOrFail();

        $this->assertFalse($account->is_hosted);
        $this->assertNotNull($account->bot_token_id);
    }

    // =====================================================================
    // THE SESSION
    // =====================================================================

    public function test_the_session_is_encrypted_at_rest(): void
    {
        $account = $this->account();

        $this->worker('put', "accounts/{$account->id}/session", ['session' => '1BQANOTEuMTA4LjU2LjE'])
            ->assertOk();

        // What the application reads back.
        $this->assertSame('1BQANOTEuMTA4LjU2LjE', $account->fresh()->session);

        // What a database dump would yield, which is the point of the cast.
        $stored = DB::table('telegram_accounts')->where('id', $account->id)->value('session');
        $this->assertNotSame('1BQANOTEuMTA4LjU2LjE', $stored);
        $this->assertStringNotContainsString('1BQANOTEuMTA4LjU2LjE', (string) $stored);
    }

    public function test_the_session_is_not_serialised_towards_a_browser(): void
    {
        $account = $this->account();
        $account->update(['session' => 'a-real-session-string']);

        // Livewire payloads, JSON responses and careless logging all go through here.
        $this->assertArrayNotHasKey('session', $account->fresh()->toArray());
    }

    // =====================================================================
    // THE WORKER CREDENTIAL
    // =====================================================================

    public function test_the_worker_endpoints_refuse_an_unknown_token(): void
    {
        $this->getJson(route('api.telegram.worker.accounts'), [
            'Authorization' => 'Bearer not-the-worker',
        ])->assertStatus(401);
    }

    public function test_an_unconfigured_worker_refuses_rather_than_falls_open(): void
    {
        // The state a fresh install is in, and the one least able to notice it is open.
        config(['telegram.worker_token' => null]);

        $this->getJson(route('api.telegram.worker.accounts'), [
            'Authorization' => 'Bearer anything',
        ])->assertStatus(503);
    }

    public function test_a_tenants_own_token_does_not_reach_the_worker_surface(): void
    {
        [$plaintext] = \App\Models\BotToken::generate($this->user, 'Collector');

        $this->getJson(route('api.telegram.worker.accounts'), [
            'Authorization' => "Bearer {$plaintext}",
        ])->assertStatus(401);
    }

    // =====================================================================
    // WHAT THE WORKER SEES
    // =====================================================================

    public function test_the_worker_is_given_hosted_accounts_and_their_sessions(): void
    {
        $account = $this->account();
        $account->update(['session' => 'session-string', 'login_state' => TelegramAccount::ACTIVE]);

        $body = $this->worker('get', 'accounts')->assertOk()->json();

        $this->assertCount(1, $body['accounts']);
        $this->assertSame($account->id, $body['accounts'][0]['id']);
        $this->assertSame('session-string', $body['accounts'][0]['session']);
    }

    public function test_an_account_a_tenant_runs_themselves_is_left_alone(): void
    {
        // Signing it in here would open a second session on somebody's Telegram account,
        // which Telegram reads as exactly the compromise it looks like.
        $account = $this->account(['is_hosted' => false]);

        $this->assertSame([], $this->worker('get', 'accounts')->json('accounts'));

        $this->worker('get', "accounts/{$account->id}/login")->assertStatus(404);
        $this->worker('put', "accounts/{$account->id}/session", ['session' => 'x'])->assertStatus(404);
    }

    // =====================================================================
    // THE CONVERSATION
    // =====================================================================

    public function test_the_worker_is_told_to_send_a_code_then_to_sign_in(): void
    {
        $account = $this->account();

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('phone', '+60138787676')
            ->call('beginLogin', $account->id);

        $this->assertSame([
            'action' => 'send_code',
            'phone' => '+60138787676',
        ], $this->worker('get', "accounts/{$account->id}/login")->json());

        // Telegram has now sent it and the person has typed it in.
        $this->worker('post', "accounts/{$account->id}/login", ['state' => 'code_sent'])->assertOk();

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('code', '54321')
            ->call('submitCode', $account->id);

        $instruction = $this->worker('get', "accounts/{$account->id}/login")->json();

        $this->assertSame('sign_in', $instruction['action']);
        $this->assertSame('54321', $instruction['code']);
    }

    public function test_a_code_is_handed_over_exactly_once(): void
    {
        $account = $this->account();
        $account->advance(TelegramAccount::CODE_SUBMITTED);
        LoginController::relay($account, 'code', '11111');

        $this->assertSame('11111', $this->worker('get', "accounts/{$account->id}/login")->json('code'));

        // A stolen worker token cannot replay a sign-in with a code already spent.
        $this->assertNull($this->worker('get', "accounts/{$account->id}/login")->json('code'));
    }

    public function test_a_completed_sign_in_names_the_account_and_clears_the_number(): void
    {
        $account = $this->account();
        $account->update(['login_phone' => '+60138787676']);
        $account->advance(TelegramAccount::CODE_SUBMITTED);

        $this->worker('post', "accounts/{$account->id}/login", [
            'state' => 'active',
            'username' => 'someone',
            'name' => 'Some One',
        ])->assertOk();

        $account->refresh();

        $this->assertSame(TelegramAccount::ACTIVE, $account->login_state);
        $this->assertSame('someone', $account->telegram_username);
        $this->assertNull($account->login_phone);
    }

    public function test_checkpoints_survive_a_redeploy(): void
    {
        // Without these the worker re-sends the tail of every watched chat on restart,
        // and a re-sent signal is a signal that can be acted on twice.
        $account = $this->account();

        $this->worker('put', "accounts/{$account->id}/state", [
            'ingest_state' => ['seen' => ['100200300' => 4242]],
        ])->assertOk();

        $this->assertSame(['seen' => ['100200300' => 4242]], $account->fresh()->ingest_state);
    }

    // =====================================================================
    // INGEST
    // =====================================================================

    public function test_the_worker_posts_messages_through_the_same_pipeline(): void
    {
        // Not a parallel ingest path. Idempotency on chat plus message id, the channel
        // switch and the parser are things there must only ever be one of, so the hosted
        // route is the collector's controller with the identity supplied differently.
        $account = $this->account();

        $this->worker('post', "accounts/{$account->id}/messages", [
            'messages' => [[
                'chat_id' => '100200300',
                'message_id' => 7,
                'text' => 'XAUUSD BUY @ 2400 SL 2390 TP 2420',
            ]],
        ])->assertOk()->assertJson(['stored' => 1]);

        $signal = TelegramSignal::firstOrFail();

        // Attributed to the tenant who owns the account, not to whoever holds the
        // worker token - which is the whole job of the binding middleware.
        $this->assertSame($this->user->id, $signal->user_id);
        $this->assertSame('100200300', $signal->chat_id);
    }

    public function test_a_replayed_message_does_not_become_a_second_signal(): void
    {
        $account = $this->account();

        $payload = ['messages' => [[
            'chat_id' => '100200300',
            'message_id' => 7,
            'text' => 'XAUUSD BUY @ 2400 SL 2390 TP 2420',
        ]]];

        $this->worker('post', "accounts/{$account->id}/messages", $payload)->assertOk();
        $this->worker('post', "accounts/{$account->id}/messages", $payload)->assertOk();

        $this->assertSame(1, TelegramSignal::count());
    }

    public function test_the_ingest_path_is_closed_to_self_hosted_accounts(): void
    {
        $account = $this->account(['is_hosted' => false]);

        $this->worker('post', "accounts/{$account->id}/messages", [
            'messages' => [['chat_id' => '1', 'message_id' => 1, 'text' => 'x']],
        ])->assertStatus(404);

        $this->assertSame(0, TelegramSignal::count());
    }

    // =====================================================================
    // THE PAGE
    // =====================================================================

    public function test_a_sign_in_nothing_can_answer_is_refused_rather_than_started(): void
    {
        // The bug this exists to prevent: a spinner reading "Asking Telegram for a code"
        // that never resolves, because no worker was configured to answer it. A
        // misconfigured deployment should say so, not look like a broken product.
        config(['telegram.app_id' => null]);

        $account = $this->account();

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('phone', '+60138787676')
            ->call('beginLogin', $account->id);

        $this->assertSame(TelegramAccount::IDLE, $account->fresh()->login_state);
    }

    public function test_a_configured_deployment_starts_the_sign_in(): void
    {
        config(['telegram.app_id' => '38334417', 'telegram.app_hash' => 'hash']);

        $account = $this->account();

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->set('phone', '+60138787676')
            ->call('beginLogin', $account->id);

        $this->assertSame(TelegramAccount::REQUESTED, $account->fresh()->login_state);
    }

    public function test_a_hosted_account_is_not_offered_a_token_it_cannot_use(): void
    {
        $account = $this->account();

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            // No collector authenticates as a hosted account, so a token here would open
            // nothing. Issuing one teaches people that secrets on this page are noise.
            ->call('reissue', $account->id)
            ->assertDontSee('python collector.py run');

        $this->assertNull($account->fresh()->bot_token_id);
    }

    public function test_the_page_warns_when_hosted_sign_in_is_unconfigured(): void
    {
        config(['telegram.worker_token' => null]);

        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->assertSee('Hosted sign-in is not configured');
    }

    public function test_a_configured_page_does_not_cry_wolf(): void
    {
        Livewire::actingAs($this->user)->test(TelegramAccounts::class)
            ->assertDontSee('Hosted sign-in is not configured');
    }

    // =====================================================================

    private function account(array $attributes = []): TelegramAccount
    {
        // $attributes first: PHP's array union keeps the left-hand value for a duplicate
        // key, so defaults have to be on the right or an override is silently ignored.
        return TelegramAccount::create($attributes + [
            'user_id' => $this->user->id,
            'label' => 'Personal',
            'is_hosted' => true,
        ]);
    }

    private function worker(string $method, string $path, array $payload = [])
    {
        return $this->json(strtoupper($method), "/api/v1/telegram/worker/{$path}", $payload, [
            'Authorization' => 'Bearer '.self::WORKER_TOKEN,
        ]);
    }
}

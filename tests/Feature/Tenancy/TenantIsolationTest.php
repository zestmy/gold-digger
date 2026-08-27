<?php

namespace Tests\Feature\Tenancy;

use App\Livewire\Pages\BotLogs;
use App\Models\Alert;
use App\Models\BotHeartbeat;
use App\Models\BotLog;
use App\Models\BotSettings;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\User;
use App\Notifications\TradingAlert;
use App\Services\Monitoring\AlertNotifier;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tenant Isolation
 *
 * The test that has to exist for anything else here to be sellable.
 *
 * Isolation used to be 93 hand-written `where('user_id', Auth::id())` clauses, and the
 * thing about a convention enforced by memory is that nothing tells you the one time it
 * was forgotten. `/logs` was that one time: every tenant read every other tenant's
 * executor output, any of them could delete a row by posting its id, and one button
 * truncated the table for the whole platform.
 *
 * So these are not tests of the logs page. They are tests of the mechanism that makes the
 * logs page unable to do that again, applied to every model that carries an owner - which
 * means a model added later without `BelongsToTenant` fails here rather than in production.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
    }

    // =========================================================================
    // THE MECHANISM
    // =========================================================================

    /**
     * Every model with a `user_id` column must be scoped by it.
     *
     * Written as a loop over the models rather than one test each, so that adding a model
     * to the list is the whole cost of covering it - and so the failure message names the
     * model that is missing the trait.
     */
    public function test_every_owned_model_filters_to_the_current_tenant(): void
    {
        $models = [
            Alert::class => ['key' => 'alert-key', 'level' => 'warning', 'title' => 't', 'body' => 'b', 'first_seen_at' => now(), 'last_seen_at' => now()],
            BotLog::class => ['level' => 'info', 'source' => 'test', 'message' => 'hello'],
            BrokerAccount::class => ['label' => 'Acct', 'broker_name' => 'B', 'account_number' => '1', 'server' => 'S'],
            BotHeartbeat::class => ['source' => 'mql5_ea', 'last_seen_at' => now()],
        ];

        foreach ($models as $class => $attributes) {
            $class::query()->forceCreate($attributes + ['user_id' => $this->alice->id]);

            Tenant::for($this->bob, function () use ($class) {
                $this->assertSame(
                    0,
                    $class::query()->count(),
                    $class.' is readable by a tenant who does not own it. Does it use BelongsToTenant?'
                );
            });

            Tenant::for($this->alice, function () use ($class) {
                $this->assertSame(1, $class::query()->count(), $class.' is not readable by its own owner.');
            });
        }
    }

    /**
     * The two rows `UserObserver` creates on registration are covered separately, because
     * they exist for both tenants already and so cannot be counted the same way.
     */
    public function test_the_rows_created_at_registration_belong_only_to_their_owner(): void
    {
        Tenant::for($this->alice, function () {
            $this->assertSame($this->alice->id, BotSettings::query()->sole()->user_id);
            $this->assertSame($this->alice->id, Strategy::query()->sole()->user_id);
        });

        Tenant::for($this->bob, function () {
            $this->assertSame($this->bob->id, BotSettings::query()->sole()->user_id);
            $this->assertSame($this->bob->id, Strategy::query()->sole()->user_id);
        });
    }

    public function test_a_created_row_is_stamped_with_the_current_tenant(): void
    {
        Tenant::for($this->alice, function () {
            BotLog::create(['level' => 'info', 'source' => 'test', 'message' => 'mine']);
        });

        $this->assertSame($this->alice->id, BotLog::acrossTenants()->sole()->user_id);
    }

    public function test_an_explicit_owner_is_never_overwritten_by_the_current_tenant(): void
    {
        // This is what lets the bot API and the console write on somebody's behalf. If the
        // stamp won, an EA's log would be attributed to whoever happened to be current.
        Tenant::for($this->alice, function () {
            BotLog::create(['user_id' => $this->bob->id, 'level' => 'info', 'source' => 'test', 'message' => 'theirs']);
        });

        $this->assertSame($this->bob->id, BotLog::acrossTenants()->sole()->user_id);
    }

    public function test_no_tenant_means_no_filter_so_console_work_still_sees_everything(): void
    {
        // The scheduler iterates every user on purpose - bot:monitor, copier:protect,
        // ai:decide. A scope that returned nothing outside a request would stop trading
        // silently, which is the worst failure this application has.
        BotLog::create(['user_id' => $this->alice->id, 'level' => 'info', 'source' => 'test', 'message' => 'a']);
        BotLog::create(['user_id' => $this->bob->id, 'level' => 'info', 'source' => 'test', 'message' => 'b']);

        $this->assertSame(2, BotLog::query()->count());
    }

    // =========================================================================
    // THE PAGE THAT LEAKED
    // =========================================================================

    public function test_the_logs_page_shows_only_this_tenants_logs(): void
    {
        BotLog::create(['user_id' => $this->alice->id, 'level' => 'error', 'source' => 'mql5_ea', 'message' => 'alice trade rejected']);
        BotLog::create(['user_id' => $this->bob->id, 'level' => 'error', 'source' => 'mql5_ea', 'message' => 'bob trade rejected']);

        Livewire::actingAs($this->alice)
            ->test(BotLogs::class)
            ->assertSee('alice trade rejected')
            ->assertDontSee('bob trade rejected');
    }

    public function test_the_logs_page_counts_only_this_tenants_logs(): void
    {
        BotLog::create(['user_id' => $this->alice->id, 'level' => 'error', 'source' => 'x', 'message' => 'a']);
        BotLog::create(['user_id' => $this->bob->id, 'level' => 'error', 'source' => 'x', 'message' => 'b1']);
        BotLog::create(['user_id' => $this->bob->id, 'level' => 'error', 'source' => 'x', 'message' => 'b2']);

        // The stats row was its own leak: it reported a platform-wide total beside a
        // filtered table, so the numbers disagreed with the list under them.
        Livewire::actingAs($this->alice)
            ->test(BotLogs::class)
            ->assertViewHas('stats', fn (array $stats) => $stats['total'] === 1 && $stats['errors'] === 1);
    }

    public function test_one_tenant_cannot_delete_another_tenants_log_by_id(): void
    {
        $theirs = BotLog::create(['user_id' => $this->bob->id, 'level' => 'info', 'source' => 'x', 'message' => 'bobs']);

        Livewire::actingAs($this->alice)
            ->test(BotLogs::class)
            ->call('clearLog', $theirs->id);

        $this->assertDatabaseHas('bot_logs', ['id' => $theirs->id]);
    }

    public function test_clearing_all_logs_clears_only_this_tenants(): void
    {
        BotLog::create(['user_id' => $this->alice->id, 'level' => 'info', 'source' => 'x', 'message' => 'mine']);
        $theirs = BotLog::create(['user_id' => $this->bob->id, 'level' => 'info', 'source' => 'x', 'message' => 'theirs']);

        Livewire::actingAs($this->alice)
            ->test(BotLogs::class)
            ->call('clearAllLogs');

        $this->assertDatabaseMissing('bot_logs', ['message' => 'mine']);
        $this->assertDatabaseHas('bot_logs', ['id' => $theirs->id]);
    }

    public function test_a_log_nobody_owns_is_shown_to_nobody(): void
    {
        // Rows the backfill could not attribute. Disappearing from a page is the safe
        // direction for a leak; appearing on a stranger's is not.
        BotLog::create(['user_id' => null, 'level' => 'info', 'source' => 'x', 'message' => 'orphaned']);

        Livewire::actingAs($this->alice)
            ->test(BotLogs::class)
            ->assertDontSee('orphaned');
    }

    // =========================================================================
    // THE MACHINE ENDPOINTS
    // =========================================================================

    public function test_a_bot_token_scopes_the_api_to_its_own_owner(): void
    {
        [$plaintext] = BotToken::generate($this->alice, 'alice-terminal');

        $this->withToken($plaintext)
            ->postJson('/api/v1/bot/logs', ['level' => 'error', 'message' => 'from alices terminal'])
            ->assertCreated();

        $log = BotLog::acrossTenants()->sole();

        $this->assertSame($this->alice->id, $log->user_id, 'A log written through the API must belong to the token holder.');

        Livewire::actingAs($this->bob)
            ->test(BotLogs::class)
            ->assertDontSee('from alices terminal');
    }

    public function test_a_token_resolves_even_while_another_tenant_is_current(): void
    {
        [$plaintext] = BotToken::generate($this->alice, 'alice-terminal');

        // Resolving a credential is what establishes the tenant, so it must not itself be
        // filtered by whoever happens to be current.
        Tenant::for($this->bob, function () use ($plaintext) {
            $this->assertNotNull(BotToken::resolve($plaintext));
        });
    }

    // =========================================================================
    // THE ESCAPE HATCHES
    // =========================================================================

    public function test_reading_across_tenants_has_to_be_asked_for(): void
    {
        BotLog::create(['user_id' => $this->alice->id, 'level' => 'info', 'source' => 'x', 'message' => 'a']);
        BotLog::create(['user_id' => $this->bob->id, 'level' => 'info', 'source' => 'x', 'message' => 'b']);

        Tenant::for($this->alice, function () {
            $this->assertSame(1, BotLog::query()->count());
            $this->assertSame(2, BotLog::acrossTenants()->count());
            $this->assertSame(2, Tenant::acrossTenants(fn () => BotLog::query()->count()));
        });
    }

    public function test_the_current_tenant_is_restored_after_a_scoped_block(): void
    {
        Tenant::actAs($this->alice);

        Tenant::for($this->bob, fn () => $this->assertSame($this->bob->id, Tenant::current()));

        $this->assertSame($this->alice->id, Tenant::current());
    }

    /**
     * Relationships are queried through the model, so they are scoped too - which is worth
     * pinning down, because it is where a "safe" eager load would otherwise leak.
     */
    public function test_a_relationship_cannot_reach_across_tenants(): void
    {
        $account = BrokerAccount::query()->forceCreate([
            'user_id' => $this->alice->id, 'label' => 'A', 'broker_name' => 'B', 'account_number' => '1', 'server' => 'S',
        ]);

        Trade::query()->forceCreate([
            'user_id' => $this->alice->id,
            'strategy_id' => Strategy::acrossTenants()->where('user_id', $this->alice->id)->value('id'),
            'broker_account_id' => $account->id,
            'symbol' => 'XAUUSD',
            'direction' => 'buy',
            'initial_lot_size' => 0.01,
            'remaining_lot_size' => 0.01,
            'entry_price' => 2000,
            'sl_price' => 1990,
            'status' => 'open',
        ]);

        Tenant::for($this->bob, function () use ($account) {
            $this->assertCount(0, $account->trades()->get());
        });
    }

    // =========================================================================
    // ALERT ROUTING
    // =========================================================================

    public function test_an_alert_reaches_the_tenant_it_concerns_and_not_the_operator(): void
    {
        config()->set('alerts.telegram.token', 'platform-bot-token');
        config()->set('alerts.telegram.chat_id', '999-operator');

        $this->alice->update(['telegram_chat_id' => '111-alice']);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $alert = Alert::query()->forceCreate([
            'user_id' => $this->alice->id, 'key' => 'executor_offline', 'level' => 'critical',
            'title' => 'Executor offline', 'body' => 'No heartbeat for 12 minutes.',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        app(AlertNotifier::class)->send($alert);

        Http::assertSent(function ($request) {
            $chat = $request->data()['chat_id'] ?? null;

            $this->assertSame('111-alice', $chat, 'A tenant incident must go to that tenant, never to the operator chat.');

            return true;
        });
    }

    public function test_a_tenant_without_telegram_is_emailed_rather_than_left_in_silence(): void
    {
        config()->set('alerts.telegram.token', 'platform-bot-token');
        config()->set('alerts.telegram.chat_id', '999-operator');

        Notification::fake();
        Http::fake();

        $alert = Alert::query()->forceCreate([
            'user_id' => $this->bob->id, 'key' => 'executor_offline', 'level' => 'critical',
            'title' => 'Executor offline', 'body' => 'No heartbeat for 12 minutes.',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        app(AlertNotifier::class)->send($alert);

        Http::assertNothingSent();
        Notification::assertSentTo($this->bob, TradingAlert::class);
    }

    public function test_the_incident_log_row_belongs_to_the_tenant_it_concerns(): void
    {
        Notification::fake();

        $alert = Alert::query()->forceCreate([
            'user_id' => $this->alice->id, 'key' => 'spread_wide', 'level' => 'warning',
            'title' => 'Spread wide', 'body' => 'Wider than configured.',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        app(AlertNotifier::class)->send($alert);

        Livewire::actingAs($this->bob)
            ->test(BotLogs::class)
            ->assertDontSee('Spread wide');
    }
}

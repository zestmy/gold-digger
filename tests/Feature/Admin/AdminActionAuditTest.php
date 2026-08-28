<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAction;
use App\Models\BotSettings;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What an administrator did to somebody else's account.
 *
 * The Filament panel is the one place cross-tenant access happens by design - a support
 * console that could only see its own operator would be useless - and every resource in it
 * carries an edit action and a bulk delete. An administrator could change a customer's stop
 * price or raise their capital cap, and nothing recorded it.
 *
 * Two properties matter more than the recording itself. It has to stay silent for an
 * operator working on their own account, or a single-operator deployment fills the table
 * with its own housekeeping and nobody reads it. And it must never store the plaintext of
 * the secrets it audits - an audit log that leaked encrypted account numbers would be a
 * worse breach than the one it exists to detect.
 */
class AdminActionAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create();
    }

    // =====================================================================
    // SILENT UNTIL IT MATTERS
    // =====================================================================

    /**
     * An operator editing their own strategy is using the application, not exercising a
     * privilege. On a single-operator deployment this table stays empty.
     */
    public function test_an_admin_working_on_their_own_data_records_nothing(): void
    {
        $this->actingAs($this->admin);

        Strategy::acrossTenants()->where('user_id', $this->admin->id)->firstOrFail()
            ->update(['adx_threshold' => 30]);

        $this->assertSame(0, AdminAction::count());
    }

    public function test_a_non_admin_records_nothing_even_across_accounts(): void
    {
        $ordinary = User::factory()->create();
        $this->actingAs($ordinary);

        // Not something the application permits, but the audit trail must not be the only
        // thing standing between an ordinary account and somebody else's row.
        Tenant::acrossTenants(fn () => Strategy::acrossTenants()
            ->where('user_id', $this->customer->id)->firstOrFail()
            ->update(['adx_threshold' => 30]));

        $this->assertSame(0, AdminAction::count());
    }

    public function test_nothing_is_recorded_when_nobody_is_authenticated(): void
    {
        // Console work, queued jobs, the executor API. All legitimate, none of it a
        // privileged act by a person.
        Strategy::acrossTenants()->where('user_id', $this->customer->id)->firstOrFail()
            ->update(['adx_threshold' => 30]);

        $this->assertSame(0, AdminAction::count());
    }

    // =====================================================================
    // WHAT IT DOES RECORD
    // =====================================================================

    public function test_an_admin_editing_a_customers_record_is_recorded(): void
    {
        $this->actingAs($this->admin);

        $strategy = Strategy::acrossTenants()->where('user_id', $this->customer->id)->firstOrFail();
        $strategy->update(['adx_threshold' => 30]);

        $action = AdminAction::sole();

        $this->assertSame($this->admin->id, $action->admin_user_id);
        $this->assertSame($this->customer->id, $action->subject_user_id);
        $this->assertSame(AdminAction::UPDATED, $action->action);
        $this->assertSame(Strategy::class, $action->subject_type);
        $this->assertSame($strategy->id, $action->subject_id);
    }

    /**
     * A diff, not the whole row. It is what somebody investigating actually wants, and
     * storing every column of every save would bury it.
     */
    public function test_an_update_records_what_changed_and_not_the_rest(): void
    {
        $this->actingAs($this->admin);

        Strategy::acrossTenants()->where('user_id', $this->customer->id)->firstOrFail()
            ->update(['adx_threshold' => 30]);

        $changes = AdminAction::sole()->changes;

        $this->assertArrayHasKey('adx_threshold', $changes);
        $this->assertArrayNotHasKey('name', $changes, 'unchanged columns are not the audit');
        $this->assertArrayNotHasKey('updated_at', $changes, 'every save changes this and it says nothing');
    }

    public function test_a_deletion_is_recorded_without_rebuilding_the_row(): void
    {
        $this->actingAs($this->admin);

        $account = BrokerAccount::query()->forceCreate([
            'user_id' => $this->customer->id, 'label' => 'Theirs', 'broker_name' => 'Elev8',
            'account_number' => '9911223344', 'server' => 'Elev8-Demo',
        ]);

        $account->delete();

        $action = AdminAction::where('action', AdminAction::DELETED)->sole();

        $this->assertSame($this->customer->id, $action->subject_user_id);
        // The auditable fact is that the row is gone. Reproducing its columns here would
        // rebuild the record the deletion removed.
        $this->assertNull($action->changes);
    }

    public function test_the_acting_address_is_recorded(): void
    {
        $this->actingAs($this->admin);

        Strategy::acrossTenants()->where('user_id', $this->customer->id)->firstOrFail()
            ->update(['adx_threshold' => 30]);

        // A support action from an unexpected address is the shape of a compromised
        // administrator account.
        $this->assertNotNull(AdminAction::sole()->ip);
    }

    // =====================================================================
    // REDACTION
    // =====================================================================

    /**
     * The most important test here. `account_number` is encrypted at rest precisely so a
     * database leak does not expose it; writing the plaintext into an audit table would
     * undo that in the one table nobody thinks to protect.
     */
    public function test_an_encrypted_account_number_is_never_stored_in_the_audit(): void
    {
        $this->actingAs($this->admin);

        $account = BrokerAccount::query()->forceCreate([
            'user_id' => $this->customer->id, 'label' => 'Theirs', 'broker_name' => 'Elev8',
            'account_number' => '1234567890', 'server' => 'Elev8-Demo',
        ]);

        $account->update(['account_number' => '9999888877']);

        $recorded = json_encode(AdminAction::pluck('changes')->all());

        $this->assertStringNotContainsString('9999888877', (string) $recorded);
        $this->assertStringNotContainsString('1234567890', (string) $recorded);
        $this->assertStringContainsString('[redacted]', (string) $recorded);
    }

    /**
     * A Telegram session can read every chat on somebody's account and post as them. It is
     * hidden on the model, and anything the model refuses to serialise is a credential by
     * the model's own account.
     */
    public function test_anything_the_model_hides_is_redacted_too(): void
    {
        $this->actingAs($this->admin);

        [$plaintext, $token] = BotToken::generate($this->customer, 'Theirs');

        $token->update(['name' => 'Renamed']);

        $recorded = json_encode(AdminAction::pluck('changes')->all());

        $this->assertStringNotContainsString($plaintext, (string) $recorded);
        $this->assertStringNotContainsString(BotToken::hash($plaintext), (string) $recorded);
    }

    // =====================================================================
    // IT MUST NOT BECOME THE PROBLEM
    // =====================================================================

    /**
     * An audit trail that could stop a support fix from saving would be removed the first
     * time it did so, and then there would be no audit trail at all.
     */
    public function test_the_change_still_saves_even_if_it_cannot_be_recorded(): void
    {
        $this->actingAs($this->admin);

        // A table that is not there is the crudest way to make recording fail.
        Schema::drop('admin_actions');

        $settings = BotSettings::acrossTenants()->where('user_id', $this->customer->id)->firstOrFail();
        $settings->update(['max_concurrent_trades' => 7]);

        $this->assertSame(7, $settings->fresh()->max_concurrent_trades);
    }

    /**
     * Auditing the audit would grow without end and tell nobody anything.
     */
    public function test_the_audit_table_does_not_audit_itself(): void
    {
        $this->actingAs($this->admin);

        $account = BrokerAccount::query()->forceCreate([
            'user_id' => $this->customer->id, 'label' => 'Theirs', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo',
        ]);

        Trade::query()->forceCreate([
            'user_id' => $this->customer->id,
            'strategy_id' => Strategy::acrossTenants()->where('user_id', $this->customer->id)->value('id'),
            'broker_account_id' => $account->id,
            'symbol' => 'XAUUSD', 'direction' => 'buy',
            'initial_lot_size' => 0.01, 'remaining_lot_size' => 0.01,
            'entry_price' => 2000, 'sl_price' => 1990, 'status' => 'open',
        ]);

        // One row for the account, one for the trade, and no row about either of those
        // rows. Auditing the audit would grow without end and tell nobody anything.
        $this->assertSame(2, AdminAction::count());
        $this->assertSame(0, AdminAction::where('subject_type', AdminAction::class)->count());
    }
}

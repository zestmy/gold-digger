<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\SignalResource\Pages\CreateSignal;
use App\Filament\Resources\SignalResource\Pages\EditSignal;
use App\Filament\Resources\StrategyResource\Pages\ListStrategies;
use App\Filament\Resources\TradeResource;
use App\Filament\Resources\TradeResource\Pages\ListTrades;
use App\Models\AdminAction;
use App\Models\BrokerAccount;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradePartial;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The console sees everybody, and everything it does to somebody else is written down.
 *
 * ## Why the first half needs saying
 *
 * `Tenant::current()` falls back to the signed-in user, which is right for the dashboard
 * and wrong for a support console: an administrator opening Trades saw their own trades
 * and nobody else's, which is a console that cannot support anyone. Each resource now
 * reads across tenants on purpose, and the relation pickers do too - a signal's strategy
 * list that offered only the administrator's own strategies would let them re-parent a
 * customer's signal to themselves by accident.
 *
 * ## Why the second half needs saying
 *
 * Signals and partial closes carry no `user_id`. The audit observer used to look for one,
 * find nothing, and record nothing - so the two screens listing every tenant's rows were
 * the two an administrator could edit unrecorded.
 */
class AdminConsoleScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create();

        $this->actingAs($this->admin);
    }

    // =====================================================================
    // READS ACROSS TENANTS
    // =====================================================================

    public function test_the_trades_list_shows_every_tenants_trades(): void
    {
        $this->rendersAFilamentPage();

        $own = $this->tradeFor($this->admin);
        $theirs = $this->tradeFor($this->customer);

        Livewire::test(ListTrades::class)
            ->assertCanSeeTableRecords([$own, $theirs]);
    }

    public function test_the_strategies_list_shows_every_tenants_strategies(): void
    {
        $this->rendersAFilamentPage();

        Livewire::test(ListStrategies::class)
            ->assertCanSeeTableRecords([
                $this->strategyOf($this->admin),
                $this->strategyOf($this->customer),
            ]);
    }

    /**
     * The name comes through a relation, and the relation is on a tenant-scoped model. A
     * list that showed the row but not whose strategy it was would be half a fix.
     */
    public function test_a_customers_trade_lists_with_its_strategy_name(): void
    {
        $this->rendersAFilamentPage();

        $theirs = $this->tradeFor($this->customer);

        Livewire::test(ListTrades::class)
            ->assertTableColumnStateSet('user.name', $this->customer->name, $theirs);

        // The resource's own query is what the page reads through. A plain `with('strategy')`
        // would apply the tenant filter to the relation and load nothing - which is the
        // bug, and is why the resource declares the eager load itself.
        $this->assertSame(
            $this->strategyOf($this->customer)->name,
            TradeResource::getEloquentQuery()->find($theirs->id)->strategy?->name,
        );
    }

    public function test_the_signal_form_offers_every_tenants_strategies(): void
    {
        $this->rendersAFilamentPage();

        $theirs = $this->strategyOf($this->customer);

        Livewire::test(CreateSignal::class)
            ->assertFormFieldExists('strategy_id', function (Select $field) use ($theirs): bool {
                return array_key_exists($theirs->id, $field->getOptions());
            });
    }

    // =====================================================================
    // WRITES ARE RECORDED, EVEN WITHOUT A USER_ID
    // =====================================================================

    public function test_editing_a_customers_signal_in_the_console_is_recorded(): void
    {
        $this->rendersAFilamentPage();

        $signal = $this->signalFor($this->customer);

        Livewire::test(EditSignal::class, ['record' => $signal->getRouteKey()])
            ->fillForm(['skip_reason' => 'reviewed by support'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('reviewed by support', $signal->fresh()->skip_reason);

        // Creating the fixture as the administrator was itself a recorded act on the
        // customer's account; the edit is the row under test.
        $action = AdminAction::where('action', AdminAction::UPDATED)->sole();

        $this->assertSame($this->admin->id, $action->admin_user_id);
        $this->assertSame($this->customer->id, $action->subject_user_id);
        $this->assertSame(Signal::class, $action->subject_type);
        $this->assertSame($signal->id, $action->subject_id);
        $this->assertArrayHasKey('skip_reason', $action->changes);
    }

    public function test_editing_a_customers_partial_close_is_recorded(): void
    {
        $partial = TradePartial::query()->forceCreate([
            'trade_id' => $this->tradeFor($this->customer)->id,
            'closed_lot_size' => 0.01, 'close_price' => 2010, 'close_reason' => 'tp1',
            'pips_profit' => 100, 'gross_money_profit' => 1, 'net_money_profit' => 1,
            'closed_at' => now(),
        ]);

        AdminAction::query()->delete(); // the trade's own creation is not what is under test

        $partial->update(['pips_profit' => 90]);

        $action = AdminAction::sole();

        $this->assertSame($this->customer->id, $action->subject_user_id);
        $this->assertSame(TradePartial::class, $action->subject_type);
    }

    public function test_an_admins_own_signal_records_nothing(): void
    {
        $this->signalFor($this->admin)->update(['skip_reason' => 'mine']);

        $this->assertSame(0, AdminAction::count());
    }

    /**
     * A signal whose strategy is gone belongs to nobody, and the observer must say so
     * quietly rather than fail the save.
     */
    public function test_a_signal_with_no_owner_to_be_found_records_nothing_and_still_saves(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Orphaning a row past its foreign key is done with a SQLite pragma.');
        }

        $signal = $this->signalFor($this->customer);

        // The foreign key cascades, so deleting the strategy would delete the row under
        // test; and `PRAGMA foreign_keys = OFF` is a no-op inside the transaction that
        // RefreshDatabase holds open. Deferring the check until commit - which never
        // comes - is the one way SQLite lets a test point a row at nothing.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('signals')->where('id', $signal->id)->update(['strategy_id' => 999_999]);
        $signal->refresh();

        AdminAction::query()->delete();

        $signal->update(['skip_reason' => 'orphaned']);

        $this->assertSame('orphaned', $signal->fresh()->skip_reason);
        $this->assertSame(0, AdminAction::count());
    }

    // =====================================================================
    // FIXTURES
    // =====================================================================

    /**
     * Filament's tables format numbers through `Number::format()`, which needs the intl
     * extension. CI has it; a local PHP without it cannot render these pages at all, and
     * a skip that says why beats a stack trace from inside a Blade component.
     */
    private function rendersAFilamentPage(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Rendering a Filament page needs the intl extension: php -d extension=intl artisan test');
        }
    }

    private function strategyOf(User $user): Strategy
    {
        return Strategy::acrossTenants()->where('user_id', $user->id)->firstOrFail();
    }

    private function tradeFor(User $user): Trade
    {
        $account = BrokerAccount::query()->forceCreate([
            'user_id' => $user->id, 'label' => 'Acc', 'broker_name' => 'Elev8',
            'account_number' => (string) random_int(1000, 9999), 'server' => 'Elev8-Demo',
        ]);

        return Trade::query()->forceCreate([
            'user_id' => $user->id,
            'strategy_id' => $this->strategyOf($user)->id,
            'broker_account_id' => $account->id,
            'symbol' => 'XAUUSD', 'direction' => 'buy',
            'initial_lot_size' => 0.01, 'remaining_lot_size' => 0.01,
            'entry_price' => 2000, 'sl_price' => 1990, 'status' => 'open',
        ]);
    }

    private function signalFor(User $user): Signal
    {
        return Signal::query()->forceCreate([
            'strategy_id' => $this->strategyOf($user)->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'direction' => 'buy',
            'entry_price' => 2000, 'sl_price' => 1990,
            'generated_at' => now(),
        ]);
    }
}

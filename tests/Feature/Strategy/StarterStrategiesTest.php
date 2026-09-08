<?php

namespace Tests\Feature\Strategy;

use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The set of strategies an account starts with, and how an older account catches up.
 *
 * The strategy layer has been multi-symbol for a while - `SymbolResolver` reads pip size and
 * pip value per instrument, and the EA carries a list - but the feed still showed one
 * instrument, because a signal needs a *strategy* naming that instrument and exactly one was
 * ever created. These cases pin both halves of the fix: registration hands over the full set,
 * and `strategies:add-starters` reaches accounts that registered before the set grew.
 *
 * The properties that matter are the ones that make the backfill safe to run on a live
 * deployment: it never touches a symbol the account already covers, it never activates
 * anything, and running it twice does nothing the second time.
 */
class StarterStrategiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string>
     */
    private function symbolsFor(User $user): array
    {
        return Strategy::acrossTenants()
            ->where('user_id', $user->id)
            ->orderBy('symbol')
            ->pluck('symbol')
            ->all();
    }

    public function test_a_new_account_starts_with_gold_and_both_majors(): void
    {
        $user = User::factory()->create();

        $this->assertSame(['EURUSD', 'GBPUSD', 'XAUUSD'], $this->symbolsFor($user));
    }

    /**
     * Nothing trades on the strength of having been created. The owner reviews the
     * parameters and activates on the Strategies page.
     */
    public function test_every_starter_begins_inactive(): void
    {
        $user = User::factory()->create();

        $active = Strategy::acrossTenants()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->count();

        $this->assertSame(0, $active);
    }

    /**
     * The case the command exists for: an account created when gold was the only preset.
     */
    public function test_the_command_backfills_the_symbols_an_older_account_is_missing(): void
    {
        $user = User::factory()->create();

        Strategy::acrossTenants()
            ->where('user_id', $user->id)
            ->whereIn('symbol', ['EURUSD', 'GBPUSD'])
            ->delete();

        $this->assertSame(['XAUUSD'], $this->symbolsFor($user));

        $this->artisan('strategies:add-starters')->assertSuccessful();

        $this->assertSame(['EURUSD', 'GBPUSD', 'XAUUSD'], $this->symbolsFor($user));
    }

    public function test_running_the_command_twice_adds_nothing_the_second_time(): void
    {
        $user = User::factory()->create();

        $this->artisan('strategies:add-starters')->assertSuccessful();
        $this->artisan('strategies:add-starters')->assertSuccessful();

        $this->assertSame(['EURUSD', 'GBPUSD', 'XAUUSD'], $this->symbolsFor($user));
    }

    /**
     * A renamed strategy is still that instrument's strategy. Matching on the preset's name
     * would hand the owner a second EURUSD row for having called the first one something
     * else, and then generate two signals per bar on the same pair.
     */
    public function test_a_renamed_strategy_still_counts_as_covering_its_symbol(): void
    {
        $user = User::factory()->create();

        Strategy::acrossTenants()
            ->where('user_id', $user->id)
            ->where('symbol', 'EURUSD')
            ->update(['name' => 'My Own Euro Setup']);

        $this->artisan('strategies:add-starters')->assertSuccessful();

        $euro = Strategy::acrossTenants()
            ->where('user_id', $user->id)
            ->where('symbol', 'EURUSD')
            ->get();

        $this->assertCount(1, $euro);
        $this->assertSame('My Own Euro Setup', $euro->first()->name);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $user = User::factory()->create();

        Strategy::acrossTenants()
            ->where('user_id', $user->id)
            ->where('symbol', 'GBPUSD')
            ->delete();

        $this->artisan('strategies:add-starters --dry-run')->assertSuccessful();

        $this->assertSame(['EURUSD', 'XAUUSD'], $this->symbolsFor($user));
    }

    /**
     * The backfill is per-account, so an admin fixing one deployment does not have to touch
     * every account on it.
     */
    public function test_the_user_option_limits_the_backfill_to_one_account(): void
    {
        $kept = User::factory()->create();
        $target = User::factory()->create();

        foreach ([$kept, $target] as $user) {
            Strategy::acrossTenants()
                ->where('user_id', $user->id)
                ->where('symbol', 'GBPUSD')
                ->delete();
        }

        $this->artisan('strategies:add-starters --user='.$target->id)->assertSuccessful();

        $this->assertSame(['EURUSD', 'GBPUSD', 'XAUUSD'], $this->symbolsFor($target));
        $this->assertSame(['EURUSD', 'XAUUSD'], $this->symbolsFor($kept));
    }
}

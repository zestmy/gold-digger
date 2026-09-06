<?php

namespace Tests\Feature\Auth;

use App\Models\BotSettings;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The first account on a fresh install.
 *
 * Registration is off by default and `user:admin` can only promote an account that
 * already exists, so without this command a new deployment had no supported way to get a
 * user at all. The important property is not that a row appears - it is that the row is
 * a working account: settings, a starter strategy, a hashed password, and admin access
 * only when asked for.
 */
class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_working_account(): void
    {
        $this->artisan('user:create owner@example.test --name="The Owner"')
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Confirm password', 'correct-horse-battery')
            ->assertSuccessful();

        $user = User::where('email', 'owner@example.test')->sole();

        $this->assertSame('The Owner', $user->name);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
        $this->assertFalse((bool) $user->is_admin);
    }

    /**
     * The seeder used to run with model events off and produced an account that opened the
     * dashboard to a null settings row. This goes through the model so the observer runs.
     */
    public function test_the_account_gets_settings_and_a_starter_strategy(): void
    {
        $this->artisan('user:create owner@example.test')
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Confirm password', 'correct-horse-battery')
            ->assertSuccessful();

        $user = User::where('email', 'owner@example.test')->sole();

        $this->assertTrue(BotSettings::acrossTenants()->where('user_id', $user->id)->exists());
        $this->assertTrue(Strategy::acrossTenants()->where('user_id', $user->id)->exists());
    }

    public function test_the_name_defaults_to_the_local_part_of_the_address(): void
    {
        $this->artisan('user:create Owner@Example.test')
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Confirm password', 'correct-horse-battery')
            ->assertSuccessful();

        $user = User::where('email', 'owner@example.test')->sole();

        $this->assertSame('owner', $user->name);
    }

    public function test_admin_is_granted_only_when_asked_for(): void
    {
        $this->artisan('user:create owner@example.test --admin')
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Confirm password', 'correct-horse-battery')
            ->assertSuccessful();

        $this->assertTrue((bool) User::where('email', 'owner@example.test')->sole()->is_admin);
    }

    public function test_it_refuses_an_address_that_already_has_an_account(): void
    {
        User::factory()->create(['email' => 'owner@example.test']);

        $this->artisan('user:create owner@example.test')
            ->assertFailed();

        $this->assertSame(1, User::where('email', 'owner@example.test')->count());
    }

    public function test_it_refuses_an_address_that_is_not_one(): void
    {
        $this->artisan('user:create not-an-address')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_a_password_that_does_not_match_its_confirmation(): void
    {
        $this->artisan('user:create owner@example.test')
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Confirm password', 'correct-horse-staple')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_a_short_password(): void
    {
        $this->artisan('user:create owner@example.test')
            ->expectsQuestion('Password', 'short')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}

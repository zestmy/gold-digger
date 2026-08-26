<?php

namespace Tests\Feature\Auth;

use App\Models\BotSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Registration is off unless REGISTRATION_ENABLED says otherwise, and the route is not
     * defined at all in that state rather than defined and blocked - so Route::has('register')
     * is a single source of truth the views read to decide whether to offer a sign-up button.
     */
    public function test_the_registration_screen_is_absent_by_default(): void
    {
        $this->get('/register')->assertNotFound();
    }

    /**
     * Exercised through the component rather than the route, because the route's existence is
     * decided once at boot from config. This proves the form still works for anyone who turns
     * registration on; whether the door is open is the test above.
     */
    public function test_new_users_can_register(): void
    {
        $component = Volt::test('pages.auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    /**
     * A new account arrives with its copied positions already looked after.
     *
     * Null on `copier_protect_at_r` makes `PositionManager::manage()` return before it
     * looks at anything, so the previous default left a copied position with two things
     * minding it: the stop the order carries, and whatever the provider remembers to post.
     * The failure that produces - a winner that gave everything back overnight - reads as
     * the market's fault rather than as a setting nobody knew to turn on.
     *
     * `UserObserver` does not set these. They come off the columns, so this is really a
     * test that the defaults in `protect_copied_positions_by_default` survive.
     */
    public function test_a_new_account_starts_with_its_copied_positions_protected(): void
    {
        $settings = BotSettings::where('user_id', User::factory()->create()->id)->firstOrFail();

        $this->assertEquals(1.00, $settings->copier_protect_at_r);
        $this->assertSame(50, (int) $settings->copier_profit_lock_pct);
        $this->assertEquals(1.00, $settings->copier_trail_distance_r);

        // Trailing supersedes break-even rather than running beside it, so these two are
        // what happens if the trail is ever cleared, not a second action alongside it.
        $this->assertTrue((bool) $settings->copier_breakeven);
        $this->assertEquals(30.00, $settings->copier_breakeven_offset_pips);
    }

    /**
     * Protected is not the same as trading.
     *
     * The kill switch governs whether anything may be opened or modified at all, and
     * `manage()` checks it before it checks the trigger. Defaulting the protection on must
     * not quietly default the account into acting on its own.
     */
    public function test_a_new_account_still_does_not_trade_until_it_is_switched_on(): void
    {
        $settings = BotSettings::where('user_id', User::factory()->create()->id)->firstOrFail();

        $this->assertFalse((bool) $settings->is_active);
    }

    /**
     * Setting a default must not have made the columns NOT NULL as a side effect.
     *
     * `change()` rewrites a column from the definition it is handed, so an attribute left
     * out is an attribute dropped. Turning the protection off again has to stay possible.
     */
    public function test_the_protection_can_still_be_cleared(): void
    {
        $settings = BotSettings::where('user_id', User::factory()->create()->id)->firstOrFail();

        $settings->update([
            'copier_protect_at_r' => null,
            'copier_profit_lock_pct' => null,
            'copier_trail_distance_r' => null,
            'copier_breakeven_offset_pips' => null,
        ]);

        $settings->refresh();

        $this->assertNull($settings->copier_protect_at_r);
        $this->assertNull($settings->copier_profit_lock_pct);
        $this->assertNull($settings->copier_trail_distance_r);
        $this->assertNull($settings->copier_breakeven_offset_pips);
    }
}

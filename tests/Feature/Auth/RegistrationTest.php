<?php

namespace Tests\Feature\Auth;

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
}

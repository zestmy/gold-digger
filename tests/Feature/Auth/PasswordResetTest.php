<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response
            ->assertSeeVolt('pages.auth.forgot-password')
            ->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink')
            ->assertHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * The broker's own message for an unknown address is "We can't find a user with that
     * email address", which turns the form into a directory of who has an account here.
     * Known or not, the caller is told the same thing.
     */
    public function test_an_unknown_address_gets_the_same_answer_as_a_known_one(): void
    {
        Notification::fake();

        $known = User::factory()->create();

        $sent = 'If that address has an account, a reset link has been sent to it.';

        Volt::test('pages.auth.forgot-password')
            ->set('email', $known->email)
            ->call('sendPasswordResetLink')
            ->assertHasNoErrors()
            ->assertSee($sent);

        Volt::test('pages.auth.forgot-password')
            ->set('email', 'nobody@example.test')
            ->call('sendPasswordResetLink')
            ->assertHasNoErrors()
            ->assertSee($sent);

        Notification::assertSentTo($known, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_an_unknown_address_never_sees_the_brokers_message(): void
    {
        Notification::fake();

        Volt::test('pages.auth.forgot-password')
            ->set('email', 'nobody@example.test')
            ->call('sendPasswordResetLink')
            ->assertHasNoErrors()
            ->assertDontSee("can't find a user");
    }

    /**
     * Every submission sends somebody an email. The route's throttle only meets the page
     * load; the submission arrives through Livewire, so the limit that binds is the
     * component's own.
     */
    public function test_requests_are_throttled_per_address(): void
    {
        Notification::fake();

        $component = Volt::test('pages.auth.forgot-password');

        foreach (range(1, 6) as $attempt) {
            $component->set('email', 'nobody@example.test')
                ->call('sendPasswordResetLink')
                ->assertHasNoErrors();
        }

        $component->set('email', 'nobody@example.test')
            ->call('sendPasswordResetLink')
            ->assertHasErrors('email');
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response
                ->assertSeeVolt('pages.auth.reset-password')
                ->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $component = Volt::test('pages.auth.reset-password', ['token' => $notification->token])
                ->set('email', $user->email)
                ->set('password', 'password')
                ->set('password_confirmation', 'password');

            $component->call('resetPassword');

            $component
                ->assertRedirect('/login')
                ->assertHasNoErrors();

            return true;
        });
    }
}

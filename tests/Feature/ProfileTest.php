<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Settings > Account.
 *
 * The stock Breeze profile page, in this application's shell, plus the one thing a
 * subscriber has to tell the system about themselves that Breeze never asked: where to
 * send the alerts.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile'));

        $response
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertSeeVolt('profile.telegram-alerts-form')
            ->assertSeeVolt('profile.delete-user-form')
            ->assertSeeLivewire('profile.account-security');
    }

    /**
     * The page lives inside the Settings destination and looks like the rest of it: the
     * shared header, the tab strip, and no light-theme card left over from Breeze.
     */
    public function test_the_page_is_in_the_dark_shell_with_its_tabs(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('profile'))->getContent();

        $this->assertStringContainsString('<h1 class="text-lg font-semibold text-white">Settings</h1>', $html);
        $this->assertStringContainsString('aria-label="Sections"', $html);
        $this->assertStringContainsString('href="'.route('logs').'"', $html);
        $this->assertStringContainsString('border-gray-700 bg-gray-800', $html);
        // The Breeze card, and the Breeze page padding around it.
        $this->assertStringNotContainsString('bg-white dark:bg-gray-800 shadow sm:rounded-lg', $html);
        $this->assertStringNotContainsString('class="py-12"', $html);
    }

    public function test_the_user_menu_calls_it_settings(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/dashboard')->getContent();

        $this->assertStringContainsString('href="'.route('profile').'" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700">Settings</a>', $html);
        $this->assertStringNotContainsString('hover:bg-gray-700">Profile</a>', $html);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
    }

    // =====================================================================
    // TELEGRAM ALERTS
    // =====================================================================

    public function test_the_alerts_form_says_what_is_sent_and_why_to_message_the_bot_first(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('profile.telegram-alerts-form')
            ->assertSee('Telegram alerts')
            ->assertSee('Incidents and every order the copier places are sent here. Message the bot first so it can reply.');
    }

    public function test_the_alerts_form_shows_the_current_chat_id(): void
    {
        $this->actingAs(User::factory()->create(['telegram_chat_id' => '316745398']));

        Volt::test('profile.telegram-alerts-form')->assertSet('telegram_chat_id', '316745398');
    }

    /**
     * Off keeps the chat id. A holiday is not a reason to look the number up again after.
     */
    public function test_alerts_can_be_switched_off_without_losing_the_chat_id(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '316745398', 'alerts_enabled' => true]);
        $this->actingAs($user);

        Volt::test('profile.telegram-alerts-form')
            ->assertSet('alerts_enabled', true)
            ->set('alerts_enabled', false)
            ->call('updateTelegramAlerts')
            ->assertHasNoErrors();

        $this->assertFalse($user->fresh()->alerts_enabled);
        $this->assertSame('316745398', $user->fresh()->telegram_chat_id);
    }

    public function test_a_private_chat_id_is_saved(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('profile.telegram-alerts-form')
            ->set('telegram_chat_id', '316745398')
            ->call('updateTelegramAlerts')
            ->assertHasNoErrors()
            ->assertDispatched('telegram-alerts-updated');

        $this->assertSame('316745398', $user->fresh()->telegram_chat_id);
    }

    /**
     * Groups and supergroups carry a leading minus. Rejecting it would refuse the one
     * kind of chat a small team actually uses.
     */
    public function test_a_group_chat_id_is_saved(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('profile.telegram-alerts-form')
            ->set('telegram_chat_id', '-1001234567890')
            ->call('updateTelegramAlerts')
            ->assertHasNoErrors();

        $this->assertSame('-1001234567890', $user->fresh()->telegram_chat_id);
    }

    /**
     * A username or a phone number typed here would fail quietly at delivery time -
     * Telegram answers 400 to both - and the first anyone would know is an incident that
     * reached nobody. So it is refused at the form.
     */
    public function test_anything_but_digits_is_refused(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '4242']);
        $this->actingAs($user);

        foreach (['@fxsignalpro', '+60123456789', '12 34', '12-34', '--12', 'abc'] as $bad) {
            Volt::test('profile.telegram-alerts-form')
                ->set('telegram_chat_id', $bad)
                ->call('updateTelegramAlerts')
                ->assertHasErrors(['telegram_chat_id']);
        }

        $this->assertSame('4242', $user->fresh()->telegram_chat_id, 'a refused value leaves the old one in place');
    }

    /**
     * Blank means "nowhere", and stays null rather than becoming an empty string the
     * notifier would have to special-case.
     */
    public function test_clearing_the_chat_id_stores_null(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '4242']);
        $this->actingAs($user);

        Volt::test('profile.telegram-alerts-form')
            ->set('telegram_chat_id', '')
            ->call('updateTelegramAlerts')
            ->assertHasNoErrors();

        $this->assertNull($user->fresh()->telegram_chat_id);
    }

    // =====================================================================
    // DELETION
    // =====================================================================

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $component
            ->assertHasErrors('password')
            ->assertNoRedirect();

        $this->assertNotNull($user->fresh());
    }
}

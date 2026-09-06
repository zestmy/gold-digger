<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One door into the console, and it is the dashboard's.
 *
 * Filament's stock login page authenticated with `Auth::attempt()` and nothing else, so an
 * administrator who had switched two-factor on could still sign in at `/admin/login` with a
 * password alone. The panel now has no login page of its own: an unauthenticated visit is
 * sent to `/login`, where `LoginForm` runs the challenge, and the resulting `web` session
 * is what the panel accepts.
 */
class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_panel_has_no_login_page_of_its_own(): void
    {
        $this->get('/admin/login')->assertNotFound();
    }

    public function test_an_unauthenticated_visit_is_sent_to_the_dashboards_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_an_admin_with_a_web_session_can_open_the_panel(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin')
            ->assertOk();
    }

    public function test_an_ordinary_account_is_refused_rather_than_redirected(): void
    {
        // Signed in but not an administrator: a 403, not a bounce to a login they have
        // already passed.
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    /**
     * Removing the login page must not take the logout with it. Filament registers the
     * logout route inside the authenticated group, not alongside the login page.
     */
    public function test_an_admin_can_still_sign_out_from_the_panel(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/logout')
            ->assertRedirect();

        $this->assertGuest();
    }
}

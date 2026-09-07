<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The landing page: what the product is, what it costs, and the one door in.
 */
class WelcomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_pitch(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('AI trading signals, with the reasoning shown and the entry told plainly')
            ->assertSee('Gold &middot; EURUSD &middot; GBPUSD &middot; USDJPY &middot; GBPJPY', false)
            ->assertSee('Every signal timestamped, every outcome kept')
            ->assertSee('Confidence is computed, not claimed')
            ->assertSee('Your broker, your account, your risk')
            ->assertSee('How it works')
            ->assertSee('not financial advice');
    }

    public function test_it_shows_the_example_card_and_the_old_feature_copy_is_gone(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('XAUUSD')
            ->assertSee('92%')
            ->assertSee('Set limit order')
            ->assertSee('4,579.82')
            ->assertSee('4,591.82')
            ->assertSee('1:2.3')
            ->assertDontSee('EMA Crossover')
            ->assertDontSee('Trade Screenshots')
            ->assertDontSee('Copy Signals');
    }

    public function test_pricing_lists_three_tiers_with_no_amount_yet(): void
    {
        $response = $this->get('/')
            ->assertOk()
            ->assertSee('Pricing')
            ->assertSee('Free')
            ->assertSee('Pro')
            ->assertSee('Auto')
            ->assertSee('30 minutes delayed')
            ->assertSee('Telegram alerts')
            ->assertSee('Auto-trade on your own MT5')
            ->assertSee('Capped AI fund');

        // Once per paid tier. There is no billing, so no number is promised.
        $this->assertSame(2, substr_count($response->getContent(), 'Pricing announced at launch'));
    }

    /**
     * Registration is off by default and the route does not exist; the page must not link
     * to it, and every call to action becomes the sign-in instead.
     */
    public function test_it_offers_log_in_and_no_sign_up_when_registration_is_closed(): void
    {
        $this->assertFalse(Route::has('register'));

        $response = $this->get('/')
            ->assertOk()
            ->assertSee('Log in')
            ->assertDontSee('Start free')
            ->assertDontSee('/register');

        // Nav, hero, three tiers: every door is the same one.
        $this->assertSame(5, substr_count($response->getContent(), 'href="'.route('login').'"'));
    }

    public function test_it_offers_start_free_once_registration_is_open(): void
    {
        // Registered after boot, so the name lookup has to be rebuilt for Route::has()
        // to see it - the same test that would otherwise pass against a stale table.
        Route::middleware('web')->get('/register', fn () => 'register')->name('register');
        Route::getRoutes()->refreshNameLookups();

        $this->get('/')
            ->assertOk()
            ->assertSee('Start free')
            ->assertSee('No card. Delayed signals on the free plan.')
            ->assertSee('href="'.route('register').'"', false);
    }

    public function test_a_signed_in_visitor_is_sent_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('Log in');
    }
}

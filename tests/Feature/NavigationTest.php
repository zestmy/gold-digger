<?php

namespace Tests\Feature;

use App\Models\User;
use App\View\Components\PageTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The six-destination layout.
 *
 * Sixteen pages became six menu items with tabs inside. The route names did not change,
 * so nothing that links by name moved - but every address did, and the old ones have to
 * keep answering: alert messages, bookmarks and the EA setup guide all carried them.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function oldAddresses(): array
    {
        return [
            'live trades' => ['/trades/live', '/trades'],
            'analytics' => ['/analytics', '/trades/performance'],
            'chart analysis' => ['/analysis', '/signals/scan'],
            'signal copier' => ['/signals/copier', '/signals/copied'],
            'channels' => ['/signals/channels', '/providers'],
            'telegram accounts' => ['/signals/accounts', '/providers/accounts'],
            'setup' => ['/setup', '/auto-trade'],
            'terminal' => ['/terminal', '/auto-trade/terminal'],
            'broker accounts' => ['/broker-accounts', '/auto-trade/accounts'],
            'logs' => ['/logs', '/settings/activity'],
            'profile' => ['/profile', '/settings'],
        ];
    }

    #[DataProvider('oldAddresses')]
    public function test_an_old_address_redirects_to_where_the_page_went(string $old, string $new): void
    {
        $this->actingAs(User::factory()->create())
            ->get($old)
            ->assertRedirect($new);
    }

    /**
     * Every tab in every strip is a named route that resolves, so a renamed route cannot
     * leave a dead tab behind.
     */
    public function test_every_tab_names_a_route_that_exists(): void
    {
        foreach (PageTabs::GROUPS as $group => $tabs) {
            foreach ($tabs as [$route, $label]) {
                $this->assertTrue(Route::has($route), "Tab '{$label}' in group '{$group}' names a route that does not exist: {$route}");
            }
        }
    }

    public function test_each_destination_renders_its_tabs(): void
    {
        $user = User::factory()->create();

        foreach ([
            'trades.live' => ['Open', 'History', 'Performance'],
            'signals.channels' => ['Channels', 'Telegram accounts'],
            'setup' => ['Connection', 'Terminal', 'Broker accounts', 'Risk &amp; filters'],
            'logs' => ['Account', 'Activity'],
        ] as $route => $labels) {
            $response = $this->actingAs($user)->get(route($route))->assertOk();

            foreach ($labels as $label) {
                $response->assertSee($label, false);
            }
        }
    }

    /**
     * The strategy editor and the improver tune what generates every subscriber's
     * signals. A subscriber reaching one by address gets a 403, not the page.
     */
    public function test_the_operator_tools_refuse_a_subscriber(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('strategies'))->assertForbidden();
        $this->actingAs($user)->get(route('strategies.improve'))->assertForbidden();
    }

    public function test_the_operator_tools_open_for_an_administrator(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('strategies'))->assertOk();
    }
}

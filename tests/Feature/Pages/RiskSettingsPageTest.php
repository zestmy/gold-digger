<?php

namespace Tests\Feature\Pages;

use App\Livewire\Pages\Settings;
use App\Models\BotSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auto-Trade > Risk & filters.
 *
 * The page a subscriber decides on: whether auto-trade is on, how much may be lost, and
 * when. What it deliberately does not carry is as much the point as what it does - a
 * strategy parameter here would invite retuning something that cannot be backtested, and
 * a screenshot checkbox here was a control for a feature that never existed.
 */
class RiskSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_the_page_renders_inside_the_auto_trade_destination(): void
    {
        $this->get(route('settings'))
            ->assertOk()
            ->assertSee('Auto-Trade')
            ->assertSee('Risk &amp; filters', false)
            ->assertSee('Signals become trades on your MT5 when this is on.');
    }

    public function test_the_master_switch_is_called_auto_trade(): void
    {
        Livewire::test(Settings::class)
            ->assertSee('Auto-trade')
            ->assertDontSee('Trading Bot');
    }

    /**
     * Nothing has ever written a trade screenshot. A checkbox that changes nothing is a
     * lie on a form, so it is gone - the column stays until a drop migration is decided on.
     */
    public function test_the_screenshot_section_is_gone(): void
    {
        Livewire::test(Settings::class)
            ->assertDontSee('Screenshot Settings')
            ->assertDontSee('Capture Trade Screenshots')
            ->assertDontSee('capture_screenshots');
    }

    public function test_the_component_no_longer_carries_a_screenshot_property(): void
    {
        $this->assertFalse(property_exists(Settings::class, 'capture_screenshots'));
    }

    public function test_a_new_user_is_not_seeded_with_the_screenshot_flag_by_the_observer(): void
    {
        // The column's own default still applies; the point is that nothing in code
        // decides it any more.
        $settings = BotSettings::where('user_id', $this->user->id)->sole();

        $this->assertTrue((bool) $settings->capture_screenshots, 'the column default carries the value');
        $this->assertStringNotContainsString(
            "'capture_screenshots'",
            file_get_contents(app_path('Observers/UserObserver.php')),
        );
    }

    public function test_the_footer_says_whose_the_strategy_parameters_are(): void
    {
        Livewire::test(Settings::class)
            ->assertSee('Strategy parameters are managed by FXSignal Pro. You choose instruments and risk.');
    }

    public function test_every_other_section_is_still_there(): void
    {
        Livewire::test(Settings::class)
            ->assertSee('Risk Management')
            ->assertSee('Allowed Trading Sessions')
            ->assertSee('Trade Filters')
            ->assertSee('AI Trading Fund');
    }

    public function test_the_master_switch_still_flips_the_flag(): void
    {
        $component = Livewire::test(Settings::class)->call('toggleBot');

        $this->assertTrue((bool) BotSettings::where('user_id', $this->user->id)->value('is_active'));

        $component->call('toggleBot');

        $this->assertFalse((bool) BotSettings::where('user_id', $this->user->id)->value('is_active'));
    }

    public function test_saving_leaves_the_screenshot_column_alone(): void
    {
        BotSettings::where('user_id', $this->user->id)->update(['capture_screenshots' => false]);

        Livewire::test(Settings::class)
            ->set('risk_percentage', '2')
            ->call('save')
            ->assertHasNoErrors();

        $settings = BotSettings::where('user_id', $this->user->id)->sole();

        $this->assertEquals(2.0, $settings->risk_percentage);
        $this->assertFalse((bool) $settings->capture_screenshots, 'a column no form offers is not rewritten by saving the form');
    }
}

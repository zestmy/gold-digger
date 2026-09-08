<?php

namespace Tests\Feature;

use App\Livewire\Pages\Settings;
use App\Livewire\Pages\Signals as SignalsPage;
use App\Models\BotSettings;
use App\Models\User;
use App\Services\Strategy\SignalQuality;
use App\Support\TradingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Trading modes.
 *
 * A mode is a word for an appetite, and the settings are what the word means. Nothing
 * reads the mode to decide a trade - the generator reads the floors, the reviewer reads
 * the review setting - which is why choosing one has to write the real columns, and why
 * an account whose columns match no preset is honestly `custom`.
 */
class TradingModeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_a_new_account_is_moderate_and_moderate_is_the_platform_default(): void
    {
        $settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame(TradingMode::MODERATE, $settings->trading_mode);
        $this->assertSame(TradingMode::MODERATE, TradingMode::of($settings), 'The observer defaults must be the moderate preset, or a new account would read as custom.');
    }

    public function test_every_preset_detects_as_itself(): void
    {
        foreach (TradingMode::MODES as $mode) {
            $this->assertSame($mode, TradingMode::detect(TradingMode::values($mode)));
        }
    }

    public function test_choosing_a_mode_from_the_risk_page_writes_its_values(): void
    {
        Livewire::test(Settings::class)
            ->call('chooseMode', TradingMode::PASSIVE)
            ->assertHasNoErrors()
            ->assertSet('trading_mode', TradingMode::PASSIVE);

        $settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame(TradingMode::PASSIVE, $settings->trading_mode);
        $this->assertEquals(0.50, $settings->risk_percentage);
        $this->assertSame(1, (int) $settings->max_concurrent_trades);
        $this->assertSame(['london', 'overlap'], $settings->allowed_sessions);
        $this->assertEquals(2.00, $settings->min_reward_ratio);
        $this->assertEquals(4.00, $settings->min_confluence);
        $this->assertSame(BotSettings::COPIER_REVIEW_MODEL, $settings->copier_review);
    }

    public function test_aggressive_trusts_the_provider_and_drops_the_reward_floor(): void
    {
        Livewire::test(Settings::class)->call('chooseMode', TradingMode::AGGRESSIVE)->assertHasNoErrors();

        $settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame(TradingMode::AGGRESSIVE, $settings->trading_mode);
        $this->assertSame(BotSettings::COPIER_REVIEW_GATES, $settings->copier_review);
        $this->assertNull($settings->min_reward_ratio);
        $this->assertSame([], $settings->allowed_sessions);
        $this->assertEquals(2.00, $settings->risk_percentage);
    }

    /**
     * A mode is a description of the values, not a label beside them. Change a value and
     * the description changes with it.
     */
    public function test_editing_a_value_by_hand_makes_the_account_custom(): void
    {
        Livewire::test(Settings::class)
            ->call('chooseMode', TradingMode::PASSIVE)
            ->set('risk_percentage', '0.75')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('trading_mode', TradingMode::CUSTOM);

        $this->assertSame(TradingMode::CUSTOM, BotSettings::where('user_id', $this->user->id)->value('trading_mode'));
    }

    /**
     * The mode changes what a signal is held to, because the floors are the mode. This is
     * the "signals reflect the mode" half of the promise.
     */
    public function test_the_signal_floors_follow_the_mode(): void
    {
        $settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $quality = new SignalQuality;

        $settings->update(TradingMode::values(TradingMode::PASSIVE));
        $this->assertEquals(4.0, $quality->minConfluence($settings->fresh()));

        $settings->update(TradingMode::values(TradingMode::AGGRESSIVE));
        $this->assertEquals(2.0, $quality->minConfluence($settings->fresh()));
    }

    public function test_the_risk_page_and_the_signals_page_name_the_mode(): void
    {
        Livewire::test(Settings::class)->call('chooseMode', TradingMode::PASSIVE);

        Livewire::test(Settings::class)->assertSee('Passive');
        Livewire::test(SignalsPage::class)->assertSee('Passive');
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        Livewire::test(Settings::class)
            ->call('chooseMode', 'reckless')
            ->assertSet('trading_mode', TradingMode::MODERATE);

        $this->assertEquals(1.00, BotSettings::where('user_id', $this->user->id)->value('risk_percentage'));
    }
}

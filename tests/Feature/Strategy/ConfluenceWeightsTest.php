<?php

namespace Tests\Feature\Strategy;

use App\Models\BotSettings;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Strategy\MarketContext;
use App\Services\Strategy\SignalQuality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What each factor contributes to the agreement score.
 *
 * The weights were literals in the factor list, so the only way to score a book differently
 * was to edit and deploy. Making them settable is easy; making them settable without
 * quietly breaking the thing they encode is the work.
 *
 * Two properties matter more than the configurability itself. The defaults have to change
 * nothing - an untouched deployment scores exactly as it did. And the score has to keep
 * reporting the scale it sits on, because `min_confluence` is an absolute number of
 * weighted factors rather than a percentage, so moving a weight moves the bar.
 */
class ConfluenceWeightsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Strategy $strategy;

    private BotSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
    }

    // =====================================================================
    // THE DEFAULTS CHANGE NOTHING
    // =====================================================================

    public function test_the_shipped_weights_are_what_they_have_always_been(): void
    {
        $quality = app(SignalQuality::class);

        $this->assertSame(1.0, $quality->weightFor('trend_htf'));
        $this->assertSame(1.0, $quality->weightFor('trend_entry'));
        $this->assertSame(1.0, $quality->weightFor('trend_present_adx'));
        $this->assertSame(1.0, $quality->weightFor('session_open'));
        $this->assertSame(1.0, $quality->weightFor('news_clear'));
        $this->assertSame(0.5, $quality->weightFor('volatility_usable'));
    }

    /**
     * The two half-weights are the independence argument in numeric form. DI direction and
     * the trend factors are close to the same measurement twice; a squeeze raises the odds
     * of a move without saying which way.
     */
    public function test_the_two_corroborating_factors_are_still_half(): void
    {
        $quality = app(SignalQuality::class);

        $this->assertSame(0.5, $quality->weightFor('direction_di'));
        $this->assertSame(0.5, $quality->weightFor('volatility_squeeze'));
    }

    /**
     * 6.5 by default, and the number matters: `min_confluence` is 3.0 of it. If the total
     * moves without the floor moving, the bar has changed without anybody deciding to.
     */
    public function test_the_default_scale_is_the_one_the_floor_was_chosen_against(): void
    {
        $assessment = $this->assess();

        $this->assertSame(6.5, $assessment['possible']);
        $this->assertLessThan($assessment['possible'], $assessment['min_confluence']);
    }

    // =====================================================================
    // CONFIGURING THEM
    // =====================================================================

    public function test_a_configured_weight_replaces_the_default(): void
    {
        config(['trading.confluence.weights.trend_htf' => 2.5]);

        $this->assertSame(2.5, app(SignalQuality::class)->weightFor('trend_htf'));
    }

    public function test_a_configured_weight_moves_the_scale_the_score_sits_on(): void
    {
        config(['trading.confluence.weights.trend_htf' => 3.0]);

        // 6.5 with trend_htf at 1.0; 8.5 with it at 3.0.
        $this->assertSame(8.5, $this->assess()['possible']);
    }

    public function test_a_factor_can_be_switched_off_entirely(): void
    {
        config(['trading.confluence.weights.volatility_squeeze' => 0]);

        $this->assertSame(0.0, app(SignalQuality::class)->weightFor('volatility_squeeze'));
        $this->assertSame(6.0, $this->assess()['possible']);
    }

    /**
     * A factor that subtracts from agreement when it is met is not a weight, it is a typo.
     */
    public function test_a_negative_weight_is_clamped_rather_than_trusted(): void
    {
        config(['trading.confluence.weights.news_clear' => -4]);

        $this->assertSame(0.0, app(SignalQuality::class)->weightFor('news_clear'));
    }

    /**
     * Keyed by a stable identifier rather than the display name, so rewording a card
     * cannot silently reset a configured weight.
     */
    public function test_an_unknown_key_is_worth_nothing_rather_than_guessing(): void
    {
        $this->assertSame(0.0, app(SignalQuality::class)->weightFor('nonsense_factor'));
    }

    // =====================================================================
    // THE GRADE
    // =====================================================================

    public function test_the_grade_bands_are_fixed_rather_than_curved(): void
    {
        $quality = app(SignalQuality::class);

        // An A has to mean the same thing next month as last. A grade computed against the
        // current distribution would drift with the market rather than describe the setup.
        $this->assertSame('A', $quality->grade(100));
        $this->assertSame('A', $quality->grade(85));
        $this->assertSame('B', $quality->grade(84));
        $this->assertSame('B', $quality->grade(70));
        $this->assertSame('C', $quality->grade(69));
        $this->assertSame('C', $quality->grade(55));
        $this->assertSame('D', $quality->grade(54));
        $this->assertSame('D', $quality->grade(0));
    }

    /**
     * The percentage is computed against whatever the weights add up to, so it stays
     * comparable across deployments that score differently.
     */
    public function test_the_assessment_reports_a_grade_beside_the_score(): void
    {
        $assessment = $this->assess();

        $this->assertContains($assessment['grade'], ['A', 'B', 'C', 'D']);
        $this->assertSame($assessment['grade'], app(SignalQuality::class)->grade($assessment['confidence']));
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
    private function assess(): array
    {
        // The real context object rather than a hand-built array, so this cannot pass
        // against a shape the assessor no longer reads. Cold, which is fine: the scale is
        // what is under test, not the score.
        $market = app(MarketContext::class)->for($this->strategy, null, 'XAUUSD');

        return app(SignalQuality::class)->assess($this->strategy, null, 'XAUUSD', 'buy', market: $market);
    }
}

<?php

namespace Tests\Feature\Strategy;

use App\Models\BotSettings;
use App\Models\Strategy;
use App\Models\TelegramChannel;
use App\Models\User;
use App\Services\Strategy\MarketContext;
use App\Services\Strategy\RewardFloor;
use App\Services\Strategy\SignalQuality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three floors an entry has to clear.
 *
 * Two of them - confluence and its directional half - were compile-time constants, so the
 * only way to run a stricter book was to edit and deploy. The third did not exist at all:
 * the reward-to-risk ratio was computed in three places, displayed on two pages, stored on
 * two tables and put in front of the reviewer model, and no gate anywhere looked at it. A
 * signal offering to risk three to make one passed everything.
 *
 * The behaviour these pin down most carefully is the *default*. An untouched account has to
 * trade exactly as it did before any of this existed, or a migration nobody read has
 * changed what a live copier refuses.
 */
class EntryFloorsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BotSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
    }

    // =====================================================================
    // THE REWARD FLOOR
    // =====================================================================

    /**
     * The load-bearing default. Turning a floor on by default would start refusing trades
     * that currently execute, on the strength of a migration nobody read.
     */
    public function test_no_floor_is_configured_by_default_and_everything_passes(): void
    {
        $floor = app(RewardFloor::class);

        $this->assertNull($floor->floorFor($this->settings));

        // Risking 10 to make 1 - dreadful, and taken, because nobody asked for it not to be.
        $this->assertNull($floor->objection($this->settings, 10.0, 1.0));
    }

    public function test_a_configured_floor_refuses_a_poor_ratio(): void
    {
        $this->settings->update(['min_reward_ratio' => 1.5]);

        $floor = app(RewardFloor::class);

        $this->assertSame(RewardFloor::OBJECTION, $floor->objection($this->settings->fresh(), 10.0, 10.0));
        $this->assertNull($floor->objection($this->settings->fresh(), 10.0, 20.0));
    }

    public function test_exactly_the_floor_is_good_enough(): void
    {
        $this->settings->update(['min_reward_ratio' => 2.0]);

        $this->assertNull(app(RewardFloor::class)->objection($this->settings->fresh(), 5.0, 10.0));
    }

    /**
     * A signal with no target names no reward. Waving those through would enforce the floor
     * only against the signals that bothered to state their case.
     */
    public function test_an_unmeasurable_reward_is_refused_rather_than_waved_through(): void
    {
        $this->settings->update(['min_reward_ratio' => 1.5]);

        $settings = $this->settings->fresh();

        $this->assertSame(RewardFloor::OBJECTION, app(RewardFloor::class)->objection($settings, 10.0, null));
        $this->assertSame(RewardFloor::OBJECTION, app(RewardFloor::class)->objection($settings, 10.0, 0.0));
    }

    /**
     * A missing stop distance is a trade whose risk is unknown, not one with infinite
     * reward. Dividing by it would produce a number that clears every floor there is.
     */
    public function test_a_missing_stop_distance_is_not_an_infinite_ratio(): void
    {
        $floor = app(RewardFloor::class);

        $this->assertNull($floor->ratio(0.0, 50.0));
        $this->assertNull($floor->ratio(null, 50.0));

        $this->settings->update(['min_reward_ratio' => 1.5]);
        $this->assertSame(RewardFloor::OBJECTION, $floor->objection($this->settings->fresh(), 0.0, 50.0));
    }

    public function test_the_platform_default_applies_when_the_account_has_not_set_one(): void
    {
        config(['trading.min_reward_ratio' => 2.0]);

        $floor = app(RewardFloor::class);

        $this->assertSame(2.0, $floor->floorFor($this->settings));
        $this->assertSame(RewardFloor::OBJECTION, $floor->objection($this->settings, 10.0, 15.0));
    }

    public function test_the_account_overrides_the_platform(): void
    {
        config(['trading.min_reward_ratio' => 3.0]);
        $this->settings->update(['min_reward_ratio' => 1.0]);

        $floor = app(RewardFloor::class);

        $this->assertSame(1.0, $floor->floorFor($this->settings->fresh()));
        $this->assertNull($floor->objection($this->settings->fresh(), 10.0, 12.0));
    }

    /**
     * A floor of zero is one every trade clears, so it means the same thing as no floor.
     * Worth pinning down because the confluence floors treat zero and null differently and
     * somebody reading both will expect consistency.
     */
    public function test_a_floor_of_zero_means_no_floor(): void
    {
        $this->settings->update(['min_reward_ratio' => 0]);

        $floor = app(RewardFloor::class);

        $this->assertNull($floor->floorFor($this->settings->fresh()));
        $this->assertNull($floor->objection($this->settings->fresh(), 10.0, 1.0));
    }

    public function test_the_refusal_says_what_was_offered_and_what_was_required(): void
    {
        $this->settings->update(['min_reward_ratio' => 1.5]);

        $said = app(RewardFloor::class)->explain($this->settings->fresh(), 10.0, 8.0);

        $this->assertStringContainsString('0.8', $said);
        $this->assertStringContainsString('1.5', $said);
    }

    // =====================================================================
    // THE CONFLUENCE FLOORS
    // =====================================================================

    public function test_the_defaults_are_what_they_have_always_been(): void
    {
        $quality = app(SignalQuality::class);

        $this->assertSame(SignalQuality::MIN_CONFLUENCE, $quality->minConfluence($this->settings));
        $this->assertSame(SignalQuality::MIN_DIRECTIONAL, $quality->minDirectional($this->settings));
    }

    public function test_an_account_can_hold_itself_to_a_stricter_bar(): void
    {
        $this->settings->update(['min_confluence' => 4.5, 'min_directional' => 3.0]);

        $quality = app(SignalQuality::class);
        $settings = $this->settings->fresh();

        $this->assertSame(4.5, $quality->minConfluence($settings));
        $this->assertSame(3.0, $quality->minDirectional($settings));
    }

    public function test_the_platform_default_applies_to_an_account_that_has_not_chosen(): void
    {
        config(['trading.min_confluence' => 4.0, 'trading.min_directional' => 2.5]);

        $quality = app(SignalQuality::class);

        $this->assertSame(4.0, $quality->minConfluence($this->settings));
        $this->assertSame(2.5, $quality->minDirectional($this->settings));
    }

    /**
     * Zero here is a real setting meaning "no confluence required", unlike the reward
     * floor where zero and unset coincide. The distinction is that a confluence floor
     * always applies - the question is only how high - so an explicit zero has to survive.
     */
    public function test_a_confluence_floor_of_zero_is_honoured_rather_than_replaced(): void
    {
        config(['trading.min_confluence' => 3.0]);
        $this->settings->update(['min_confluence' => 0]);

        $this->assertSame(0.0, app(SignalQuality::class)->minConfluence($this->settings->fresh()));
    }

    /**
     * A negative floor is one every signal clears, which is indistinguishable from the gate
     * being absent - and a gate that has silently stopped gating is worse than one that was
     * never there.
     */
    public function test_a_negative_floor_is_clamped_rather_than_trusted(): void
    {
        $this->settings->update(['min_confluence' => -5]);

        $this->assertSame(0.0, app(SignalQuality::class)->minConfluence($this->settings->fresh()));
    }

    /**
     * A per-channel override was already possible while the account itself had no floor to
     * state, so "stricter for this provider" was expressible and "stricter everywhere" was
     * not. A channel with no override now inherits the account's bar.
     */
    public function test_a_channel_without_an_override_inherits_the_accounts_bar(): void
    {
        $this->settings->update(['min_confluence' => 4.5]);

        $channel = TelegramChannel::create([
            'user_id' => $this->user->id,
            'source' => 'telegram',
            'chat_id' => '-100123',
            'title' => 'A provider',
            'is_enabled' => true,
        ]);

        $this->assertSame(4.5, $channel->policy($this->settings->fresh())['min_confluence']);
    }

    public function test_a_channel_with_an_override_keeps_it(): void
    {
        $this->settings->update(['min_confluence' => 4.5]);

        $channel = TelegramChannel::create([
            'user_id' => $this->user->id,
            'source' => 'telegram',
            'chat_id' => '-100124',
            'title' => 'A stricter provider',
            'is_enabled' => true,
            'min_confluence' => 6.0,
        ]);

        $this->assertSame(6.0, (float) $channel->policy($this->settings->fresh())['min_confluence']);
    }

    /**
     * The verdict has to carry the numbers it was written from, or a caller enforcing the
     * bar elsewhere is comparing against a different one.
     */
    public function test_the_assessment_reports_the_floors_it_was_judged_against(): void
    {
        $this->settings->update(['min_confluence' => 4.0, 'min_directional' => 2.0]);

        $strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        // The real context object rather than a hand-built array, so this test cannot pass
        // against a shape the assessor no longer reads. With no stored bars it comes back
        // cold, which is all this needs - the floors are what is under test, not the score.
        $market = app(MarketContext::class)->for($strategy, null, 'XAUUSD');

        $assessment = app(SignalQuality::class)->assess($strategy, null, 'XAUUSD', 'buy', market: $market);

        $this->assertSame(4.0, $assessment['min_confluence']);
        $this->assertSame(2.0, $assessment['min_directional']);
        $this->assertFalse($assessment['tradeable']);
    }
}

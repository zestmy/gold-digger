<?php

namespace Tests\Feature\Analysis;

use App\Services\Analysis\SetupClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Which kind of setup, if any, the conditions support.
 *
 * The failure this exists to prevent is fluency. Ask a language model "what kind of setup
 * is this?" and it answers with a setup type, because that is what the question wants and
 * the vocabulary is what it has - a pullback in a range, a reversal in a pullback, stated
 * with the same confidence either way.
 *
 * So the conditions are measured here and the model chooses among what was found. The
 * tests that carry the most weight are therefore the negative ones: a market that is
 * nothing in particular has to come back as nothing in particular.
 */
class SetupClassifierTest extends TestCase
{
    // =====================================================================
    // NOTHING WINS BY DEFAULT
    // =====================================================================

    /**
     * A market between levels, no trend, no break, no rejection. The common case, and the
     * answer is an empty list rather than the least-bad label.
     */
    public function test_a_market_that_is_nothing_in_particular_matches_nothing(): void
    {
        $found = $this->classify(
            bias: 'ranging',
            adx: 12.0,
            position: 0.5,
            event: null,
        );

        $this->assertSame([], $found);
    }

    /**
     * A pattern with half its requirements missing is not a weak example of that pattern,
     * it is a different market wearing the name.
     */
    public function test_a_type_below_its_own_support_floor_is_not_offered(): void
    {
        // Directional and extended, but no trend present and character just changed -
        // continuation loses half its definition.
        $found = $this->classify(
            bias: 'bullish',
            adx: 8.0,
            position: 0.9,
            event: ['type' => 'CHoCH', 'direction' => 'bearish', 'level' => 100.0, 'index' => 119],
        );

        $this->assertNotContains(SetupClassifier::TREND_CONTINUATION, array_column($found, 'type'));
    }

    // =====================================================================
    // THE TYPES IT DOES FIND
    // =====================================================================

    public function test_a_break_price_has_returned_to_is_a_retest(): void
    {
        $found = $this->classify(
            bias: 'bullish',
            adx: 28.0,
            position: 0.6,
            // Broken five bars ago, and price is back at the level it broke.
            event: ['type' => 'BOS', 'direction' => 'bullish', 'level' => 100.0, 'index' => 114],
            retest: true,
        );

        $this->assertSame(SetupClassifier::BREAKOUT_RETEST, $found[0]['type']);
        $this->assertSame('buy', $found[0]['direction']);
    }

    /**
     * The same break with price still away from the level is a plain breakout. The two
     * differ only in where price is now, which is exactly why the retest is measured
     * against the broken level rather than against "somewhere near".
     */
    public function test_the_same_break_without_the_return_is_a_plain_breakout(): void
    {
        $found = $this->classify(
            bias: 'bullish',
            adx: 28.0,
            position: 0.9,
            event: ['type' => 'BOS', 'direction' => 'bullish', 'level' => 100.0, 'index' => 114],
        );

        $types = array_column($found, 'type');

        $this->assertContains(SetupClassifier::BREAKOUT, $types);
        $this->assertNotContains(SetupClassifier::BREAKOUT_RETEST, $types);
    }

    public function test_a_change_of_character_at_a_respected_level_is_a_reversal(): void
    {
        $found = $this->classify(
            bias: 'bullish',
            adx: 24.0,
            position: 0.8,
            event: ['type' => 'CHoCH', 'direction' => 'bearish', 'level' => 120.0, 'index' => 116],
            retest: true,
            levels: [['price' => 120.0, 'kind' => 'resistance', 'touches' => 3, 'last_index' => 116]],
        );

        $reversal = collect($found)->firstWhere('type', SetupClassifier::REVERSAL);

        $this->assertNotNull($reversal);

        // Against the bias that has just been broken - that is what the change of character
        // means, and a reversal trading with the old bias is not a reversal.
        $this->assertSame('sell', $reversal['direction']);
    }

    public function test_a_quiet_market_at_the_edge_of_its_range_is_a_range_trade(): void
    {
        $found = $this->classify(
            bias: 'ranging',
            adx: 11.0,
            position: 0.1,
            event: null,
        );

        $range = collect($found)->firstWhere('type', SetupClassifier::RANGE);

        $this->assertNotNull($range);
        $this->assertSame('buy', $range['direction'], 'at the low of the range');
    }

    /**
     * A range has no direction until price is at one of its edges. Naming one from the
     * middle is how a range trade becomes a guess.
     */
    public function test_a_range_gives_no_direction_from_the_middle(): void
    {
        $found = $this->classify(bias: 'ranging', adx: 11.0, position: 0.5, event: null);

        $range = collect($found)->firstWhere('type', SetupClassifier::RANGE);

        // It may not qualify at all from the middle, but if it does it must not name a side.
        if ($range !== null) {
            $this->assertNull($range['direction']);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_a_retracement_inside_a_trend_is_a_pullback(): void
    {
        $found = $this->classify(
            bias: 'bullish',
            adx: 30.0,
            position: 0.3,
            event: ['type' => 'BOS', 'direction' => 'bullish', 'level' => 100.0, 'index' => 60],
        );

        $types = array_column($found, 'type');

        $this->assertContains(SetupClassifier::PULLBACK, $types);
    }

    // =====================================================================
    // THE EVIDENCE TRAVELS WITH THE VERDICT
    // =====================================================================

    /**
     * A support figure with nothing behind it is the confident-sounding number this whole
     * class exists to avoid producing.
     */
    public function test_every_candidate_carries_what_was_met_and_what_was_missing(): void
    {
        $found = $this->classify(
            bias: 'bullish',
            adx: 28.0,
            position: 0.6,
            event: ['type' => 'BOS', 'direction' => 'bullish', 'level' => 100.0, 'index' => 114],
            retest: true,
        );

        $this->assertNotEmpty($found);

        foreach ($found as $candidate) {
            $this->assertNotEmpty($candidate['met'], $candidate['type'].' claims support with no met conditions');
            $this->assertGreaterThanOrEqual(66, $candidate['support']);
            $this->assertLessThanOrEqual(100, $candidate['support']);
            $this->assertIsArray($candidate['missing']);
        }
    }

    public function test_candidates_are_ranked_best_supported_first(): void
    {
        $found = $this->classify(
            bias: 'bullish',
            adx: 30.0,
            position: 0.6,
            event: ['type' => 'BOS', 'direction' => 'bullish', 'level' => 100.0, 'index' => 114],
            retest: true,
        );

        $supports = array_column($found, 'support');
        $sorted = $supports;
        rsort($sorted);

        $this->assertSame($sorted, $supports);
    }

    // =====================================================================
    // FIXTURE
    // =====================================================================

    /**
     * @param  array<string, mixed>|null  $event
     * @param  array<int, array<string, mixed>>  $levels
     * @return array<int, array<string, mixed>>
     */
    private function classify(
        string $bias,
        float $adx,
        float $position,
        ?array $event,
        bool $retest = false,
        array $levels = [],
    ): array {
        // A range the requested position resolves inside, so `position` is the dial the
        // test actually turns rather than three numbers that have to agree.
        $low = 100.0;
        $high = 200.0;
        $close = $low + ($position * ($high - $low));

        // A retest is price back at the level a break went through, so the level has to be
        // where price is now. Expressed as a flag rather than a price, because the test
        // sets position and cannot know what close that resolves to.
        if ($retest && $event !== null) {
            $event['level'] = $close;
        }

        $structure = [
            'levels' => $levels,
            'range_high' => $high,
            'range_low' => $low,
            'bias' => $bias,
            'last_event' => $event,
            'swings' => [],
        ];

        $market = [
            'last_close' => $close,
            'atr' => 1.0,
            'adx' => $adx,
        ];

        // 120 flat closes: enough for the squeeze calculation to run, and deliberately
        // featureless so the dials above are the only thing under test.
        return (new SetupClassifier)->classify($structure, $market, array_fill(0, 120, $close));
    }
}

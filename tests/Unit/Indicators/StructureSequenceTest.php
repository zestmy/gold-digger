<?php

namespace Tests\Unit\Indicators;

use App\Services\Indicators\Structure;
use PHPUnit\Framework\TestCase;

/**
 * Swing labelling, and breaks of structure.
 *
 * BOS and CHoCH are the two readings most likely to be asserted confidently and computed
 * loosely, because both are "price closed past a level" and the difference between them is
 * entirely about what the market was doing beforehand. So these tests fix the vocabulary
 * against constructed series where the right answer is not a matter of opinion.
 *
 * The lookahead test is the one that matters most. A swing is not knowable until `WING`
 * further bars confirm it, and a version of this that ignored that would report structure
 * breaking against levels which did not yet exist - improving every backtest run over it,
 * fictionally.
 */
class StructureSequenceTest extends TestCase
{
    /**
     * A staircase up, then a sharp drop.
     *
     * Two swing highs and one swing low form on the way up, so the sequence reads HH / HL,
     * each new high is a continuation (BOS), and the drop through the last higher low is
     * the first thing that is not (CHoCH).
     */
    public function test_a_trend_that_continues_then_turns_reads_bos_then_choch(): void
    {
        $closes = $this->series([
            [10.0, 20.0, 5],   // up
            [20.0, 16.0, 4],   // pullback: swing high at 20, swing low at 16
            [16.0, 26.0, 5],   // higher high
            [26.0, 22.0, 4],   // higher low
            [22.0, 30.0, 5],   // continuation
            [30.0, 14.0, 8],   // reversal
        ]);

        $result = Structure::sequence($this->highs($closes), $this->lows($closes), $closes);

        $labels = array_values(array_filter(array_column($result['swings'], 'label')));
        $this->assertSame(['HH', 'HL', 'HH'], $labels);

        $types = array_column($result['events'], 'type');
        $this->assertSame(['BOS', 'BOS', 'CHoCH'], $types);

        $last = $result['last_event'];
        $this->assertSame('CHoCH', $last['type']);
        $this->assertSame('bearish', $last['direction']);
    }

    /**
     * CHoCH says the character changed, not that the structure has flipped. The swing
     * sequence is still HH/HL at this point because the down-leg has not printed a
     * confirmed swing of its own - and reporting `bearish` here would be claiming a turn
     * that the swings do not yet support.
     */
    public function test_a_choch_does_not_by_itself_flip_the_bias(): void
    {
        $closes = $this->series([
            [10.0, 20.0, 5],
            [20.0, 16.0, 4],
            [16.0, 26.0, 5],
            [26.0, 22.0, 4],
            [22.0, 30.0, 5],
            [30.0, 14.0, 8],
        ]);

        $result = Structure::sequence($this->highs($closes), $this->lows($closes), $closes);

        $this->assertSame('CHoCH', $result['last_event']['type']);
        $this->assertSame('bullish', $result['bias']);
    }

    public function test_a_falling_sequence_labels_lower_highs_and_lower_lows(): void
    {
        $closes = $this->series([
            [30.0, 20.0, 5],
            [20.0, 24.0, 4],   // swing low 20, swing high 24
            [24.0, 14.0, 5],   // lower low
            [14.0, 18.0, 4],   // lower high
            [18.0, 8.0, 5],
        ]);

        $result = Structure::sequence($this->highs($closes), $this->lows($closes), $closes);

        $labels = array_values(array_filter(array_column($result['swings'], 'label')));

        $this->assertContains('LL', $labels);
        $this->assertContains('LH', $labels);
        $this->assertSame('bearish', $result['bias']);
    }

    /**
     * The break must be recorded no earlier than `WING` bars after the swing formed,
     * because that is when the swing became knowable.
     */
    public function test_a_break_is_never_recorded_before_its_level_was_confirmed(): void
    {
        $closes = $this->series([
            [10.0, 20.0, 5],
            [20.0, 16.0, 4],
            [16.0, 26.0, 5],
            [26.0, 22.0, 4],
            [22.0, 30.0, 5],
        ]);

        $result = Structure::sequence($this->highs($closes), $this->lows($closes), $closes);

        $swingsByIndex = array_column($result['swings'], null, 'index');

        foreach ($result['events'] as $event) {
            $formedAt = null;

            foreach ($swingsByIndex as $index => $swing) {
                if (abs($swing['price'] - $event['level']) < 1e-9) {
                    $formedAt = $index;
                    break;
                }
            }

            $this->assertNotNull($formedAt, 'Every event must break a swing that this reading actually found.');
            $this->assertGreaterThanOrEqual(
                $formedAt + 2,
                $event['index'],
                'A break was recorded before its swing had been confirmed. That is lookahead bias.'
            );
        }
    }

    /**
     * A trend that keeps running past one level must not print an event on every bar - the
     * list is meant to say where something happened, not that it is still true.
     */
    public function test_each_swing_is_broken_at_most_once(): void
    {
        $closes = $this->series([
            [10.0, 20.0, 5],
            [20.0, 16.0, 4],
            [16.0, 60.0, 30],  // one long run well past the swing high
        ]);

        $result = Structure::sequence($this->highs($closes), $this->lows($closes), $closes);

        $levels = array_column($result['events'], 'level');

        $this->assertSame(count($levels), count(array_unique($levels)), 'The same level was broken twice.');
    }

    public function test_a_series_with_no_completed_swings_is_ranging_and_silent(): void
    {
        $closes = array_fill(0, 30, 15.0);

        $result = Structure::sequence($closes, $closes, $closes);

        $this->assertSame('ranging', $result['bias']);
        $this->assertSame([], $result['events']);
        $this->assertNull($result['last_event']);
    }

    /**
     * `of()` is what the analysis surfaces already call. The new readings have to arrive
     * through it too, or every caller has to be changed to get them.
     */
    public function test_the_sequence_is_reachable_from_the_reading_callers_already_use(): void
    {
        $closes = $this->series([
            [10.0, 20.0, 5],
            [20.0, 16.0, 4],
            [16.0, 26.0, 5],
            [26.0, 22.0, 4],
            [22.0, 30.0, 5],
        ]);

        $reading = Structure::of($this->highs($closes), $this->lows($closes), $closes, atr: 1.0);

        $this->assertArrayHasKey('swings', $reading);
        $this->assertArrayHasKey('events', $reading);
        $this->assertSame('bullish', $reading['bias']);

        // And the keys that existed before still do.
        $this->assertArrayHasKey('levels', $reading);
        $this->assertArrayHasKey('swing_high', $reading);
    }

    // =====================================================================
    // FIXTURE HELPERS
    // =====================================================================

    /**
     * Build a close series from a list of [from, to, steps] legs.
     *
     * Legs are five bars or so rather than two, because a turn needs to clear `WING` bars
     * on each side before it counts as a swing at all - a tighter zigzag produces a series
     * with no structure in it, which tests nothing.
     *
     * @param  array<int, array{0: float, 1: float, 2: int}>  $legs
     * @return array<int, float>
     */
    private function series(array $legs): array
    {
        $out = [$legs[0][0]];

        foreach ($legs as [$from, $to, $steps]) {
            for ($i = 1; $i <= $steps; $i++) {
                $out[] = $from + (($to - $from) * $i / $steps);
            }
        }

        return $out;
    }

    /**
     * @param  array<int, float>  $closes
     * @return array<int, float>
     */
    private function highs(array $closes): array
    {
        return array_map(fn (float $c) => $c + 0.05, $closes);
    }

    /**
     * @param  array<int, float>  $closes
     * @return array<int, float>
     */
    private function lows(array $closes): array
    {
        return array_map(fn (float $c) => $c - 0.05, $closes);
    }
}

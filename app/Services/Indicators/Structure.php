<?php

namespace App\Services\Indicators;

/**
 * Market structure, measured rather than described.
 *
 * ## Why the levels are computed here and not asked for
 *
 * "Key levels" is the part of chart analysis most obviously suited to a language model and
 * least suited to one. A model reading a list of candles will produce round, plausible
 * numbers - 2650, 2700 - because those are the numbers text about markets contains. They
 * are not where this instrument actually turned.
 *
 * A pivot is a definition: a bar whose high exceeds its neighbours on both sides. Applying
 * it is arithmetic, it is reproducible, and it finds the levels price genuinely reacted at
 * rather than the ones that sound like levels. So the arithmetic happens here, and the
 * model's job becomes interpreting a list it did not invent.
 *
 * ## Clustering, because a level is a region
 *
 * Price rarely turns at exactly the same figure twice. Three pivots at 2648.10, 2649.40
 * and 2648.80 are one level being tested three times, and reporting them separately would
 * suggest three. They are merged when within a fraction of ATR, and the count of merged
 * touches becomes the level's strength - which is the thing that actually distinguishes a
 * level worth watching from a high that happened once.
 */
final class Structure
{
    /** Bars either side that a pivot must exceed. Larger finds fewer, more significant turns. */
    private const WING = 2;

    /** Pivots merge into one level when closer than this multiple of ATR. */
    private const MERGE_ATR = 0.5;

    /**
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array{
     *     levels: array<int, array{price: float, kind: string, touches: int, last_index: int}>,
     *     swing_high: float|null,
     *     swing_low: float|null,
     *     range_high: float|null,
     *     range_low: float|null,
     *     structure: string
     * }
     */
    public static function of(array $highs, array $lows, array $closes, float $atr): array
    {
        $count = count($closes);

        if ($count < (self::WING * 2) + 3 || $atr <= 0.0) {
            return [
                'levels' => [],
                'swing_high' => null,
                'swing_low' => null,
                'range_high' => null,
                'range_low' => null,
                'structure' => 'Not enough history to read structure from.',
            ];
        }

        $pivotHighs = self::pivots($highs, true);
        $pivotLows = self::pivots($lows, false);

        $levels = self::cluster(array_merge($pivotHighs, $pivotLows), $atr);
        $sequence = self::sequence($highs, $lows, $closes);

        return [
            'levels' => $levels,
            // The labelled swing sequence and the breaks of it. Additive keys: everything
            // that read this array before still reads the same things.
            'swings' => $sequence['swings'],
            'events' => $sequence['events'],
            'bias' => $sequence['bias'],
            'last_event' => $sequence['last_event'],
            // The most recent confirmed turn in each direction, which is what "the last
            // swing" means when somebody says it.
            'swing_high' => empty($pivotHighs) ? null : end($pivotHighs)['price'],
            'swing_low' => empty($pivotLows) ? null : end($pivotLows)['price'],
            'range_high' => max($highs),
            'range_low' => min($lows),
            'structure' => self::describe($pivotHighs, $pivotLows),
        ];
    }

    /**
     * The swing sequence, and the moments price broke it.
     *
     * ## The vocabulary, defined rather than assumed
     *
     * Everyone means something slightly different by these, so this is what they mean here:
     *
     * - **HH / HL / LH / LL** label each confirmed swing against the previous swing of the
     *   same kind. They describe the sequence, not the price.
     * - **Bias** is rising when the last two highs are higher and the last two lows are
     *   higher, falling on the mirror image, and ranging whenever the two disagree.
     * - **BOS** - break of structure - is a close beyond the most recent confirmed swing
     *   *in the direction the bias already pointed*. It is continuation.
     * - **CHoCH** - change of character - is a close beyond the most recent confirmed swing
     *   *against* the prevailing bias. It is the first evidence that a trend has stopped.
     *
     * The distinction between the last two is the entire point of computing this. They are
     * the same arithmetic; what differs is what the market was doing beforehand, which is
     * why a bare "price broke a level" reading is not worth much.
     *
     * ## Confirmation lag, which is where this goes wrong if it goes wrong
     *
     * A pivot is not a pivot until `WING` further bars have printed - that is what makes it
     * a turn rather than a bar that happens to be high. So a swing formed at bar `i` is not
     * knowable until bar `i + WING`, and a break of it can only be recorded from that bar
     * onwards.
     *
     * Skipping that is lookahead bias: the series would show structure being broken using
     * levels that were not yet established, every backtest over it would improve, and the
     * improvement would be entirely fictional. It is the same reasoning that makes
     * `CandleController` reject bars the terminal has not marked closed.
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array{
     *     swings: array<int, array{index: int, price: float, kind: string, label: string|null}>,
     *     events: array<int, array{index: int, type: string, direction: string, level: float, close: float}>,
     *     bias: string,
     *     last_event: array{index: int, type: string, direction: string, level: float, close: float}|null
     * }
     */
    public static function sequence(array $highs, array $lows, array $closes): array
    {
        $swings = self::labelled(self::pivots($highs, true), self::pivots($lows, false));

        $events = [];
        $bias = 'ranging';

        // Walked bar by bar rather than evaluated at the end, because "what was the bias
        // before this break" is a question about the past and cannot be answered from the
        // finished series.
        $confirmedHighs = [];
        $confirmedLows = [];
        $cursor = 0;
        $lastBrokenHigh = null;
        $lastBrokenLow = null;

        foreach ($closes as $i => $close) {
            // Take in every swing that has become knowable as of this bar.
            while ($cursor < count($swings) && $swings[$cursor]['index'] + self::WING <= $i) {
                $swing = $swings[$cursor];
                $swing['kind'] === 'high' ? $confirmedHighs[] = $swing : $confirmedLows[] = $swing;
                $cursor++;
            }

            $bias = self::biasOf($confirmedHighs, $confirmedLows);

            $high = $confirmedHighs === [] ? null : end($confirmedHighs);
            $low = $confirmedLows === [] ? null : end($confirmedLows);

            // Each swing is broken once. Without this a trend that keeps running prints an
            // event on every bar, and the list stops meaning "here is where it happened".
            if ($high !== null && $close > $high['price'] && $lastBrokenHigh !== $high['index']) {
                $lastBrokenHigh = $high['index'];
                $events[] = [
                    'index' => $i,
                    'type' => $bias === 'bearish' ? 'CHoCH' : 'BOS',
                    'direction' => 'bullish',
                    'level' => $high['price'],
                    'close' => $close,
                ];
            }

            if ($low !== null && $close < $low['price'] && $lastBrokenLow !== $low['index']) {
                $lastBrokenLow = $low['index'];
                $events[] = [
                    'index' => $i,
                    'type' => $bias === 'bullish' ? 'CHoCH' : 'BOS',
                    'direction' => 'bearish',
                    'level' => $low['price'],
                    'close' => $close,
                ];
            }
        }

        return [
            'swings' => $swings,
            'events' => $events,
            'bias' => $bias,
            'last_event' => $events === [] ? null : end($events),
        ];
    }

    /**
     * Label each swing against the previous one of its own kind, then interleave them back
     * into time order - which is how they have to be consumed, one bar at a time.
     *
     * @param  array<int, array{price: float, index: int, kind: string}>  $highs
     * @param  array<int, array{price: float, index: int, kind: string}>  $lows
     * @return array<int, array{index: int, price: float, kind: string, label: string|null}>
     */
    private static function labelled(array $highs, array $lows): array
    {
        $out = [];

        foreach ($highs as $n => $pivot) {
            $out[] = [
                'index' => $pivot['index'],
                'price' => $pivot['price'],
                'kind' => 'high',
                // The first swing of each kind has nothing to be higher or lower than.
                'label' => $n === 0 ? null : ($pivot['price'] > $highs[$n - 1]['price'] ? 'HH' : 'LH'),
            ];
        }

        foreach ($lows as $n => $pivot) {
            $out[] = [
                'index' => $pivot['index'],
                'price' => $pivot['price'],
                'kind' => 'low',
                'label' => $n === 0 ? null : ($pivot['price'] > $lows[$n - 1]['price'] ? 'HL' : 'LL'),
            ];
        }

        usort($out, fn (array $a, array $b) => $a['index'] <=> $b['index']);

        return $out;
    }

    /**
     * Rising, falling, or neither, from the swings known so far.
     *
     * @param  array<int, array{index: int, price: float, kind: string, label: string|null}>  $highs
     * @param  array<int, array{index: int, price: float, kind: string, label: string|null}>  $lows
     */
    private static function biasOf(array $highs, array $lows): string
    {
        if (count($highs) < 2 || count($lows) < 2) {
            return 'ranging';
        }

        $higherHigh = $highs[count($highs) - 1]['price'] > $highs[count($highs) - 2]['price'];
        $higherLow = $lows[count($lows) - 1]['price'] > $lows[count($lows) - 2]['price'];

        return match (true) {
            $higherHigh && $higherLow => 'bullish',
            ! $higherHigh && ! $higherLow => 'bearish',
            default => 'ranging',
        };
    }

    /**
     * Bars that exceed their neighbours on both sides.
     *
     * @param  array<int, float>  $series
     * @return array<int, array{price: float, index: int, kind: string}>
     */
    private static function pivots(array $series, bool $high): array
    {
        $found = [];
        $count = count($series);

        // The last WING bars can never be pivots: there is not yet enough to their right to
        // confirm one. Including them would report a turn that has not happened.
        for ($i = self::WING; $i < $count - self::WING; $i++) {
            $isPivot = true;

            for ($j = 1; $j <= self::WING; $j++) {
                $left = $high ? $series[$i] <= $series[$i - $j] : $series[$i] >= $series[$i - $j];
                $right = $high ? $series[$i] <= $series[$i + $j] : $series[$i] >= $series[$i + $j];

                if ($left || $right) {
                    $isPivot = false;
                    break;
                }
            }

            if ($isPivot) {
                $found[] = ['price' => $series[$i], 'index' => $i, 'kind' => $high ? 'resistance' : 'support'];
            }
        }

        return $found;
    }

    /**
     * Merge pivots that are really the same level tested more than once.
     *
     * @param  array<int, array{price: float, index: int, kind: string}>  $pivots
     * @return array<int, array{price: float, kind: string, touches: int, last_index: int}>
     */
    private static function cluster(array $pivots, float $atr): array
    {
        usort($pivots, fn (array $a, array $b) => $a['price'] <=> $b['price']);

        $tolerance = $atr * self::MERGE_ATR;
        $clusters = [];

        foreach ($pivots as $pivot) {
            $last = count($clusters) - 1;

            if ($last >= 0 && abs($pivot['price'] - $clusters[$last]['price']) <= $tolerance) {
                $c = &$clusters[$last];

                // The mean of what has been merged, so a level drifts toward where price
                // actually turned rather than sitting on whichever pivot came first.
                $c['price'] = round((($c['price'] * $c['touches']) + $pivot['price']) / ($c['touches'] + 1), 6);
                $c['touches']++;
                $c['last_index'] = max($c['last_index'], $pivot['index']);

                // A level tested from both sides is neither support nor resistance any
                // more; it is a level, and calling it one is more useful than picking.
                if ($c['kind'] !== $pivot['kind']) {
                    $c['kind'] = 'pivot';
                }

                unset($c);

                continue;
            }

            $clusters[] = [
                'price' => round($pivot['price'], 6),
                'kind' => $pivot['kind'],
                'touches' => 1,
                'last_index' => $pivot['index'],
            ];
        }

        // Most-tested first: a level price turned at four times is a different object from
        // one it turned at once, and a list sorted by price buries that.
        usort($clusters, fn (array $a, array $b) => [$b['touches'], $b['last_index']] <=> [$a['touches'], $a['last_index']]);

        return $clusters;
    }

    /**
     * Higher highs and higher lows, or the opposite, or neither.
     *
     * @param  array<int, array{price: float, index: int, kind: string}>  $highs
     * @param  array<int, array{price: float, index: int, kind: string}>  $lows
     */
    private static function describe(array $highs, array $lows): string
    {
        if (count($highs) < 2 || count($lows) < 2) {
            return 'Too few completed swings to call structure either way.';
        }

        $lastHighs = array_slice($highs, -2);
        $lastLows = array_slice($lows, -2);

        $higherHigh = $lastHighs[1]['price'] > $lastHighs[0]['price'];
        $higherLow = $lastLows[1]['price'] > $lastLows[0]['price'];

        return match (true) {
            $higherHigh && $higherLow => 'Higher high and higher low: structure is rising.',
            ! $higherHigh && ! $higherLow => 'Lower high and lower low: structure is falling.',
            // The interesting case, and the one a trend-following entry is most likely to
            // be late into.
            default => 'Highs and lows disagree: structure is mixed, with no clear sequence.',
        };
    }
}

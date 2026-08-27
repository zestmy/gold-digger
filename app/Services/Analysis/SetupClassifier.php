<?php

namespace App\Services\Analysis;

use App\Services\Indicators\Indicators;

/**
 * Setup Classifier
 *
 * Which kind of setup, if any, the measured conditions support.
 *
 * ## Measured, then chosen - not named
 *
 * A language model asked "what kind of setup is this?" will answer with a setup type,
 * because that is what the question wants. It will find a pullback in a range and a
 * reversal in a pullback, fluently, because the vocabulary is what it has and the
 * conditions are not.
 *
 * So the conditions are evaluated here in arithmetic, each type is scored on how many of
 * its own requirements the market actually meets, and the model is handed a ranked
 * shortlist with the evidence attached. Its job becomes choosing among candidates that were
 * measured, which is the same arrangement `Structure` already imposes for price levels: the
 * model cannot write a level, and now it cannot invent a setup either.
 *
 * ## Every type states its own requirements
 *
 * Each entry in `types()` is a list of conditions with a weight. A type scores the fraction
 * of its own weight that the market satisfies, so a breakout that has the break but not the
 * volatility expansion scores lower than one with both, and both are visible in `met` and
 * `missing`.
 *
 * That fraction is not a probability. It says how much of the pattern's own definition is
 * present, which is a statement about how much is known - the same claim `SignalQuality`
 * makes about confluence and the same one the walk-forward remains the only real test of.
 *
 * ## Nothing wins by default
 *
 * A market between levels with no trend, no break and no rejection matches nothing, and
 * `classify()` returns an empty list. That is the common case and it is the answer: a
 * classifier that always names a type is a vocabulary, not a measurement.
 */
final class SetupClassifier
{
    public const TREND_CONTINUATION = 'trend_continuation';

    public const PULLBACK = 'pullback';

    public const BREAKOUT = 'breakout';

    public const BREAKOUT_RETEST = 'breakout_retest';

    public const REJECTION = 'rejection';

    public const RANGE = 'range';

    public const REVERSAL = 'reversal';

    /**
     * Fraction of its own definition a type must meet to be offered at all.
     *
     * Two thirds rather than a half: a pattern with half its requirements missing is not a
     * weak example of that pattern, it is a different market wearing the name.
     */
    private const MIN_SUPPORT = 0.66;

    /** ADX above which a trend is present rather than merely sloped. Matches SignalQuality. */
    private const ADX_TRENDING = 20.0;

    /** How near a level counts as "at" it, in ATR. */
    private const NEAR_ATR = 0.75;

    /** Bars within which a structure break still counts as recent. */
    private const RECENT_BARS = 10;

    /**
     * Rank the setup types this market supports.
     *
     * @param  array<string, mixed>  $structure  From Structure::of()
     * @param  array<string, mixed>  $market  From MarketContext::for()
     * @param  array<int, float>  $closes  Entry-timeframe closes, oldest-first
     * @return array<int, array{
     *     type: string,
     *     label: string,
     *     direction: string|null,
     *     support: int,
     *     met: array<int, string>,
     *     missing: array<int, string>
     * }>
     */
    public function classify(array $structure, array $market, array $closes): array
    {
        $facts = $this->facts($structure, $market, $closes);
        $out = [];

        foreach ($this->types($facts) as $type => $definition) {
            $possible = array_sum(array_column($definition['conditions'], 'weight'));

            if ($possible <= 0.0) {
                continue;
            }

            $scored = array_sum(array_map(
                fn (array $c) => $c['met'] ? $c['weight'] : 0.0,
                $definition['conditions'],
            ));

            $support = $scored / $possible;

            if ($support < self::MIN_SUPPORT) {
                continue;
            }

            $out[] = [
                'type' => $type,
                'label' => $definition['label'],
                'direction' => $definition['direction'],
                'support' => (int) round($support * 100),
                'met' => array_values(array_map(
                    fn (array $c) => $c['says'],
                    array_filter($definition['conditions'], fn (array $c) => $c['met']),
                )),
                'missing' => array_values(array_map(
                    fn (array $c) => $c['says'],
                    array_filter($definition['conditions'], fn (array $c) => ! $c['met']),
                )),
            ];
        }

        // Best-supported first. A tie keeps declaration order, which runs from the most
        // specific pattern to the least - a breakout-and-retest is a more particular claim
        // than a plain breakout and should not be buried under it.
        usort($out, fn (array $a, array $b) => $b['support'] <=> $a['support']);

        return $out;
    }

    /**
     * The measurements every type is scored against, taken once.
     *
     * @param  array<string, mixed>  $structure
     * @param  array<string, mixed>  $market
     * @param  array<int, float>  $closes
     * @return array<string, mixed>
     */
    private function facts(array $structure, array $market, array $closes): array
    {
        $close = $market['last_close'] ?? (end($closes) ?: null);
        $atr = (float) ($market['atr'] ?? 0.0);
        $adx = $market['adx'];
        $bias = $structure['bias'] ?? 'ranging';
        $event = $structure['last_event'] ?? null;
        $bars = count($closes);

        $rangeHigh = $structure['range_high'] ?? null;
        $rangeLow = $structure['range_low'] ?? null;

        // How far into the window's range price is sitting, 0 at the low and 1 at the high.
        $position = ($close !== null && $rangeHigh !== null && $rangeLow !== null && $rangeHigh > $rangeLow)
            ? ($close - $rangeLow) / ($rangeHigh - $rangeLow)
            : null;

        // The strongest level price is currently near, if any. Strength is touch count -
        // a level price turned at four times is a different object from one it grazed once.
        $nearest = null;

        if ($close !== null && $atr > 0.0) {
            foreach ($structure['levels'] ?? [] as $level) {
                $distance = abs((float) $level['price'] - $close);

                if ($distance <= $atr * self::NEAR_ATR) {
                    if ($nearest === null || $level['touches'] > $nearest['touches']) {
                        $nearest = $level;
                    }
                }
            }
        }

        $eventAge = ($event !== null && isset($event['index'])) ? ($bars - 1 - (int) $event['index']) : null;

        // Price back at the level a break went through - the retest. Measured against the
        // broken level itself rather than against "somewhere near", because a retest that
        // is not at the level is just a pullback.
        $retesting = $event !== null && $atr > 0.0 && $close !== null
            && abs((float) $event['level'] - $close) <= $atr * self::NEAR_ATR;

        return [
            'close' => $close,
            'atr' => $atr,
            'adx' => $adx,
            'trending' => $adx !== null && $adx >= self::ADX_TRENDING,
            'bias' => $bias,
            'directional' => in_array($bias, ['bullish', 'bearish'], true),
            'event' => $event,
            'event_recent' => $eventAge !== null && $eventAge <= self::RECENT_BARS,
            'event_age' => $eventAge,
            'retesting' => $retesting,
            'position' => $position,
            'nearest' => $nearest,
            'squeezed' => Indicators::squeeze($closes)['squeezed'],
            'swings' => $structure['swings'] ?? [],
        ];
    }

    /**
     * Each type, and the conditions it is scored on.
     *
     * Written as data rather than as branches so the definition of a pattern can be read
     * without following control flow - and so `met` and `missing` come out of the same
     * declaration the score does, rather than being described separately and drifting.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, array{label: string, direction: string|null, conditions: array<int, array{says: string, weight: float, met: bool}>}>
     */
    private function types(array $f): array
    {
        $bullish = $f['bias'] === 'bullish';
        $direction = $f['directional'] ? ($bullish ? 'buy' : 'sell') : null;

        $eventIs = fn (string $type, bool $withBias): bool => $f['event'] !== null
            && $f['event']['type'] === $type
            && ($withBias
                ? $f['event']['direction'] === ($bullish ? 'bullish' : 'bearish')
                : $f['event']['direction'] !== ($bullish ? 'bullish' : 'bearish'));

        // Retraced against the prevailing direction without breaking it: in the lower half
        // of the range while bullish, upper half while bearish.
        $retraced = $f['position'] !== null && $f['directional']
            && ($bullish ? $f['position'] <= 0.5 : $f['position'] >= 0.5);

        return [
            self::BREAKOUT_RETEST => [
                'label' => 'Breakout and retest',
                'direction' => $direction,
                'conditions' => [
                    ['says' => 'structure broke in the direction of the trend', 'weight' => 1.0, 'met' => $eventIs('BOS', true)],
                    ['says' => 'the break was recent', 'weight' => 1.0, 'met' => $f['event_recent']],
                    ['says' => 'price has returned to the level it broke', 'weight' => 1.5, 'met' => $f['retesting']],
                    ['says' => 'a trend is present', 'weight' => 0.5, 'met' => $f['trending']],
                ],
            ],

            self::BREAKOUT => [
                'label' => 'Breakout',
                'direction' => $direction,
                'conditions' => [
                    ['says' => 'structure broke in the direction of the trend', 'weight' => 1.0, 'met' => $eventIs('BOS', true)],
                    ['says' => 'the break was recent', 'weight' => 1.0, 'met' => $f['event_recent']],
                    ['says' => 'price has not come back to the broken level', 'weight' => 0.5, 'met' => ! $f['retesting']],
                    // Compression before the break, or expansion after it. A breakout out of
                    // nothing is a bar that went further than the last one.
                    ['says' => 'volatility supports a move', 'weight' => 0.5, 'met' => $f['squeezed'] || $f['trending']],
                ],
            ],

            self::REVERSAL => [
                'label' => 'Reversal',
                // The trade is against the bias that has just been broken, which is what a
                // change of character means.
                'direction' => $f['directional'] ? ($bullish ? 'sell' : 'buy') : null,
                'conditions' => [
                    ['says' => 'character changed against the prevailing structure', 'weight' => 1.5, 'met' => $f['event'] !== null && $f['event']['type'] === 'CHoCH'],
                    ['says' => 'the change was recent', 'weight' => 1.0, 'met' => $f['event_recent']],
                    ['says' => 'it happened at a level price has respected before', 'weight' => 1.0, 'met' => $f['nearest'] !== null && $f['nearest']['touches'] >= 2],
                ],
            ],

            self::REJECTION => [
                'label' => 'Support or resistance rejection',
                'direction' => $direction,
                'conditions' => [
                    ['says' => 'price is at a level it has turned at before', 'weight' => 1.5, 'met' => $f['nearest'] !== null && $f['nearest']['touches'] >= 2],
                    ['says' => 'the level has been tested more than twice', 'weight' => 0.5, 'met' => $f['nearest'] !== null && $f['nearest']['touches'] >= 3],
                    ['says' => 'structure has not just broken through it', 'weight' => 1.0, 'met' => ! ($f['event_recent'] && $f['retesting'])],
                ],
            ],

            self::PULLBACK => [
                'label' => 'Pullback entry',
                'direction' => $direction,
                'conditions' => [
                    ['says' => 'structure is directional', 'weight' => 1.0, 'met' => $f['directional']],
                    ['says' => 'a trend is present', 'weight' => 1.0, 'met' => $f['trending']],
                    ['says' => 'price has retraced against it', 'weight' => 1.5, 'met' => $retraced],
                    ['says' => 'character has not changed', 'weight' => 1.0, 'met' => ! ($f['event'] !== null && $f['event']['type'] === 'CHoCH' && $f['event_recent'])],
                ],
            ],

            self::TREND_CONTINUATION => [
                'label' => 'Trend continuation',
                'direction' => $direction,
                'conditions' => [
                    ['says' => 'structure is directional', 'weight' => 1.0, 'met' => $f['directional']],
                    ['says' => 'a trend is present', 'weight' => 1.0, 'met' => $f['trending']],
                    ['says' => 'price is extended in the trend direction', 'weight' => 1.0, 'met' => $f['position'] !== null && ($bullish ? $f['position'] > 0.5 : $f['position'] < 0.5)],
                    ['says' => 'character has not changed', 'weight' => 1.0, 'met' => ! ($f['event'] !== null && $f['event']['type'] === 'CHoCH' && $f['event_recent'])],
                ],
            ],

            self::RANGE => [
                'label' => 'Range trading',
                // A range has no direction until price is at one of its edges, and naming
                // one from the middle is how a range trade becomes a guess.
                'direction' => $f['position'] === null ? null : ($f['position'] <= 0.25 ? 'buy' : ($f['position'] >= 0.75 ? 'sell' : null)),
                'conditions' => [
                    ['says' => 'structure is not directional', 'weight' => 1.0, 'met' => ! $f['directional']],
                    ['says' => 'no trend is present', 'weight' => 1.0, 'met' => ! $f['trending']],
                    // Weighted heavily enough that missing it sinks the type below the
                    // support floor, which is the intent: a quiet market in the middle of
                    // its range is not a weak range trade, it is no trade. Without this the
                    // other three conditions alone offer a candidate with no side to take,
                    // which is a label rather than a setup.
                    ['says' => 'price is at one edge of the range', 'weight' => 2.0, 'met' => $f['position'] !== null && ($f['position'] <= 0.25 || $f['position'] >= 0.75)],
                    ['says' => 'structure has not broken recently', 'weight' => 0.5, 'met' => ! $f['event_recent']],
                ],
            ],
        ];
    }
}

<?php

namespace App\Services\Backtest;

use App\Models\Strategy;
use InvalidArgumentException;

/**
 * Parameter Grid
 *
 * Turns a handful of `name=values` specifications into every combination of them.
 *
 * ## Why the size limit is not negotiable
 *
 * A grid is a product, not a sum: five parameters with five values each is 3,125 runs, and each
 * run walks the whole series calling the indicator stack on every bar. Left unbounded, an
 * innocent-looking command becomes an afternoon.
 *
 * The limit is also a hint about method. Sweeping many parameters at once over one series does
 * not find a good strategy; it finds the corner of the grid that best fits this particular
 * stretch of history. Two or three parameters at a time, validated out-of-sample, says
 * something. Ten does not.
 */
final class ParameterGrid
{
    /** Strategy columns worth sweeping. Anything else is either structural or not numeric. */
    public const SWEEPABLE = [
        'ema_fast',
        'ema_slow',
        'adx_threshold',
        'atr_period',
        'sl_atr_multiplier',
        'tp1_pips',
        'tp2_pips',
        'tp3_pips',
        'tp1_close_pct',
        'tp2_close_pct',
        'max_holding_bars',
    ];

    /** @var array<string, array<int, float>> */
    private array $axes = [];

    /**
     * @param  array<int, string>  $specifications  e.g. ["ema_fast=10,20,30", "adx_threshold=20:30:5"]
     */
    public function __construct(array $specifications)
    {
        foreach ($specifications as $specification) {
            [$name, $values] = $this->parse($specification);
            $this->axes[$name] = $values;
        }
    }

    /**
     * @return array<string, array<int, float>>
     */
    public function axes(): array
    {
        return $this->axes;
    }

    public function size(): int
    {
        return array_product(array_map('count', $this->axes)) ?: 0;
    }

    /**
     * Every combination, as name => value maps.
     *
     * @return array<int, array<string, float>>
     */
    public function combinations(): array
    {
        if ($this->axes === []) {
            return [];
        }

        $combinations = [[]];

        foreach ($this->axes as $name => $values) {
            $expanded = [];

            foreach ($combinations as $partial) {
                foreach ($values as $value) {
                    $expanded[] = $partial + [$name => $value];
                }
            }

            $combinations = $expanded;
        }

        // Combinations that contradict the strategy's own structure are dropped rather than
        // run: a fast EMA at or above the slow one inverts every signal, and the ladder has
        // to ascend or TradeManager takes its rungs out of order.
        return array_values(array_filter($combinations, fn (array $c) => $this->coherent($c)));
    }

    /**
     * Apply a combination to a strategy without touching the stored row.
     *
     * `replicate()` returns a model that does not exist in the database, so nothing here can
     * be saved by accident - which matters, because a sweep that quietly rewrote the live
     * strategy would be changing what trades while measuring what does not.
     *
     * @param  array<string, float>  $combination
     */
    public static function apply(Strategy $strategy, array $combination): Strategy
    {
        $candidate = $strategy->replicate();

        foreach ($combination as $name => $value) {
            $candidate->{$name} = $value;
        }

        return $candidate;
    }

    /**
     * @param  array<string, float>  $combination
     */
    private function coherent(array $combination): bool
    {
        $get = fn (string $name, $default) => $combination[$name] ?? $default;

        $fast = $get('ema_fast', null);
        $slow = $get('ema_slow', null);

        if ($fast !== null && $slow !== null && $fast >= $slow) {
            return false;
        }

        $tp1 = $get('tp1_pips', null);
        $tp2 = $get('tp2_pips', null);
        $tp3 = $get('tp3_pips', null);

        if ($tp1 !== null && $tp2 !== null && $tp1 >= $tp2) {
            return false;
        }

        if ($tp2 !== null && $tp3 !== null && $tp2 >= $tp3) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: string, 1: array<int, float>}
     */
    private function parse(string $specification): array
    {
        if (! str_contains($specification, '=')) {
            throw new InvalidArgumentException("Expected name=values, got '{$specification}'.");
        }

        [$name, $rest] = explode('=', $specification, 2);
        $name = trim($name);

        if (! in_array($name, self::SWEEPABLE, true)) {
            throw new InvalidArgumentException(
                "'{$name}' cannot be swept. Try one of: ".implode(', ', self::SWEEPABLE)
            );
        }

        // start:end:step is friendlier than writing out a long list, and less error-prone.
        $values = str_contains($rest, ':')
            ? $this->range($rest)
            : array_map('trim', explode(',', $rest));

        $values = array_values(array_unique(array_map(
            fn ($v) => (float) $v,
            array_filter($values, fn ($v) => $v !== '' && is_numeric($v)),
        )));

        if ($values === []) {
            throw new InvalidArgumentException("No usable values in '{$specification}'.");
        }

        sort($values);

        return [$name, $values];
    }

    /**
     * @return array<int, string>
     */
    private function range(string $spec): array
    {
        $parts = explode(':', $spec);

        if (count($parts) !== 3) {
            throw new InvalidArgumentException("Expected start:end:step, got '{$spec}'.");
        }

        [$start, $end, $step] = array_map('floatval', $parts);

        if ($step <= 0 || $end < $start) {
            throw new InvalidArgumentException("'{$spec}' does not describe an ascending range.");
        }

        $values = [];

        // A small epsilon so an inclusive end is not lost to floating-point drift -
        // 0.1 steps from 1.0 to 2.0 otherwise stops at 1.9.
        for ($v = $start; $v <= $end + 1e-9; $v += $step) {
            $values[] = (string) round($v, 6);
        }

        return $values;
    }
}

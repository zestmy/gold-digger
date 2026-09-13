<?php

namespace App\Services\Trading;

/**
 * Volume Rules
 *
 * The broker's lot grid, in one place: what a size becomes once it is snapped onto the
 * step, and whether what is left is a size the broker will accept.
 *
 * ## Why this is not left to the terminal
 *
 * `CFXSExecutor::NormalizeVolume` already does this arithmetic, and for a while that was
 * the argument for the dashboard not doing it: only the terminal knows the step, and
 * rounding in two places could round twice. But the executor does not only round down -
 *
 *     double snapped = MathFloor(volume / m_vol_step) * m_vol_step;
 *     if(snapped < m_vol_min) snapped = m_vol_min;     // <- upward, past the risk setting
 *
 * - and that clamp is the whole problem. A size the risk setting worked out as 0.004 lots
 * is not refused, it is *raised* to the broker's minimum, typically 0.01. The account then
 * carries two and a half times the risk that was asked for, on the trades where it can
 * least afford it, and nothing in the signal, the command or the backtest says so.
 *
 * So this snaps down, and where the executor would inflate, this returns null and the
 * caller declines. Rounding twice is harmless when both roundings are `floor` onto the
 * same grid; silently trading more than the setting allows is not.
 *
 * The copier already worked this way - `SignalExecutor`, `FollowUpExecutor` and
 * `PositionManager` each grew their own copy of these four lines. This is the one they
 * share, and the one the strategy path and the backtester now use, so all four cannot
 * disagree about what the grid is.
 */
final class VolumeRules
{
    /**
     * Every broker's grid, when nobody has told us otherwise.
     *
     * 0.01 is the near-universal minimum and step for a retail account. It is a fallback,
     * not a truth: the terminal reports both on the heartbeat, and `SymbolResolver` prefers
     * what it said.
     */
    public const DEFAULT_STEP = 0.01;

    public const DEFAULT_MIN = 0.01;

    /**
     * A volume snapped down onto the broker's step.
     *
     * The 1e-9 is float dust, not tolerance: 0.03 / 0.01 is 2.9999999999999996 in binary
     * floating point, and a bare floor turns a legal 0.03 into 0.02.
     */
    public static function snap(float $lots, ?float $step = null): float
    {
        $step = $step !== null && $step > 0.0 ? $step : self::DEFAULT_STEP;

        $snapped = floor(($lots + 1e-9) / $step) * $step;

        // Back onto the grid's own precision, so 0.06999999999999999 is not what reaches
        // the wire or an assertion.
        return round(max(0.0, $snapped), self::precision($step));
    }

    /**
     * The volume that would actually trade, or null when the broker would not accept it.
     *
     * Null is the answer that matters. The caller records why - `below_min_volume` in the
     * strategy path, the same reason in a backtest's declined column - which is a visible
     * fault. The alternative, letting the terminal round the position up to something
     * tradeable, is an invisible one.
     */
    public static function tradeable(float $lots, ?float $step = null, ?float $min = null): ?float
    {
        $snapped = self::snap($lots, $step);
        $min = $min !== null && $min > 0.0 ? $min : self::DEFAULT_MIN;

        // Compared with the same dust guard the snap uses: a snapped 0.01 can arrive as
        // 0.009999999999999998 against a minimum of 0.01.
        return $snapped + 1e-9 >= $min ? $snapped : null;
    }

    /**
     * Decimal places in a step, so a snapped volume can be rounded back onto it.
     *
     * Counted from how the step is written, not from its order of magnitude. A 0.15 step has
     * two decimals where `-log10(0.15)` suggests one, and rounding a snapped 0.15 to one
     * decimal returns 0.2 - larger than the size that was passed in, which is the single
     * thing this class exists to never do.
     */
    private static function precision(float $step): int
    {
        $written = rtrim(rtrim(number_format($step, 8, '.', ''), '0'), '.');
        $point = strpos($written, '.');

        return $point === false ? 0 : strlen($written) - $point - 1;
    }
}

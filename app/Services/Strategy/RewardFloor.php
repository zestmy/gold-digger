<?php

namespace App\Services\Strategy;

use App\Models\BotSettings;

/**
 * Reward Floor
 *
 * Whether a trade offers enough to be worth the risk, and one definition of "enough".
 *
 * ## Why this exists at all
 *
 * The ratio was already computed in three places - `MarketScanner`, `SignalPlan` and
 * `ChartAnalyst` - displayed on two pages, stored on two tables, and put in front of the
 * reviewer model. What none of them did was refuse anything. A copied signal offering to
 * risk three to make one cleared every gate this system has, because no gate looked.
 *
 * ## Measured to the exit the order actually carries
 *
 * This is the part that is easy to get wrong and expensive to get wrong quietly. A signal
 * with three targets has three ratios, and only the last one describes the trade: the
 * copier takes no partials at the intermediate rungs, so the position runs to the final
 * take-profit or to the stop. Judging a 1:3 signal on its first rung would pass trades on
 * the strength of a level the order never exits at.
 *
 * `SignalReviewer` already says this in the brief it writes - "the only take-profit the
 * order carries" - so measuring it any other way here would put the gate and the
 * explanation in disagreement about the same trade.
 *
 * ## Off unless somebody turns it on
 *
 * `floorFor()` returns null when nothing is configured, and null means every ratio passes.
 * That is deliberate rather than timid: switching a floor on by default would start
 * refusing trades that currently execute, on a live copier, on the strength of a migration
 * nobody read. Whether 1.5R is a sensible bar depends on a win rate this project has not
 * measured, and plenty of profitable books run below 1R.
 *
 * So the platform default is none, the tenant sets their own, and turning it on is a
 * decision somebody makes rather than one that happens to them.
 */
final class RewardFloor
{
    /** The skip reason recorded when a setup is refused for this. */
    public const OBJECTION = 'reward_below_floor';

    /**
     * Reward divided by risk, or null when the question does not apply.
     *
     * Distances rather than prices, so this works equally for the strategy path - which
     * thinks in pips off a symbol spec - and the copier path, which thinks in absolute
     * levels. Both reduce to two lengths.
     */
    public function ratio(?float $risk, ?float $reward): ?float
    {
        // A zero or missing stop distance is not a trade with infinite reward; it is a
        // trade whose risk is unknown, and dividing by it would produce a number that
        // passes every floor there is.
        if ($risk === null || $risk <= 0.0 || $reward === null || $reward <= 0.0) {
            return null;
        }

        return round($reward / $risk, 4);
    }

    /**
     * The bar this account holds trades to, or null for none.
     *
     * Tenant setting first, then the platform default from config. Zero and null both mean
     * no floor - a floor of zero is one every trade clears, so there is nothing to be
     * gained by distinguishing them and a `> 0` check reads more plainly than a null one
     * at every call site.
     */
    public function floorFor(?BotSettings $settings): ?float
    {
        $configured = $settings?->min_reward_ratio;

        if ($configured === null) {
            $configured = config('trading.min_reward_ratio');
        }

        $floor = is_numeric($configured) ? (float) $configured : 0.0;

        return $floor > 0.0 ? $floor : null;
    }

    /**
     * Why this trade should not be taken, or null if it should.
     *
     * Returns the skip reason rather than a boolean, so the strategy path can record it
     * beside every other objection and `/signals` can explain a refusal in the same
     * vocabulary as `adx_below_threshold`.
     */
    public function objection(?BotSettings $settings, ?float $risk, ?float $reward): ?string
    {
        $floor = $this->floorFor($settings);

        if ($floor === null) {
            return null;
        }

        $ratio = $this->ratio($risk, $reward);

        // An unmeasurable ratio is not a passing one. A signal with no target names no
        // reward, and a floor that waved those through would be enforced only against the
        // trades that bothered to state their case.
        if ($ratio === null) {
            return self::OBJECTION;
        }

        return $ratio < $floor ? self::OBJECTION : null;
    }

    /**
     * The refusal in words, for a card a person reads.
     */
    public function explain(?BotSettings $settings, ?float $risk, ?float $reward): string
    {
        $floor = $this->floorFor($settings);
        $ratio = $this->ratio($risk, $reward);

        if ($ratio === null) {
            return sprintf(
                'No measurable reward against risk, and this account requires at least %s : 1.',
                $this->trim($floor ?? 0.0),
            );
        }

        return sprintf(
            'Offers %s : 1 against a floor of %s : 1.',
            $this->trim($ratio),
            $this->trim($floor ?? 0.0),
        );
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

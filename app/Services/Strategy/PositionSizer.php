<?php

namespace App\Services\Strategy;

/**
 * Position Sizer
 *
 * Converts a risk percentage and a stop distance into a lot size.
 *
 *     lots = (balance * risk%) / (stop distance in pips * value of one pip per lot)
 *
 * The point of risk-based sizing is that every trade loses the same *amount* when it is
 * wrong, regardless of how wide its stop is. A fixed lot size does the opposite: a wide
 * ATR stop on a volatile day loses several times what a quiet day's trade loses, so the
 * strategy's worst losses cluster exactly where they hurt most.
 *
 * ## Why pip value comes from the terminal
 *
 * `pip_value_per_lot` arrives on the heartbeat. It depends on contract size, tick value
 * and the account's deposit currency, and getting it wrong does not fail loudly - it
 * trades a size nobody chose, in the right direction, and looks fine until the loss
 * arrives. There is no default here for that reason: absent the value, this returns null
 * and the caller records the signal unexecuted rather than guessing gold is $10 a pip.
 *
 * ## Rounding
 *
 * The result is deliberately *not* snapped to the broker's volume step. Only the terminal
 * knows the step, and CGDExecutor::NormalizeVolume already snaps downward - rounding here
 * as well would round twice and could round up into more risk than the setting allows.
 */
final class PositionSizer
{
    /**
     * Lots to trade, or null when the inputs cannot support an honest answer.
     *
     * @param  float  $balance  Account balance in the deposit currency
     * @param  float  $riskPercentage  Percent of balance to risk, e.g. 1.0 for 1%
     * @param  float  $stopPips  Distance from entry to stop, in pips
     * @param  float|null  $pipValuePerLot  Deposit-currency value of a one-pip move on one lot
     */
    public function size(float $balance, float $riskPercentage, float $stopPips, ?float $pipValuePerLot): ?float
    {
        if ($pipValuePerLot === null || $pipValuePerLot <= 0.0) {
            return null;
        }

        if ($balance <= 0.0 || $riskPercentage <= 0.0 || $stopPips <= 0.0) {
            return null;
        }

        $riskMoney = $balance * ($riskPercentage / 100.0);
        $lossPerLot = $stopPips * $pipValuePerLot;

        $lots = $riskMoney / $lossPerLot;

        // Four decimals matches signals.suggested_lot_size. Below that the number is
        // smaller than any broker's minimum volume anyway.
        $lots = round($lots, 4);

        return $lots > 0.0 ? $lots : null;
    }
}

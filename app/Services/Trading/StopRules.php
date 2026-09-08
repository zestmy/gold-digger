<?php

namespace App\Services\Trading;

use App\Models\Trade;

/**
 * Stop Rules
 *
 * The arithmetic every engine that moves a stop has to agree on. There were two engines -
 * TradeManager for the strategy's own positions, PositionManager for copied ones - and
 * each had its own copy of these three rules. They disagreed, and every disagreement was
 * a bug in one of them that the other had already fixed:
 *
 * - TradeManager learned that a recorded stop of zero is "no stop", not a price below
 *   every level a sell could be moved to. PositionManager still compared it as a price,
 *   so a copied sell whose fill never carried a stop was never protected.
 * - TradeManager learned to bucket a trail's idempotency key in the instrument's own pip,
 *   because rounding to two decimals gave every trail on a five-digit pair the same key
 *   and only the first was ever sent. PositionManager still rounded to two decimals.
 * - PositionManager learned that a padded break-even stop can land past the market, where
 *   the broker refuses it or fills it as an immediate exit. TradeManager had no guard.
 *
 * One implementation, used by both, so the next lesson is learned once.
 */
final class StopRules
{
    /**
     * Does this level move the stop toward profit by more than a rounding error?
     *
     * A stop only ever moves one way: loosening it would widen the risk on a position
     * whose risk was decided when it opened, and no rule in this system is allowed to do
     * that. A null or zero recorded stop is "no stop", and any level improves on it.
     *
     * The tolerance is a twentieth of a pip in the instrument's own pip. Without a pip
     * size it is the gold figure this used to hardcode - and nothing trails without one,
     * so that path reaches here only for break-even.
     */
    public function tightens(Trade $trade, float $level, ?float $pipSize): bool
    {
        if ($trade->sl_price === null || (float) $trade->sl_price <= 0.0) {
            return true;
        }

        $current = (float) $trade->sl_price;
        $epsilon = $pipSize !== null && $pipSize > 0 ? 0.05 * $pipSize : 0.005;

        return $this->isBuy($trade)
            ? $level > $current + $epsilon
            : $level < $current - $epsilon;
    }

    /**
     * A price rounded to the nearest whole pip, rendered with a pip's worth of decimals,
     * for an idempotency key.
     *
     * Two proposals that round to the same stop are the same instruction. The rendering
     * matters because it goes into a key: 1.1033 and 1.10330 have to be the same string,
     * so the decimal count comes from the pip size - four for 0.0001, one for 0.1. With
     * no pip size the old two-decimal rendering is kept as a fallback.
     */
    public function bucket(float $level, ?float $pipSize): string
    {
        if ($pipSize === null || $pipSize <= 0) {
            return number_format($level, 2, '.', '');
        }

        $decimals = max(0, (int) ceil(-log10($pipSize) - 1e-9));

        return number_format(round($level / $pipSize) * $pipSize, $decimals, '.', '');
    }

    /**
     * Where a break-even stop actually goes.
     *
     * The entry plus an offset in the profitable direction. Closing at the entry exactly
     * is not breaking even: the position has already paid the spread it crossed to get
     * in, and it still owes commission both ways. The offset is what makes the phrase
     * true. Zero, or no pip size to place it in price, means the entry.
     *
     * A padded stop has to stay behind the market on both readings that exist: the best
     * price the position ever saw, and the last price it is at now. Past either one it is
     * a stop on the wrong side of price, which the broker refuses outright or fills as an
     * immediate exit - turning a protective move into a close. Both readings are needed:
     * the best alone misses a position that has run far and retraced; the last alone
     * misses one whose padding was never earned. Beyond the market, the stop goes to the
     * entry instead.
     *
     * @param  float|null  $best  The best price since entry, from closed bars
     * @param  float|null  $last  The most recent close
     */
    public function breakEven(Trade $trade, float $offsetPips, ?float $pipSize, ?float $best, ?float $last): float
    {
        $entry = (float) $trade->entry_price;

        if ($offsetPips <= 0.0 || $pipSize === null || $pipSize <= 0.0) {
            return $entry;
        }

        $isBuy = $this->isBuy($trade);
        $padded = $entry + (($isBuy ? 1.0 : -1.0) * $offsetPips * $pipSize);

        $readings = array_values(array_filter([$best, $last], static fn (?float $p) => $p !== null));

        if ($readings === []) {
            return $padded;
        }

        $limit = $isBuy ? min($readings) : max($readings);
        $beyondTheMarket = $isBuy ? $padded >= $limit : $padded <= $limit;

        return $beyondTheMarket ? $entry : $padded;
    }

    private function isBuy(Trade $trade): bool
    {
        return strtolower((string) $trade->direction) === 'buy';
    }
}

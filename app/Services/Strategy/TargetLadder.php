<?php

namespace App\Services\Strategy;

use App\Models\Strategy;

/**
 * Target Ladder
 *
 * The three take-profit levels a strategy puts on a setup, and the one target the order
 * actually carries.
 *
 * ## One place, because two disagreed
 *
 * The generator and the backtester each built the ladder for themselves. The same
 * arithmetic twice is how a backtest ends up measuring a ladder the live strategy does
 * not place, and the walk-forward numbers stop being about anything.
 *
 * ## Why targets are in R
 *
 * The stop is ATR-sized: a volatility-aware distance. A target in fixed pips against it
 * means the reward the ladder offers swings with volatility, and nobody chose it. The
 * first month of outcome tracking put a number on that: the first target sat about 0.6R
 * from entry and was reached half the time - 50% at 0.6R loses money. A rung in R is the
 * same trade in a quiet market and a wild one, and it can be compared across instruments.
 *
 * Pips remain for a strategy that sets no `tp1_r`: the pip columns then mean what they
 * always did, including needing the terminal's pip size to become a price.
 */
final class TargetLadder
{
    public const UNIT_R = 'r';

    public const UNIT_PIPS = 'pips';

    /**
     * @param  float  $sign  +1 for a buy, -1 for a sell
     * @param  float  $stopDistance  entry to stop, in price
     * @param  float|null  $pipSize  the instrument's pip, if the terminal has reported it
     * @return array{unit: string, tp1_price: float|null, tp2_price: float|null, tp3_price: float|null, order_tp_pips: float|null}
     */
    public function prices(Strategy $strategy, float $entry, float $sign, float $stopDistance, ?float $pipSize): array
    {
        if ($this->inR($strategy)) {
            return $this->fromR($strategy, $entry, $sign, $stopDistance, $pipSize);
        }

        return $this->fromPips($strategy, $entry, $sign, $pipSize);
    }

    public function inR(Strategy $strategy): bool
    {
        return $strategy->tp1_r !== null && (float) $strategy->tp1_r > 0.0;
    }

    /**
     * The final rung: the one the order carries as its broker-side take profit.
     */
    public function finalR(Strategy $strategy): ?float
    {
        if (! $this->inR($strategy)) {
            return null;
        }

        return $strategy->tp3_r !== null ? (float) $strategy->tp3_r : (float) $strategy->tp2_r;
    }

    /**
     * @return array{unit: string, tp1_price: float|null, tp2_price: float|null, tp3_price: float|null, order_tp_pips: float|null}
     */
    private function fromR(Strategy $strategy, float $entry, float $sign, float $stopDistance, ?float $pipSize): array
    {
        $level = static fn (?float $r): ?float => $r === null || $r <= 0.0
            ? null
            : round($entry + ($sign * $r * $stopDistance), 5);

        $final = $this->finalR($strategy);

        return [
            'unit' => self::UNIT_R,
            'tp1_price' => $level((float) $strategy->tp1_r),
            'tp2_price' => $level($strategy->tp2_r !== null ? (float) $strategy->tp2_r : null),
            'tp3_price' => $level($strategy->tp3_r !== null ? (float) $strategy->tp3_r : null),
            // The order's own target, as pips for the wire. Needs the pip size like every
            // pip figure does; the price levels above do not, which is the other thing R
            // buys - a signal can name its targets before the terminal has said what a
            // pip is.
            'order_tp_pips' => ($pipSize !== null && $pipSize > 0.0 && $final !== null)
                ? round($final * $stopDistance / $pipSize, 2)
                : null,
        ];
    }

    /**
     * @return array{unit: string, tp1_price: float|null, tp2_price: float|null, tp3_price: float|null, order_tp_pips: float|null}
     */
    private function fromPips(Strategy $strategy, float $entry, float $sign, ?float $pipSize): array
    {
        $finalPips = $strategy->tp3_pips !== null
            ? (float) $strategy->tp3_pips
            : (float) $strategy->tp2_pips;

        // Turning pips into a price without the terminal's pip size is precisely the
        // guess the pip trap punishes.
        if ($pipSize === null || $pipSize <= 0.0) {
            return [
                'unit' => self::UNIT_PIPS,
                'tp1_price' => null,
                'tp2_price' => null,
                'tp3_price' => null,
                'order_tp_pips' => null,
            ];
        }

        $level = static fn (?float $pips): ?float => $pips === null
            ? null
            : round($entry + ($sign * $pips * $pipSize), 5);

        return [
            'unit' => self::UNIT_PIPS,
            'tp1_price' => $level((float) $strategy->tp1_pips),
            'tp2_price' => $level((float) $strategy->tp2_pips),
            'tp3_price' => $level($strategy->tp3_pips !== null ? (float) $strategy->tp3_pips : null),
            'order_tp_pips' => $finalPips,
        ];
    }
}

<?php

namespace App\Services\Backtest;

use App\Models\BotHeartbeat;

/**
 * Market Assumptions
 *
 * Every cost and friction the simulation applies, in one place, because these are the numbers
 * that decide whether a backtest is useful or flattering.
 *
 * A backtest with no spread, no slippage and fills exactly at the target will show a profit for
 * almost any strategy. The defaults here are deliberately pessimistic: the point of running one
 * is to find out whether an edge survives contact with costs, and a result that looks worse
 * than reality is recoverable in a way that the opposite is not.
 *
 * ## Candle prices are treated as bid
 *
 * Which is what MT5 charts show. A buy therefore enters at bid + spread and exits at bid; a
 * sell enters at bid and exits at bid + spread. The spread is paid once per round trip, on the
 * side that actually crosses it, rather than being halved at both ends - the same total, but it
 * lands where it really lands.
 */
final readonly class MarketAssumptions
{
    /**
     * @param  float  $pipSize  Price movement of one pip. Gold: 0.10
     * @param  float  $pipValuePerLot  Account-currency value of a one-pip move on one lot
     * @param  float  $pointSize  Broker point, used to read spread_points off a candle
     * @param  float|null  $spreadPips  Fixed spread, or null to read each bar's own
     * @param  float  $slippagePips  Adverse slippage on every market order
     * @param  float  $commissionPerLot  Charged per lot per side
     * @param  float  $startingBalance  What the account starts with
     */
    public function __construct(
        public float $pipSize = 0.10,
        public float $pipValuePerLot = 10.0,
        public float $pointSize = 0.01,
        public ?float $spreadPips = null,
        public float $slippagePips = 0.3,
        public float $commissionPerLot = 7.0,
        public float $startingBalance = 10000.0,
    ) {}

    /**
     * Build from what the terminal has actually reported, falling back to gold defaults.
     *
     * Using the live symbol specification matters: a backtest run with a pip value the broker
     * does not use is measuring a different instrument, and position sizing is a division by
     * exactly that number.
     */
    public static function fromHeartbeat(?BotHeartbeat $heartbeat, array $overrides = []): self
    {
        $pipSize = $overrides['pipSize']
            ?? ($heartbeat?->pip_size !== null ? (float) $heartbeat->pip_size : 0.10);

        return new self(
            pipSize: $pipSize,
            pipValuePerLot: $overrides['pipValuePerLot']
                ?? ($heartbeat?->pip_value_per_lot !== null ? (float) $heartbeat->pip_value_per_lot : 10.0),
            // Conventionally a pip is ten points - true for gold quoted to 2 digits and for
            // a 5-digit FX pair alike. Overridable because "conventionally" is not "always".
            pointSize: $overrides['pointSize'] ?? ($pipSize / 10),
            spreadPips: $overrides['spreadPips'] ?? null,
            slippagePips: $overrides['slippagePips'] ?? 0.3,
            commissionPerLot: $overrides['commissionPerLot'] ?? 7.0,
            startingBalance: $overrides['startingBalance'] ?? 10000.0,
        );
    }

    /**
     * Spread for a bar, in pips.
     *
     * Prefers the bar's own recorded spread - which is why `candles.spread_points` is stored -
     * and falls back to the configured figure. A fixed spread across a backtest hides the fact
     * that spreads widen exactly when a strategy is most likely to be triggering.
     */
    public function spreadPipsFor(?float $spreadPoints): float
    {
        if ($this->spreadPips !== null) {
            return $this->spreadPips;
        }

        if ($spreadPoints === null || $spreadPoints <= 0 || $this->pipSize <= 0) {
            // No recorded spread and none configured. Two pips on gold is a normal quiet
            // market; assuming zero would be the flattering choice.
            return 2.0;
        }

        return ($spreadPoints * $this->pointSize) / $this->pipSize;
    }

    public function pipsToPrice(float $pips): float
    {
        return $pips * $this->pipSize;
    }

    public function priceToPips(float $price): float
    {
        return $this->pipSize > 0 ? $price / $this->pipSize : 0.0;
    }

    /**
     * Money value of a pip move on a given volume.
     */
    public function money(float $pips, float $lots): float
    {
        return $pips * $this->pipValuePerLot * $lots;
    }
}

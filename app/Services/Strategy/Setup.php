<?php

namespace App\Services\Strategy;

use Carbon\CarbonInterface;

/**
 * Setup
 *
 * An entry condition the strategy's rules recognised on a specific bar, before any
 * risk or session filter has had a say.
 *
 * Separating "the rules fired" from "we are allowed to trade it" is what makes the
 * signals table worth having. A skipped setup is still recorded, with the reason, so
 * the question the schema was designed to answer - were the filters too strict? - has
 * data behind it. Collapsing the two would only ever record trades that were taken.
 */
final readonly class Setup
{
    /**
     * @param  'buy'|'sell'  $direction
     * @param  array<string, float|int|string|null>  $features  Indicator readings at signal time, stored as signals.features
     */
    public function __construct(
        public string $direction,
        public float $entryPrice,
        public float $atr,
        public float $adx,
        public CarbonInterface $barTime,
        public array $features,
    ) {}

    public function isBuy(): bool
    {
        return $this->direction === 'buy';
    }

    /**
     * Price sign for the direction: targets sit above entry on a buy, below on a sell.
     */
    public function sign(): float
    {
        return $this->isBuy() ? 1.0 : -1.0;
    }
}

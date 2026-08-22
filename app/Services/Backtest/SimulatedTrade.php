<?php

namespace App\Services\Backtest;

use Carbon\CarbonInterface;

/**
 * Simulated Trade
 *
 * One position during a backtest walk, and the record of it afterwards.
 *
 * Mirrors the shape of a real `trades` row plus its `trade_partials`, because the whole value
 * of the exercise is that the simulated ladder behaves the way TradeManager actually behaves -
 * rungs detected on bar close and filled at market, the final target sitting on the order as a
 * broker-side limit, and the stop moving to break-even once the first rung fills.
 */
final class SimulatedTrade
{
    /** @var array<int, array{reason: string, lots: float, price: float, pips: float, money: float, at: CarbonInterface}> */
    public array $closes = [];

    public float $remainingLots;

    public ?CarbonInterface $closedAt = null;

    public ?string $closureReason = null;

    /** Realised profit and loss, net of commission, in account currency. */
    public float $netPnl = 0.0;

    public float $grossPnl = 0.0;

    public float $costs = 0.0;

    /** Bars the position has been held, used for the holding-time exit. */
    public int $barsHeld = 0;

    public bool $breakEven = false;

    public bool $trailing = false;

    /**
     * The most favourable price reached since entry.
     *
     * A trailing stop follows this rather than the latest close. Following the close would
     * loosen the stop on every pullback, which is not a trailing stop but a drifting one.
     */
    public ?float $bestPrice = null;

    public function __construct(
        public readonly string $direction,
        public readonly float $entryPrice,
        public readonly float $lots,
        public float $stopPrice,
        public readonly ?float $tp1,
        public readonly ?float $tp2,
        public readonly ?float $tp3,
        public readonly float $stopPips,
        public readonly CarbonInterface $openedAt,
        public readonly array $features = [],
    ) {
        $this->remainingLots = $lots;
    }

    /**
     * Record how far the bar ran in the position's favour.
     */
    public function observe(float $high, float $low): void
    {
        $extreme = $this->isBuy() ? $high : $low;

        if ($this->bestPrice === null) {
            $this->bestPrice = $extreme;

            return;
        }

        $this->bestPrice = $this->isBuy()
            ? max($this->bestPrice, $extreme)
            : min($this->bestPrice, $extreme);
    }

    public function isBuy(): bool
    {
        return $this->direction === 'buy';
    }

    public function isOpen(): bool
    {
        return $this->remainingLots > 0.00005;
    }

    /**
     * The level sitting on the order itself.
     *
     * TP3 when the strategy has one, otherwise TP2 - the same rule SignalGenerator uses when
     * it decides what to put on the order, and the reason TradeManager does not ladder the
     * final rung.
     */
    public function finalTarget(): ?float
    {
        return $this->tp3 ?? $this->tp2;
    }

    /**
     * Rungs taken so far, so the same one is never taken twice.
     *
     * @return array<int, string>
     */
    public function filledRungs(): array
    {
        return array_column($this->closes, 'reason');
    }

    public function hasFilled(string $rung): bool
    {
        return in_array($rung, $this->filledRungs(), true);
    }

    /**
     * Record a close of part or all of the position.
     */
    public function close(string $reason, float $lots, float $price, float $pips, float $money, float $costs, CarbonInterface $at): void
    {
        $lots = min($lots, $this->remainingLots);

        $this->closes[] = [
            'reason' => $reason,
            'lots' => round($lots, 4),
            'price' => round($price, 5),
            'pips' => round($pips, 2),
            'money' => round($money, 2),
            'at' => $at,
        ];

        $this->remainingLots = round($this->remainingLots - $lots, 4);

        $this->grossPnl += $money;
        $this->costs += $costs;
        $this->netPnl += $money - $costs;

        if (! $this->isOpen()) {
            $this->closedAt = $at;
            // The reason the position ended is the last one, which is what analytics groups by.
            $this->closureReason = $reason;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction,
            'opened_at' => $this->openedAt->toDateTimeString(),
            'closed_at' => $this->closedAt?->toDateTimeString(),
            'entry_price' => round($this->entryPrice, 5),
            'lots' => $this->lots,
            'stop_pips' => $this->stopPips,
            'closure_reason' => $this->closureReason,
            'gross_pnl' => round($this->grossPnl, 2),
            'costs' => round($this->costs, 2),
            'net_pnl' => round($this->netPnl, 2),
            'bars_held' => $this->barsHeld,
            'rungs' => $this->filledRungs(),
            'trailed' => $this->trailing,
        ];
    }
}

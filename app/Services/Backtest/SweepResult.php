<?php

namespace App\Services\Backtest;

/**
 * Sweep Result
 *
 * One combination's outcome, and whether it is worth believing.
 */
final class SweepResult
{
    public readonly bool $qualifies;

    public readonly float $score;

    /**
     * @param  array<string, float>  $parameters
     * @param  array<string, float|int>  $metrics
     */
    public function __construct(
        public readonly array $parameters,
        public readonly array $metrics,
        int $minTrades,
    ) {
        $this->qualifies = ($metrics['trades'] ?? 0) >= $minTrades;
        $this->score = $this->recoveryFactor();
    }

    /**
     * Return measured against the worst fall it had to survive.
     *
     * Net profit on its own cannot tell doubling an account through a 60% drawdown from half
     * that return through 5%, and the second is the one somebody could actually have held.
     *
     * A drawdown floor of 1% of the return keeps a run that never drew down from dividing by
     * something near zero and scoring as infinitely good - which, on a short sample, usually
     * means it took two trades.
     */
    private function recoveryFactor(): float
    {
        $net = (float) ($this->metrics['net_pnl'] ?? 0);
        $drawdown = (float) ($this->metrics['max_drawdown'] ?? 0);

        if ($net <= 0) {
            // A losing combination is ordered by how much it lost. No amount of smoothness
            // makes a negative expectancy worth ranking above another.
            return $net;
        }

        return round($net / max($drawdown, abs($net) * 0.01, 0.01), 3);
    }

    public function value(string $metric): float
    {
        return match ($metric) {
            'score' => $this->score,
            default => (float) ($this->metrics[$metric] ?? 0),
        };
    }

    /**
     * The combination as a short, stable string - used to compare winners across metrics.
     */
    public function label(): string
    {
        $parts = [];

        foreach ($this->parameters as $name => $value) {
            $parts[] = $name.'='.(floor($value) == $value ? (int) $value : $value);
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'parameters' => $this->parameters,
            'qualifies' => $this->qualifies,
            'score' => $this->score,
            'metrics' => $this->metrics,
        ];
    }
}

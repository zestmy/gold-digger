<?php

namespace App\Services\Backtest;

use App\Models\Strategy;

/**
 * Walk-Forward Report
 *
 * The folds, and what they say together.
 *
 * The headline is not the aggregate profit. It is the relationship between the in-sample and
 * out-of-sample results, and whether the winning parameters held still. Those two answer the
 * only question worth asking of an optimisation: is there anything here, or did the grid just
 * fit the sample?
 */
final class WalkForwardReport
{
    /** @var array<int, array<string, mixed>> */
    public array $folds = [];

    /** @var array<int, string> */
    public array $notes = [];

    /**
     * Out-of-sample trades below which no verdict is offered.
     *
     * Not a statistical threshold so much as a floor on embarrassment: below this, the
     * difference between a good result and a bad one is which way a few trades happened to go.
     */
    public const MIN_MEANINGFUL_TRADES = 20;

    public function __construct(
        public readonly Strategy $strategy,
        public readonly int $requestedFolds,
        public readonly array $combinations,
    ) {}

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }

    /**
     * @param  array<string, float>  $parameters
     * @param  array<string, float|int>  $inSample
     * @param  array<string, float|int>|null  $outOfSample  Null when no combination qualified
     * @param  array<string, string|int>  $window
     */
    public function addFold(int $fold, array $parameters, array $inSample, ?array $outOfSample, array $window): void
    {
        $this->folds[] = [
            'fold' => $fold,
            'parameters' => $parameters,
            'in_sample' => $inSample,
            'out_of_sample' => $outOfSample,
            'window' => $window,
        ];
    }

    /**
     * Folds that produced a genuine out-of-sample result.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tested(): array
    {
        return array_values(array_filter($this->folds, fn (array $f) => $f['out_of_sample'] !== null));
    }

    /**
     * The out-of-sample record, stitched across folds.
     *
     * This is the closest history can come to an estimate of live behaviour: in every fold the
     * parameters were chosen without reference to the bars they were then run on.
     *
     * @return array<string, float|int>
     */
    public function outOfSample(): array
    {
        $tested = $this->tested();

        if ($tested === []) {
            return ['trades' => 0, 'net_pnl' => 0.0, 'win_rate' => 0.0, 'profit_factor' => 0.0, 'expectancy' => 0.0];
        }

        $trades = 0;
        $net = 0.0;
        $wins = 0.0;

        foreach ($tested as $fold) {
            $m = $fold['out_of_sample'];
            $trades += (int) $m['trades'];
            $net += (float) $m['net_pnl'];
            // Reconstructed from the rate, because the folds report percentages rather than
            // counts. Rounded back to whole trades at the end.
            $wins += ((float) $m['win_rate'] / 100) * (int) $m['trades'];
        }

        return [
            'trades' => $trades,
            'net_pnl' => round($net, 2),
            'win_rate' => $trades > 0 ? round(($wins / $trades) * 100, 1) : 0.0,
            'expectancy' => $trades > 0 ? round($net / $trades, 2) : 0.0,
            'folds_tested' => count($tested),
            'folds_profitable' => count(array_filter($tested, fn (array $f) => (float) $f['out_of_sample']['net_pnl'] > 0)),
        ];
    }

    /**
     * @return array<string, float>
     */
    public function inSample(): array
    {
        $tested = $this->tested();

        if ($tested === []) {
            return ['net_pnl' => 0.0, 'expectancy' => 0.0];
        }

        $net = array_sum(array_map(fn (array $f) => (float) $f['in_sample']['net_pnl'], $tested));
        $trades = array_sum(array_map(fn (array $f) => (int) $f['in_sample']['trades'], $tested));

        return [
            'net_pnl' => round($net, 2),
            'trades' => $trades,
            'expectancy' => $trades > 0 ? round($net / $trades, 2) : 0.0,
        ];
    }

    /**
     * How much of the optimised performance survived contact with unseen bars.
     *
     * Compared on expectancy per trade rather than on totals, because the two windows contain
     * different numbers of trades and comparing their sums would mostly measure that.
     *
     * @return array<string, mixed>
     */
    public function degradation(): array
    {
        $in = $this->inSample();
        $out = $this->outOfSample();

        $inExp = (float) ($in['expectancy'] ?? 0);
        $outExp = (float) ($out['expectancy'] ?? 0);

        $retained = $inExp > 0 ? round(($outExp / $inExp) * 100, 1) : null;

        return [
            'in_sample_expectancy' => $inExp,
            'out_of_sample_expectancy' => $outExp,
            'retained_pct' => $retained,
            'verdict' => $this->verdict($inExp, $outExp, $out),
        ];
    }

    /**
     * A plain reading of the numbers.
     *
     * Deliberately blunt, and deliberately biased toward "not proven". The failure mode this
     * whole exercise guards against is somebody reading an optimisation as a discovery.
     */
    private function verdict(float $inExp, float $outExp, array $out): string
    {
        $trades = (int) ($out['trades'] ?? 0);
        $folds = (int) ($out['folds_tested'] ?? 0);

        if ($trades === 0) {
            return 'No out-of-sample trades. Nothing was tested, so nothing was learned.';
        }

        // Checked before anything about the sign of the result. Run against real bars, an
        // early version of this reported "most of the edge survived" from a single
        // out-of-sample trade - which is precisely the over-claim the whole exercise exists
        // to prevent. A handful of trades cannot distinguish an edge from a coin.
        if ($trades < self::MIN_MEANINGFUL_TRADES) {
            return sprintf(
                'Only %d out-of-sample trade%s across %d fold%s - too few to conclude anything, '
                .'in either direction. Needs a longer series before the result means anything.',
                $trades,
                $trades === 1 ? '' : 's',
                $folds,
                $folds === 1 ? '' : 's',
            );
        }

        if ($outExp <= 0) {
            return 'Out-of-sample expectancy is not positive. The optimisation fitted this sample '
                .'and did not generalise - which is the normal result, and the reason to run this.';
        }

        if ($inExp <= 0) {
            return 'Out-of-sample beat in-sample, which usually means too few trades rather than a '
                .'discovery. Treat with suspicion.';
        }

        $retained = ($outExp / $inExp) * 100;

        if ($retained >= 60) {
            return 'Most of the optimised edge survived on unseen bars. Worth taking further - '
                .'on a longer series, and then on a demo account.';
        }

        if ($retained >= 25) {
            return 'Some of the edge survived, but most did not. Consistent with a weak real effect '
                .'and a lot of fitting.';
        }

        return 'Very little survived. Treat the optimised parameters as fitted to the sample.';
    }

    /**
     * Did the winning parameters hold still across folds?
     *
     * Parameters that jump around fold to fold mean there is no stable optimum - the surface is
     * flat and the search is following noise. Stability is weaker evidence than out-of-sample
     * performance, but instability is strong evidence against.
     *
     * @return array<string, mixed>
     */
    public function stability(): array
    {
        $tested = $this->tested();

        if (count($tested) < 2) {
            return ['stable' => null, 'per_parameter' => []];
        }

        $perParameter = [];

        foreach (array_keys($tested[0]['parameters']) as $name) {
            $values = array_map(fn (array $f) => $f['parameters'][$name] ?? null, $tested);
            $distinct = array_values(array_unique($values));

            $perParameter[$name] = [
                'values' => $values,
                'distinct' => count($distinct),
                'stable' => count($distinct) === 1,
            ];
        }

        return [
            'stable' => ! in_array(false, array_column($perParameter, 'stable'), true),
            'per_parameter' => $perParameter,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'strategy' => ['id' => $this->strategy->id, 'name' => $this->strategy->name],
            'folds_requested' => $this->requestedFolds,
            'combinations' => count($this->combinations),
            'in_sample' => $this->inSample(),
            'out_of_sample' => $this->outOfSample(),
            'degradation' => $this->degradation(),
            'stability' => $this->stability(),
            'folds' => $this->folds,
            'notes' => $this->notes,
        ];
    }
}

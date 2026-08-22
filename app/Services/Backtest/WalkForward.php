<?php

namespace App\Services\Backtest;

use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Strategy;

/**
 * Walk-Forward Validation
 *
 * Optimise on a stretch of history, then test the winner on the stretch that came *next* -
 * data the optimisation never saw. Roll the window forward and repeat.
 *
 * ## Why a sweep alone proves nothing
 *
 * Any grid search over one series will find a combination that did well on it. With enough
 * parameters it will find one that did brilliantly. That result says the grid contains a curve
 * shaped like this particular stretch of history, which is true of any sufficiently large grid
 * and any stretch of history. It is not evidence about the future, and the more thoroughly you
 * search, the less evidence it is.
 *
 * Walk-forward is the cheapest honest answer. Each fold's out-of-sample result is a genuine
 * prediction: parameters chosen without reference to those bars, then run on them. Stitched
 * together, the out-of-sample results are the closest thing to an estimate of live behaviour
 * that history can give.
 *
 * ## Degradation is the number to read
 *
 * In-sample results are always good - that is what optimisation does. The comparison between
 * in-sample and out-of-sample is the finding:
 *
 *   - Out-of-sample close to in-sample: the edge may be real.
 *   - Out-of-sample much worse, or negative: the sweep fitted noise. This is the normal result,
 *     and reporting it is the entire point of running one.
 *   - Winning parameters that jump around between folds: there is no stable optimum, so no
 *     reason to believe any of them.
 *
 * ## The anchored window
 *
 * Each fold trains on everything before it rather than on a fixed-width rolling window. That
 * matches how the strategy would actually be re-tuned - with all the history available at the
 * time - and avoids discarding data that a short series cannot spare.
 */
final class WalkForward
{
    public function __construct(
        private readonly SweepRunner $sweeper = new SweepRunner,
        private readonly Backtester $backtester = new Backtester,
    ) {}

    /**
     * @param  array<int, Candle>  $entryCandles
     * @param  array<int, Candle>  $trendCandles
     * @param  array<int, array<string, float>>  $combinations
     * @param  callable|null  $onProgress  Called with (fold, folds, runsDone, runsTotal)
     */
    public function run(
        Strategy $strategy,
        array $entryCandles,
        array $trendCandles,
        array $combinations,
        MarketAssumptions $market,
        ?BotSettings $settings = null,
        int $folds = 4,
        int $minTrades = 10,
        ?callable $onProgress = null,
    ): WalkForwardReport {
        $report = new WalkForwardReport($strategy, $folds, $combinations);

        $total = count($entryCandles);
        $emaSlow = (int) $strategy->ema_slow;
        $period = (int) $strategy->atr_period;
        $warmup = max($emaSlow + 2, (2 * $period) + 1);

        // Every fold needs its own warm-up before it can produce a signal, and the first fold
        // needs a training window on top of that.
        $minimum = ($warmup + 20) * ($folds + 1);

        if ($total < $minimum) {
            $report->note(
                "Not enough bars for {$folds} folds: {$total} available, about {$minimum} needed. "
                .'Use fewer folds, or wait for more history.'
            );

            return $report;
        }

        // The first fold trains on everything before the first test window.
        $testSize = (int) floor(($total - $warmup) / ($folds + 1));
        $runsTotal = $folds * (count($combinations) + 1);
        $runsDone = 0;

        for ($fold = 0; $fold < $folds; $fold++) {
            $trainEnd = $warmup + $testSize * ($fold + 1);
            $testEnd = min($trainEnd + $testSize, $total);

            $train = array_slice($entryCandles, 0, $trainEnd);

            // The test slice carries its own warm-up: the evaluator needs bars behind the
            // first testable one, and they have to come from before the window rather than
            // from inside it - otherwise the fold cannot trade its own opening bars.
            $test = array_slice($entryCandles, max(0, $trainEnd - $warmup - 2), $testEnd - max(0, $trainEnd - $warmup - 2));

            if (count($test) <= $warmup + 1) {
                continue;
            }

            $trainTrend = $this->trendUpTo($trendCandles, end($train)->open_time);
            $testTrend = $this->trendUpTo($trendCandles, end($test)->open_time);

            // ---- optimise, seeing only the training window ----
            $sweep = $this->sweeper->run(
                $strategy, $train, $trainTrend, $combinations, $market, $settings, $minTrades,
                function () use (&$runsDone, $runsTotal, $onProgress, $fold, $folds) {
                    $runsDone++;

                    if ($onProgress !== null) {
                        $onProgress($fold + 1, $folds, $runsDone, $runsTotal);
                    }
                },
            );

            $ranked = SweepRunner::rank($sweep);
            $winner = $ranked[0] ?? null;

            if ($winner === null || ! $winner->qualifies) {
                $report->addFold($fold + 1, $winner?->parameters ?? [], $winner?->metrics ?? [], null, $this->window($train, $test));
                $runsDone++;

                if ($onProgress !== null) {
                    $onProgress($fold + 1, $folds, $runsDone, $runsTotal);
                }

                continue;
            }

            // ---- test the winner on bars the optimisation never saw ----
            $candidate = ParameterGrid::apply($strategy, $winner->parameters);
            $outOfSample = $this->backtester->run($candidate, $test, $testTrend, $market, $settings);

            $runsDone++;

            if ($onProgress !== null) {
                $onProgress($fold + 1, $folds, $runsDone, $runsTotal);
            }

            $report->addFold($fold + 1, $winner->parameters, $winner->metrics, $outOfSample->metrics(), $this->window($train, $test));
        }

        return $report;
    }

    /**
     * @param  array<int, Candle>  $train
     * @param  array<int, Candle>  $test
     * @return array<string, string|int>
     */
    private function window(array $train, array $test): array
    {
        return [
            'train_bars' => count($train),
            'test_bars' => count($test),
            'test_from' => $test[0]->open_time->toDateTimeString(),
            'test_to' => end($test)->open_time->toDateTimeString(),
        ];
    }

    /**
     * Trend bars at or before a moment.
     *
     * @param  array<int, Candle>  $trendCandles
     * @return array<int, Candle>
     */
    private function trendUpTo(array $trendCandles, $at): array
    {
        $usable = [];

        foreach ($trendCandles as $candle) {
            if ($candle->open_time->greaterThan($at)) {
                break;
            }

            $usable[] = $candle;
        }

        return $usable;
    }
}

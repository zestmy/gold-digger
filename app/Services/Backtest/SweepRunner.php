<?php

namespace App\Services\Backtest;

use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Strategy;

/**
 * Sweep Runner
 *
 * Runs a backtest for every combination in a grid and ranks the outcomes.
 *
 * ## Ranking is the whole problem
 *
 * Sorting by net profit picks whichever combination best fits the sample, which is exactly the
 * mistake a sweep exists to enable. Three defences are built in, and none of them is optional:
 *
 *  1. **A minimum trade count.** A combination that took four trades did not find an edge; it
 *     found four coincidences. Below the floor a result is not ranked at all.
 *  2. **Return measured against drawdown**, not in isolation. Doubling the account through a
 *     60% drawdown is not better than half the return through 5%, and net profit cannot see
 *     the difference.
 *  3. **Rank agreement is reported.** When the best combination by one metric is not the best
 *     by the others, that disagreement is the finding - it means the ranking is being driven
 *     by noise rather than by an edge.
 *
 * Even with all three, a sweep over one series tells you what fitted. `WalkForward` is what
 * tells you whether it generalises.
 */
final class SweepRunner
{
    /**
     * Below this, a result is noise dressed as a strategy.
     *
     * Thirty is not statistically magic; it is the point past which a win rate stops being
     * dominated by whichever way the first few trades happened to go.
     */
    public const DEFAULT_MIN_TRADES = 30;

    public function __construct(
        private readonly Backtester $backtester = new Backtester,
    ) {}

    /**
     * @param  array<int, Candle>  $entryCandles
     * @param  array<int, Candle>  $trendCandles
     * @param  array<int, array<string, float>>  $combinations
     * @param  callable|null  $onProgress  Called with (index, total) after each run
     * @return array<int, SweepResult>
     */
    public function run(
        Strategy $strategy,
        array $entryCandles,
        array $trendCandles,
        array $combinations,
        MarketAssumptions $market,
        ?BotSettings $settings = null,
        int $minTrades = self::DEFAULT_MIN_TRADES,
        ?callable $onProgress = null,
    ): array {
        $results = [];

        foreach ($combinations as $i => $combination) {
            $candidate = ParameterGrid::apply($strategy, $combination);

            $report = $this->backtester->run($candidate, $entryCandles, $trendCandles, $market, $settings);

            $results[] = new SweepResult($combination, $report->metrics(), $minTrades);

            if ($onProgress !== null) {
                $onProgress($i + 1, count($combinations));
            }
        }

        return $results;
    }

    /**
     * Rank, best first, with unqualified results last regardless of what they scored.
     *
     * @param  array<int, SweepResult>  $results
     * @return array<int, SweepResult>
     */
    public static function rank(array $results, string $metric = 'score'): array
    {
        usort($results, function (SweepResult $a, SweepResult $b) use ($metric) {
            // A result below the trade floor never outranks one above it, whatever its
            // numbers say. This is the guard that stops a four-trade fluke topping the table.
            if ($a->qualifies !== $b->qualifies) {
                return $a->qualifies ? -1 : 1;
            }

            return $b->value($metric) <=> $a->value($metric);
        });

        return $results;
    }

    /**
     * Do the metrics agree about which combination is best?
     *
     * Disagreement is worth surfacing rather than resolving. If net profit likes one corner of
     * the grid and drawdown-adjusted return likes another, neither is a finding - the surface
     * is flat and the ranking is picking up noise.
     *
     * @param  array<int, SweepResult>  $results
     * @return array{agree: bool, winners: array<string, string>}
     */
    public static function agreement(array $results): array
    {
        $qualified = array_values(array_filter($results, fn (SweepResult $r) => $r->qualifies));

        if (count($qualified) < 2) {
            return ['agree' => true, 'winners' => []];
        }

        $winners = [];

        foreach (['score', 'net_pnl', 'profit_factor', 'expectancy'] as $metric) {
            $ranked = self::rank($qualified, $metric);
            $winners[$metric] = $ranked[0]->label();
        }

        return [
            'agree' => count(array_unique($winners)) === 1,
            'winners' => $winners,
        ];
    }
}

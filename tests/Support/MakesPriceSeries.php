<?php

namespace Tests\Support;

use App\Models\Candle;
use App\Services\Indicators\Indicators;
use Illuminate\Support\Carbon;

/**
 * Builds candle fixtures for strategy tests.
 *
 * The awkward part of testing a crossover strategy is that the evaluator only ever looks
 * at the most recent closed bar, so a fixture has to place the cross exactly there.
 * `crossCloses()` does that by generating a long move, reversing it hard, and trimming the
 * series at the bar where the EMAs actually cross.
 *
 * That trim uses Indicators::ema, which is the code under test elsewhere - but not here.
 * These tests assert what the *generator* does with a cross; the maths that finds one is
 * pinned separately in IndicatorsTest against hand-computed values.
 */
trait MakesPriceSeries
{
    /**
     * Write a close series as candles ending on $lastBar.
     *
     * Bars get a one-point range around the close, which keeps ATR realistic for gold
     * without making any test depend on a specific volatility.
     *
     * @param  array<int, float>  $closes
     */
    protected function seedSeries(
        array $closes,
        string $timeframe,
        Carbon $lastBar,
        int $userId,
        int $accountId,
        string $symbol,
    ): void {
        $spacing = $this->barSeconds($timeframe);
        $count = count($closes);

        $rows = [];

        foreach ($closes as $i => $close) {
            $rows[] = [
                'user_id' => $userId,
                'broker_account_id' => $accountId,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'open_time' => $lastBar->copy()->subSeconds(($count - 1 - $i) * $spacing),
                'open' => $close,
                'high' => $close + 1.0,
                'low' => $close - 1.0,
                'close' => $close,
                'tick_volume' => 100,
                'spread_points' => 20,
                'source' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        Candle::insert($rows);
    }

    /**
     * The same series as bar payloads for POST /api/v1/bot/candles.
     *
     * @param  array<int, float>  $closes
     * @return array<int, array<string, float|int>>
     */
    protected function barPayloads(array $closes, string $timeframe, Carbon $lastBar): array
    {
        $spacing = $this->barSeconds($timeframe);
        $count = count($closes);

        $bars = [];

        foreach ($closes as $i => $close) {
            $bars[] = [
                'time' => $lastBar->copy()->subSeconds(($count - 1 - $i) * $spacing)->getTimestamp(),
                'open' => $close,
                'high' => $close + 1.0,
                'low' => $close - 1.0,
                'close' => $close,
                'tick_volume' => 100,
                'spread_points' => 20,
            ];
        }

        return $bars;
    }

    /**
     * A close series whose fast EMA crosses the slow one on the very last bar.
     *
     * @return array<int, float>
     */
    protected function crossCloses(string $direction, int $fastPeriod = 20, int $slowPeriod = 50): array
    {
        $closes = [];

        if ($direction === 'buy') {
            for ($i = 0; $i < 140; $i++) {
                $closes[] = 2400.0 - ($i * 1.5);
            }
            $base = $closes[count($closes) - 1];
            for ($i = 1; $i <= 120; $i++) {
                $closes[] = $base + ($i * 4.0);
            }
        } else {
            for ($i = 0; $i < 140; $i++) {
                $closes[] = 2000.0 + ($i * 1.5);
            }
            $base = $closes[count($closes) - 1];
            for ($i = 1; $i <= 120; $i++) {
                $closes[] = $base - ($i * 4.0);
            }
        }

        $fast = Indicators::ema($closes, $fastPeriod);
        $slow = Indicators::ema($closes, $slowPeriod);

        for ($i = 141; $i < count($closes); $i++) {
            if ($fast[$i] === null || $slow[$i] === null) {
                continue;
            }

            $crossed = $direction === 'buy'
                ? ($fast[$i - 1] <= $slow[$i - 1] && $fast[$i] > $slow[$i])
                : ($fast[$i - 1] >= $slow[$i - 1] && $fast[$i] < $slow[$i]);

            if ($crossed) {
                return array_slice($closes, 0, $i + 1);
            }
        }

        $this->fail("Fixture never produced a {$direction} crossover.");
    }

    /**
     * A steadily trending close series, for the higher timeframe.
     *
     * @return array<int, float>
     */
    protected function trendCloses(int $count, bool $rising): array
    {
        $closes = [];

        for ($i = 0; $i < $count; $i++) {
            $closes[] = $rising ? 2000.0 + ($i * 3.0) : 2400.0 - ($i * 3.0);
        }

        return $closes;
    }

    private function barSeconds(string $timeframe): int
    {
        return match (strtoupper($timeframe)) {
            'H1' => 3600,
            'M15' => 900,
            'M1' => 60,
            default => 300,
        };
    }
}

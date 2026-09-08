<?php

namespace App\Services\Outcomes;

use App\Models\Candle;
use App\Models\SignalOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stop-Width Report
 *
 * What the same signals would have done under a wider or narrower stop.
 *
 * ## The question it answers
 *
 * The first month of outcomes showed an average worst excursion of about -1.1R: many
 * signals went a little past the stop and then to the target. Whether that means the
 * stop is too tight or the entry is too early cannot be told from the summary numbers,
 * because the summary does not say which came first. This walks the stored bars again for
 * every decided signal, with the stop at a multiple of the one it had, and reports the
 * win rate and expectancy at each width. If a wider stop turns losers into winners the
 * stop is the problem; if it only turns them into slower losers, the entry is.
 *
 * ## What "wider" means here
 *
 * The stop moves; the ladder moves with it. Targets are in R of the stop - 1R, 2R, 3R -
 * so a stop twice as wide puts the first target twice as far away. That is the trade the
 * strategy would actually make at that width, and the only comparison that is fair:
 * scoring a wider stop against the old target would flatter every row.
 *
 * Position size follows the stop as well, so R is the same money at every width and the
 * expectancies compare directly.
 *
 * ## What it does not do
 *
 * It does not write anything. It is a report over rows and bars that already exist, and a
 * signal whose bars have since been pruned is counted as unscorable rather than guessed at.
 * Every width uses the same pessimistic rules as the tracker: the stop is judged before the
 * target on a bar that spans both, and the fill is at the stored reference price.
 */
final class StopWidthReport
{
    /** Multiples of the stop the signal actually had. 1.0 is the row the tracker scored. */
    public const MULTIPLES = [0.75, 1.0, 1.25, 1.5, 2.0, 2.5, 3.0];

    /**
     * @param  array<int, float>  $multiples
     * @return array{
     *     source: string,
     *     scorable: int,
     *     unscorable: int,
     *     rows: array<int, array{multiple: float, n: int, won: int, lost: int, expired: int, win_rate: float|null, expectancy_r: float|null, avg_tp1_bars: float|null, tp2_rate: float|null, tp3_rate: float|null}>
     * }
     */
    public function forUser(int $userId, ?Carbon $since = null, string $source = SignalOutcome::SOURCE_AI, array $multiples = self::MULTIPLES): array
    {
        $outcomes = SignalOutcome::query()
            ->where('user_id', $userId)
            ->where('source', $source)
            ->whereIn('status', [SignalOutcome::WON, SignalOutcome::LOST, SignalOutcome::EXPIRED])
            ->whereNotNull('activated_at')
            ->when($since !== null, fn ($q) => $q->where('started_at', '>=', $since))
            ->orderBy('started_at')
            ->get();

        $walks = [];
        $unscorable = 0;

        foreach ($outcomes as $outcome) {
            $bars = $this->barsFor($outcome);

            if ($bars->isEmpty() || $outcome->risk === null || (float) $outcome->risk <= 0.0) {
                $unscorable++;

                continue;
            }

            $walks[] = [$outcome, $bars];
        }

        $rows = [];

        foreach ($multiples as $multiple) {
            $rows[] = $this->row((float) $multiple, $walks);
        }

        return [
            'source' => $source,
            'scorable' => count($walks),
            'unscorable' => $unscorable,
            'rows' => $rows,
        ];
    }

    /**
     * One width, over every scorable signal.
     *
     * @param  array<int, array{0: SignalOutcome, 1: Collection<int, Candle>}>  $walks
     * @return array{multiple: float, n: int, won: int, lost: int, expired: int, win_rate: float|null, expectancy_r: float|null, avg_tp1_bars: float|null, tp2_rate: float|null, tp3_rate: float|null}
     */
    private function row(float $multiple, array $walks): array
    {
        $won = $lost = $expired = $tp2 = $tp3 = 0;
        $tp1Bars = [];
        $r = [];

        foreach ($walks as [$outcome, $bars]) {
            $result = $this->walk($outcome, $bars, $multiple);

            match ($result['verdict']) {
                'won' => $won++,
                'lost' => $lost++,
                default => $expired++,
            };

            if ($result['tp1_bars'] !== null) {
                $tp1Bars[] = $result['tp1_bars'];
            }

            $tp2 += $result['tp2'] ? 1 : 0;
            $tp3 += $result['tp3'] ? 1 : 0;

            // The same convention as OutcomeStats: a win pays the first rung, a loss pays
            // the stop, and a signal that reached neither is worth where it closed.
            $r[] = match ($result['verdict']) {
                'won' => 1.0,
                'lost' => -1.0,
                default => $result['close_r'],
            };
        }

        $n = count($walks);
        $decided = $won + $lost;

        return [
            'multiple' => $multiple,
            'n' => $n,
            'won' => $won,
            'lost' => $lost,
            'expired' => $expired,
            'win_rate' => $decided > 0 ? round($won / $decided * 100, 1) : null,
            'expectancy_r' => $n > 0 ? round(array_sum($r) / $n, 2) : null,
            'avg_tp1_bars' => $tp1Bars === [] ? null : round(array_sum($tp1Bars) / count($tp1Bars), 1),
            'tp2_rate' => $n > 0 ? round($tp2 / $n * 100, 1) : null,
            'tp3_rate' => $n > 0 ? round($tp3 / $n * 100, 1) : null,
        ];
    }

    /**
     * Walk one signal's bars with the stop at `$multiple` times its recorded risk, the
     * ladder at 1R / 2R / 3R of that stop.
     *
     * @param  Collection<int, Candle>  $bars
     * @return array{verdict: string, tp1_bars: int|null, tp2: bool, tp3: bool, close_r: float}
     */
    private function walk(SignalOutcome $outcome, Collection $bars, float $multiple): array
    {
        $buy = $outcome->isBuy();
        $sign = $buy ? 1.0 : -1.0;
        $reference = (float) $outcome->reference_price;
        $risk = (float) $outcome->risk * $multiple;

        $stop = $reference - ($sign * $risk);
        $targets = [1 => $reference + ($sign * $risk), 2 => $reference + ($sign * 2 * $risk), 3 => $reference + ($sign * 3 * $risk)];

        $hit = [1 => null, 2 => null, 3 => null];
        $stopBar = null;
        $n = 0;
        $close = $reference;

        foreach ($bars as $bar) {
            $n++;
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $close = (float) $bar->close;

            // The stop first, on purpose: a bar that spans both is a loss.
            if ($buy ? $low <= $stop : $high >= $stop) {
                $stopBar = $n;
                break;
            }

            foreach ($targets as $rung => $level) {
                if ($hit[$rung] === null && ($buy ? $high >= $level : $low <= $level)) {
                    $hit[$rung] = $n;
                }
            }

            if ($hit[3] !== null || $n >= (int) $outcome->horizon_bars) {
                break;
            }
        }

        $verdict = match (true) {
            $stopBar !== null && $hit[1] === null => 'lost',
            $hit[1] !== null => 'won',
            default => 'expired',
        };

        return [
            'verdict' => $verdict,
            'tp1_bars' => $hit[1],
            'tp2' => $hit[2] !== null,
            'tp3' => $hit[3] !== null,
            'close_r' => $risk > 0 ? ($sign * ($close - $reference)) / $risk : 0.0,
        ];
    }

    /**
     * The bars the tracker scored this signal over, from the bar it was activated on.
     *
     * @return Collection<int, Candle>
     */
    private function barsFor(SignalOutcome $outcome): Collection
    {
        return Candle::query()
            ->series($outcome->broker_account_id, $outcome->symbol, $outcome->timeframe)
            ->where('open_time', '>=', $outcome->activated_at)
            ->orderBy('open_time')
            ->limit(max(1, (int) $outcome->horizon_bars))
            ->get();
    }
}

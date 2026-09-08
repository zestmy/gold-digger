<?php

namespace App\Services\Outcomes;

use App\Models\Candle;
use App\Models\Signal;
use App\Models\SignalOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pullback-Entry Report
 *
 * What the same signals would have done had the entry waited for a pullback.
 *
 * ## The question it answers
 *
 * The stop-width report settled that a wider stop does not help: the losers are entries
 * that go the wrong way and stay there. The next candidate is the entry itself. An EMA
 * cross fires at a bar close that is often the end of the move rather than the start,
 * and the card already says "wait for pullback" on many signals. This scores that advice:
 * a limit resting some fraction of the stop distance behind the signal price, filled if
 * price comes back to it within a window, and the trade scored from there.
 *
 * ## The rules, and why
 *
 * - **The stop stays where the signal put it.** The stop is a structural level - so many
 *   ATR below the signal bar - and a pullback entry that keeps it shortens the risk. That
 *   is the whole point of waiting, and the report is honest about the other side: the
 *   ladder is 1R / 2R / 3R of the *new*, shorter risk, so the targets move in with it.
 * - **A limit that never fills is a missed trade, worth 0R.** It is counted in the
 *   per-signal expectancy, because "we only take the ones that come back to us" is a
 *   strategy whose cost is the ones that never do. The fill rate is shown beside it.
 * - **A fill bar that also reaches the stop is a loss.** The same pessimism as the
 *   tracker and the stop-width report, or the three would disagree.
 * - **Depth 0 is the entry as traded**, filled at the reference price on the first bar,
 *   so the first row reproduces the stop-width report's "as traded" row and the others
 *   are read against it.
 * - **The signal's own zone** - the far edge the card publishes - is scored as its own
 *   row when the signal recorded one.
 *
 * It writes nothing. A signal whose bars have been pruned is counted, not guessed at.
 */
final class PullbackEntryReport
{
    /** Pullback depths as fractions of the stop distance. 0 is the entry as traded. */
    public const DEPTHS = [0.0, 0.25, 0.5, 0.75];

    /** Bars a resting limit waits before the setup is called stale. Twelve M5 bars is an hour. */
    public const WAIT_BARS = 12;

    public const ZONE = 'zone';

    /**
     * @param  array<int, float|string>  $depths  Fractions of the stop, or 'zone'
     * @return array{
     *     scorable: int,
     *     unscorable: int,
     *     wait_bars: int,
     *     rows: array<int, array{depth: float|string, n: int, filled: int, unfilled: int, won: int, lost: int, expired: int, fill_rate: float|null, win_rate: float|null, expectancy_r: float|null, expectancy_filled_r: float|null, avg_risk_share: float|null}>
     * }
     */
    public function forUser(int $userId, ?Carbon $since = null, ?array $depths = null, int $waitBars = self::WAIT_BARS): array
    {
        $depths ??= array_merge(self::DEPTHS, [self::ZONE]);

        $outcomes = SignalOutcome::query()
            ->with('subject')
            ->where('user_id', $userId)
            ->where('source', SignalOutcome::SOURCE_AI)
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

        foreach ($depths as $depth) {
            $rows[] = $this->row($depth, $walks, max(1, $waitBars));
        }

        return [
            'scorable' => count($walks),
            'unscorable' => $unscorable,
            'wait_bars' => max(1, $waitBars),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, array{0: SignalOutcome, 1: Collection<int, Candle>}>  $walks
     * @return array{depth: float|string, n: int, filled: int, unfilled: int, won: int, lost: int, expired: int, fill_rate: float|null, win_rate: float|null, expectancy_r: float|null, expectancy_filled_r: float|null, avg_risk_share: float|null}
     */
    private function row(float|string $depth, array $walks, int $waitBars): array
    {
        $filled = $unfilled = $won = $lost = $expired = 0;
        $all = [];
        $filledR = [];
        $riskShares = [];

        foreach ($walks as [$outcome, $bars]) {
            $level = $this->level($outcome, $depth);

            if ($level === null) {
                // A signal without a zone cannot be scored on its zone; it is left out of
                // that row rather than scored as if it had one.
                continue;
            }

            $result = $this->walk($outcome, $bars, $level, $waitBars);

            if ($result === null) {
                $unfilled++;
                $all[] = 0.0;

                continue;
            }

            $filled++;
            $riskShares[] = $result['risk_share'];

            $r = match ($result['verdict']) {
                'won' => 1.0,
                'lost' => -1.0,
                default => $result['close_r'],
            };

            match ($result['verdict']) {
                'won' => $won++,
                'lost' => $lost++,
                default => $expired++,
            };

            $all[] = $r;
            $filledR[] = $r;
        }

        $n = count($all);
        $decided = $won + $lost;

        return [
            'depth' => $depth,
            'n' => $n,
            'filled' => $filled,
            'unfilled' => $unfilled,
            'won' => $won,
            'lost' => $lost,
            'expired' => $expired,
            'fill_rate' => $n > 0 ? round($filled / $n * 100, 1) : null,
            'win_rate' => $decided > 0 ? round($won / $decided * 100, 1) : null,
            'expectancy_r' => $n > 0 ? round(array_sum($all) / $n, 2) : null,
            'expectancy_filled_r' => $filledR === [] ? null : round(array_sum($filledR) / count($filledR), 2),
            // How much of the signal's stop distance the pullback entry still risks: 0.5
            // means the fill halved the risk, which is what a deeper entry buys.
            'avg_risk_share' => $riskShares === [] ? null : round(array_sum($riskShares) / count($riskShares), 2),
        ];
    }

    /**
     * The price a limit would rest at for this depth, or null when the signal has no zone.
     */
    private function level(SignalOutcome $outcome, float|string $depth): ?float
    {
        $sign = $outcome->isBuy() ? 1.0 : -1.0;
        $reference = (float) $outcome->reference_price;

        if ($depth === self::ZONE) {
            $signal = $outcome->subject;

            if (! $signal instanceof Signal) {
                return null;
            }

            $features = (array) ($signal->features ?? []);
            $low = $features['entry_zone_low'] ?? null;
            $high = $features['entry_zone_high'] ?? null;

            if (! is_numeric($low) || ! is_numeric($high)) {
                return null;
            }

            // The far edge: the lowest price of the zone on a buy, the highest on a sell.
            $far = $outcome->isBuy() ? min((float) $low, (float) $high) : max((float) $low, (float) $high);

            // A zone that is the signal price itself is not a pullback; it is scored as
            // the entry as traded, which depth 0 already covers.
            return abs($far - $reference) < 1e-9 ? null : $far;
        }

        return $reference - ($sign * (float) $depth * (float) $outcome->risk);
    }

    /**
     * Rest a limit at `$level` for up to `$waitBars`, then score the trade from the fill
     * with the signal's own stop and a 1R / 2R / 3R ladder off the new risk.
     *
     * @param  Collection<int, Candle>  $bars
     * @return array{verdict: string, close_r: float, risk_share: float}|null Null when never filled
     */
    private function walk(SignalOutcome $outcome, Collection $bars, float $level, int $waitBars): ?array
    {
        $buy = $outcome->isBuy();
        $sign = $buy ? 1.0 : -1.0;
        $stop = (float) $outcome->stop_price;
        $originalRisk = (float) $outcome->risk;

        // A level at or past the stop is not an entry.
        $risk = $sign * ($level - $stop);

        if ($risk <= 0.0) {
            return null;
        }

        $targets = [1 => $level + $sign * $risk, 2 => $level + $sign * 2 * $risk, 3 => $level + $sign * 3 * $risk];
        $hit = [1 => null, 2 => null, 3 => null];

        $filled = false;
        $n = 0;
        $close = $level;
        $stopBar = null;
        $horizon = (int) $outcome->horizon_bars;

        foreach ($bars as $index => $bar) {
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $close = (float) $bar->close;

            if (! $filled) {
                if ($index >= $waitBars) {
                    return null;
                }

                // Depth 0 fills at the reference on the first bar, as traded. Any other
                // level fills when the bar's range reaches it.
                $reaches = abs($level - (float) $outcome->reference_price) < 1e-9
                    || ($buy ? $low <= $level : $high >= $level);

                if (! $reaches) {
                    continue;
                }

                $filled = true;
            }

            $n++;

            // The stop first, on purpose: a fill bar that also reaches the stop is a loss.
            if ($buy ? $low <= $stop : $high >= $stop) {
                $stopBar = $n;
                break;
            }

            foreach ($targets as $rung => $target) {
                if ($hit[$rung] === null && ($buy ? $high >= $target : $low <= $target)) {
                    $hit[$rung] = $n;
                }
            }

            if ($hit[3] !== null || $n >= $horizon) {
                break;
            }
        }

        if (! $filled) {
            return null;
        }

        $verdict = match (true) {
            $stopBar !== null && $hit[1] === null => 'lost',
            $hit[1] !== null => 'won',
            default => 'expired',
        };

        return [
            'verdict' => $verdict,
            'close_r' => ($sign * ($close - $level)) / $risk,
            'risk_share' => $risk / $originalRisk,
        ];
    }

    /**
     * @return Collection<int, Candle>
     */
    private function barsFor(SignalOutcome $outcome): Collection
    {
        return Candle::query()
            ->series($outcome->broker_account_id, $outcome->symbol, $outcome->timeframe)
            ->where('open_time', '>=', $outcome->activated_at)
            ->orderBy('open_time')
            ->limit(max(1, (int) $outcome->horizon_bars) + self::WAIT_BARS)
            ->get()
            ->values();
    }
}

<?php

namespace App\Services\Outcomes;

use App\Models\SignalOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Outcome Stats
 *
 * What the tracked signals add up to, and along which lines they differ.
 *
 * ## Expectancy is in R, on the first target
 *
 * The one figure that is comparable across instruments, sizes and accounts: a won signal
 * pays the first target's R, a lost one pays -1. That is a floor on what a signal is worth
 * to somebody who takes it as published, not what a managed position made - the trade's
 * own ladder and stop moves are measured on the Trades page.
 *
 * ## Every breakdown carries its sample size
 *
 * A 70% win rate over ten signals is not a finding. Each group reports its count beside
 * its rate, and the view is expected to show both; a rate without its n is how a hand-set
 * threshold gets mistaken for a measurement.
 */
final class OutcomeStats
{
    /** Below this a group's rate is shown but flagged as thin. */
    public const THIN_SAMPLE = 30;

    /**
     * @return array{
     *     tracked: int, open: int, won: int, lost: int, expired: int,
     *     win_rate: float|null, expectancy_r: float|null, avg_mfe_r: float|null, avg_mae_r: float|null,
     *     avg_tp1_bars: float|null, avg_sl_bars: float|null,
     *     by_source: array<string, array<string, mixed>>,
     *     by_confidence: array<string, array<string, mixed>>,
     *     by_session: array<string, array<string, mixed>>,
     *     by_instrument: array<string, array<string, mixed>>,
     *     by_hour: array<int, array<string, mixed>>
     * }
     */
    public function forUser(int $userId, ?Carbon $since = null): array
    {
        $outcomes = SignalOutcome::query()
            ->where('user_id', $userId)
            ->when($since !== null, fn ($q) => $q->where('started_at', '>=', $since))
            ->get();

        $decided = $outcomes->whereIn('status', [SignalOutcome::WON, SignalOutcome::LOST, SignalOutcome::EXPIRED]);

        return $this->summarise($outcomes) + [
            'by_source' => $this->groupBy($decided, fn (SignalOutcome $o) => $o->source),
            'by_confidence' => $this->groupBy($decided, fn (SignalOutcome $o) => $this->confidenceBand($o)),
            'by_session' => $this->groupBy($decided, fn (SignalOutcome $o) => $this->session($o)),
            'by_instrument' => $this->groupBy($decided, fn (SignalOutcome $o) => $o->context['instrument'] ?? 'unknown'),
            'by_hour' => $this->groupBy($decided, fn (SignalOutcome $o) => (string) ($o->context['hour_utc'] ?? '?')),
        ];
    }

    /**
     * @param  Collection<int, SignalOutcome>  $outcomes
     * @return array<string, mixed>
     */
    private function summarise(Collection $outcomes): array
    {
        $won = $outcomes->where('status', SignalOutcome::WON);
        $lost = $outcomes->where('status', SignalOutcome::LOST);
        $expired = $outcomes->where('status', SignalOutcome::EXPIRED);
        $unfilled = $outcomes->where('status', SignalOutcome::UNFILLED);
        $decided = $won->count() + $lost->count();

        // Expired signals count as neither won nor lost in the rate - the level was never
        // reached - but they are visible, because a strategy whose signals mostly expire
        // is one whose targets are too far, and that is a finding too.
        $winRate = $decided > 0 ? round($won->count() / $decided * 100, 1) : null;

        $expectancy = null;

        if ($decided > 0) {
            $paid = $won->sum(fn (SignalOutcome $o) => $o->tp1R() ?? 0.0) - $lost->count();
            $expectancy = round($paid / $decided, 2);
        }

        $walked = $outcomes->where('bars_seen', '>', 0);

        return [
            'tracked' => $outcomes->count(),
            'open' => $outcomes->where('status', SignalOutcome::OPEN)->count(),
            'won' => $won->count(),
            'lost' => $lost->count(),
            'expired' => $expired->count(),
            // A named entry the market never came back to. Neither a win nor a loss, and
            // worth showing: a provider whose entries rarely fill is a provider whose
            // published results were mostly never available.
            'unfilled' => $unfilled->count(),
            'win_rate' => $winRate,
            'expectancy_r' => $expectancy,
            'avg_mfe_r' => $this->avg($walked, 'mfe_r'),
            'avg_mae_r' => $this->avg($walked, 'mae_r'),
            'avg_tp1_bars' => $this->avg($won, 'tp1_bars'),
            'avg_sl_bars' => $this->avg($lost, 'sl_bars'),
            'thin' => $decided < self::THIN_SAMPLE,
        ];
    }

    /**
     * @param  Collection<int, SignalOutcome>  $decided
     * @return array<string, array<string, mixed>>
     */
    private function groupBy(Collection $decided, callable $key): array
    {
        return $decided
            ->groupBy($key)
            ->map(fn (Collection $group) => $this->summarise($group))
            ->sortByDesc('tracked')
            ->all();
    }

    private function confidenceBand(SignalOutcome $outcome): string
    {
        $confidence = $outcome->context['confidence'] ?? null;

        return match (true) {
            $confidence === null => 'unscored',
            $confidence >= 70 => '70% and up',
            $confidence >= 55 => '55–69%',
            default => 'under 55%',
        };
    }

    private function session(SignalOutcome $outcome): string
    {
        $sessions = $outcome->context['sessions'] ?? [];

        if ($sessions === []) {
            return 'none open';
        }

        // The overlap is its own thing; otherwise the one session that was open.
        return count($sessions) > 1 ? 'overlap' : (string) $sessions[0];
    }

    /**
     * @param  Collection<int, SignalOutcome>  $rows
     */
    private function avg(Collection $rows, string $field): ?float
    {
        $values = $rows->pluck($field)->filter(fn ($v) => $v !== null);

        return $values->isEmpty() ? null : round((float) $values->avg(), 2);
    }
}

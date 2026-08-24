<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Market Event Model
 *
 * One scheduled economic release. See the migration for why the calendar is global rather
 * than per user.
 */
class MarketEvent extends Model
{
    protected $fillable = [
        'source',
        'title',
        'currency',
        'impact',
        'scheduled_at',
        'forecast',
        'previous',
        'actual',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
        ];
    }

    // =========================================================================
    // QUERIES
    // =========================================================================

    /**
     * Events that could black out a moment, widened by the largest window either side.
     *
     * The caller decides which of these actually overlap - this only has to be a superset,
     * and being a superset is what lets the backtester load a whole run's events in one query
     * instead of one query per bar.
     */
    public function scopeInWindow(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->where('scheduled_at', '>=', $from)
            ->where('scheduled_at', '<=', $to);
    }

    /**
     * Only the events the filter has been configured to care about.
     *
     * @param  array<int, string>  $currencies
     * @param  array<int, string>  $impacts
     */
    public function scopeOfInterest(Builder $query, array $currencies, array $impacts): Builder
    {
        if ($currencies !== []) {
            $query->whereIn('currency', array_map('strtoupper', $currencies));
        }

        if ($impacts !== []) {
            $query->whereIn('impact', array_map('strtolower', $impacts));
        }

        return $query;
    }

    // =========================================================================
    // BEHAVIOUR
    // =========================================================================

    /**
     * The blackout window this event imposes, as [start, end].
     */
    public function blackout(int $beforeMinutes, int $afterMinutes): array
    {
        $at = $this->scheduled_at->copy();

        return [
            $at->copy()->subMinutes(max(0, $beforeMinutes)),
            $at->copy()->addMinutes(max(0, $afterMinutes)),
        ];
    }

    /**
     * Does this event's blackout overlap the half-open interval `[$from, $to)`?
     *
     * Half-open because the interval passed in is a bar, and a bar's end instant is the next
     * bar's start. Treating it as closed would make an event landing exactly on a boundary
     * black out two bars, one of which has not happened yet.
     */
    public function blacksOut(CarbonInterface $from, CarbonInterface $to, int $beforeMinutes, int $afterMinutes): bool
    {
        [$start, $end] = $this->blackout($beforeMinutes, $afterMinutes);

        return $start <= $to && $end >= $from;
    }

    /**
     * How the skip reads on `/signals` and in `bot_logs`.
     */
    public function describe(): string
    {
        return $this->currency.' '.$this->title.' at '.$this->scheduled_at->toDateTimeString().' UTC';
    }

    /**
     * When the calendar was last extended, for the staleness check in HealthMonitor.
     *
     * The newest *scheduled* event, not the newest row: an importer that keeps re-writing last
     * week's rows is not a working importer, and `updated_at` would call it one.
     */
    public static function horizon(): ?Carbon
    {
        $newest = self::query()->max('scheduled_at');

        return $newest === null ? null : Carbon::parse($newest);
    }
}

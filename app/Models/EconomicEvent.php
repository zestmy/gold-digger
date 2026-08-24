<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Economic Event
 *
 * A scheduled macro release from the calendar feed. See the migration for why this table
 * exists at all: `news_filter_enabled` was a setting with nothing behind it.
 *
 * Only `high` impact gates trading. Medium and low are stored and displayed because
 * knowing a quiet afternoon is quiet for a reason is worth something, but gating on them
 * would blackout most of the trading day for no measured benefit.
 */
class EconomicEvent extends Model
{
    public const IMPACT_HIGH = 'high';

    protected $fillable = [
        'external_id',
        'title',
        'currency',
        'impact',
        'scheduled_at',
        'actual',
        'forecast',
        'previous',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * The identity of an event on a feed that publishes no ids.
     *
     * Title, currency and scheduled time together are stable across refetches within a
     * week, which is what upserting needs. Deliberately not including forecast or
     * previous: those are revised in place, and including them would make a revision look
     * like a new event and double it in the calendar.
     */
    public static function identify(string $title, string $currency, Carbon $scheduledAt): string
    {
        return hash('sha256', strtolower(trim($title))."|{$currency}|".$scheduledAt->utc()->toIso8601String());
    }

    public function scopeHighImpact(Builder $query): Builder
    {
        return $query->where('impact', self::IMPACT_HIGH);
    }

    /**
     * @param  array<int, string>  $currencies
     */
    public function scopeForCurrencies(Builder $query, array $currencies): Builder
    {
        return $query->whereIn('currency', array_map('strtoupper', $currencies));
    }

    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('scheduled_at', [$from, $to]);
    }

    public function isHighImpact(): bool
    {
        return $this->impact === self::IMPACT_HIGH;
    }

    /**
     * Has this release already printed?
     *
     * `actual` arriving is the signal that it has, rather than the clock passing: a
     * delayed release is still ahead of you even once its scheduled minute is behind you.
     */
    public function hasPrinted(): bool
    {
        return $this->actual !== null && $this->actual !== '';
    }
}

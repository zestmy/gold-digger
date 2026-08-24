<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Candle Model
 *
 * One closed bar of one series. See the migration for why the series comes from the
 * executor's own feed rather than a market-data vendor.
 */
class Candle extends Model
{
    protected $fillable = [
        'user_id',
        'broker_account_id',
        'symbol',
        'timeframe',
        'open_time',
        'open',
        'high',
        'low',
        'close',
        'tick_volume',
        'spread_points',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'open_time' => 'datetime',
            'open' => 'float',
            'high' => 'float',
            'low' => 'float',
            'close' => 'float',
            'tick_volume' => 'integer',
            'spread_points' => 'float',
        ];
    }

    // =========================================================================
    // QUERIES
    // =========================================================================

    public function scopeSeries(Builder $query, ?int $brokerAccountId, string $symbol, string $timeframe): Builder
    {
        return $query
            ->where('broker_account_id', $brokerAccountId)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe);
    }

    /**
     * The newest `$limit` closed bars, returned oldest-first.
     *
     * Oldest-first is what every indicator in App\Services\Indicators expects, and the
     * ordering is applied here rather than left to callers because an accidentally
     * reversed series does not throw - it silently produces an EMA of the future.
     */
    public static function recentSeries(
        ?int $brokerAccountId,
        string $symbol,
        string $timeframe,
        int $limit,
    ): array {
        return self::query()
            ->series($brokerAccountId, $symbol, $timeframe)
            ->orderByDesc('open_time')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->all();
    }

    /**
     * Close prices of a series, oldest-first.
     */
    public static function closes(array $candles): array
    {
        return array_map(static fn (self $c) => (float) $c->close, $candles);
    }

    /**
     * High prices of a series, oldest-first.
     */
    public static function highs(array $candles): array
    {
        return array_map(static fn (self $c) => (float) $c->high, $candles);
    }

    /**
     * Low prices of a series, oldest-first.
     */
    public static function lows(array $candles): array
    {
        return array_map(static fn (self $c) => (float) $c->low, $candles);
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }
}

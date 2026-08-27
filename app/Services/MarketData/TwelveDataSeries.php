<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Twelve Data Series
 *
 * Bars from a market-data vendor, for the surfaces that describe charts rather than decide
 * prices. See `SeriesProvider` for where that line sits and why it is not configurable.
 *
 * ## Nothing here is written to the database
 *
 * That is the whole point. A year of H1 history is 6,000 bars; fetching it to answer one
 * question and then letting it go costs nothing on disk, where storing it cost 91% of the
 * database. Responses are cached against the newest bar's boundary, so a page left open
 * does not re-fetch and a busy afternoon costs a handful of requests rather than one per
 * render.
 *
 * ## Symbols are translated, and the translation is the fragile part
 *
 * Brokers publish `XAUUSDm`, `XAUUSD.a`, `GOLD`; vendors want `XAU/USD`. The mapping is
 * config so a deployment can correct it without a release, and an unmapped symbol returns
 * nothing rather than guessing - a guess here is a chart of the wrong instrument, which is
 * worse than an empty one because it looks like an answer.
 *
 * ## Failure is empty, never an exception
 *
 * Every caller is a page or a report. A vendor being down, rate-limited or misconfigured
 * should degrade the analysis to what stored bars can support, not 500 a dashboard
 * somebody is watching a live account on.
 */
final class TwelveDataSeries implements SeriesProvider
{
    /**
     * MetaTrader timeframe names to the vendor's own interval strings.
     *
     * Kept here rather than in config because these are the vendor's vocabulary, not a
     * preference - getting one wrong returns a chart at the wrong resolution.
     */
    private const INTERVALS = [
        'M1' => '1min',
        'M5' => '5min',
        'M15' => '15min',
        'M30' => '30min',
        'H1' => '1h',
        'H4' => '4h',
        'D1' => '1day',
        'W1' => '1week',
        'MN1' => '1month',
    ];

    /** Minutes one interval's bars stay cached, keyed by how fast that interval moves. */
    private const CACHE_MINUTES = [
        'M1' => 1, 'M5' => 5, 'M15' => 15, 'M30' => 15,
        'H1' => 30, 'H4' => 60, 'D1' => 120, 'W1' => 240, 'MN1' => 240,
    ];

    public function available(): bool
    {
        return filled(config('marketdata.key'));
    }

    public function name(): string
    {
        return 'twelvedata';
    }

    /**
     * @return array<int, Candle>
     */
    public function series(string $symbol, string $timeframe, int $limit, ?int $brokerAccountId = null): array
    {
        $timeframe = strtoupper($timeframe);
        $interval = self::INTERVALS[$timeframe] ?? null;
        $ticker = $this->ticker($symbol);

        if (! $this->available() || $interval === null || $ticker === null) {
            return [];
        }

        // The vendor caps a single response. Asking for more returns the cap silently,
        // which would look like a short series rather than a truncated one.
        $limit = max(1, min($limit, (int) config('marketdata.max_bars', 5000)));

        $key = sprintf('md:%s:%s:%s:%d', $this->name(), $ticker, $interval, $limit);
        $minutes = self::CACHE_MINUTES[$timeframe] ?? 15;

        $rows = Cache::remember($key, now()->addMinutes($minutes), fn () => $this->fetch($ticker, $interval, $limit));

        return $this->toCandles($rows ?? [], $symbol, $timeframe, $brokerAccountId);
    }

    /**
     * The vendor's name for a broker's symbol, or null if nothing maps.
     *
     * Matched on the longest configured prefix, because broker suffixes vary (`XAUUSDm`,
     * `XAUUSD.a`) while the instrument does not. Longest-first so `XAUUSD` cannot be
     * claimed by a shorter entry that happens to prefix it.
     */
    private function ticker(string $symbol): ?string
    {
        $symbol = strtoupper($symbol);

        /** @var array<string, string> $map */
        $map = (array) config('marketdata.symbols', []);

        if (isset($map[$symbol])) {
            return $map[$symbol];
        }

        $keys = array_keys($map);
        usort($keys, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($keys as $prefix) {
            if (str_starts_with($symbol, strtoupper($prefix))) {
                return $map[$prefix];
            }
        }

        // Deliberately not a guess. An unmapped symbol charted as something else looks
        // like an answer, which is worse than an empty chart.
        return null;
    }

    /**
     * One request. Returns the raw `values` array, or null on any failure.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fetch(string $ticker, string $interval, int $limit): ?array
    {
        try {
            $response = Http::timeout((int) config('marketdata.timeout', 15))
                ->retry(2, 1000, when: fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->acceptJson()
                ->get(rtrim((string) config('marketdata.base_url'), '/').'/time_series', [
                    'symbol' => $ticker,
                    'interval' => $interval,
                    'outputsize' => $limit,
                    'order' => 'DESC',
                    'apikey' => (string) config('marketdata.key'),
                ]);
        } catch (Throwable $e) {
            Log::info('[marketdata] request failed: '.$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::info(sprintf('[marketdata] HTTP %d for %s %s', $response->status(), $ticker, $interval));

            return null;
        }

        // The vendor answers errors with 200 and a status field, so the status code alone
        // is not enough to know whether this worked.
        if ($response->json('status') === 'error') {
            Log::info('[marketdata] '.((string) $response->json('message')));

            return null;
        }

        $values = $response->json('values');

        return is_array($values) ? $values : null;
    }

    /**
     * Vendor rows into unsaved Candle instances, oldest-first.
     *
     * `newFromBuilder` rather than `new Candle(...)` so the model is not marked as
     * needing to exist: these are read-only views of somebody else's data, and one that
     * wandered into a `save()` would put vendor prices in the table the strategy trades
     * from. Being unsaveable by construction is cheaper than remembering not to.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, Candle>
     */
    private function toCandles(array $rows, string $symbol, string $timeframe, ?int $brokerAccountId): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (! isset($row['datetime'], $row['open'], $row['high'], $row['low'], $row['close'])) {
                continue;
            }

            $candle = new Candle;

            $candle->forceFill([
                'broker_account_id' => $brokerAccountId,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'open_time' => Carbon::parse((string) $row['datetime']),
                'open' => (float) $row['open'],
                'high' => (float) $row['high'],
                'low' => (float) $row['low'],
                'close' => (float) $row['close'],
                'tick_volume' => isset($row['volume']) ? (int) $row['volume'] : null,
                'source' => $this->name(),
            ]);

            $out[] = $candle;
        }

        // The request asked for DESC because that is how the vendor returns the newest
        // bars when a limit is applied; every consumer downstream wants oldest-first.
        return array_reverse($out);
    }
}

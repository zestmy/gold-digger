<?php

namespace App\Services\News;

use App\Models\CotReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Commitments of Traders, from the CFTC's own publication.
 *
 * ## Why the source is the regulator rather than an aggregator
 *
 * COT is published by the CFTC as public data. Every free site that redistributes it adds
 * a chance of a transcription error and a dependency on somebody else staying online, in
 * exchange for nothing - the original is a JSON endpoint with no key.
 *
 * ## Why it is fetched weekly and read as stale
 *
 * Positions are counted on a Tuesday and published the following Friday afternoon. There
 * is no version of this that is fresh, and treating it as though it were is the mistake
 * the data invites: it describes where positioning was, not where it is.
 *
 * So it is never a gate on an entry. NewsBlackout fails closed because a release during a
 * position is a live hazard; this one, missing, costs a paragraph of context and nothing
 * else, so it fails open and says so.
 */
final class CotFeed
{
    /** The CFTC's Socrata endpoint for the legacy futures-only report. */
    private const URL = 'https://publicreporting.cftc.gov/resource/6dca-aqww.json';

    /**
     * Markets worth carrying, mapped from the instruments this system trades.
     *
     * Matched on the CFTC's own market names, which are verbose and stable. Anything not
     * listed simply has no COT reading, which is the honest outcome - a great many
     * instruments have no futures market to have positioning in.
     */
    private const MARKETS = [
        'XAUUSD' => 'GOLD - COMMODITY EXCHANGE INC.',
        'XAGUSD' => 'SILVER - COMMODITY EXCHANGE INC.',
        'EURUSD' => 'EURO FX - CHICAGO MERCANTILE EXCHANGE',
        'GBPUSD' => 'BRITISH POUND - CHICAGO MERCANTILE EXCHANGE',
        'USDJPY' => 'JAPANESE YEN - CHICAGO MERCANTILE EXCHANGE',
        'AUDUSD' => 'AUSTRALIAN DOLLAR - CHICAGO MERCANTILE EXCHANGE',
        'USDCAD' => 'CANADIAN DOLLAR - CHICAGO MERCANTILE EXCHANGE',
        'USDCHF' => 'SWISS FRANC - CHICAGO MERCANTILE EXCHANGE',
        'NZDUSD' => 'NEW ZEALAND DOLLAR - CHICAGO MERCANTILE EXCHANGE',
        'US30' => 'DJIA Consolidated - CHICAGO BOARD OF TRADE',
        'NAS100' => 'NASDAQ-100 Consolidated - CHICAGO MERCANTILE EXCHANGE',
        'SPX500' => 'E-MINI S&P 500 - CHICAGO MERCANTILE EXCHANGE',
        'USOIL' => 'CRUDE OIL, LIGHT SWEET - NEW YORK MERCANTILE EXCHANGE',
    ];

    /** How many weeks of history to keep, for judging whether positioning is crowded. */
    private const WEEKS = 104;

    /**
     * @return array{ok: bool, stored: int, error: string|null}
     */
    public function fetch(): array
    {
        try {
            $response = Http::timeout(30)
                ->retry(2, 3000)
                ->acceptJson()
                ->get(self::URL, [
                    // Ordered newest first and bounded, rather than pulling the whole
                    // archive every week to keep the last two years of it.
                    '$where' => "report_date_as_yyyy_mm_dd > '".now()->subWeeks(self::WEEKS)->toDateString()."'",
                    '$order' => 'report_date_as_yyyy_mm_dd DESC',
                    '$limit' => 5000,
                ]);
        } catch (Throwable $e) {
            return $this->failure('COT fetch failed: '.$e->getMessage());
        }

        if ($response->status() === 429) {
            return $this->failure('The CFTC endpoint is rate-limiting this address.');
        }

        if (! $response->successful()) {
            return $this->failure("The CFTC endpoint returned HTTP {$response->status()}.");
        }

        $rows = $response->json();

        if (! is_array($rows)) {
            return $this->failure('The CFTC endpoint returned an unexpected payload.');
        }

        $wanted = array_flip(self::MARKETS);
        $stored = 0;

        foreach ($rows as $row) {
            $market = (string) ($row['market_and_exchange_names'] ?? '');

            // Only the handful of markets this system can attach to an instrument. The
            // report covers hundreds, and storing them all would be keeping data nothing
            // will ever read.
            if (! isset($wanted[$market])) {
                continue;
            }

            $date = $row['report_date_as_yyyy_mm_dd'] ?? null;

            if ($date === null) {
                continue;
            }

            CotReport::updateOrCreate(
                ['market' => $market, 'report_date' => Carbon::parse($date)->toDateString()],
                [
                    'noncommercial_long' => (int) ($row['noncomm_positions_long_all'] ?? 0),
                    'noncommercial_short' => (int) ($row['noncomm_positions_short_all'] ?? 0),
                    'commercial_long' => isset($row['comm_positions_long_all']) ? (int) $row['comm_positions_long_all'] : null,
                    'commercial_short' => isset($row['comm_positions_short_all']) ? (int) $row['comm_positions_short_all'] : null,
                    'open_interest' => isset($row['open_interest_all']) ? (int) $row['open_interest_all'] : null,
                    'fetched_at' => now(),
                ],
            );

            $stored++;
        }

        return ['ok' => true, 'stored' => $stored, 'error' => null];
    }

    /**
     * The CFTC market this instrument's positioning would be found under, if any.
     */
    public static function marketFor(string $symbol): ?string
    {
        return self::MARKETS[strtoupper($symbol)] ?? null;
    }

    /**
     * @return array{ok: false, stored: 0, error: string}
     */
    private function failure(string $message): array
    {
        // Logged, not raised. A missing COT reading costs a paragraph of context; a
        // scheduler that stops because of one would cost more.
        Log::info("[cot] {$message}");

        return ['ok' => false, 'stored' => 0, 'error' => $message];
    }
}

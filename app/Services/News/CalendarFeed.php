<?php

namespace App\Services\News;

use App\Models\EconomicEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calendar Feed
 *
 * Fetches the week's economic calendar and upserts it into `economic_events`.
 *
 * ## The source
 *
 * ForexFactory's weekly JSON, which is what most FX traders' calendars are built on. It
 * needs no key, which is the reason it was chosen over Finnhub or TradingEconomics - a
 * risk control that stops working when a free tier's quota runs out is not one.
 *
 * It is also unofficial and could change or disappear without notice. Everything here is
 * written on that assumption: a failed fetch leaves the previous week's rows untouched
 * rather than truncating, a malformed record is skipped rather than aborting the batch,
 * and `NewsBlackout` decides separately whether what is stored is fresh enough to trust.
 *
 * ## Field notes
 *
 * `country` carries a currency code, not a country - "USD", "EUR", "NZD". The name is the
 * feed's, not ours. `actual` is absent entirely until a release prints, rather than
 * present and empty. Dates carry a US Eastern offset and are normalised to UTC on entry.
 */
final class CalendarFeed
{
    public const URL = 'https://nfs.faireconomy.media/ff_calendar_thisweek.json';

    private const TIMEOUT_SECONDS = 15;

    /**
     * Fetch the week and upsert it.
     *
     * @return array{ok: bool, imported: int, skipped: int, error: string|null}
     */
    public function refresh(): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                // Three seconds between attempts, not milliseconds. The feed rate-limits
                // by IP and answers 429 readily; a tight retry turns one refused request
                // into three and can extend the cooldown that caused it.
                ->retry(2, 3000, throw: false)
                // Identify ourselves. A default client UA is the kind of thing a CDN
                // in front of a free endpoint declines on sight.
                ->withHeaders(['User-Agent' => 'GoldDigger/1.0 (economic calendar sync)'])
                ->acceptJson()
                ->get(self::URL);
        } catch (Throwable $e) {
            return $this->failure('Calendar fetch threw: '.$e->getMessage());
        }

        if ($response->status() === 429) {
            // Named separately because the remedy is "wait", not "investigate". Hourly
            // scheduling stays well inside the limit; this is what a manual re-run during
            // debugging looks like, and it must not read as the feed being broken.
            return $this->failure('Calendar is rate-limiting us (HTTP 429). The stored calendar is unchanged.');
        }

        if (! $response->successful()) {
            return $this->failure("Calendar fetch returned HTTP {$response->status()}.");
        }

        $rows = $response->json();

        if (! is_array($rows)) {
            return $this->failure('Calendar response was not a JSON array.');
        }

        // An empty array is not obviously an error - a week could legitimately be empty -
        // but in practice it means the feed changed shape. Refuse it rather than let
        // `fetched_at` advance on nothing and make stale data look fresh.
        if ($rows === []) {
            return $this->failure('Calendar returned no events; treating as a failed fetch.');
        }

        $now = Carbon::now('UTC');
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $event = $this->parse($row, $now);

            if ($event === null) {
                $skipped++;

                continue;
            }

            EconomicEvent::updateOrCreate(['external_id' => $event['external_id']], $event);
            $imported++;
        }

        if ($imported === 0) {
            return $this->failure("Calendar had {$skipped} rows and none could be parsed.");
        }

        return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'error' => null];
    }

    /**
     * @param  mixed  $row
     * @return array<string, mixed>|null
     */
    private function parse($row, Carbon $now): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $title = trim((string) ($row['title'] ?? ''));
        $currency = strtoupper(trim((string) ($row['country'] ?? '')));
        $date = (string) ($row['date'] ?? '');

        if ($title === '' || $currency === '' || $date === '') {
            return null;
        }

        try {
            $scheduledAt = Carbon::parse($date)->utc();
        } catch (Throwable) {
            // A single unparseable date must not cost the rest of the week.
            return null;
        }

        return [
            'external_id' => EconomicEvent::identify($title, $currency, $scheduledAt),
            'title' => $title,
            'currency' => $currency,
            'impact' => $this->normaliseImpact($row['impact'] ?? null),
            'scheduled_at' => $scheduledAt,
            'actual' => $this->cleanValue($row['actual'] ?? null),
            'forecast' => $this->cleanValue($row['forecast'] ?? null),
            'previous' => $this->cleanValue($row['previous'] ?? null),
            'fetched_at' => $now,
        ];
    }

    /**
     * The feed publishes "High" / "Medium" / "Low" / "Holiday".
     *
     * Anything unrecognised becomes `low`, never `high`: an unknown impact must not be
     * able to invent a blackout the user did not ask for.
     */
    private function normaliseImpact(mixed $impact): string
    {
        $value = strtolower(trim((string) $impact));

        return in_array($value, ['high', 'medium', 'low', 'holiday'], true) ? $value : 'low';
    }

    private function cleanValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 32);
    }

    /**
     * @return array{ok: false, imported: int, skipped: int, error: string}
     */
    private function failure(string $message): array
    {
        // Logged rather than thrown: the caller is a scheduled command, and a calendar
        // that is briefly unreachable is an expected condition, not an exception. What it
        // must not do is silently look like success - `fetched_at` stays where it was, so
        // NewsBlackout sees the data ageing.
        Log::warning("[calendar] {$message}");

        return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'error' => $message];
    }
}

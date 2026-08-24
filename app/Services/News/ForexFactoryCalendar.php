<?php

namespace App\Services\News;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ForexFactory Calendar
 *
 * Reads the free weekly calendar feed. No key, no account, one request.
 *
 * The feed publishes the current week as a flat JSON array:
 *
 *     {"title":"Core Retail Sales q/q","country":"NZD","date":"2026-08-23T18:45:00-04:00",
 *      "impact":"Low","forecast":"0.3%","previous":"1.0%"}
 *
 * ## `country` is a currency
 *
 * The field is named for the country and contains the currency code - USD, EUR, NZD. It is
 * stored as `currency` here because that is what it is and what the filter matches on.
 *
 * ## Times arrive with an offset, and are stored without one
 *
 * The feed publishes New York time with an explicit UTC offset that changes twice a year with
 * US daylight saving. `Carbon::parse` honours the offset, and everything is converted to UTC
 * on the way in - because bars are indexed in UTC, and a comparison between a bar and an event
 * is not a place to be doing timezone arithmetic.
 *
 * ## A bad response is not an exception
 *
 * Anything unreachable, non-JSON, or shaped wrong returns an empty array and logs. The
 * importer treats that as "no new information" and leaves the calendar untouched, so a feed
 * outage degrades to a stale calendar - which `HealthMonitor` already watches for - rather
 * than to a failed scheduled command nobody reads the output of.
 */
final class ForexFactoryCalendar implements CalendarSource
{
    /**
     * How the feed's impact words map onto the `market_events.impact` enum.
     *
     * Unrecognised values fall to 'low', which is the level the filter ignores by default. A
     * new word from the feed should not silently start blocking trades.
     */
    private const IMPACTS = [
        'high' => 'high',
        'medium' => 'medium',
        'low' => 'low',
        'holiday' => 'holiday',
        'non-economic' => 'holiday',
    ];

    public function name(): string
    {
        return 'forexfactory';
    }

    public function fetch(): array
    {
        $url = (string) config('trading.news.calendar_url');

        if ($url === '') {
            return [];
        }

        try {
            $response = Http::timeout((int) config('trading.news.calendar_timeout', 15))
                ->acceptJson()
                ->get($url);

            if (! $response->successful()) {
                Log::warning('Calendar feed returned HTTP '.$response->status(), ['url' => $url]);

                return [];
            }

            $rows = $response->json();
        } catch (Throwable $exception) {
            Log::warning('Calendar feed unreachable: '.$exception->getMessage(), ['url' => $url]);

            return [];
        }

        if (! is_array($rows)) {
            Log::warning('Calendar feed was not a JSON array.', ['url' => $url]);

            return [];
        }

        $events = [];

        foreach ($rows as $row) {
            $event = $this->parse($row);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * One feed row into one storable event, or null if it is not usable.
     *
     * A row missing a title, a currency or a parseable time cannot be matched against a bar,
     * and a row that cannot be matched against a bar is not worth storing. Skipping it is
     * quieter than storing a half-row that later blacks out nothing and looks like a bug.
     */
    private function parse(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $title = trim((string) ($row['title'] ?? ''));
        $currency = strtoupper(trim((string) ($row['country'] ?? '')));
        $date = trim((string) ($row['date'] ?? ''));

        if ($title === '' || $currency === '' || $date === '') {
            return null;
        }

        try {
            $scheduledAt = Carbon::parse($date)->utc();
        } catch (Throwable) {
            return null;
        }

        return [
            'title' => $title,
            'currency' => $currency,
            'impact' => self::IMPACTS[strtolower(trim((string) ($row['impact'] ?? '')))] ?? 'low',
            'scheduled_at' => $scheduledAt,
            'forecast' => $this->nullableString($row['forecast'] ?? null),
            'previous' => $this->nullableString($row['previous'] ?? null),
            'actual' => $this->nullableString($row['actual'] ?? null),
        ];
    }

    /**
     * The feed sends "" for a value it does not have. Null is what that means.
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 32);
    }
}

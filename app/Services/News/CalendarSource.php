<?php

namespace App\Services\News;

use Illuminate\Support\Carbon;

/**
 * Calendar Source
 *
 * Where scheduled economic releases come from.
 *
 * There is one implementation today and the interface still earns its place, for a reason
 * specific to this data: free calendar feeds disappear, change shape without notice, and rate
 * limit. The filter that consumes this is a trading gate, so the day the feed breaks is a day
 * something has to replace it quickly - and swapping a class named in config is a smaller
 * operation under time pressure than unpicking HTTP calls from an importer.
 */
interface CalendarSource
{
    /**
     * Every event the source currently publishes.
     *
     * Returns rows shaped for `market_events`, with `scheduled_at` already resolved to UTC.
     * An unreachable or malformed source returns an empty array rather than throwing: the
     * importer's contract with the rest of the system is that a failed import leaves the
     * calendar as it was.
     *
     * @return array<int, array{title: string, currency: string, impact: string, scheduled_at: Carbon, forecast: ?string, previous: ?string, actual: ?string}>
     */
    public function fetch(): array;

    /**
     * The value written to `market_events.source`, so a row can be traced to what wrote it.
     */
    public function name(): string;
}

<?php

namespace App\Services\News;

use App\Models\BotSettings;
use App\Models\EconomicEvent;
use App\Services\Instruments\InstrumentProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * News Blackout
 *
 * Decides whether a moment sits too close to a high-impact release to trade.
 *
 * ## Why this fails closed
 *
 * If `news_filter_enabled` is on and the calendar is missing or stale, this reports a
 * blackout rather than waving the trade through. That is the uncomfortable direction, and
 * it is the correct one: the setting is a declared risk control, and a control that
 * quietly stops applying when its data source has a bad afternoon is the exact failure
 * that gets budgeted for and then isn't there. Gold moves several dollars in seconds on an
 * NFP print; a stop placed 4 pips from entry is not a stop during it.
 *
 * The cost is real and deliberate: if the feed stays down, entries stop. That is visible
 * rather than silent - the signal is recorded with `news_data_stale`, distinct from
 * `news_blackout`, so the two are never confused - and the alerting will say so. Turning
 * `news_filter_enabled` off resumes trading immediately, which is a decision a person
 * makes knowingly rather than one a failed HTTP request makes for them.
 *
 * ## Only high impact
 *
 * Medium and low releases are stored and shown but gate nothing. Blacking out for every
 * medium-impact speech would close most of the trading day, and there is no evidence here
 * that it would pay for itself.
 */
final class NewsBlackout
{
    /**
     * How old the calendar may be before it stops being evidence.
     *
     * The feed is refreshed hourly, so six hours is five consecutive failures - past any
     * transient outage and into "this is broken".
     */
    public const STALE_AFTER_HOURS = 6;

    public const REASON_BLACKOUT = 'news_blackout';

    public const REASON_STALE = 'news_data_stale';

    /**
     * Why trading is blocked at `$moment`, or null when it is not.
     *
     * @param  array<int, string>  $currencies  Currencies the instrument is exposed to
     * @return self::REASON_*|null
     */
    public function objection(?BotSettings $settings, array $currencies, Carbon $moment): ?string
    {
        if ($settings === null || ! $settings->news_filter_enabled) {
            return null;
        }

        if ($currencies === []) {
            // Nothing to be exposed to. A symbol whose currencies could not be read is not
            // evidence of danger, and refusing every trade on it would be a filter nobody
            // configured.
            return null;
        }

        if ($this->isStale()) {
            return self::REASON_STALE;
        }

        $before = (int) ($settings->news_blackout_before_minutes ?? 0);
        $after = (int) ($settings->news_blackout_after_minutes ?? 0);

        // A window of zero on both sides means the filter is on but configured to block
        // nothing. Respect that rather than inventing a default.
        if ($before <= 0 && $after <= 0) {
            return null;
        }

        $exists = EconomicEvent::query()
            ->highImpact()
            ->forCurrencies($currencies)
            // An event at T blacks out [T - before, T + after]. Asked from the moment's
            // side: any event scheduled between (moment - after) and (moment + before).
            ->between($moment->copy()->subMinutes($after), $moment->copy()->addMinutes($before))
            ->exists();

        return $exists ? self::REASON_BLACKOUT : null;
    }

    /**
     * The next high-impact release for these currencies, for display and countdowns.
     *
     * @param  array<int, string>  $currencies
     */
    public function nextEvent(array $currencies, Carbon $from): ?EconomicEvent
    {
        if ($currencies === []) {
            return null;
        }

        return EconomicEvent::query()
            ->highImpact()
            ->forCurrencies($currencies)
            ->where('scheduled_at', '>=', $from)
            ->orderBy('scheduled_at')
            ->first();
    }

    /**
     * Is the stored calendar too old to be evidence of anything?
     *
     * Cached briefly because the signal path asks this per evaluation and the answer
     * cannot meaningfully change between two bars.
     */
    public function isStale(): bool
    {
        return Cache::remember('news.calendar.stale', now()->addMinute(), function (): bool {
            $freshest = EconomicEvent::max('fetched_at');

            if ($freshest === null) {
                // Never fetched. Distinct from stale in meaning but identical in
                // consequence: there is no calendar to check against.
                return true;
            }

            return Carbon::parse($freshest)->lt(now()->subHours(self::STALE_AFTER_HOURS));
        });
    }

    /**
     * Currencies an instrument is exposed to, read off its name.
     *
     * XAUUSD is exposed to USD releases; gold itself has no calendar. Broker suffixes
     * (XAUUSDm, XAUUSD.a) are stripped first, which is why the strategy's configured
     * symbol is the right input here rather than the resolved one.
     *
     * @return array<int, string>
     */
    public function currenciesFor(string $symbol): array
    {
        // Delegated to InstrumentProfile because reading a pair off a six-letter name only
        // works for FX and metals. US30 has no second leg, so the old rule returned no
        // currencies and an index never blacked out - through the US calendar, which is
        // the one thing that moves it hardest.
        return app(InstrumentProfile::class)->for($symbol)['currencies'];
    }
}

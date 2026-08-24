<?php

namespace App\Services\Strategy;

use App\Models\BotSettings;
use App\Models\MarketEvent;
use Carbon\CarbonInterface;

/**
 * News Blackout
 *
 * Decides whether a bar falls close enough to a scheduled release that a new position should
 * not be opened on it.
 *
 * This is what `bot_settings.news_filter_enabled` has always claimed to do and never did. The
 * switch has been on the settings page, defaulted to true, since the table was created, and it
 * appeared in no decision path anywhere. Until now a user reading their own settings page was
 * being told something untrue about their own risk.
 *
 * ## Deliberately not an AI feature
 *
 * `docs/AI_INTEGRATION.md` puts this under the AI plan and then argues it out again: the rule
 * has to replay identically inside `php artisan backtest`, and a language model cannot do that
 * - its training data contains the future of any bar it is asked about, so a backtested model
 * verdict is contaminated by construction and flatters itself. Arithmetic over a calendar has
 * no such problem. The model's place here is describing the week ahead in the digest, not
 * deciding whether an order is placed.
 *
 * ## It fails open, and the monitor is what makes that safe
 *
 * An empty calendar blocks nothing. The alternative - treating "no events known" as "assume the
 * worst" - means a failed import silently halts trading, and a bot that stops for reasons its
 * owner cannot see is worse than one that trades through a release. So the filter degrades to
 * the behaviour that existed before it, and `HealthMonitor` raises `news_calendar_stale` when
 * the calendar stops being extended. That alert is the only reason failing open is defensible,
 * exactly as `queue_stalled` is what makes `trading.queue_evaluation` safe to offer.
 *
 * ## The window is compared against the whole bar, not its open
 *
 * `TradingSession` tests the bar's open time, because a session is hours wide and an hour
 * either way does not change which session a bar belongs to. A blackout is minutes wide, and
 * the entry does not happen at the bar's open - it happens after the bar closes, when the
 * strategy sees it. Testing the open alone leaves a hole one timeframe deep on the near side
 * of every event: a bar opening at 12:14 for a window starting at 12:15 would be let through
 * and then filled at 12:19, inside the blackout it was supposed to respect. So the bar is
 * treated as the interval it covers, and any overlap with the window is enough.
 */
final class NewsBlackout
{
    /**
     * @param  array<int, MarketEvent>|null  $events  Preloaded events, or null to query per call.
     */
    public function __construct(private readonly ?array $events = null) {}

    /**
     * Preload every event of interest in a range, for callers that will ask about thousands of
     * bars inside it.
     *
     * The backtester walks a whole series; querying per bar would be one round trip per bar to
     * re-read the same handful of rows. The range wants padding by the widest blackout the
     * caller might apply, since an event just outside it can still reach in.
     */
    public static function forRange(CarbonInterface $from, CarbonInterface $to): self
    {
        $events = MarketEvent::query()
            ->ofInterest(self::currencies(), self::impacts())
            ->inWindow($from, $to)
            ->orderBy('scheduled_at')
            ->get()
            ->all();

        return new self($events);
    }

    /**
     * The event blacking out a bar, or null if nothing does.
     *
     * Returns the event rather than a boolean so the caller can say *which* release it stood
     * aside for. "news_blackout" on its own is the kind of skip reason that gets argued with;
     * "news_blackout" next to a log line naming the release is not.
     */
    public function blocking(
        ?BotSettings $settings,
        CarbonInterface $barOpen,
        string $timeframe,
    ): ?MarketEvent {
        if ($settings === null || ! $settings->news_filter_enabled) {
            return null;
        }

        $before = (int) ($settings->news_blackout_before_minutes ?? 0);
        $after = (int) ($settings->news_blackout_after_minutes ?? 0);

        // Both sides zero is a filter switched on and configured to do nothing. Treating that
        // as "block the instant of the release" would be inventing a window the user set to
        // nothing on purpose.
        if ($before <= 0 && $after <= 0) {
            return null;
        }

        $barEnd = $barOpen->copy()->addSeconds(Timeframe::seconds($timeframe));

        foreach ($this->candidates($barOpen, $barEnd, $before, $after) as $event) {
            if ($event->blacksOut($barOpen, $barEnd, $before, $after)) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Events that could possibly reach this bar.
     *
     * @return iterable<int, MarketEvent>
     */
    private function candidates(CarbonInterface $barOpen, CarbonInterface $barEnd, int $before, int $after): iterable
    {
        if ($this->events !== null) {
            return $this->events;
        }

        // An event is a candidate if its own window can touch the bar, so the query widens by
        // the blackout in the opposite direction on each side.
        return MarketEvent::query()
            ->ofInterest(self::currencies(), self::impacts())
            ->inWindow(
                $barOpen->copy()->subMinutes($after),
                $barEnd->copy()->addMinutes($before),
            )
            ->orderBy('scheduled_at')
            ->get()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function currencies(): array
    {
        return (array) config('trading.news.currencies', ['USD']);
    }

    /**
     * @return array<int, string>
     */
    public static function impacts(): array
    {
        return (array) config('trading.news.impacts', ['high']);
    }
}

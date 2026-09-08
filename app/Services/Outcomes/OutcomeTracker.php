<?php

namespace App\Services\Outcomes;

use App\Models\BotHeartbeat;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\SignalOutcome;
use App\Models\TelegramSignal;
use App\Services\Strategy\SymbolResolver;
use App\Services\Strategy\TradingSession;
use App\Support\Timeframe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Outcome Tracker
 *
 * Opens an outcome row for every signal and walks it forward over the bars that arrive.
 *
 * ## Why it measures the signal, not the trade
 *
 * A position is one way of acting on a signal, with a size, a broker, a fill and a stop
 * that moves. The signal itself is a claim about price: from here, this level before that
 * one. That claim can be scored from bars alone, for every signal ever recorded, whether
 * or not anybody traded it - which is what turns two dozen signals a month into a dataset
 * and a "profitable" into something with a sample size beside it.
 *
 * ## The rules, stated so they can be argued with
 *
 * - A level counts as touched when the bar's range reaches it: high for a target above,
 *   low for one below. Nothing is interpolated inside a bar.
 * - When a bar reaches both the stop and a target, the stop is taken to have come first.
 *   The order inside a bar is unknowable from bars, and the pessimistic reading is the one
 *   that cannot flatter a strategy. Where a target was already reached on an earlier bar,
 *   that stands.
 * - `won` and `lost` are decided by whichever of TP1 and the stop is reached first.
 *   Later targets are recorded for what they are worth; they do not change the verdict.
 * - Tracking runs to the stop, the final target, or the horizon - whichever comes first -
 *   so excursions and later rungs are measured even after the verdict is in.
 * - The first bar counted is the one after the signal bar for the strategy's signals
 *   (the bar close is the entry reference and its own bar is spent) and the first bar
 *   opening at or after the post time for a copied one.
 * - A copied signal that names an entry is a pending order. Nothing is scored until a
 *   bar's range reaches that entry (or zone); the bars before that are counted as
 *   waiting, and a signal the market never comes back to within the horizon is
 *   `unfilled` - neither a win nor a loss. Scoring a sell posted at 4,600 with price at
 *   4,550 from the post itself credited an instant target and a favourable "worst"
 *   excursion, which is how the first day's average worst excursion came out above zero.
 */
final class OutcomeTracker
{
    /**
     * Bumped whenever a scoring rule changes. Every row records the version it was scored
     * under; the scheduled pass removes rows from an older one and re-opens them from the
     * same bars, so a rule change re-derives history on its own rather than leaving two
     * generations of figures in one table.
     *
     * 2: a copied market order is referenced to the open of the first bar after the post,
     *    not the last close before it, which could be five minutes stale in the
     *    provider's favour.
     */
    public const SCORING_VERSION = 2;

    public function __construct(
        private readonly SymbolResolver $symbols = new SymbolResolver,
        private readonly TradingSession $sessions = new TradingSession,
    ) {}

    // =========================================================================
    // OPENING
    // =========================================================================

    /**
     * Open outcome rows for every signal that has none, recent enough to matter.
     *
     * @return int how many were opened
     */
    public function openPending(?int $days = null): int
    {
        $since = now()->subDays($days ?? (int) config('outcomes.backfill_days', 30));
        $opened = 0;

        // Rows scored under an older rule are derived data: drop them and let the loops
        // below re-open them from the same bars.
        SignalOutcome::query()
            ->where(fn ($q) => $q
                ->whereNull('context->scoring_version')
                ->orWhere('context->scoring_version', '<', self::SCORING_VERSION))
            ->delete();

        Signal::query()
            ->where('generated_at', '>=', $since)
            ->whereDoesntHave('outcome')
            ->with('strategy')
            ->orderBy('id')
            ->each(function (Signal $signal) use (&$opened) {
                if ($this->openForSignal($signal) !== null) {
                    $opened++;
                }
            });

        TelegramSignal::query()
            ->where('kind', TelegramSignal::KIND_SIGNAL)
            ->where('parse_status', TelegramSignal::PARSE_OK)
            ->where(fn ($q) => $q->where('posted_at', '>=', $since)->orWhere('created_at', '>=', $since))
            ->whereDoesntHave('outcome')
            ->orderBy('id')
            ->each(function (TelegramSignal $signal) use (&$opened) {
                if ($this->openForCopied($signal) !== null) {
                    $opened++;
                }
            });

        return $opened;
    }

    /**
     * Open tracking for one of the strategy's own signals.
     *
     * The entry reference is the signal bar's close - what the signal was measured from -
     * and the levels are the ones stored on the row. A signal with no stop cannot be
     * scored in R and is skipped rather than scored against an invented one.
     */
    public function openForSignal(Signal $signal): ?SignalOutcome
    {
        $stop = $signal->sl_price === null ? null : (float) $signal->sl_price;
        $entry = (float) $signal->entry_price;

        if ($stop === null || $entry <= 0.0 || abs($entry - $stop) <= 0.0 || $signal->generated_at === null) {
            return null;
        }

        $userId = (int) $signal->strategy->user_id;
        $accountId = $this->accountFor($userId);
        $features = $signal->features ?? [];

        return SignalOutcome::create([
            'user_id' => $userId,
            'subject_type' => Signal::class,
            'subject_id' => $signal->id,
            'source' => SignalOutcome::SOURCE_AI,
            'broker_account_id' => $accountId,
            'symbol' => $signal->symbol,
            'timeframe' => strtoupper((string) $signal->timeframe),
            'direction' => $signal->direction,
            'reference_price' => $entry,
            'stop_price' => $stop,
            'tp1_price' => $signal->tp1_price === null ? null : (float) $signal->tp1_price,
            'tp2_price' => $signal->tp2_price === null ? null : (float) $signal->tp2_price,
            'tp3_price' => $signal->tp3_price === null ? null : (float) $signal->tp3_price,
            'risk' => abs($entry - $stop),
            // The signal bar is spent: its close is the reference, so counting starts on
            // the bar after it - and starts scoring at once, because the reference is
            // where the market was.
            'started_at' => $signal->generated_at->copy()->addSeconds(Timeframe::seconds((string) $signal->timeframe)),
            'activated_at' => $signal->generated_at->copy()->addSeconds(Timeframe::seconds((string) $signal->timeframe)),
            'horizon_bars' => $this->horizon(),
            'context' => $this->context([
                'confidence' => $features['quality']['confidence'] ?? null,
                'grade' => $features['quality']['grade'] ?? null,
                'risk_grade' => $features['quality']['risk'] ?? null,
                'adx' => $features['adx'] ?? null,
                'atr' => $features['atr'] ?? null,
                'skip_reason' => $signal->skip_reason,
                'traded' => (bool) $signal->was_executed,
            ], $signal->generated_at, $signal->symbol),
        ]);
    }

    /**
     * Open tracking for a parsed copied signal.
     *
     * The provider's entry is the reference where one was named; otherwise the last close
     * stored at the time it was posted, which is where a market order would have filled.
     * The instrument is resolved to the broker's own name, because that is the name the
     * bars are stored under.
     */
    public function openForCopied(TelegramSignal $signal): ?SignalOutcome
    {
        if ($signal->symbol === null || $signal->direction === null || $signal->sl_price === null) {
            return null;
        }

        $postedAt = $signal->posted_at ?? $signal->created_at;
        $accountId = $this->accountFor((int) $signal->user_id);
        $timeframe = strtoupper((string) config('outcomes.copied_timeframe', 'M5'));
        $symbol = $this->symbols->for($accountId, (string) $signal->symbol)['symbol'];

        // A named entry is a pending order: the reference is the entry, and scoring waits
        // for price to reach it. Without one the signal is at market, the reference is the
        // last close stored at the post, and scoring starts at once.
        $entry = $signal->entry_price !== null ? (float) $signal->entry_price : null;
        $zoneHigh = $signal->entry_zone_high !== null ? (float) $signal->entry_zone_high : null;
        $pending = $entry !== null;

        if ($entry === null) {
            // Where a market order placed on reading the post would have filled: the open
            // of the first bar after it. The close before the post can be five minutes
            // stale, and stale in the provider's favour whenever they post mid-move. The
            // last close is the fallback for a post nothing has been pushed since.
            $entry = Candle::query()
                ->series($accountId, $symbol, $timeframe)
                ->where('open_time', '>=', $postedAt)
                ->orderBy('open_time')
                ->value('open')
                ?? Candle::query()
                    ->series($accountId, $symbol, $timeframe)
                    ->where('open_time', '<=', $postedAt)
                    ->orderByDesc('open_time')
                    ->value('close');

            $entry = $entry === null ? null : (float) $entry;
        }

        $stop = (float) $signal->sl_price;

        if ($entry === null || $entry <= 0.0 || abs($entry - $stop) <= 0.0) {
            return null;
        }

        $tps = array_values(array_map('floatval', (array) ($signal->tp_prices ?? [])));

        return SignalOutcome::create([
            'user_id' => (int) $signal->user_id,
            'subject_type' => TelegramSignal::class,
            'subject_id' => $signal->id,
            'source' => SignalOutcome::SOURCE_COPIED,
            'broker_account_id' => $accountId,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'direction' => $signal->direction,
            'reference_price' => $entry,
            'stop_price' => $stop,
            'tp1_price' => $tps[0] ?? null,
            'tp2_price' => $tps[1] ?? null,
            'tp3_price' => $tps[2] ?? null,
            'risk' => abs($entry - $stop),
            // The first bar that opens at or after the post: the provider's own bar may
            // already be under way, and crediting it a level it touched before the post
            // would flatter every provider.
            'started_at' => $postedAt,
            'activated_at' => $pending ? null : $postedAt,
            'horizon_bars' => $this->horizon(),
            'context' => $this->context([
                'pending' => $pending,
                'entry_low' => $pending ? min($entry, $zoneHigh ?? $entry) : null,
                'entry_high' => $pending ? max($entry, $zoneHigh ?? $entry) : null,
                'channel_id' => $signal->telegram_channel_id ?? null,
                'review' => $signal->review_status,
                'execution' => $signal->execution_status,
                'traded' => $signal->execution_status === TelegramSignal::EXEC_EXECUTED,
            ], $postedAt, $symbol),
        ]);
    }

    // =========================================================================
    // ADVANCING
    // =========================================================================

    /**
     * Fold newly stored bars into every open outcome on one series.
     *
     * Called from the candle push for the series that just grew, and from the schedule
     * for everything - the push is the prompt trigger, the schedule the correction.
     *
     * @return int outcomes that changed
     */
    public function advance(?int $accountId, string $symbol, string $timeframe): int
    {
        // Unresolved rather than "open": a row keeps walking after its verdict, for the
        // later rungs and the excursions, until the stop, the last rung or the horizon.
        $outcomes = SignalOutcome::query()
            ->unresolved()
            ->where('broker_account_id', $accountId)
            ->where('symbol', $symbol)
            ->where('timeframe', strtoupper($timeframe))
            ->orderBy('started_at')
            ->get();

        if ($outcomes->isEmpty()) {
            return 0;
        }

        // One read for the whole group: from the earliest unfinished start to now.
        $from = $outcomes->min(fn (SignalOutcome $o) => ($o->last_bar_at ?? $o->started_at));

        $bars = Candle::query()
            ->series($accountId, $symbol, strtoupper($timeframe))
            ->where('open_time', '>=', $from)
            ->orderBy('open_time')
            ->get(['open_time', 'high', 'low', 'close']);

        $changed = 0;

        foreach ($outcomes as $outcome) {
            if ($this->walk($outcome, $bars)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Every open outcome, series by series.
     *
     * @return int outcomes that changed
     */
    public function advanceAll(): int
    {
        $changed = 0;

        SignalOutcome::query()
            ->unresolved()
            ->select('broker_account_id', 'symbol', 'timeframe')
            ->distinct()
            ->get()
            ->each(function (SignalOutcome $series) use (&$changed) {
                $changed += $this->advance($series->broker_account_id, $series->symbol, $series->timeframe);
            });

        return $changed;
    }

    /**
     * Walk one outcome over the bars after the ones it has already seen.
     *
     * @param  Collection<int, Candle>  $bars  oldest first, may start before this outcome does
     */
    private function walk(SignalOutcome $outcome, Collection $bars): bool
    {
        $after = $outcome->last_bar_at;
        $touched = false;

        foreach ($bars as $bar) {
            if ($bar->open_time->lt($outcome->started_at)) {
                continue;
            }

            if ($after !== null && $bar->open_time->lte($after)) {
                continue;
            }

            $this->fold($outcome, $bar);
            $touched = true;

            if ($outcome->resolved_at !== null) {
                break;
            }
        }

        if ($touched) {
            $outcome->save();
        }

        return $touched;
    }

    /**
     * One bar's contribution.
     */
    private function fold(SignalOutcome $outcome, Candle $bar): void
    {
        $buy = $outcome->isBuy();

        $high = (float) $bar->high;
        $low = (float) $bar->low;
        $close = (float) $bar->close;

        // A pending entry: nothing is scored until price reaches it. The bar that does is
        // the fill bar and is scored in full, stop included - a limit that fills and is
        // stopped inside the same bar is a loss, not a miss.
        if ($outcome->activated_at === null) {
            $entryLow = (float) ($outcome->context['entry_low'] ?? $outcome->reference_price);
            $entryHigh = (float) ($outcome->context['entry_high'] ?? $outcome->reference_price);

            if ($low <= $entryHigh && $high >= $entryLow) {
                $outcome->activated_at = $bar->open_time;
            } else {
                $outcome->wait_bars++;
                $outcome->last_bar_at = $bar->open_time;

                if ($outcome->wait_bars >= $outcome->horizon_bars) {
                    $outcome->status = SignalOutcome::UNFILLED;
                    $outcome->resolved_at = $bar->open_time;
                }

                return;
            }
        }

        $n = $outcome->bars_seen + 1;

        // Excursions: the best and worst the bar's range did, in R.
        $favourable = $outcome->r($buy ? $high : $low);
        $adverse = $outcome->r($buy ? $low : $high);

        $outcome->mfe_r = max($outcome->mfe_r ?? $favourable, $favourable);
        $outcome->mae_r = min($outcome->mae_r ?? $adverse, $adverse);

        // Touches. The stop is judged first on purpose - see the class comment.
        $stopHit = $outcome->sl_bars === null
            && ($buy ? $low <= $outcome->stop_price : $high >= $outcome->stop_price);

        if ($stopHit) {
            $outcome->sl_bars = $n;
        }

        foreach (['tp1', 'tp2', 'tp3'] as $rung) {
            $level = $outcome->{$rung.'_price'};

            if ($level === null || $outcome->{$rung.'_bars'} !== null) {
                continue;
            }

            // A target the same bar reached is not credited when the stop was reached in
            // it too: the pessimistic order is the rule.
            if ($stopHit) {
                continue;
            }

            if ($buy ? $high >= $level : $low <= $level) {
                $outcome->{$rung.'_bars'} = $n;
            }
        }

        // Snapshots of where the close sat, for the "what did it look like after N bars"
        // view that needs no levels at all.
        foreach ([1, 5, 20] as $at) {
            if ($n === $at) {
                $outcome->{"r_at_{$at}"} = $outcome->r($close);
            }
        }

        $outcome->bars_seen = $n;
        $outcome->last_bar_at = $bar->open_time;

        // The verdict, once.
        if ($outcome->first_hit === null) {
            if ($outcome->sl_bars !== null) {
                $outcome->first_hit = 'sl';
                $outcome->status = SignalOutcome::LOST;
            } elseif ($outcome->tp1_bars !== null) {
                $outcome->first_hit = 'tp1';
                $outcome->status = SignalOutcome::WON;
            }
        }

        // When to stop looking: the stop ends it; so does the last rung; so does time.
        $finalRung = $outcome->tp3_price !== null ? 'tp3' : ($outcome->tp2_price !== null ? 'tp2' : 'tp1');
        $finished = $outcome->sl_bars !== null
            || ($outcome->tp1_price !== null && $outcome->{$finalRung.'_bars'} !== null)
            || $n >= $outcome->horizon_bars;

        if ($finished) {
            if ($outcome->status === SignalOutcome::OPEN) {
                $outcome->status = SignalOutcome::EXPIRED;
            }

            $outcome->resolved_at = $bar->open_time;
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function horizon(): int
    {
        return max(1, (int) config('outcomes.horizon_bars', 100));
    }

    /**
     * The account whose bars this user's signals are measured against: the one their
     * executor reports for. Null when no terminal has ever reported.
     */
    private function accountFor(int $userId): ?int
    {
        return BotHeartbeat::query()
            ->where('user_id', $userId)
            ->orderByDesc('last_seen_at')
            ->value('broker_account_id');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function context(array $extra, Carbon $at, string $symbol): array
    {
        return $extra + [
            'scoring_version' => self::SCORING_VERSION,
            'hour_utc' => (int) $at->copy()->utc()->format('G'),
            'weekday' => (int) $at->copy()->utc()->dayOfWeekIso,
            'sessions' => $this->sessions->active($at),
            'instrument' => str_starts_with(strtoupper($symbol), 'XAU') ? 'gold' : 'major',
        ];
    }
}

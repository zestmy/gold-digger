<?php

namespace App\Livewire\Dashboard;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Strategy;
use App\Services\News\NewsBlackout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Bot Status Card Component
 *
 * The Execution card on Home: is the terminal there, what is it carrying, is anything
 * holding entries, and the two controls that matter from here - pause and flatten.
 *
 * Reads bot_heartbeats rather than reporting a hardcoded state.
 *
 * The state worth surfacing loudly is "online but blocked": the terminal is running
 * and heartbeating, so everything looks healthy, but Algo Trading is switched off and
 * every order comes back 10027. Presented as a plain ONLINE badge, that failure mode
 * reads as "the bot just never trades".
 *
 * The calendar line is the one sentence of calendar state Home keeps. It is here because
 * a blackout holds entries exactly as a paused bot does, and a reader asking "why did
 * that signal not trade" should find both answers on the same card.
 */
class BotStatusCard extends Component
{
    public bool $isOnline = false;

    public ?string $lastHeartbeat = null;

    public ?string $activeBroker = null;

    public ?string $blockedReason = null;

    public ?string $resolvedSymbol = null;

    public int $openPositions = 0;

    /** Age of the newest bar received, or null if none has ever arrived. */
    public ?string $feedAge = null;

    /**
     * Why the strategy layer cannot act, even though the terminal looks healthy.
     *
     * Separate from blockedReason, which is about the terminal itself. These are the
     * states where the EA is running fine and signals still cannot be produced or sized -
     * the failure that otherwise reads as "the bot just never trades".
     */
    public ?string $dataWarning = null;

    /**
     * Every instrument the terminal reported carrying, by the name this dashboard uses.
     *
     * @var array<int, string>
     */
    public array $instruments = [];

    /** One line on the economic calendar: off, missing, blocking, or clear. */
    public ?string $calendar = null;

    /** @var 'muted'|'stop'|'go' */
    public string $calendarTone = 'muted';

    public function mount(): void
    {
        $this->refreshStatus();
    }

    /**
     * Re-read the latest heartbeat. Called by wire:poll from the view.
     */
    public function refreshStatus(): void
    {
        $this->refreshCalendar();

        $beat = BotHeartbeat::with('brokerAccount')
            ->where('user_id', Auth::id())
            ->orderByDesc('last_seen_at')
            ->first();

        if ($beat === null) {
            $this->isOnline = false;
            $this->lastHeartbeat = null;
            $this->activeBroker = null;
            $this->blockedReason = 'No executor has ever checked in. Is the EA attached to a chart?';
            $this->resolvedSymbol = null;
            $this->openPositions = 0;
            $this->feedAge = null;
            $this->dataWarning = null;
            $this->instruments = [];

            return;
        }

        $this->isOnline = $beat->isOnline();
        $this->lastHeartbeat = $beat->last_seen_at?->diffForHumans();
        $this->activeBroker = $beat->brokerAccount?->label;
        $this->blockedReason = $beat->blockedReason();
        $this->resolvedSymbol = $beat->resolved_symbol;
        $this->openPositions = $beat->open_positions;

        // The multi-symbol list where the EA sends one; the single resolved symbol for
        // an older build that only ever carried gold.
        $this->instruments = collect($beat->symbols ?? [])
            ->pluck('base')
            ->filter()
            ->whenEmpty(fn ($c) => collect(array_filter([$beat->resolved_symbol])))
            ->values()
            ->all();

        $newest = Candle::where('broker_account_id', $beat->broker_account_id)
            ->orderByDesc('open_time')
            ->value('open_time');

        $this->feedAge = $newest?->diffForHumans();

        // Ordered by what blocks first. Bars are the input to everything; without the
        // symbol spec the signals are recorded but can never be sized into an order.
        $this->dataWarning = match (true) {
            $newest === null => 'No price bars have arrived. Signals cannot be generated until the EA pushes candles.',
            $beat->pip_size === null => 'The terminal has not reported a pip size, so stop distances cannot be computed.',
            $beat->pip_value_per_lot === null => 'The terminal has not reported a pip value, so positions cannot be sized.',
            default => null,
        };
    }

    /**
     * The same four states the news filter distinguishes, in one line each. Stale is worded as loudly
     * as a blackout because the consequence is the same - entries are held - while the
     * remedy is completely different.
     */
    private function refreshCalendar(): void
    {
        $blackout = app(NewsBlackout::class);
        $now = Carbon::now('UTC');

        $settings = BotSettings::where('user_id', Auth::id())->first();
        $strategy = Strategy::where('user_id', Auth::id())
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        $currencies = $strategy ? $blackout->currenciesFor((string) $strategy->symbol) : [];

        if (! ($settings?->news_filter_enabled ?? false)) {
            $this->calendar = 'News filter off; entries are not held around releases.';
            $this->calendarTone = 'muted';

            return;
        }

        if ($blackout->isStale()) {
            $this->calendar = 'No calendar: the news feed is missing or stale, so entries are held.';
            $this->calendarTone = 'stop';

            return;
        }

        if ($blackout->objection($settings, $currencies, $now) === NewsBlackout::REASON_BLACKOUT) {
            $this->calendar = 'News blackout: a high-impact release is inside the window, so entries are held.';
            $this->calendarTone = 'stop';

            return;
        }

        $next = $blackout->nextEvent($currencies, $now);

        $this->calendarTone = 'go';
        $this->calendar = $next === null
            ? 'Calendar clear; no high-impact release scheduled.'
            : sprintf(
                'Calendar clear; next %s in %s.',
                $next->title,
                $next->scheduled_at->diffForHumans($now, ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]),
            );
    }

    public function render()
    {
        return view('livewire.dashboard.bot-status-card');
    }
}

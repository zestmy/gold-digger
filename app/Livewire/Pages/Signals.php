<?php

namespace App\Livewire\Pages;

use App\Models\BotHeartbeat;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use App\Services\Strategy\SignalCard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Signals Page
 *
 * Every decision the strategy layer has made, including the ones that decided not to trade.
 *
 * This is the screen the design revolves around. The bot recording *why* it declined is what
 * makes "it has not traded all morning" answerable, and until this page existed those rows
 * were only reachable through the database. A user watching an idle dashboard had no way to
 * tell a strategy that saw nothing from one blocked by a filter, and no way at all to tell
 * either from a broken data feed.
 *
 * The feed strip above the list exists for that last case: if bars have stopped arriving,
 * no signal will ever be generated and every other explanation on this page is a red
 * herring. It is one line while bars are flowing and a full panel only when they are not,
 * because a healthy feed is not what anybody came here to read about.
 *
 * ## Feed on the left, one signal on the right
 *
 * The list answers "what has fired", the card answers "what do I do about this one". They
 * used to be stacked, which meant the card pushed the list below the fold and picking a
 * different row scrolled the reader away from the thing they had just picked. Side by
 * side, choosing a row changes the card without moving anything else.
 */
#[Layout('layouts.app')]
#[Title('Signals - FXSignalPro')]
class Signals extends Component
{
    use WithPagination;

    /** Filter: '' for everything, 'taken' for acted on, or a specific skip reason. */
    public string $filter = '';

    /**
     * Which instruments the feed shows: 'gold', 'majors' or 'all'.
     *
     * Gold is its own chip because for most accounts here it is most of the feed, and
     * "everything except gold" is the question the other chip answers. Anything not
     * priced in XAU counts as a major, which is a simplification the feed can afford: the
     * point is to split the pile in two, not to classify instruments.
     */
    public string $instrument = 'all';

    /**
     * The signal shown on the card, or null for the newest.
     *
     * The card is the point of the page for somebody about to place a trade: the row says
     * what fired, the card says what to do about it against the price now. Any row can be
     * put on it, because the question "was that one worth taking" is asked about old
     * signals as often as new ones.
     *
     * In the URL, so the Home page can send somebody straight to one row and so a card
     * somebody is looking at can be sent to somebody else.
     */
    #[Url]
    public ?int $selected = null;

    /**
     * Newest bar per instrument and timeframe, for this render only.
     *
     * Private, so Livewire never carries it between requests: it is a memo for the card
     * and the list sharing one lookup, not state.
     *
     * @var array<string, Candle|null>
     */
    private array $lastBars = [];

    public function show(int $signalId): void
    {
        $this->selected = $signalId;
    }

    /**
     * Back to the latest signal. On a phone the selected card is an overlay over the
     * feed, and this is its close button; on a desktop the panel simply shows the newest
     * signal again.
     */
    public function close(): void
    {
        $this->selected = null;
    }

    /**
     * What each skip reason means, in the terms a person would ask the question.
     *
     * Kept here rather than in the view because it is the page's actual content: a bare
     * `adx_below_threshold` tells a user nothing about which knob to turn.
     */
    public const REASONS = [
        'no_bot_settings' => ['label' => 'No settings', 'help' => 'This account has no bot settings row.'],
        'bot_inactive' => ['label' => 'Bot stopped', 'help' => 'The kill switch is off. Start the bot to trade these.'],
        'algo_trading_disabled' => ['label' => 'Algo trading off', 'help' => "The terminal's Algo Trading button is off. Orders would be refused with 10027."],
        'session_closed' => ['label' => 'Outside session', 'help' => 'The bar closed outside the sessions allowed in settings.'],
        'news_blackout' => ['label' => 'News blackout', 'help' => 'A high-impact release for this pair fell inside the blackout window set in settings.'],
        'news_data_stale' => ['label' => 'No calendar', 'help' => 'The news filter is on but the calendar is missing or stale, so it cannot be checked. Entries are held rather than taken unprotected — fix the feed, or turn the filter off in settings.'],
        'adx_below_threshold' => ['label' => 'Trend too weak', 'help' => 'ADX was under the strategy threshold. Lower it to take more of these.'],
        'atr_below_threshold' => ['label' => 'Too quiet', 'help' => 'ATR was under the minimum in settings.'],
        'no_symbol_spec' => ['label' => 'No pip size', 'help' => 'The terminal has not reported the pip size, so no honest stop distance exists.'],
        'no_account_snapshot' => ['label' => 'No balance', 'help' => 'No heartbeat balance to size a position against.'],
        'reward_below_floor' => ['label' => 'Not worth the risk', 'help' => 'The take-profit the order would carry was too close to the entry against the stop, for the reward floor in settings. Lower or clear that floor to take more of these.'],
        'max_trades_reached' => ['label' => 'Too many open', 'help' => 'Already at max concurrent trades.'],
        'daily_loss_limit' => ['label' => 'Daily loss limit', 'help' => "Today's realised losses passed the configured limit."],
        'lot_size_unavailable' => ['label' => 'Cannot size', 'help' => 'Pip value per lot is unknown, so no position size could be computed.'],
    ];

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function updatingInstrument(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $strategyIds = Strategy::where('user_id', Auth::id())->pluck('id');

        $base = Signal::whereIn('strategy_id', $strategyIds);

        // The instrument chip narrows the list and the reason counts together, so the
        // chips describe the slice on screen rather than a pile the reader cannot see.
        $scoped = $this->forInstrument(clone $base);

        $signals = (clone $scoped)
            ->with(['strategy', 'resultingTrade', 'outcome'])
            ->when($this->filter === 'taken', fn ($q) => $q->whereNull('skip_reason'))
            ->when($this->filter !== '' && $this->filter !== 'taken', fn ($q) => $q->where('skip_reason', $this->filter))
            ->orderByDesc('generated_at')
            ->paginate(25);

        // Counts per reason, so the filter chips show where the signals are actually going
        // rather than making the user try each one.
        $byReason = (clone $scoped)
            ->selectRaw('skip_reason, count(*) as total')
            ->groupBy('skip_reason')
            ->orderByDesc('total')
            ->pluck('total', 'skip_reason')
            ->all();

        $heartbeat = BotHeartbeat::where('user_id', Auth::id())
            ->orderByDesc('last_seen_at')
            ->first();

        $featured = $this->featured($base);

        return view('livewire.pages.signals', [
            'signals' => $signals,
            'byReason' => $byReason,
            'total' => array_sum($byReason),
            'heartbeat' => $heartbeat,
            'feed' => $this->feed($heartbeat),
            'featured' => $featured,
            'card' => $featured === null ? null : $this->card($featured, $heartbeat),
            'readings' => $this->readings($signals->items(), $heartbeat),
        ]);
    }

    /**
     * Scope a query to the instrument chip.
     *
     * Only XAU is checked because that is the whole distinction the chips draw. A symbol
     * with a broker suffix (`XAUUSDm`) still starts with XAU, which is why this is a
     * prefix and not a list.
     */
    private function forInstrument($query)
    {
        return match ($this->instrument) {
            'gold' => $query->where('symbol', 'like', 'XAU%'),
            'majors' => $query->where('symbol', 'not like', 'XAU%'),
            default => $query,
        };
    }

    /**
     * The signal on the card: the one asked for, if it is this user's, else the newest.
     *
     * Looked up against everything the user has rather than the filtered slice, so a link
     * to one signal still lands on it whatever chips happen to be set.
     */
    private function featured($base): ?Signal
    {
        if ($this->selected !== null) {
            $chosen = (clone $base)->find($this->selected);

            if ($chosen !== null) {
                return $chosen;
            }
        }

        return (clone $base)->orderByDesc('generated_at')->first();
    }

    /**
     * The card, against the last close the feed has stored for that instrument.
     *
     * The close of the last stored bar rather than a live tick, because a live tick is
     * something only the terminal has. Its time is shown beside the price so a reader
     * knows how stale "current" is.
     *
     * @return array<string, mixed>
     */
    private function card(Signal $signal, ?BotHeartbeat $heartbeat): array
    {
        $bar = $this->lastClose($signal, $heartbeat);

        return app(SignalCard::class)->for(
            $signal,
            $bar === null ? null : (float) $bar->close,
            $bar?->open_time,
        );
    }

    /**
     * Each listed row read the same way the card reads it, so the list can say in a chip
     * what the card says in a headline.
     *
     * The same maths as the card rather than a cheaper approximation: a row that said
     * "enter now" beside a card that said "too late" for the same signal would be the
     * page disagreeing with itself. One close lookup per instrument and timeframe, not
     * per row, since a page of gold signals on M5 shares one last bar.
     *
     * @param  array<int, Signal>  $signals
     * @return array<int, array<string, mixed>>
     */
    private function readings(array $signals, ?BotHeartbeat $heartbeat): array
    {
        $readings = [];

        foreach ($signals as $signal) {
            $bar = $this->lastClose($signal, $heartbeat);

            $readings[$signal->id] = app(SignalCard::class)->for(
                $signal,
                $bar === null ? null : (float) $bar->close,
                $bar?->open_time,
            );
        }

        return $readings;
    }

    /**
     * The newest stored bar for a signal's instrument and timeframe, fetched once per
     * pair for the render.
     */
    private function lastClose(Signal $signal, ?BotHeartbeat $heartbeat): ?Candle
    {
        if ($heartbeat?->broker_account_id === null) {
            return null;
        }

        $key = $signal->symbol.'|'.$signal->timeframe;

        if (! array_key_exists($key, $this->lastBars)) {
            $this->lastBars[$key] = Candle::query()
                ->series($heartbeat->broker_account_id, $signal->symbol, $signal->timeframe)
                ->orderByDesc('open_time')
                ->first();
        }

        return $this->lastBars[$key];
    }

    /**
     * Health of the candle feed the whole strategy layer depends on.
     *
     * Reported per timeframe because the two series fail independently: a strategy whose
     * trend timeframe has stopped arriving generates nothing, and looks exactly like a
     * strategy that simply has not seen a setup.
     *
     * @return array<int, array<string, mixed>>
     */
    private function feed(?BotHeartbeat $heartbeat): array
    {
        if ($heartbeat?->broker_account_id === null) {
            return [];
        }

        $rows = Candle::query()
            ->where('broker_account_id', $heartbeat->broker_account_id)
            ->selectRaw('timeframe, count(*) as bars, max(open_time) as newest')
            ->groupBy('timeframe')
            ->get();

        return $rows->map(function ($row) {
            $newest = $row->newest ? Carbon::parse($row->newest) : null;

            return [
                'timeframe' => $row->timeframe,
                'bars' => (int) $row->bars,
                'newest' => $newest,
                // Indicators need a long warm-up: ADX alone wants 2 x period bars before it
                // reads at all, so a short series silently produces no signals.
                'warm' => (int) $row->bars >= 100,
            ];
        })->all();
    }
}

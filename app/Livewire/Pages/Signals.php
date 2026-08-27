<?php

namespace App\Livewire\Pages;

use App\Models\BotHeartbeat;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Strategy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
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
 * The feed panel at the top exists for that last case: if bars have stopped arriving, no
 * signal will ever be generated and every other explanation on this page is a red herring.
 */
#[Layout('layouts.app')]
#[Title('Signals - FXSignalPro')]
class Signals extends Component
{
    use WithPagination;

    /** Filter: '' for everything, 'taken' for acted on, or a specific skip reason. */
    public string $filter = '';

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

    public function render()
    {
        $strategyIds = Strategy::where('user_id', Auth::id())->pluck('id');

        $base = Signal::whereIn('strategy_id', $strategyIds);

        $signals = (clone $base)
            ->with(['strategy', 'resultingTrade'])
            ->when($this->filter === 'taken', fn ($q) => $q->whereNull('skip_reason'))
            ->when($this->filter !== '' && $this->filter !== 'taken', fn ($q) => $q->where('skip_reason', $this->filter))
            ->orderByDesc('generated_at')
            ->paginate(25);

        // Counts per reason, so the filter chips show where the signals are actually going
        // rather than making the user try each one.
        $byReason = (clone $base)
            ->selectRaw('skip_reason, count(*) as total')
            ->groupBy('skip_reason')
            ->orderByDesc('total')
            ->pluck('total', 'skip_reason')
            ->all();

        $heartbeat = BotHeartbeat::where('user_id', Auth::id())
            ->orderByDesc('last_seen_at')
            ->first();

        return view('livewire.pages.signals', [
            'signals' => $signals,
            'byReason' => $byReason,
            'total' => array_sum($byReason),
            'heartbeat' => $heartbeat,
            'feed' => $this->feed($heartbeat),
        ]);
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

<?php

namespace App\Livewire\Dashboard;

use App\Models\BotHeartbeat;
use App\Models\Candle;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Bot Status Card Component
 *
 * Reads bot_heartbeats rather than reporting a hardcoded state.
 *
 * The state worth surfacing loudly is "online but blocked": the terminal is running
 * and heartbeating, so everything looks healthy, but Algo Trading is switched off and
 * every order comes back 10027. Presented as a plain ONLINE badge, that failure mode
 * reads as "the bot just never trades".
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

    public function mount(): void
    {
        $this->refreshStatus();
    }

    /**
     * Re-read the latest heartbeat. Called by wire:poll from the view.
     */
    public function refreshStatus(): void
    {
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

            return;
        }

        $this->isOnline = $beat->isOnline();
        $this->lastHeartbeat = $beat->last_seen_at?->diffForHumans();
        $this->activeBroker = $beat->brokerAccount?->label;
        $this->blockedReason = $beat->blockedReason();
        $this->resolvedSymbol = $beat->resolved_symbol;
        $this->openPositions = $beat->open_positions;

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

    public function render()
    {
        return view('livewire.dashboard.bot-status-card');
    }
}

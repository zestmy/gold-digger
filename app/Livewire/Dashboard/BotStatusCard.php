<?php

namespace App\Livewire\Dashboard;

use App\Models\BotHeartbeat;
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

            return;
        }

        $this->isOnline = $beat->isOnline();
        $this->lastHeartbeat = $beat->last_seen_at?->diffForHumans();
        $this->activeBroker = $beat->brokerAccount?->label;
        $this->blockedReason = $beat->blockedReason();
        $this->resolvedSymbol = $beat->resolved_symbol;
        $this->openPositions = $beat->open_positions;
    }

    public function render()
    {
        return view('livewire.dashboard.bot-status-card');
    }
}

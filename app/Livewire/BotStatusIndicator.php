<?php

namespace App\Livewire;

use App\Models\BotHeartbeat;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Bot Status Indicator
 *
 * The dot and label in the sidebar footer, on every page.
 *
 * This used to be hardcoded markup reading "Bot Offline" with a red dot, written before
 * anything reported a heartbeat and never revisited. It said Offline while the dashboard
 * card two columns away said ONLINE, which is how a status display stops being read at
 * all. It now shares `BotHeartbeat::status()` with the card, so the two cannot disagree.
 *
 * Deliberately small: the sidebar is not the place to explain a fault, only to say that
 * one exists and where to look. `BotStatusCard` carries the reason.
 */
class BotStatusIndicator extends Component
{
    public string $status = BotHeartbeat::STATUS_OFFLINE;

    /** False when no executor has ever checked in, which reads differently to "went quiet". */
    public bool $hasEverReported = false;

    public function mount(): void
    {
        $this->refreshStatus();
    }

    /**
     * Called by wire:poll. Slower than the card's 10s: this is an at-a-glance indicator
     * rendered twice per page (mobile drawer and desktop rail), and the card is where
     * someone actually watching goes.
     */
    public function refreshStatus(): void
    {
        $beat = BotHeartbeat::where('user_id', Auth::id())
            ->orderByDesc('last_seen_at')
            ->first();

        $this->hasEverReported = $beat !== null;
        $this->status = $beat?->status() ?? BotHeartbeat::STATUS_OFFLINE;
    }

    public function render()
    {
        return view('livewire.bot-status-indicator');
    }
}

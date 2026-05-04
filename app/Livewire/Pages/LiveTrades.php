<?php

namespace App\Livewire\Pages;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Live Trades Page
 *
 * Will display currently open positions in real-time.
 * Features planned for Phase 1B:
 * - Open positions table with live P&L updates
 * - Position management (modify SL/TP, partial close)
 * - Real-time price updates via WebSocket
 */
#[Layout('layouts.app')]
#[Title('Live Trades - Gold Digger')]
class LiveTrades extends Component
{
    public string $pageTitle = 'Live Trades';
    public string $pageDescription = 'Monitor and manage your currently open positions in real-time.';
    public string $comingPhase = 'Phase 1B';

    public function render()
    {
        return view('livewire.pages.placeholder');
    }
}

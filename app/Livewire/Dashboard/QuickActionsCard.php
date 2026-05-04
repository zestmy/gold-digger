<?php

namespace App\Livewire\Dashboard;

use Livewire\Component;

/**
 * Quick Actions Card Component
 *
 * Provides quick access buttons for common bot operations:
 * - Start Bot: Activates the trading bot
 * - Stop Bot: Deactivates the trading bot
 * - Close All: Emergency close all open positions
 *
 * In Phase 3, these will trigger actual bot commands via API.
 */
class QuickActionsCard extends Component
{
    public function startBot(): void
    {
        // Phase 3: Send start command to Python bot
        session()->flash('message', 'Start Bot functionality available in Phase 3');
    }

    public function stopBot(): void
    {
        // Phase 3: Send stop command to Python bot
        session()->flash('message', 'Stop Bot functionality available in Phase 3');
    }

    public function closeAllPositions(): void
    {
        // Phase 3: Send close all command to Python bot
        session()->flash('message', 'Close All Positions functionality available in Phase 3');
    }

    public function render()
    {
        return view('livewire.dashboard.quick-actions-card');
    }
}

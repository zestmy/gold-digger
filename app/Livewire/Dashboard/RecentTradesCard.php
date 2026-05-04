<?php

namespace App\Livewire\Dashboard;

use App\Models\Trade;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Recent Trades Card Component
 *
 * Displays a table of the most recent trades:
 * - Time, Symbol, Direction, Lot Size
 * - Entry Price, Status, Pips, Net P&L
 *
 * Shows the last 10 trades, ordered by most recent first.
 */
class RecentTradesCard extends Component
{
    public Collection $trades;

    public function mount(): void
    {
        $this->loadTrades();
    }

    public function loadTrades(): void
    {
        $userId = Auth::id();

        if (!$userId) {
            $this->trades = collect();
            return;
        }

        $this->trades = Trade::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    public function render()
    {
        return view('livewire.dashboard.recent-trades-card');
    }
}

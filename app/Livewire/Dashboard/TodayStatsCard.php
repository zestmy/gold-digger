<?php

namespace App\Livewire\Dashboard;

use App\Models\DailySummary;
use App\Models\Trade;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Today's Stats Card Component
 *
 * Displays today's trading statistics:
 * - Number of trades
 * - Gross P&L (before costs)
 * - Total costs (spread + commission + swap)
 * - Net P&L (highlighted in gold)
 *
 * Data is calculated from trades closed today.
 */
class TodayStatsCard extends Component
{
    public int $tradesCount = 0;
    public float $grossPnl = 0;
    public float $totalCosts = 0;
    public float $netPnl = 0;

    public function mount(): void
    {
        $this->loadStats();
    }

    public function loadStats(): void
    {
        $userId = Auth::id();

        if (!$userId) {
            return;
        }

        // Get today's trades for the current user
        $todaysTrades = Trade::where('user_id', $userId)
            ->whereDate('closed_at', today())
            ->whereIn('status', ['fully_closed', 'stopped_out', 'partially_closed'])
            ->get();

        $this->tradesCount = $todaysTrades->count();
        $this->grossPnl = (float) $todaysTrades->sum('gross_pnl_money');

        // Calculate total costs
        $this->totalCosts = $todaysTrades->sum(function ($trade) {
            return (float) $trade->entry_spread_money
                + (float) $trade->commission_money
                + (float) $trade->swap_money;
        });

        $this->netPnl = (float) $todaysTrades->sum('net_pnl_money');
    }

    public function render()
    {
        return view('livewire.dashboard.today-stats-card');
    }
}

<?php

namespace App\Livewire\Pages;

use App\Models\Trade;
use App\Models\BrokerAccount;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Live Trades - Gold Digger')]
class LiveTrades extends Component
{
    public ?int $closingTradeId = null;

    public function closeAllTrades(): void
    {
        // In a real implementation, this would send commands to the Python bot
        // For now, we'll mark all open trades as closed
        Trade::where('user_id', Auth::id())
            ->whereIn('status', ['pending', 'open', 'partially_closed'])
            ->update([
                'status' => 'fully_closed',
                'closure_reason' => 'manual_close',
                'closed_at' => now(),
            ]);

        $this->dispatch('notify', message: 'All positions closed!', type: 'success');
    }

    public function closeTrade(int $id): void
    {
        $trade = Trade::where('user_id', Auth::id())
            ->whereIn('status', ['pending', 'open', 'partially_closed'])
            ->findOrFail($id);

        // In a real implementation, this would send a close command to the Python bot
        $trade->update([
            'status' => 'fully_closed',
            'closure_reason' => 'manual_close',
            'closed_at' => now(),
        ]);

        $this->closingTradeId = null;
        $this->dispatch('notify', message: 'Position closed!', type: 'success');
    }

    public function render()
    {
        $trades = Trade::where('user_id', Auth::id())
            ->with(['strategy', 'brokerAccount'])
            ->whereIn('status', ['pending', 'open', 'partially_closed'])
            ->orderBy('opened_at', 'desc')
            ->get();

        $activeAccount = BrokerAccount::where('user_id', Auth::id())
            ->where('is_active', true)
            ->first();

        // Calculate summary
        $summary = [
            'total_positions' => $trades->count(),
            'total_lots' => $trades->sum('remaining_lot_size'),
            'unrealized_pnl' => $trades->sum('gross_pnl_money'),
            'buy_positions' => $trades->where('direction', 'buy')->count(),
            'sell_positions' => $trades->where('direction', 'sell')->count(),
        ];

        return view('livewire.pages.live-trades', [
            'trades' => $trades,
            'activeAccount' => $activeAccount,
            'summary' => $summary,
        ]);
    }
}

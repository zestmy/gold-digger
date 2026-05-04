<?php

namespace App\Livewire\Pages;

use App\Models\Trade;
use App\Models\Strategy;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Trade History - Gold Digger')]
class TradeHistory extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $direction = '';

    #[Url]
    public string $strategy = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public ?int $viewingTradeId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingDirection(): void
    {
        $this->resetPage();
    }

    public function updatingStrategy(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->direction = '';
        $this->strategy = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    public function viewTrade(int $id): void
    {
        $this->viewingTradeId = $id;
    }

    public function closeTradeView(): void
    {
        $this->viewingTradeId = null;
    }

    public function render()
    {
        $query = Trade::where('user_id', Auth::id())
            ->with(['strategy', 'brokerAccount'])
            ->whereNotIn('status', ['pending', 'open']);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('symbol', 'like', "%{$this->search}%")
                    ->orWhere('mt5_ticket', 'like', "%{$this->search}%")
                    ->orWhere('notes', 'like', "%{$this->search}%");
            });
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->direction) {
            $query->where('direction', $this->direction);
        }

        if ($this->strategy) {
            $query->where('strategy_id', $this->strategy);
        }

        if ($this->dateFrom) {
            $query->whereDate('closed_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('closed_at', '<=', $this->dateTo);
        }

        $trades = $query->orderBy('closed_at', 'desc')->paginate(20);

        // Get summary stats for filtered results
        $summaryQuery = Trade::where('user_id', Auth::id())
            ->whereNotIn('status', ['pending', 'open']);

        if ($this->dateFrom) {
            $summaryQuery->whereDate('closed_at', '>=', $this->dateFrom);
        }
        if ($this->dateTo) {
            $summaryQuery->whereDate('closed_at', '<=', $this->dateTo);
        }

        $summary = [
            'total_trades' => $summaryQuery->count(),
            'winners' => (clone $summaryQuery)->where('net_pnl_money', '>', 0)->count(),
            'losers' => (clone $summaryQuery)->where('net_pnl_money', '<', 0)->count(),
            'total_pnl' => (clone $summaryQuery)->sum('net_pnl_money'),
        ];

        $strategies = Strategy::where('user_id', Auth::id())->pluck('name', 'id');

        $viewingTrade = $this->viewingTradeId
            ? Trade::with(['strategy', 'brokerAccount', 'partials', 'screenshots'])
                ->where('user_id', Auth::id())
                ->find($this->viewingTradeId)
            : null;

        return view('livewire.pages.trade-history', [
            'trades' => $trades,
            'strategies' => $strategies,
            'summary' => $summary,
            'viewingTrade' => $viewingTrade,
        ]);
    }
}

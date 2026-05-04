<?php

namespace App\Livewire\Pages;

use App\Models\BotLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Bot Logs - Gold Digger')]
class BotLogs extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $level = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public array $levels = [
        'debug' => ['label' => 'Debug', 'color' => 'gray'],
        'info' => ['label' => 'Info', 'color' => 'blue'],
        'warning' => ['label' => 'Warning', 'color' => 'yellow'],
        'error' => ['label' => 'Error', 'color' => 'red'],
        'critical' => ['label' => 'Critical', 'color' => 'purple'],
    ];

    public array $sources = [
        'python_bot' => 'Python Bot',
        'laravel' => 'Laravel Dashboard',
        'mt5' => 'MT5 Terminal',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingLevel(): void
    {
        $this->resetPage();
    }

    public function updatingSource(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'level', 'source', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function clearLog(int $id): void
    {
        BotLog::find($id)?->delete();
        $this->dispatch('notify', message: 'Log entry deleted!', type: 'success');
    }

    public function clearAllLogs(): void
    {
        BotLog::query()->delete();
        $this->dispatch('notify', message: 'All logs cleared!', type: 'success');
    }

    public function render()
    {
        $query = BotLog::query()
            ->with(['trade', 'signal'])
            ->orderBy('created_at', 'desc');

        if ($this->search) {
            $query->where('message', 'like', "%{$this->search}%");
        }

        if ($this->level) {
            $query->where('level', $this->level);
        }

        if ($this->source) {
            $query->where('source', $this->source);
        }

        if ($this->dateFrom) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        $logs = $query->paginate(50);

        // Get log stats
        $stats = [
            'total' => BotLog::count(),
            'errors' => BotLog::whereIn('level', ['error', 'critical'])->count(),
            'warnings' => BotLog::where('level', 'warning')->count(),
            'today' => BotLog::whereDate('created_at', today())->count(),
        ];

        return view('livewire.pages.bot-logs', [
            'logs' => $logs,
            'stats' => $stats,
        ]);
    }
}

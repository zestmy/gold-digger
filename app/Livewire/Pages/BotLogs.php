<?php

namespace App\Livewire\Pages;

use App\Models\BotLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Bot Logs
 *
 * Everything the executor, the monitor and the copier had to say, for this account.
 *
 * ## "For this account" is new, and it is the whole point of this file
 *
 * `bot_logs` had no owner column, so none of the queries below had anywhere to filter.
 * This page therefore showed every tenant every other tenant's executor output, deleted
 * any row whose id was posted to it without checking whose it was, and offered a button
 * that truncated the table for the entire platform.
 *
 * The filter is no longer written here. `BotLog` carries `BelongsToTenant`, so the scope
 * is applied by the model to reads, deletes and counts alike - including `find()`, which
 * is what closes the delete-by-id hole rather than an ownership check bolted onto the
 * action. Written out, that means: a row belonging to somebody else does not fail to
 * delete, it fails to be found, which is the same answer this page would give for an id
 * that never existed.
 *
 * Rows the backfill could not attribute have a null owner and match nobody. They are
 * reachable only from the admin panel, which is the correct place for them.
 */
#[Layout('layouts.app')]
#[Title('Bot Logs - FXSignalPro')]
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

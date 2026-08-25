<?php

namespace App\Livewire\Pages;

use App\Jobs\RunStrategyImprovement;
use App\Models\BotHeartbeat;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Models\StrategyImprovement as ImprovementRun;
use App\Services\Ai\StrategyImprovement;
use App\Services\Ai\StrategyProposer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Strategy Improver
 *
 * Queue a run, watch it, read what it measured.
 *
 * ## What this page is careful about
 *
 * It shows an LLM's suggestions next to backtest numbers, on the same screen as a live
 * account. The temptation it has to resist is presenting a proposal that reads well as a
 * proposal that performed well - so the verdict is rendered before the table, a thin
 * sample suppresses any "better" badge regardless of the arithmetic, and there is no
 * apply button anywhere. Changing a strategy stays something a person does, having read
 * why.
 */
#[Layout('layouts.app')]
#[Title('Strategy Improver - Gold Digger')]
class StrategyImprover extends Component
{
    #[Validate('required|integer')]
    public int $strategyId = 0;

    #[Validate('required|integer|min:2000|max:60000')]
    public int $bars = StrategyImprovement::DEFAULT_BARS;

    #[Validate('required|integer|min:2|max:8')]
    public int $folds = 4;

    #[Validate('required|integer|min:1|max:100')]
    public int $minTrades = 10;

    public ?int $watching = null;

    public function mount(): void
    {
        $this->strategyId = (int) (Strategy::where('user_id', Auth::id())
            ->orderByDesc('is_active')->orderBy('id')->value('id') ?? 0);

        // Re-attach to a run already in flight, so a refresh or a second tab does not
        // look like nothing is happening and invite a duplicate.
        $this->watching = ImprovementRun::where('user_id', Auth::id())
            ->whereIn('status', [ImprovementRun::STATUS_QUEUED, ImprovementRun::STATUS_RUNNING])
            ->value('id');
    }

    public function queueRun(): void
    {
        $this->validate();

        if (! app(StrategyProposer::class)->configured()) {
            $this->addError('strategyId', 'No OPENROUTER_API_KEY is configured.');

            return;
        }

        $strategy = Strategy::where('user_id', Auth::id())->find($this->strategyId);

        if ($strategy === null) {
            $this->addError('strategyId', 'That strategy does not belong to this account.');

            return;
        }

        // One at a time. Two concurrent walk-forwards on a 1GB droplet is an out-of-memory
        // kill, and the kernel's usual choice of victim is MySQL.
        $inFlight = ImprovementRun::where('user_id', Auth::id())
            ->whereIn('status', [ImprovementRun::STATUS_QUEUED, ImprovementRun::STATUS_RUNNING])
            ->exists();

        if ($inFlight) {
            $this->addError('strategyId', 'A run is already in progress. Wait for it to finish.');

            return;
        }

        $heartbeat = BotHeartbeat::where('user_id', Auth::id())->orderByDesc('last_seen_at')->first();

        $run = ImprovementRun::create([
            'user_id' => Auth::id(),
            'strategy_id' => $strategy->id,
            'status' => ImprovementRun::STATUS_QUEUED,
            'options' => [
                'bars' => $this->bars,
                'folds' => $this->folds,
                'min_trades' => $this->minTrades,
                'symbol' => $heartbeat?->resolved_symbol ?: $strategy->symbol,
                'account' => $heartbeat?->broker_account_id
                    ?? BrokerAccount::where('user_id', Auth::id())->where('is_active', true)->value('id'),
            ],
        ]);

        RunStrategyImprovement::dispatch($run->id);

        $this->watching = $run->id;
        $this->dispatch('notify', message: 'Run queued. This takes a few minutes.', type: 'success');
    }

    /**
     * Polled while a run is in flight, and only then.
     */
    public function refreshRuns(): void
    {
        if ($this->watching === null) {
            return;
        }

        $run = ImprovementRun::find($this->watching);

        if ($run?->isFinished()) {
            $this->watching = null;
        }
    }

    public function render()
    {
        return view('livewire.pages.strategy-improver', [
            'strategies' => Strategy::where('user_id', Auth::id())->orderBy('id')->get(),
            'runs' => ImprovementRun::with('strategy')
                ->where('user_id', Auth::id())
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
            'configured' => app(StrategyProposer::class)->configured(),
        ]);
    }
}

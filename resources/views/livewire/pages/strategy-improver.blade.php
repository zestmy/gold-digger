<div @if($watching) wire:poll.5s="refreshRuns" @endif>
    <x-slot name="header">
        Strategy Improver
    </x-slot>

    <div class="space-y-6">
        {{-- What this is, before anything that looks like a recommendation. --}}
        <div class="rounded-lg border border-gray-700 bg-gray-800/50 p-4">
            <p class="text-sm text-gray-300">
                A model proposes candidate parameters; your walk-forward backtester measures them
                on bars the proposal never saw.
            </p>
            <p class="mt-1 text-xs text-gray-500">
                The model never sees a result and never gets a vote. Nothing here is applied
                automatically &mdash; there is no apply button, by design. A proposal that does not
                beat the baseline out of sample is a proposal that failed, however well it reads.
            </p>
        </div>

        @if(! $configured)
            <div class="rounded-lg bg-gray-800 p-6">
                <p class="text-sm text-gray-400">
                    No <code class="text-gray-300">OPENROUTER_API_KEY</code> is configured, so nothing can be proposed.
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    <code>backtest:optimise</code> still works without it &mdash; it just needs you to pick the grid.
                </p>
            </div>
        @else
            <!-- Run form -->
            <div class="rounded-lg bg-gray-800 p-6">
                <h3 class="text-sm font-medium text-gray-400">New run</h3>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label for="strategyId" class="block text-sm font-medium text-gray-300">Strategy</label>
                        <select id="strategyId" wire:model="strategyId"
                                class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                            @foreach($strategies as $s)
                                <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->symbol }} {{ $s->timeframe_entry }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="bars" class="block text-sm font-medium text-gray-300">Bars</label>
                        <input id="bars" type="number" wire:model="bars" min="2000" max="60000" step="1000"
                               class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                        @error('bars') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="folds" class="block text-sm font-medium text-gray-300">Folds</label>
                        <input id="folds" type="number" wire:model="folds" min="2" max="8"
                               class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                        @error('folds') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                @error('strategyId') <p class="mt-3 text-sm text-red-400">{{ $message }}</p> @enderror

                <div class="mt-4 flex items-center gap-x-3">
                    <button type="button" wire:click="queueRun" wire:loading.attr="disabled"
                            @if($watching) disabled @endif
                            class="rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400 disabled:opacity-40">
                        {{ $watching ? 'Run in progress…' : 'Queue run' }}
                    </button>
                    <span class="text-xs text-gray-500">
                        Takes several minutes. More bars is more evidence and more memory &mdash;
                        {{ number_format(\App\Services\Ai\StrategyImprovement::DEFAULT_BARS) }} is the default for a reason.
                    </span>
                </div>
            </div>

            <!-- Runs -->
            @forelse($runs as $run)
                <div class="rounded-lg bg-gray-800 p-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-medium text-gray-300">
                            {{ $run->strategy->name ?? 'Deleted strategy' }}
                            <span class="ml-2 font-mono text-xs text-gray-500">
                                {{ $run->options['symbol'] ?? '' }}
                                @if(isset($run->options['bars'])) · {{ number_format($run->options['bars']) }} bars @endif
                                @if(isset($run->options['from'])) · {{ $run->options['from'] }} → {{ $run->options['to'] }} @endif
                            </span>
                        </h3>
                        <span class="text-xs text-gray-500">{{ $run->created_at->diffForHumans() }}</span>
                    </div>

                    @if($run->status === \App\Models\StrategyImprovement::STATUS_QUEUED)
                        <p class="mt-3 text-sm text-gray-400">Queued. Waiting for a worker to pick it up.</p>

                    @elseif($run->status === \App\Models\StrategyImprovement::STATUS_RUNNING)
                        <p class="mt-3 flex items-center gap-x-2 text-sm text-yellow-400">
                            <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-yellow-400"></span>
                            Running since {{ $run->started_at?->diffForHumans() }} — measuring the baseline, then the proposals.
                        </p>

                    @elseif($run->status === \App\Models\StrategyImprovement::STATUS_FAILED)
                        <div class="mt-3 rounded-md bg-red-900/30 p-3">
                            <p class="text-sm text-red-300">{{ $run->error }}</p>
                        </div>

                    @else
                        {{-- The verdict first. A table is far more persuasive than a caveat
                             underneath it, so the caveat goes above and the numbers arrive
                             already qualified. --}}
                        @if($run->thin)
                            <div class="mt-3 rounded-md border border-amber-500/20 bg-amber-900/20 p-3">
                                <p class="text-sm text-amber-300">{{ $run->verdict }}</p>
                                <p class="mt-1 text-xs text-amber-200/60">
                                    Nothing below supports a change &mdash; including the rows that look better.
                                </p>
                            </div>
                        @elseif($run->beatsBaseline())
                            <div class="mt-3 rounded-md border border-green-500/20 bg-green-900/20 p-3">
                                <p class="text-sm text-green-300">{{ $run->verdict }}</p>
                            </div>
                        @else
                            <div class="mt-3 rounded-md bg-gray-900 p-3">
                                <p class="text-sm text-gray-300">{{ $run->verdict }}</p>
                            </div>
                        @endif

                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-xs uppercase tracking-wide text-gray-500">
                                        <th class="py-2 pr-4 text-left font-medium">Metric</th>
                                        <th class="px-4 py-2 text-right font-medium">Baseline</th>
                                        <th class="py-2 pl-4 text-right font-medium">Proposed</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-700 font-mono text-gray-200">
                                    @foreach([
                                        'Trades' => 'trades',
                                        'Net P&L' => 'net_pnl',
                                        'Win rate' => 'win_rate',
                                        'Expectancy' => 'expectancy',
                                    ] as $label => $key)
                                        <tr>
                                            <td class="py-2 pr-4 font-sans text-gray-400">{{ $label }}</td>
                                            <td class="px-4 py-2 text-right">{{ $run->baseline[$key] ?? '—' }}</td>
                                            <td class="py-2 pl-4 text-right">{{ $run->proposed[$key] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                    <tr>
                                        <td class="py-2 pr-4 font-sans text-gray-400">Profitable folds</td>
                                        <td class="px-4 py-2 text-right">{{ $run->baseline['folds_profitable'] ?? 0 }} of {{ $run->baseline['folds_tested'] ?? 0 }}</td>
                                        <td class="py-2 pl-4 text-right">{{ $run->proposed['folds_profitable'] ?? 0 }} of {{ $run->proposed['folds_tested'] ?? 0 }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p class="mt-2 text-xs text-gray-600">
                            &ldquo;Proposed&rdquo; is the best candidate in each fold, stitched together &mdash;
                            the maximum of {{ count($run->proposals ?? []) }} draws reported as one, which flatters it.
                        </p>

                        @if($run->proposals)
                            <div class="mt-4 border-t border-gray-700 pt-4">
                                <p class="text-xs uppercase tracking-wide text-gray-500">
                                    What was proposed
                                    @if($run->model)<span class="ml-1 normal-case text-gray-600">· {{ $run->model }}</span>@endif
                                </p>
                                <ul class="mt-2 space-y-2">
                                    @foreach($run->proposals as $proposal)
                                        <li class="text-xs">
                                            <span class="font-mono text-gray-300">
                                                @foreach($proposal['parameters'] as $name => $value)
                                                    {{ $name }}={{ rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') }}@if(! $loop->last)<span class="text-gray-600">, </span>@endif
                                                @endforeach
                                            </span>
                                            <p class="mt-0.5 text-gray-500">{{ $proposal['rationale'] }}</p>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <p class="mt-4 border-t border-gray-700 pt-3 text-xs text-gray-500">
                            To apply one, change it on the
                            <a href="{{ route('strategies') }}" class="text-yellow-500 hover:text-yellow-400">Strategies</a>
                            page &mdash; after confirming it independently with
                            <code class="text-gray-400">backtest:optimise</code>.
                        </p>
                    @endif
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-gray-700 p-8 text-center">
                    <p class="text-sm text-gray-500">No runs yet.</p>
                </div>
            @endforelse
        @endif
    </div>
</div>

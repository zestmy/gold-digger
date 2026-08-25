<div>
    <x-slot name="header">
        Chart Analysis
    </x-slot>

    <div class="space-y-6">
        <!-- What to look at -->
        <div class="rounded-lg bg-gray-800 p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="symbol" class="block text-xs text-gray-500">Instrument</label>
                    <select id="symbol" wire:model.live="symbol"
                            class="mt-1 block rounded-md border-gray-600 bg-gray-700 text-sm text-white focus:border-yellow-500 focus:ring-yellow-500">
                        @forelse($symbols as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @empty
                            <option value="">No instrument has enough history yet</option>
                        @endforelse
                    </select>
                </div>

                <div>
                    <label for="timeframe" class="block text-xs text-gray-500">Timeframe</label>
                    <select id="timeframe" wire:model.live="timeframe"
                            class="mt-1 block rounded-md border-gray-600 bg-gray-700 text-sm text-white focus:border-yellow-500 focus:ring-yellow-500">
                        @foreach($timeframes as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- A button rather than something that fires on arrival: each analysis is a
                     model call, and a page that ran one on load would spend money every time
                     somebody navigated here by accident. --}}
                <button type="button" wire:click="analyse" wire:loading.attr="disabled"
                        @disabled($symbols === [])
                        class="rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400 disabled:opacity-50">
                    <span wire:loading.remove wire:target="analyse">Analyse</span>
                    <span wire:loading wire:target="analyse">Reading…</span>
                </button>

                <p class="text-xs text-gray-500">{{ $bars->count() }} bars stored</p>
            </div>
        </div>

        @if($analysis)
            @if($analysis['error'])
                <div class="rounded-lg border border-amber-500/30 bg-amber-900/20 p-4">
                    <p class="text-sm text-amber-300">{{ $analysis['error'] }}</p>
                </div>
            @endif

            @php($reading = $analysis['reading'])

            @if($reading)
                <!-- The reading -->
                <div class="rounded-lg bg-gray-800 p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <h3 class="text-lg font-medium text-gray-100">{{ $reading['headline'] }}</h3>

                        <div class="flex shrink-0 gap-2">
                            <span class="rounded px-2 py-1 text-xs font-medium
                                {{ $reading['bias'] === 'bullish' ? 'bg-green-400/10 text-green-400' : '' }}
                                {{ $reading['bias'] === 'bearish' ? 'bg-red-400/10 text-red-400' : '' }}
                                {{ $reading['bias'] === 'neutral' ? 'bg-gray-700 text-gray-400' : '' }}">
                                {{ strtoupper($reading['bias']) }}
                            </span>

                            <span class="rounded px-2 py-1 text-xs font-medium
                                {{ $reading['plan'] === 'wait' ? 'bg-gray-700 text-gray-400' : 'bg-yellow-400/10 text-yellow-500' }}">
                                {{ strtoupper($reading['plan']) }}
                            </span>
                        </div>
                    </div>

                    <p class="mt-3 text-sm leading-relaxed text-gray-300">{{ $reading['structure'] }}</p>

                    <!-- The plan -->
                    @if($reading['plan'] !== 'wait' && $reading['entry_price'])
                        <div class="mt-5 grid grid-cols-3 gap-4 border-t border-gray-700 pt-4">
                            <div>
                                <p class="text-xs text-gray-500">Entry</p>
                                <p class="mt-1 font-mono text-lg text-gray-100">{{ $reading['entry_price'] }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Stop</p>
                                <p class="mt-1 font-mono text-lg text-red-400">{{ $reading['stop_price'] ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Target</p>
                                <p class="mt-1 font-mono text-lg text-green-400">{{ $reading['target_price'] ?? '—' }}</p>
                            </div>
                        </div>

                        @if($reading['stop_price'] && $reading['target_price'])
                            @php
                                $risk = abs($reading['entry_price'] - $reading['stop_price']);
                                $reward = abs($reading['target_price'] - $reading['entry_price']);
                            @endphp
                            <p class="mt-2 text-xs text-gray-500">
                                {{-- Computed here rather than taken from the model: a ratio is
                                     arithmetic, and arithmetic should not be asked for. --}}
                                Reward against risk: {{ $risk > 0 ? number_format($reward / $risk, 2) : '—' }} to 1
                            </p>
                        @endif
                    @else
                        <p class="mt-4 rounded-md bg-gray-900 p-3 text-sm text-gray-400">
                            No plan proposed. Waiting is a legitimate reading, and a mixed chart has no
                            trade in it &mdash; a plan produced to fill the field would be worse than none.
                        </p>
                    @endif

                    <div class="mt-5 space-y-3 border-t border-gray-700 pt-4 text-sm">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Reasoning</p>
                            <p class="mt-1 text-gray-300">{{ $reading['reasoning'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">What would make this wrong</p>
                            <p class="mt-1 text-gray-400">{{ $reading['invalidation'] }}</p>
                        </div>
                    </div>
                </div>
            @endif

            <!-- The measured levels -->
            <div class="rounded-lg bg-gray-800 p-6">
                <h3 class="text-sm font-medium text-gray-400">Measured levels</h3>
                <p class="mt-1 text-xs text-gray-500">
                    {{ $analysis['structure'] }}
                    Each is a price this instrument actually turned at, found by definition rather than
                    named &mdash; pivots merged when within half an ATR, so a level tested three times
                    counts as one.
                </p>

                @if($analysis['levels'])
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-700 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th class="pb-2 pr-4 font-medium">#</th>
                                    <th class="pb-2 pr-4 font-medium">Price</th>
                                    <th class="pb-2 pr-4 font-medium">Kind</th>
                                    <th class="pb-2 font-medium">Touches</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-700/50">
                                @foreach($analysis['levels'] as $i => $level)
                                    <tr class="{{ $reading && in_array($i, [$reading['entry_level'], $reading['stop_level'], $reading['target_level']], true) ? 'bg-yellow-400/5' : '' }}">
                                        <td class="py-2 pr-4 text-gray-600">{{ $i }}</td>
                                        <td class="py-2 pr-4 font-mono text-gray-200">{{ $level['price'] }}</td>
                                        <td class="py-2 pr-4 text-gray-400">{{ $level['kind'] }}</td>
                                        <td class="py-2 text-gray-400">{{ $level['touches'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="mt-4 text-sm text-gray-500">No completed swings in this window.</p>
                @endif
            </div>
        @else
            <div class="rounded-lg bg-gray-800 p-8 text-center">
                <p class="text-sm text-gray-400">Pick an instrument and press Analyse.</p>
                <p class="mt-2 text-xs text-gray-500">
                    Levels are measured from stored bars; the reading interprets them. Nothing here places
                    an order &mdash; it is a proposal to argue with.
                </p>
            </div>
        @endif
    </div>
</div>

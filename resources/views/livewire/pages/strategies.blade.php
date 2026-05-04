<div>
    <x-slot name="header">
        Strategies
    </x-slot>

    <!-- Header with Add Button -->
    <div class="mb-6 flex items-center justify-between">
        <p class="text-gray-400">Configure your trading strategies and parameters</p>
        <button
            wire:click="openModal"
            class="inline-flex items-center rounded-md bg-yellow-500 px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm hover:bg-yellow-400 transition-colors"
        >
            <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            New Strategy
        </button>
    </div>

    <!-- Strategies Grid -->
    @if($strategies->isEmpty())
        <div class="rounded-lg bg-gray-800 p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
            </svg>
            <h3 class="mt-4 text-lg font-medium text-white">No strategies yet</h3>
            <p class="mt-2 text-sm text-gray-400">Create your first trading strategy to get started.</p>
            <button
                wire:click="openModal"
                class="mt-6 inline-flex items-center rounded-md bg-yellow-500 px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-yellow-400"
            >
                Create Strategy
            </button>
        </div>
    @else
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($strategies as $strategy)
                <div class="rounded-lg bg-gray-800 p-6 {{ $strategy->is_active ? 'ring-1 ring-yellow-500/50' : '' }}">
                    <!-- Header -->
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-white">{{ $strategy->name }}</h3>
                            <p class="text-sm text-gray-400">{{ $strategy->symbol }} · {{ $strategy->timeframe_entry }}</p>
                        </div>
                        <button
                            wire:click="toggleActive({{ $strategy->id }})"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors {{ $strategy->is_active ? 'bg-yellow-500' : 'bg-gray-600' }}"
                        >
                            <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform {{ $strategy->is_active ? 'translate-x-6' : 'translate-x-1' }}"></span>
                        </button>
                    </div>

                    <!-- Stats -->
                    <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-gray-500">EMA</span>
                            <p class="text-white">{{ $strategy->ema_fast }}/{{ $strategy->ema_slow }}</p>
                        </div>
                        <div>
                            <span class="text-gray-500">ADX Threshold</span>
                            <p class="text-white">{{ $strategy->adx_threshold }}</p>
                        </div>
                        <div>
                            <span class="text-gray-500">Stop Loss</span>
                            <p class="text-white">{{ $strategy->sl_atr_multiplier }}x ATR</p>
                        </div>
                        <div>
                            <span class="text-gray-500">Trades</span>
                            <p class="text-white">{{ $strategy->trades_count }}</p>
                        </div>
                    </div>

                    <!-- Take Profits -->
                    <div class="mt-4 rounded bg-gray-900 p-3">
                        <span class="text-xs text-gray-500">Take Profit Levels</span>
                        <div class="mt-2 flex justify-between text-xs">
                            <div class="text-center">
                                <p class="text-yellow-400">TP1</p>
                                <p class="text-white">{{ $strategy->tp1_pips }} pips</p>
                                <p class="text-gray-500">{{ $strategy->tp1_close_pct }}%</p>
                            </div>
                            <div class="text-center">
                                <p class="text-yellow-400">TP2</p>
                                <p class="text-white">{{ $strategy->tp2_pips }} pips</p>
                                <p class="text-gray-500">{{ $strategy->tp2_close_pct }}%</p>
                            </div>
                            <div class="text-center">
                                <p class="text-yellow-400">TP3</p>
                                <p class="text-white">{{ $strategy->tp3_pips }} pips</p>
                                <p class="text-gray-500">{{ $strategy->tp3_close_pct }}%</p>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="mt-4 flex justify-end space-x-2">
                        <button
                            wire:click="openModal({{ $strategy->id }})"
                            class="rounded px-3 py-1.5 text-sm text-gray-400 hover:bg-gray-700 hover:text-white transition-colors"
                        >
                            Edit
                        </button>
                        <button
                            wire:click="delete({{ $strategy->id }})"
                            wire:confirm="Are you sure you want to delete this strategy?"
                            class="rounded px-3 py-1.5 text-sm text-red-400 hover:bg-red-500/10 hover:text-red-300 transition-colors"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Modal -->
    @if($showModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <!-- Backdrop -->
                <div class="fixed inset-0 bg-black/75 transition-opacity" wire:click="closeModal"></div>

                <!-- Modal Content -->
                <div class="relative w-full max-w-2xl rounded-lg bg-gray-800 p-6 shadow-xl">
                    <h2 class="text-xl font-semibold text-white">
                        {{ $editingId ? 'Edit Strategy' : 'New Strategy' }}
                    </h2>

                    <form wire:submit="save" class="mt-6 space-y-6">
                        <!-- Basic Info -->
                        <div class="grid gap-4 md:grid-cols-3">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-300">Strategy Name</label>
                                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm" placeholder="My Gold Strategy">
                                @error('name') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300">Symbol</label>
                                <input type="text" wire:model="symbol" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                                @error('symbol') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <!-- Timeframes -->
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-300">Entry Timeframe</label>
                                <select wire:model="timeframe_entry" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                                    @foreach($timeframes as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300">Trend Timeframe</label>
                                <select wire:model="timeframe_trend" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                                    @foreach($timeframes as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <!-- Indicators -->
                        <div class="grid gap-4 md:grid-cols-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-300">EMA Fast</label>
                                <input type="number" wire:model="ema_fast" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300">EMA Slow</label>
                                <input type="number" wire:model="ema_slow" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300">ADX Threshold</label>
                                <input type="number" step="0.01" wire:model="adx_threshold" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300">ATR Period</label>
                                <input type="number" wire:model="atr_period" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                            </div>
                        </div>

                        <!-- Take Profits -->
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Take Profit Levels</label>
                            <div class="grid gap-4 md:grid-cols-3">
                                <div class="rounded bg-gray-900 p-3">
                                    <p class="text-xs text-yellow-400 mb-2">TP1</p>
                                    <div class="space-y-2">
                                        <input type="number" step="0.01" wire:model="tp1_pips" placeholder="Pips" class="block w-full rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500">
                                        <input type="number" step="1" wire:model="tp1_close_pct" placeholder="Close %" class="block w-full rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500">
                                    </div>
                                </div>
                                <div class="rounded bg-gray-900 p-3">
                                    <p class="text-xs text-yellow-400 mb-2">TP2</p>
                                    <div class="space-y-2">
                                        <input type="number" step="0.01" wire:model="tp2_pips" placeholder="Pips" class="block w-full rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500">
                                        <input type="number" step="1" wire:model="tp2_close_pct" placeholder="Close %" class="block w-full rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500">
                                    </div>
                                </div>
                                <div class="rounded bg-gray-900 p-3">
                                    <p class="text-xs text-yellow-400 mb-2">TP3</p>
                                    <div class="space-y-2">
                                        <input type="number" step="0.01" wire:model="tp3_pips" placeholder="Pips" class="block w-full rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500">
                                        <input type="number" step="1" wire:model="tp3_close_pct" placeholder="Close %" class="block w-full rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Stop Loss & Exit -->
                        <div class="grid gap-4 md:grid-cols-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-300">SL ATR Multiplier</label>
                                <input type="number" step="0.1" wire:model="sl_atr_multiplier" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300">Max Holding Bars</label>
                                <input type="number" wire:model="max_holding_bars" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                            </div>
                            <div class="flex items-end">
                                <label class="flex items-center space-x-2">
                                    <input type="checkbox" wire:model="exit_on_reversal" class="rounded border-gray-600 bg-gray-700 text-yellow-500 focus:ring-yellow-500">
                                    <span class="text-sm text-gray-300">Exit on Reversal</span>
                                </label>
                            </div>
                        </div>

                        <!-- Active Toggle -->
                        <div class="flex items-center space-x-3">
                            <input type="checkbox" wire:model="is_active" class="rounded border-gray-600 bg-gray-700 text-yellow-500 focus:ring-yellow-500">
                            <label class="text-sm text-gray-300">Strategy is active</label>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-end space-x-3 pt-4 border-t border-gray-700">
                            <button type="button" wire:click="closeModal" class="rounded-md px-4 py-2 text-sm font-medium text-gray-400 hover:text-white transition-colors">
                                Cancel
                            </button>
                            <button type="submit" class="inline-flex items-center rounded-md bg-yellow-500 px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-yellow-400 transition-colors">
                                <svg wire:loading wire:target="save" class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                {{ $editingId ? 'Update' : 'Create' }} Strategy
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

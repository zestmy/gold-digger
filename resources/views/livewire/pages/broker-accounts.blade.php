<div>
    <x-slot name="header">
        Broker Accounts
    </x-slot>

    <!-- Header with Add Button -->
    <div class="mb-6 flex items-center justify-between">
        <p class="text-gray-400">Manage your MT5 broker account connections</p>
        <button
            wire:click="openModal"
            class="inline-flex items-center rounded-md bg-yellow-500 px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm hover:bg-yellow-400 transition-colors"
        >
            <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            Add Account
        </button>
    </div>

    <!-- Accounts List -->
    @if($accounts->isEmpty())
        <div class="rounded-lg bg-gray-800 p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>
            </svg>
            <h3 class="mt-4 text-lg font-medium text-white">No broker accounts</h3>
            <p class="mt-2 text-sm text-gray-400">Add your MT5 broker account to start trading.</p>
            <button
                wire:click="openModal"
                class="mt-6 inline-flex items-center rounded-md bg-yellow-500 px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-yellow-400"
            >
                Add Account
            </button>
        </div>
    @else
        <div class="space-y-4">
            @foreach($accounts as $account)
                <div class="rounded-lg bg-gray-800 p-6 {{ $account->is_active ? 'ring-2 ring-yellow-500' : '' }}">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start space-x-4">
                            <!-- Icon -->
                            <div class="flex h-12 w-12 items-center justify-center rounded-lg {{ $account->is_demo ? 'bg-blue-500/20 text-blue-400' : 'bg-green-500/20 text-green-400' }}">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>
                                </svg>
                            </div>

                            <!-- Info -->
                            <div>
                                <div class="flex items-center space-x-2">
                                    <h3 class="text-lg font-semibold text-white">{{ $account->label }}</h3>
                                    @if($account->is_active)
                                        <span class="inline-flex items-center rounded-full bg-yellow-400/10 px-2 py-0.5 text-xs font-medium text-yellow-400 ring-1 ring-inset ring-yellow-400/20">
                                            Active
                                        </span>
                                    @endif
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $account->is_demo ? 'bg-blue-400/10 text-blue-400 ring-1 ring-blue-400/20' : 'bg-green-400/10 text-green-400 ring-1 ring-green-400/20' }}">
                                        {{ $account->is_demo ? 'Demo' : 'Live' }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-gray-400">
                                    {{ $brokers[$account->broker_name] ?? $account->broker_name }} · {{ $account->server }}
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    Account: {{ Str::mask($account->account_number, '*', 3, -3) }} · {{ $account->account_currency }} · 1:{{ $account->leverage }}
                                </p>
                            </div>
                        </div>

                        <!-- Balance Info -->
                        <div class="text-right">
                            @if($account->last_balance)
                                <p class="text-lg font-semibold text-white">
                                    {{ number_format($account->last_balance, 2) }} {{ $account->account_currency }}
                                </p>
                                <p class="text-sm text-gray-400">
                                    Equity: {{ number_format($account->last_equity, 2) }}
                                </p>
                                @if($account->last_synced_at)
                                    <p class="text-xs text-gray-500">
                                        Synced {{ $account->last_synced_at->diffForHumans() }}
                                    </p>
                                @endif
                            @else
                                <p class="text-sm text-gray-500">Not synced</p>
                            @endif
                        </div>
                    </div>

                    <!-- Stats & Actions -->
                    <div class="mt-4 flex items-center justify-between border-t border-gray-700 pt-4">
                        <div class="flex space-x-6 text-sm">
                            <div>
                                <span class="text-gray-500">Total Trades</span>
                                <p class="font-medium text-white">{{ $account->trades_count }}</p>
                            </div>
                        </div>

                        <div class="flex space-x-2">
                            @if(!$account->is_active)
                                <button
                                    wire:click="setActive({{ $account->id }})"
                                    class="rounded px-3 py-1.5 text-sm text-yellow-400 hover:bg-yellow-500/10 transition-colors"
                                >
                                    Set Active
                                </button>
                            @endif
                            <button
                                wire:click="openModal({{ $account->id }})"
                                class="rounded px-3 py-1.5 text-sm text-gray-400 hover:bg-gray-700 hover:text-white transition-colors"
                            >
                                Edit
                            </button>
                            <button
                                wire:click="delete({{ $account->id }})"
                                wire:confirm="Are you sure you want to delete this broker account?"
                                class="rounded px-3 py-1.5 text-sm text-red-400 hover:bg-red-500/10 hover:text-red-300 transition-colors"
                            >
                                Delete
                            </button>
                        </div>
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
                <div class="relative w-full max-w-lg rounded-lg bg-gray-800 p-6 shadow-xl">
                    <h2 class="text-xl font-semibold text-white">
                        {{ $editingId ? 'Edit Account' : 'Add Broker Account' }}
                    </h2>

                    <form wire:submit="save" class="mt-6 space-y-4">
                        <!-- Label -->
                        <div>
                            <label class="block text-sm font-medium text-gray-300">Account Label</label>
                            <input type="text" wire:model="label" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm" placeholder="My Demo Account">
                            @error('label') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <!-- Broker & Server -->
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-300">Broker</label>
                                <select wire:model="broker_name" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                                    @foreach($brokers as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300">Server</label>
                                <input type="text" wire:model="server" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm" placeholder="OctaFX-Demo">
                                @error('server') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <!-- Account Number -->
                        <div>
                            <label class="block text-sm font-medium text-gray-300">Account Number</label>
                            <input type="text" wire:model="account_number" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm" placeholder="12345678">
                            @error('account_number') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-gray-500">This will be encrypted and stored securely</p>
                        </div>

                        <!-- Currency & Leverage -->
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-300">Currency</label>
                                <select wire:model="account_currency" class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                                    @foreach($currencies as $currency)
                                        <option value="{{ $currency }}">{{ $currency }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300">Leverage</label>
                                <div class="mt-1 flex items-center">
                                    <span class="text-gray-400 mr-2">1:</span>
                                    <input type="number" wire:model="leverage" min="1" max="2000" class="block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                                </div>
                            </div>
                        </div>

                        <!-- Account Type -->
                        <div class="flex items-center space-x-6">
                            <label class="flex items-center space-x-2">
                                <input type="radio" wire:model="is_demo" value="1" class="border-gray-600 bg-gray-700 text-yellow-500 focus:ring-yellow-500">
                                <span class="text-sm text-gray-300">Demo Account</span>
                            </label>
                            <label class="flex items-center space-x-2">
                                <input type="radio" wire:model="is_demo" value="0" class="border-gray-600 bg-gray-700 text-yellow-500 focus:ring-yellow-500">
                                <span class="text-sm text-gray-300">Live Account</span>
                            </label>
                        </div>

                        <!-- Set as Active -->
                        <div class="flex items-center space-x-2">
                            <input type="checkbox" wire:model="is_active" class="rounded border-gray-600 bg-gray-700 text-yellow-500 focus:ring-yellow-500">
                            <label class="text-sm text-gray-300">Set as active trading account</label>
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
                                {{ $editingId ? 'Update' : 'Add' }} Account
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

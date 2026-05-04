<div>
    <x-slot name="header">
        Settings
    </x-slot>

    <form wire:submit="save" class="space-y-6">
        <!-- Bot Master Switch -->
        <div class="rounded-lg bg-gray-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-white">Trading Bot</h3>
                    <p class="text-sm text-gray-400">Master switch to enable or disable the trading bot</p>
                </div>
                <button
                    type="button"
                    wire:click="toggleBot"
                    class="relative inline-flex h-8 w-14 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2 focus:ring-offset-gray-800 {{ $is_active ? 'bg-yellow-500' : 'bg-gray-600' }}"
                >
                    <span class="inline-block h-6 w-6 transform rounded-full bg-white shadow-lg transition-transform {{ $is_active ? 'translate-x-7' : 'translate-x-1' }}"></span>
                </button>
            </div>
            @if($is_active)
                <div class="mt-4 flex items-center text-sm text-green-400">
                    <svg class="mr-2 h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    Bot is active and ready to trade
                </div>
            @else
                <div class="mt-4 flex items-center text-sm text-gray-500">
                    <svg class="mr-2 h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    Bot is paused
                </div>
            @endif
        </div>

        <!-- Risk Management -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="mb-4 text-lg font-semibold text-white">Risk Management</h3>
            <div class="grid gap-6 md:grid-cols-3">
                <!-- Risk Per Trade -->
                <div>
                    <label for="risk_percentage" class="block text-sm font-medium text-gray-300">Risk Per Trade (%)</label>
                    <div class="mt-1 relative">
                        <input
                            type="number"
                            id="risk_percentage"
                            wire:model="risk_percentage"
                            step="0.1"
                            min="0.1"
                            max="10"
                            class="block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm"
                        >
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                            <span class="text-gray-400 sm:text-sm">%</span>
                        </div>
                    </div>
                    @error('risk_percentage') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-gray-500">Recommended: 1-2%</p>
                </div>

                <!-- Max Daily Loss -->
                <div>
                    <label for="max_daily_loss_percentage" class="block text-sm font-medium text-gray-300">Max Daily Loss (%)</label>
                    <div class="mt-1 relative">
                        <input
                            type="number"
                            id="max_daily_loss_percentage"
                            wire:model="max_daily_loss_percentage"
                            step="0.5"
                            min="1"
                            max="50"
                            class="block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm"
                        >
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                            <span class="text-gray-400 sm:text-sm">%</span>
                        </div>
                    </div>
                    @error('max_daily_loss_percentage') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-gray-500">Bot stops trading after this loss</p>
                </div>

                <!-- Max Concurrent Trades -->
                <div>
                    <label for="max_concurrent_trades" class="block text-sm font-medium text-gray-300">Max Concurrent Trades</label>
                    <input
                        type="number"
                        id="max_concurrent_trades"
                        wire:model="max_concurrent_trades"
                        min="1"
                        max="10"
                        class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm"
                    >
                    @error('max_concurrent_trades') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-gray-500">Maximum open positions</p>
                </div>
            </div>
        </div>

        <!-- Trading Sessions -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="mb-4 text-lg font-semibold text-white">Allowed Trading Sessions</h3>
            <p class="mb-4 text-sm text-gray-400">Select which market sessions the bot is allowed to trade during</p>
            <div class="grid gap-4 md:grid-cols-2">
                @foreach($availableSessions as $key => $label)
                    <label class="flex items-center space-x-3 rounded-lg border border-gray-700 p-4 cursor-pointer hover:border-yellow-500/50 transition-colors {{ in_array($key, $allowed_sessions) ? 'border-yellow-500 bg-yellow-500/10' : '' }}">
                        <input
                            type="checkbox"
                            wire:model="allowed_sessions"
                            value="{{ $key }}"
                            class="h-4 w-4 rounded border-gray-600 bg-gray-700 text-yellow-500 focus:ring-yellow-500 focus:ring-offset-gray-800"
                        >
                        <span class="text-white">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            @error('allowed_sessions') <p class="mt-2 text-sm text-red-400">{{ $message }}</p> @enderror
        </div>

        <!-- Trade Filters -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="mb-4 text-lg font-semibold text-white">Trade Filters</h3>
            <div class="grid gap-6 md:grid-cols-2">
                <!-- Min ATR Threshold -->
                <div>
                    <label for="min_atr_threshold" class="block text-sm font-medium text-gray-300">Minimum ATR Threshold</label>
                    <input
                        type="number"
                        id="min_atr_threshold"
                        wire:model="min_atr_threshold"
                        step="0.1"
                        min="0"
                        class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm"
                    >
                    @error('min_atr_threshold') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-gray-500">Minimum volatility required to enter trades</p>
                </div>

                <!-- News Filter -->
                <div class="flex items-start space-x-3 pt-6">
                    <input
                        type="checkbox"
                        id="news_filter_enabled"
                        wire:model="news_filter_enabled"
                        class="mt-1 h-4 w-4 rounded border-gray-600 bg-gray-700 text-yellow-500 focus:ring-yellow-500 focus:ring-offset-gray-800"
                    >
                    <div>
                        <label for="news_filter_enabled" class="text-sm font-medium text-gray-300 cursor-pointer">Enable News Filter</label>
                        <p class="text-xs text-gray-500">Pause trading during high-impact news events</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Screenshot Settings -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="mb-4 text-lg font-semibold text-white">Screenshot Settings</h3>
            <div class="flex items-start space-x-3">
                <input
                    type="checkbox"
                    id="capture_screenshots"
                    wire:model="capture_screenshots"
                    class="mt-1 h-4 w-4 rounded border-gray-600 bg-gray-700 text-yellow-500 focus:ring-yellow-500 focus:ring-offset-gray-800"
                >
                <div>
                    <label for="capture_screenshots" class="text-sm font-medium text-gray-300 cursor-pointer">Capture Trade Screenshots</label>
                    <p class="text-xs text-gray-500">Automatically capture chart screenshots when trades are opened/closed for review</p>
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <div class="flex justify-end">
            <button
                type="submit"
                class="inline-flex items-center rounded-md bg-yellow-500 px-6 py-3 text-sm font-semibold text-gray-900 shadow-sm hover:bg-yellow-400 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2 focus:ring-offset-gray-900 transition-colors"
            >
                <svg wire:loading wire:target="save" class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span wire:loading.remove wire:target="save">Save Settings</span>
                <span wire:loading wire:target="save">Saving...</span>
            </button>
        </div>
    </form>
</div>

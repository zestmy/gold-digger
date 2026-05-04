<div>
    <x-slot name="header">
        Live Trades
    </x-slot>

    <!-- Account Info & Actions -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            @if($activeAccount)
                <p class="text-gray-400">
                    Trading on <span class="text-white font-medium">{{ $activeAccount->label }}</span>
                    <span class="text-gray-500">({{ $activeAccount->broker_name }})</span>
                </p>
            @else
                <p class="text-yellow-400">No active broker account selected</p>
            @endif
        </div>
        @if($trades->isNotEmpty())
            <button
                wire:click="closeAllTrades"
                wire:confirm="Are you sure you want to close ALL open positions?"
                class="inline-flex items-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500 transition-colors"
            >
                <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Close All Positions
            </button>
        @endif
    </div>

    <!-- Summary Cards -->
    <div class="mb-6 grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Open Positions</p>
            <p class="text-2xl font-bold text-white">{{ $summary['total_positions'] }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Total Lots</p>
            <p class="text-2xl font-bold text-white">{{ number_format($summary['total_lots'], 2) }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Unrealized P&L</p>
            <p class="text-2xl font-bold {{ $summary['unrealized_pnl'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                ${{ number_format($summary['unrealized_pnl'], 2) }}
            </p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Buy Positions</p>
            <p class="text-2xl font-bold text-green-400">{{ $summary['buy_positions'] }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Sell Positions</p>
            <p class="text-2xl font-bold text-red-400">{{ $summary['sell_positions'] }}</p>
        </div>
    </div>

    <!-- Trades Table -->
    <div class="rounded-lg bg-gray-800 overflow-hidden">
        @if($trades->isEmpty())
            <div class="p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0l-5.94-2.281m5.94 2.28l-2.28 5.941"/>
                </svg>
                <h3 class="mt-4 text-lg font-medium text-white">No open positions</h3>
                <p class="mt-2 text-sm text-gray-400">When the bot opens trades, they will appear here.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead class="bg-gray-900">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Symbol</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Direction</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Lots</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Entry</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">SL</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">TP1/TP2/TP3</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Opened</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-400">P&L</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                        @foreach($trades as $trade)
                            <tr class="hover:bg-gray-700/50 transition-colors">
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-white">
                                    {{ $trade->symbol }}
                                    <span class="block text-xs text-gray-500">#{{ $trade->mt5_ticket }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $trade->direction === 'buy' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                                        {{ strtoupper($trade->direction) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                                    {{ $trade->remaining_lot_size }}
                                    @if($trade->remaining_lot_size != $trade->initial_lot_size)
                                        <span class="text-xs text-gray-500">({{ $trade->initial_lot_size }})</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-white">
                                    {{ number_format($trade->entry_price, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-red-400">
                                    {{ number_format($trade->sl_price, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-green-400">
                                    <span class="text-xs">
                                        {{ number_format($trade->tp1_price, 2) }} /
                                        {{ number_format($trade->tp2_price, 2) }} /
                                        {{ number_format($trade->tp3_price, 2) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                                    {{ $trade->opened_at?->format('M d, H:i') }}
                                    <span class="block text-xs text-gray-500">{{ $trade->opened_at?->diffForHumans() }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-right font-medium {{ ($trade->gross_pnl_money ?? 0) >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                    ${{ number_format($trade->gross_pnl_money ?? 0, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-right">
                                    <button
                                        wire:click="closeTrade({{ $trade->id }})"
                                        wire:confirm="Close this position?"
                                        class="text-red-400 hover:text-red-300 transition-colors"
                                    >
                                        Close
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Info Box -->
    <div class="mt-6 rounded-lg bg-gray-800/50 border border-gray-700 p-4">
        <div class="flex items-start space-x-3">
            <svg class="h-5 w-5 text-yellow-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
            </svg>
            <div class="text-sm text-gray-400">
                <p class="font-medium text-gray-300">Real-time Updates</p>
                <p class="mt-1">P&L values are calculated when trades are closed. Live price updates will be available when connected to the Python trading bot.</p>
            </div>
        </div>
    </div>
</div>

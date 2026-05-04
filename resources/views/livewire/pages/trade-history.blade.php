<div>
    <x-slot name="header">
        Trade History
    </x-slot>

    <!-- Summary Stats -->
    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Total Trades</p>
            <p class="text-2xl font-bold text-white">{{ $summary['total_trades'] }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Winners</p>
            <p class="text-2xl font-bold text-green-400">{{ $summary['winners'] }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Losers</p>
            <p class="text-2xl font-bold text-red-400">{{ $summary['losers'] }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Total P&L</p>
            <p class="text-2xl font-bold {{ $summary['total_pnl'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                ${{ number_format($summary['total_pnl'], 2) }}
            </p>
        </div>
    </div>

    <!-- Filters -->
    <div class="mb-6 rounded-lg bg-gray-800 p-4">
        <div class="grid gap-4 md:grid-cols-6">
            <!-- Search -->
            <div class="md:col-span-2">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search symbol, ticket, notes..."
                    class="block w-full rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500"
                >
            </div>

            <!-- Status -->
            <div>
                <select wire:model.live="status" class="block w-full rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500">
                    <option value="">All Status</option>
                    <option value="fully_closed">Fully Closed</option>
                    <option value="stopped_out">Stopped Out</option>
                    <option value="partially_closed">Partial</option>
                </select>
            </div>

            <!-- Direction -->
            <div>
                <select wire:model.live="direction" class="block w-full rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500">
                    <option value="">All Directions</option>
                    <option value="buy">Buy</option>
                    <option value="sell">Sell</option>
                </select>
            </div>

            <!-- Strategy -->
            <div>
                <select wire:model.live="strategy" class="block w-full rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500">
                    <option value="">All Strategies</option>
                    @foreach($strategies as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Clear Filters -->
            <div>
                <button wire:click="clearFilters" class="w-full rounded-md bg-gray-700 px-4 py-2 text-sm text-gray-300 hover:bg-gray-600 transition-colors">
                    Clear Filters
                </button>
            </div>
        </div>

        <!-- Date Range -->
        <div class="mt-4 flex items-center space-x-4">
            <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-400">From:</span>
                <input type="date" wire:model.live="dateFrom" class="rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500">
            </div>
            <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-400">To:</span>
                <input type="date" wire:model.live="dateTo" class="rounded-md border-gray-600 bg-gray-700 text-white text-sm focus:border-yellow-500 focus:ring-yellow-500">
            </div>
        </div>
    </div>

    <!-- Trades Table -->
    <div class="rounded-lg bg-gray-800 overflow-hidden">
        @if($trades->isEmpty())
            <div class="p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0l-5.94-2.281m5.94 2.28l-2.28 5.941"/>
                </svg>
                <h3 class="mt-4 text-lg font-medium text-white">No trades found</h3>
                <p class="mt-2 text-sm text-gray-400">Trades will appear here once you start trading.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead class="bg-gray-900">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Symbol</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Direction</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Lot Size</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Entry</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-400">Gross P&L</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-400">Net P&L</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                        @foreach($trades as $trade)
                            <tr class="hover:bg-gray-700/50 transition-colors">
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                                    {{ $trade->closed_at?->format('M d, Y') }}<br>
                                    <span class="text-xs text-gray-500">{{ $trade->closed_at?->format('H:i') }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-white">
                                    {{ $trade->symbol }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $trade->direction === 'buy' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                                        {{ strtoupper($trade->direction) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                                    {{ $trade->initial_lot_size }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                                    {{ number_format($trade->entry_price, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm">
                                    @php
                                        $statusColors = [
                                            'fully_closed' => 'bg-gray-500/20 text-gray-400',
                                            'stopped_out' => 'bg-red-500/20 text-red-400',
                                            'partially_closed' => 'bg-yellow-500/20 text-yellow-400',
                                        ];
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColors[$trade->status] ?? 'bg-gray-500/20 text-gray-400' }}">
                                        {{ str_replace('_', ' ', ucfirst($trade->status)) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-right {{ $trade->gross_pnl_money >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                    ${{ number_format($trade->gross_pnl_money, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-right font-medium {{ $trade->net_pnl_money >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                    ${{ number_format($trade->net_pnl_money, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-right">
                                    <button wire:click="viewTrade({{ $trade->id }})" class="text-yellow-400 hover:text-yellow-300">
                                        View
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="border-t border-gray-700 px-4 py-3">
                {{ $trades->links() }}
            </div>
        @endif
    </div>

    <!-- Trade Detail Modal -->
    @if($viewingTrade)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/75 transition-opacity" wire:click="closeTradeView"></div>

                <div class="relative w-full max-w-2xl rounded-lg bg-gray-800 p-6 shadow-xl">
                    <div class="flex items-start justify-between">
                        <div>
                            <h2 class="text-xl font-semibold text-white">
                                {{ $viewingTrade->symbol }}
                                <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $viewingTrade->direction === 'buy' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                                    {{ strtoupper($viewingTrade->direction) }}
                                </span>
                            </h2>
                            <p class="text-sm text-gray-400">Ticket #{{ $viewingTrade->mt5_ticket }}</p>
                        </div>
                        <button wire:click="closeTradeView" class="text-gray-400 hover:text-white">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-gray-400">Entry Price</span>
                                <span class="text-white">{{ number_format($viewingTrade->entry_price, 5) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Stop Loss</span>
                                <span class="text-red-400">{{ number_format($viewingTrade->sl_price, 5) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Lot Size</span>
                                <span class="text-white">{{ $viewingTrade->initial_lot_size }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Strategy</span>
                                <span class="text-white">{{ $viewingTrade->strategy?->name ?? 'N/A' }}</span>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-gray-400">Opened</span>
                                <span class="text-white">{{ $viewingTrade->opened_at?->format('M d, Y H:i') }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Closed</span>
                                <span class="text-white">{{ $viewingTrade->closed_at?->format('M d, Y H:i') }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Closure Reason</span>
                                <span class="text-white">{{ $viewingTrade->closure_reason ?? 'N/A' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Broker</span>
                                <span class="text-white">{{ $viewingTrade->brokerAccount?->label ?? 'N/A' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- P&L Breakdown -->
                    <div class="mt-6 rounded-lg bg-gray-900 p-4">
                        <h3 class="text-sm font-medium text-gray-400 mb-3">P&L Breakdown</h3>
                        <div class="grid gap-2 md:grid-cols-2">
                            <div class="flex justify-between">
                                <span class="text-gray-400">Gross P&L</span>
                                <span class="{{ $viewingTrade->gross_pnl_money >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                    ${{ number_format($viewingTrade->gross_pnl_money, 2) }}
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Spread Cost</span>
                                <span class="text-red-400">-${{ number_format($viewingTrade->entry_spread_money, 4) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Commission</span>
                                <span class="text-red-400">-${{ number_format($viewingTrade->commission_money, 4) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Swap</span>
                                <span class="{{ $viewingTrade->swap_money >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                    ${{ number_format($viewingTrade->swap_money, 4) }}
                                </span>
                            </div>
                        </div>
                        <div class="mt-3 pt-3 border-t border-gray-700 flex justify-between">
                            <span class="font-medium text-white">Net P&L</span>
                            <span class="font-bold text-lg {{ $viewingTrade->net_pnl_money >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                ${{ number_format($viewingTrade->net_pnl_money, 2) }}
                            </span>
                        </div>
                    </div>

                    <!-- Partial Closes -->
                    @if($viewingTrade->partials->isNotEmpty())
                        <div class="mt-6">
                            <h3 class="text-sm font-medium text-gray-400 mb-3">Partial Closes</h3>
                            <div class="space-y-2">
                                @foreach($viewingTrade->partials as $partial)
                                    <div class="flex items-center justify-between rounded bg-gray-900 p-3 text-sm">
                                        <div>
                                            <span class="text-white">{{ $partial->close_type }}</span>
                                            <span class="text-gray-400 ml-2">{{ $partial->lot_size }} lots @ {{ number_format($partial->close_price, 5) }}</span>
                                        </div>
                                        <span class="{{ $partial->pnl_money >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                            ${{ number_format($partial->pnl_money, 2) }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- Notes -->
                    @if($viewingTrade->notes)
                        <div class="mt-6">
                            <h3 class="text-sm font-medium text-gray-400 mb-2">Notes</h3>
                            <p class="text-sm text-gray-300 bg-gray-900 rounded p-3">{{ $viewingTrade->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>

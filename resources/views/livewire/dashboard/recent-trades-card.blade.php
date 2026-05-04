<div class="rounded-lg bg-gray-800 p-6">
    <h3 class="text-sm font-medium text-gray-400">Recent Trades</h3>

    <div class="mt-4">
        @if($trades->isEmpty())
            <!-- Empty State -->
            <div class="rounded-lg border border-dashed border-gray-700 p-8 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                </svg>
                <p class="mt-4 text-sm text-gray-500">No trades yet.</p>
                <p class="text-xs text-gray-600">Bot is offline.</p>
            </div>
        @else
            <!-- Trades Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead>
                        <tr>
                            <th scope="col" class="py-3 pl-0 pr-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Time</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Symbol</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Dir</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Lot</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Entry</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                            <th scope="col" class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Pips</th>
                            <th scope="col" class="py-3 pl-3 pr-0 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Net $</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @foreach($trades as $trade)
                            <tr>
                                <td class="whitespace-nowrap py-3 pl-0 pr-3 text-sm text-gray-400">
                                    {{ $trade->opened_at?->format('H:i') ?? '-' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm font-medium text-white">
                                    {{ $trade->symbol }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm">
                                    <span class="{{ $trade->direction === 'buy' ? 'text-green-400' : 'text-red-400' }}">
                                        {{ strtoupper($trade->direction) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-gray-400">
                                    {{ number_format($trade->initial_lot_size, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-gray-400">
                                    {{ number_format($trade->entry_price, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm">
                                    @php
                                        $statusColors = [
                                            'pending' => 'text-gray-400',
                                            'open' => 'text-blue-400',
                                            'partially_closed' => 'text-yellow-400',
                                            'fully_closed' => 'text-green-400',
                                            'stopped_out' => 'text-red-400',
                                            'cancelled' => 'text-gray-500',
                                        ];
                                    @endphp
                                    <span class="{{ $statusColors[$trade->status] ?? 'text-gray-400' }}">
                                        {{ str_replace('_', ' ', ucfirst($trade->status)) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right text-sm {{ $trade->gross_pnl_pips >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                    {{ $trade->gross_pnl_pips >= 0 ? '+' : '' }}{{ number_format($trade->gross_pnl_pips, 1) }}
                                </td>
                                <td class="whitespace-nowrap py-3 pl-3 pr-0 text-right text-sm font-medium {{ $trade->net_pnl_money >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                    ${{ number_format($trade->net_pnl_money, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

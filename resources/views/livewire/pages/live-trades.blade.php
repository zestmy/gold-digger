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
                wire:confirm="Queue a close for every open position? They clear as the terminal confirms each one."
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
                            @php
                                // Which rungs the broker has actually confirmed. Read from
                                // trade_partials rather than from prices, because a level
                                // being touched is not the same as a fill.
                                $filled = $trade->partials->pluck('close_reason')->all();
                                $closing = in_array($trade->id, $pendingCloses, true);
                            @endphp
                            <tr class="hover:bg-gray-700/50 transition-colors {{ $closing ? 'opacity-60' : '' }}">
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-white">
                                    {{ $trade->symbol }}
                                    <span class="block text-xs text-gray-500">#{{ $trade->mt5_ticket }}</span>
                                    @if($trade->origin === 'adopted')
                                        {{-- Found on the terminal rather than opened from a signal. No strategy
                                             manages it: no ladder, no reversal exit, no time exit. --}}
                                        <span class="mt-1 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-purple-500/20 text-purple-300"
                                              title="Adopted by reconciliation. Not managed by any strategy - no take-profit ladder and no automatic exit.">
                                            ADOPTED &middot; UNMANAGED
                                        </span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $trade->direction === 'buy' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                                        {{ strtoupper($trade->direction) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                                    {{ $trade->remaining_lot_size }}
                                    @if($trade->remaining_lot_size != $trade->initial_lot_size)
                                        <span class="text-xs text-gray-500">of {{ $trade->initial_lot_size }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-white">
                                    {{ number_format($trade->entry_price, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm">
                                    @if($trade->sl_price === null)
                                        {{-- An adopted position may genuinely have no stop. Showing 0.00 here
                                             would read as a stop at zero. --}}
                                        <span class="text-yellow-400" title="This position has no stop loss set at the broker.">none</span>
                                    @else
                                        <span class="text-red-400">{{ number_format($trade->sl_price, 2) }}</span>
                                        @if(in_array('tp1', $filled, true))
                                            <span class="block text-[10px] text-gray-500">moved to break-even</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm">
                                    @if($trade->tp1_price === null && $trade->tp2_price === null && $trade->tp3_price === null)
                                        <span class="text-gray-500">&mdash;</span>
                                    @else
                                        <div class="flex items-center gap-1 text-xs">
                                            @foreach(['tp1', 'tp2', 'tp3'] as $rung)
                                                @php $level = $trade->{$rung.'_price'}; @endphp
                                                @if($level === null)
                                                    <span class="rounded px-1 py-0.5 bg-gray-700/40 text-gray-600">&ndash;</span>
                                                @elseif(in_array($rung, $filled, true))
                                                    {{-- Confirmed by a fill, not merely reached. --}}
                                                    <span class="rounded px-1 py-0.5 bg-green-500/30 text-green-300 font-medium"
                                                          title="{{ strtoupper($rung) }} filled">
                                                        {{ number_format($level, 2) }} &check;
                                                    </span>
                                                @else
                                                    <span class="rounded px-1 py-0.5 bg-gray-700/60 text-green-400/70">{{ number_format($level, 2) }}</span>
                                                @endif
                                            @endforeach
                                        </div>
                                        @if($trade->origin === 'bot')
                                            <span class="block text-[10px] text-gray-500 mt-0.5">
                                                the last target sits on the order; earlier rungs close at market
                                            </span>
                                        @endif
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                                    {{ $trade->opened_at?->format('M d, H:i') }}
                                    <span class="block text-xs text-gray-500">{{ $trade->opened_at?->diffForHumans() }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-right font-medium {{ ($trade->gross_pnl_money ?? 0) >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                    ${{ number_format($trade->gross_pnl_money ?? 0, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-right">
                                    @if($closing)
                                        {{-- The command is queued but the terminal has not confirmed. The row
                                             stays until a fill arrives, because until then it is still open. --}}
                                        <span class="text-xs text-yellow-400" title="A close command is queued and waiting for the terminal.">closing&hellip;</span>
                                    @else
                                        <button
                                            wire:click="closeTrade({{ $trade->id }})"
                                            wire:confirm="Queue a close for this position? It clears once the terminal confirms."
                                            class="text-red-400 hover:text-red-300 transition-colors"
                                        >
                                            Close
                                        </button>
                                    @endif
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
                <p class="font-medium text-gray-300">How closing works</p>
                <p class="mt-1">
                    Close queues a command for the Expert Advisor, which picks it up on its next poll. A position
                    stays listed until the terminal confirms the fill &mdash; until then it is still open.
                    Floating P&amp;L is whatever the terminal last reported, so it refreshes on the executor's
                    schedule rather than tick by tick.
                </p>
            </div>
        </div>
    </div>
</div>

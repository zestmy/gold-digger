<div>
    <x-slot name="header">
        Analytics
    </x-slot>

    <!-- Period Selector -->
    <div class="mb-6 flex flex-wrap items-center gap-2">
        @foreach($periods as $key => $label)
            <button
                wire:click="$set('period', '{{ $key }}')"
                class="px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ $period === $key ? 'bg-yellow-500 text-gray-900' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <!-- Key Metrics Grid -->
    <div class="mb-6 grid grid-cols-2 md:grid-cols-4 gap-4">
        <!-- Net P&L -->
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Net P&L</p>
            <p class="text-2xl font-bold {{ $metrics['net_pnl'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                ${{ number_format($metrics['net_pnl'], 2) }}
            </p>
        </div>

        <!-- Win Rate -->
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Win Rate</p>
            <p class="text-2xl font-bold {{ $metrics['win_rate'] >= 50 ? 'text-green-400' : 'text-yellow-400' }}">
                {{ $metrics['win_rate'] }}%
            </p>
        </div>

        <!-- Profit Factor -->
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Profit Factor</p>
            <p class="text-2xl font-bold {{ $metrics['profit_factor'] >= 1.5 ? 'text-green-400' : ($metrics['profit_factor'] >= 1 ? 'text-yellow-400' : 'text-red-400') }}">
                {{ $metrics['profit_factor'] }}
            </p>
        </div>

        <!-- Total Trades -->
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Total Trades</p>
            <p class="text-2xl font-bold text-white">{{ $metrics['total_trades'] }}</p>
        </div>
    </div>

    <!-- Second Row Metrics -->
    <div class="mb-6 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Winning</p>
            <p class="text-xl font-bold text-green-400">{{ $metrics['winning_trades'] }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Losing</p>
            <p class="text-xl font-bold text-red-400">{{ $metrics['losing_trades'] }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Avg Win</p>
            <p class="text-xl font-bold text-green-400">${{ number_format($metrics['avg_win'], 2) }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Avg Loss</p>
            <p class="text-xl font-bold text-red-400">${{ number_format($metrics['avg_loss'], 2) }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Largest Win</p>
            <p class="text-xl font-bold text-green-400">${{ number_format($metrics['largest_win'], 2) }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Largest Loss</p>
            <p class="text-xl font-bold text-red-400">${{ number_format($metrics['largest_loss'], 2) }}</p>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        <!-- Equity Curve -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="text-lg font-semibold text-white mb-4">Equity Curve</h3>
            @if(count($dailyPnl) > 0)
                <div class="h-64 flex items-end justify-between gap-1">
                    @php
                        $maxCumulative = max(array_column($dailyPnl, 'cumulative'));
                        $minCumulative = min(array_column($dailyPnl, 'cumulative'));
                        $range = max(abs($maxCumulative), abs($minCumulative), 1);
                    @endphp
                    @foreach($dailyPnl as $day)
                        @php
                            $height = abs($day['cumulative']) / $range * 100;
                            $isPositive = $day['cumulative'] >= 0;
                        @endphp
                        <div class="flex-1 flex flex-col justify-center items-center" title="{{ $day['date'] }}: ${{ $day['cumulative'] }}">
                            @if($isPositive)
                                <div class="w-full bg-green-500/80 rounded-t" style="height: {{ $height }}%"></div>
                            @else
                                <div class="w-full bg-red-500/80 rounded-b" style="height: {{ $height }}%"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex justify-between text-xs text-gray-500">
                    <span>{{ $dailyPnl[0]['date'] ?? '' }}</span>
                    <span>{{ end($dailyPnl)['date'] ?? '' }}</span>
                </div>
            @else
                <div class="h-64 flex items-center justify-center text-gray-500">
                    No trade data available for this period
                </div>
            @endif
        </div>

        <!-- Daily P&L Bars -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="text-lg font-semibold text-white mb-4">Daily P&L</h3>
            @if(count($dailyPnl) > 0)
                <div class="h-64 flex items-center">
                    <div class="w-full h-full flex items-center justify-between gap-1">
                        @php
                            $maxPnl = max(array_map('abs', array_column($dailyPnl, 'pnl')));
                            $maxPnl = max($maxPnl, 1);
                        @endphp
                        @foreach($dailyPnl as $day)
                            @php
                                $height = abs($day['pnl']) / $maxPnl * 50;
                                $isPositive = $day['pnl'] >= 0;
                            @endphp
                            <div class="flex-1 h-full flex flex-col justify-center items-center" title="{{ $day['date'] }}: ${{ $day['pnl'] }} ({{ $day['trades'] }} trades)">
                                @if($isPositive)
                                    <div class="w-full bg-green-500/80 rounded-t" style="height: {{ $height }}%"></div>
                                    <div class="w-full" style="height: 50%"></div>
                                @else
                                    <div class="w-full" style="height: 50%"></div>
                                    <div class="w-full bg-red-500/80 rounded-b" style="height: {{ $height }}%"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="mt-2 text-center text-xs text-gray-500">
                    Hover over bars to see details
                </div>
            @else
                <div class="h-64 flex items-center justify-center text-gray-500">
                    No trade data available for this period
                </div>
            @endif
        </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-6 mb-6">
        <!-- Direction Performance -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="text-lg font-semibold text-white mb-4">By Direction</h3>
            <div class="space-y-4">
                <!-- Buy -->
                <div class="p-3 rounded-lg bg-gray-900">
                    <div class="flex items-center justify-between mb-2">
                        <span class="inline-flex items-center rounded-full bg-green-500/20 px-2 py-0.5 text-xs font-medium text-green-400">
                            BUY
                        </span>
                        <span class="text-sm text-gray-400">{{ $directionStats['buy']['count'] }} trades</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-lg font-bold {{ $directionStats['buy']['pnl'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                            ${{ number_format($directionStats['buy']['pnl'], 2) }}
                        </span>
                        <span class="text-sm {{ $directionStats['buy']['win_rate'] >= 50 ? 'text-green-400' : 'text-yellow-400' }}">
                            {{ $directionStats['buy']['win_rate'] }}% WR
                        </span>
                    </div>
                </div>

                <!-- Sell -->
                <div class="p-3 rounded-lg bg-gray-900">
                    <div class="flex items-center justify-between mb-2">
                        <span class="inline-flex items-center rounded-full bg-red-500/20 px-2 py-0.5 text-xs font-medium text-red-400">
                            SELL
                        </span>
                        <span class="text-sm text-gray-400">{{ $directionStats['sell']['count'] }} trades</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-lg font-bold {{ $directionStats['sell']['pnl'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                            ${{ number_format($directionStats['sell']['pnl'], 2) }}
                        </span>
                        <span class="text-sm {{ $directionStats['sell']['win_rate'] >= 50 ? 'text-green-400' : 'text-yellow-400' }}">
                            {{ $directionStats['sell']['win_rate'] }}% WR
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Strategy Performance -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="text-lg font-semibold text-white mb-4">By Strategy</h3>
            @if(count($strategyStats) > 0)
                <div class="space-y-3">
                    @foreach($strategyStats as $strategy)
                        <div class="p-3 rounded-lg bg-gray-900">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-medium text-white truncate" title="{{ $strategy['name'] }}">
                                    {{ Str::limit($strategy['name'], 20) }}
                                </span>
                                <span class="text-xs text-gray-400">{{ $strategy['count'] }} trades</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-bold {{ $strategy['pnl'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                                    ${{ number_format($strategy['pnl'], 2) }}
                                </span>
                                <span class="text-xs {{ $strategy['win_rate'] >= 50 ? 'text-green-400' : 'text-yellow-400' }}">
                                    {{ $strategy['win_rate'] }}% WR
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-6 text-gray-500">
                    No strategy data available
                </div>
            @endif
        </div>

        <!-- Cost Breakdown -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="text-lg font-semibold text-white mb-4">Cost Breakdown</h3>
            <div class="space-y-4">
                <div class="flex items-center justify-between p-3 rounded-lg bg-gray-900">
                    <span class="text-gray-400">Commission</span>
                    <span class="text-red-400 font-medium">${{ number_format($costBreakdown['commission'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between p-3 rounded-lg bg-gray-900">
                    <span class="text-gray-400">Swap</span>
                    <span class="text-red-400 font-medium">${{ number_format($costBreakdown['swap'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between p-3 rounded-lg bg-yellow-500/10 border border-yellow-500/20">
                    <span class="text-yellow-400 font-medium">Total Costs</span>
                    <span class="text-red-400 font-bold">${{ number_format($costBreakdown['total'], 2) }}</span>
                </div>
            </div>

            <!-- P&L Summary -->
            <div class="mt-6 pt-4 border-t border-gray-700">
                <div class="space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-400">Gross P&L</span>
                        <span class="{{ $metrics['gross_pnl'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                            ${{ number_format($metrics['gross_pnl'], 2) }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-400">Total Costs</span>
                        <span class="text-red-400">-${{ number_format(abs($metrics['total_costs']), 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between font-bold">
                        <span class="text-white">Net P&L</span>
                        <span class="{{ $metrics['net_pnl'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                            ${{ number_format($metrics['net_pnl'], 2) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Info Box -->
    @if($metrics['total_trades'] === 0)
        <div class="rounded-lg bg-gray-800/50 border border-gray-700 p-6 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>
            </svg>
            <h3 class="mt-4 text-lg font-medium text-white">No Trading Data</h3>
            <p class="mt-2 text-sm text-gray-400">
                Analytics will appear here once you have closed trades. Start trading to see your performance metrics!
            </p>
        </div>
    @endif
</div>

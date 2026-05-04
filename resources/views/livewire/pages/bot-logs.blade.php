<div>
    <x-slot name="header">
        Bot Logs
    </x-slot>

    <!-- Stats Cards -->
    <div class="mb-6 grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Total Logs</p>
            <p class="text-2xl font-bold text-white">{{ number_format($stats['total']) }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Errors & Critical</p>
            <p class="text-2xl font-bold text-red-400">{{ number_format($stats['errors']) }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Warnings</p>
            <p class="text-2xl font-bold text-yellow-400">{{ number_format($stats['warnings']) }}</p>
        </div>
        <div class="rounded-lg bg-gray-800 p-4">
            <p class="text-sm text-gray-400">Today</p>
            <p class="text-2xl font-bold text-blue-400">{{ number_format($stats['today']) }}</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="mb-6 rounded-lg bg-gray-800 p-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4">
            <!-- Search -->
            <div class="lg:col-span-2">
                <label class="block text-sm font-medium text-gray-400 mb-1">Search</label>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search messages..."
                    class="w-full rounded-md border-gray-700 bg-gray-900 text-white placeholder-gray-500 focus:border-yellow-500 focus:ring-yellow-500"
                >
            </div>

            <!-- Level Filter -->
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Level</label>
                <select
                    wire:model.live="level"
                    class="w-full rounded-md border-gray-700 bg-gray-900 text-white focus:border-yellow-500 focus:ring-yellow-500"
                >
                    <option value="">All Levels</option>
                    @foreach($levels as $key => $levelInfo)
                        <option value="{{ $key }}">{{ $levelInfo['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Source Filter -->
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Source</label>
                <select
                    wire:model.live="source"
                    class="w-full rounded-md border-gray-700 bg-gray-900 text-white focus:border-yellow-500 focus:ring-yellow-500"
                >
                    <option value="">All Sources</option>
                    @foreach($sources as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Date From -->
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">From Date</label>
                <input
                    type="date"
                    wire:model.live="dateFrom"
                    class="w-full rounded-md border-gray-700 bg-gray-900 text-white focus:border-yellow-500 focus:ring-yellow-500"
                >
            </div>

            <!-- Date To -->
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">To Date</label>
                <input
                    type="date"
                    wire:model.live="dateTo"
                    class="w-full rounded-md border-gray-700 bg-gray-900 text-white focus:border-yellow-500 focus:ring-yellow-500"
                >
            </div>
        </div>

        <!-- Filter Actions -->
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button
                wire:click="clearFilters"
                class="inline-flex items-center rounded-md bg-gray-700 px-3 py-1.5 text-sm font-medium text-gray-300 hover:bg-gray-600 transition-colors"
            >
                <svg class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Clear Filters
            </button>

            @if($stats['total'] > 0)
                <button
                    wire:click="clearAllLogs"
                    wire:confirm="Are you sure you want to delete ALL log entries? This cannot be undone."
                    class="inline-flex items-center rounded-md bg-red-600/20 px-3 py-1.5 text-sm font-medium text-red-400 hover:bg-red-600/30 transition-colors"
                >
                    <svg class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                    </svg>
                    Clear All Logs
                </button>
            @endif
        </div>
    </div>

    <!-- Logs List -->
    <div class="rounded-lg bg-gray-800 overflow-hidden">
        @if($logs->isEmpty())
            <div class="p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                </svg>
                <h3 class="mt-4 text-lg font-medium text-white">No logs found</h3>
                <p class="mt-2 text-sm text-gray-400">
                    @if($search || $level || $source || $dateFrom || $dateTo)
                        Try adjusting your filters to see more results.
                    @else
                        Bot logs will appear here once the trading bot starts running.
                    @endif
                </p>
            </div>
        @else
            <div class="divide-y divide-gray-700">
                @foreach($logs as $log)
                    <div class="p-4 hover:bg-gray-700/50 transition-colors">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <!-- Header Row -->
                                <div class="flex flex-wrap items-center gap-2 mb-2">
                                    <!-- Level Badge -->
                                    @php
                                        $levelColors = [
                                            'debug' => 'bg-gray-500/20 text-gray-400',
                                            'info' => 'bg-blue-500/20 text-blue-400',
                                            'warning' => 'bg-yellow-500/20 text-yellow-400',
                                            'error' => 'bg-red-500/20 text-red-400',
                                            'critical' => 'bg-purple-500/20 text-purple-400',
                                        ];
                                        $levelColor = $levelColors[$log->level] ?? 'bg-gray-500/20 text-gray-400';
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $levelColor }}">
                                        {{ strtoupper($log->level) }}
                                    </span>

                                    <!-- Source Badge -->
                                    <span class="inline-flex items-center rounded-full bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-300">
                                        {{ $sources[$log->source] ?? $log->source }}
                                    </span>

                                    <!-- Timestamp -->
                                    <span class="text-xs text-gray-500">
                                        {{ $log->created_at->format('M d, H:i:s') }}
                                    </span>

                                    <!-- Related Trade Link -->
                                    @if($log->related_trade_id && $log->trade)
                                        <a href="{{ route('trades.history') }}?search={{ $log->trade->mt5_ticket }}" class="inline-flex items-center text-xs text-yellow-400 hover:text-yellow-300">
                                            <svg class="mr-1 h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                                            </svg>
                                            Trade #{{ $log->trade->mt5_ticket }}
                                        </a>
                                    @endif
                                </div>

                                <!-- Message -->
                                <p class="text-sm text-white break-words">{{ $log->message }}</p>

                                <!-- Context (if any) -->
                                @if($log->context && count($log->context) > 0)
                                    <div class="mt-2">
                                        <details class="text-xs">
                                            <summary class="text-gray-400 cursor-pointer hover:text-gray-300">
                                                Show context data
                                            </summary>
                                            <pre class="mt-2 p-2 rounded bg-gray-900 text-gray-300 overflow-x-auto">{{ json_encode($log->context, JSON_PRETTY_PRINT) }}</pre>
                                        </details>
                                    </div>
                                @endif
                            </div>

                            <!-- Delete Button -->
                            <button
                                wire:click="clearLog({{ $log->id }})"
                                wire:confirm="Delete this log entry?"
                                class="flex-shrink-0 p-1 text-gray-500 hover:text-red-400 transition-colors"
                                title="Delete log"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Pagination -->
            <div class="px-4 py-3 border-t border-gray-700">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

    <!-- Info Box -->
    <div class="mt-6 rounded-lg bg-gray-800/50 border border-gray-700 p-4">
        <div class="flex items-start space-x-3">
            <svg class="h-5 w-5 text-blue-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/>
            </svg>
            <div class="text-sm text-gray-400">
                <p class="font-medium text-gray-300">Log Levels</p>
                <ul class="mt-1 space-y-1">
                    <li><span class="text-gray-400">Debug</span> - Detailed diagnostic information</li>
                    <li><span class="text-blue-400">Info</span> - General operational events</li>
                    <li><span class="text-yellow-400">Warning</span> - Potential issues that may need attention</li>
                    <li><span class="text-red-400">Error</span> - Errors that prevented an operation</li>
                    <li><span class="text-purple-400">Critical</span> - Serious failures requiring immediate action</li>
                </ul>
            </div>
        </div>
    </div>
</div>

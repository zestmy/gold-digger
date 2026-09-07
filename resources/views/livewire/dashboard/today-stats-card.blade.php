{{-- Polls every 30s, the same cadence as the signals list beneath, so the count on the
     tile and the rows in the list change together rather than one lagging the other. --}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" wire:poll.30s="loadStats">
    {{-- Signals today --}}
    <div class="rounded-lg border border-gray-700 bg-gray-800 p-5">
        <p class="text-sm font-medium text-gray-400">Signals today</p>
        <p class="mt-2 text-3xl font-semibold tabular-nums text-white">{{ $aiSignals + $copiedSignals }}</p>
        <p class="mt-1 text-xs text-gray-500">{{ $aiSignals }} AI &middot; {{ $copiedSignals }} copied</p>
    </div>

    {{-- Open positions --}}
    <div class="rounded-lg border border-gray-700 bg-gray-800 p-5">
        <p class="text-sm font-medium text-gray-400">Open positions</p>
        <p class="mt-2 text-3xl font-semibold tabular-nums text-white">{{ $openPositions }}</p>
        <p class="mt-1 truncate text-xs text-gray-500">
            @if($openPositions === 0)
                nothing at risk
            @else
                {{ implode(' · ', $openSymbols) }}
            @endif
        </p>
    </div>

    {{-- Net P&L today --}}
    <div class="rounded-lg border border-gray-700 bg-gray-800 p-5 ring-1 ring-inset ring-yellow-500/10">
        <p class="text-sm font-medium text-gray-400">Net P&amp;L today</p>
        <p class="mt-2 text-3xl font-semibold tabular-nums {{ $netPnl > 0 ? 'text-green-400' : ($netPnl < 0 ? 'text-red-400' : 'text-white') }}">
            {{ $netPnl < 0 ? '-' : '' }}${{ number_format(abs($netPnl), 2) }}
        </p>
        <p class="mt-1 text-xs tabular-nums text-gray-500">
            30 days:
            <span class="{{ $netPnl30d > 0 ? 'text-green-400/80' : ($netPnl30d < 0 ? 'text-red-400/80' : '') }}">{{ $netPnl30d < 0 ? '-' : '+' }}${{ number_format(abs($netPnl30d), 2) }}</span>
        </p>
    </div>

    {{-- AI fund --}}
    <div class="rounded-lg border border-gray-700 bg-gray-800 p-5">
        <p class="text-sm font-medium text-gray-400">AI fund</p>
        @if($fundConfigured)
            <p class="mt-2 text-3xl font-semibold tabular-nums text-white">
                ${{ number_format($fundRemaining, 0) }}<span class="text-base font-normal text-gray-500"> of ${{ number_format($fundCap, 0) }}</span>
            </p>
            <p class="mt-1 text-xs tabular-nums text-gray-500">${{ number_format($fundCommitted, 2) }} committed</p>
        @else
            {{-- Absent is not zero: no cap means nobody has decided how much the AI may
                 risk, and the tile should send them to decide rather than show $0. --}}
            <p class="mt-2 text-3xl font-semibold text-gray-600">&mdash;</p>
            <p class="mt-1 text-xs text-gray-500">
                No cap set. <a href="{{ route('settings') }}" class="text-yellow-500 hover:text-yellow-400">Set one &rarr;</a>
            </p>
        @endif
    </div>
</div>

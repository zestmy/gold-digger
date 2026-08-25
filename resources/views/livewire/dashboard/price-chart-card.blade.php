{{-- Polls every 30s for a new bar. The chart itself is redrawn by Alpine watching the
     Livewire properties, so a poll that changes nothing repaints nothing. --}}
<div class="rounded-lg bg-gray-800 p-6" wire:poll.30s="load">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div class="flex items-baseline gap-x-3">
            <h3 class="text-sm font-medium text-gray-400">
                Price
                @if($symbol)
                    <span class="ml-1 font-mono text-xs text-gray-500">{{ $symbol }}</span>
                @endif
            </h3>

            {{-- The number itself, not just a line at it. Reading a price off the axis is
                 guesswork at this scale, and the axis label alone competes with the
                 gridline labels around it. --}}
            @if($lastPrice !== null)
                <span class="font-mono text-lg font-semibold text-gray-100">{{ number_format($lastPrice, 2) }}</span>

                @if($changePct !== null)
                    <span class="font-mono text-xs {{ $changePct >= 0 ? 'text-green-400' : 'text-red-400' }}">
                        {{ $changePct >= 0 ? '+' : '' }}{{ number_format($changePct, 2) }}%
                    </span>
                @endif

                @if($lastBarAt)
                    {{-- Says how stale it is. During the broker's daily break the last bar
                         can be an hour old, and a price with no age reads as live. --}}
                    <span class="text-xs text-gray-500">{{ $lastBarAt }}</span>
                @endif
            @endif
        </div>

        <div class="flex items-center gap-x-1">
            @foreach($timeframes as $tf)
                <button
                    type="button"
                    wire:click="selectTimeframe('{{ $tf }}')"
                    class="rounded px-2 py-1 text-xs font-medium {{ $timeframe === $tf ? 'bg-yellow-500/20 text-yellow-400' : 'text-gray-500 hover:text-gray-300' }}"
                >{{ $tf }}</button>
            @endforeach
        </div>
    </div>

    @if(! $hasData)
        <div class="mt-4 rounded-lg border border-dashed border-gray-700 p-8 text-center">
            <p class="text-sm text-gray-500">No {{ $timeframe }} bars yet.</p>
            <p class="mt-1 text-xs text-gray-600">
                The chart draws what the executor has pushed. Bars arrive once the EA is attached
                and Push Candles is on.
            </p>
        </div>
    @else
        {{-- wire:ignore is essential: Livewire must not re-render the node the chart has
             drawn into, or every poll would blow away the canvas and the user's zoom.
             Alpine repaints it from the watched properties instead. --}}
        <div
            x-data="priceChart"
            wire:ignore
            class="mt-4"
        >
            <div x-ref="canvas" class="w-full"></div>
        </div>

        @if($levels)
            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-gray-700 pt-3 text-xs">
                <span class="flex items-center gap-x-1.5 text-gray-400">
                    <span class="inline-block h-0.5 w-4 bg-gray-400"></span> Entry
                </span>
                <span class="flex items-center gap-x-1.5 text-gray-400">
                    <span class="inline-block h-0.5 w-4 border-t border-dashed border-red-500"></span> Stop loss
                </span>
                <span class="flex items-center gap-x-1.5 text-gray-400">
                    <span class="inline-block h-0.5 w-4 border-t border-dotted border-green-500"></span> Take profit
                </span>
                <span class="text-gray-600">
                    Levels are what this dashboard believes. The broker's are authoritative.
                </span>
            </div>
        @else
            <p class="mt-3 border-t border-gray-700 pt-3 text-xs text-gray-600">
                No open positions &mdash; entry, stop and target levels appear here once one is filled.
            </p>
        @endif
    @endif
</div>

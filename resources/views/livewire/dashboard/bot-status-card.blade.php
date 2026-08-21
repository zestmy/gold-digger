{{-- Polls every 10s so a dead terminal becomes visible without a manual refresh. --}}
<div class="rounded-lg bg-gray-800 p-6" wire:poll.10s="refreshStatus">
    <h3 class="text-sm font-medium text-gray-400">Bot Status</h3>

    <div class="mt-4 space-y-4">
        <!-- Status Indicator -->
        <div class="flex items-center gap-x-3">
            @if($isOnline && ! $blockedReason)
                <div class="h-3 w-3 rounded-full bg-green-500 animate-pulse"></div>
                <span class="text-lg font-semibold text-green-400">ONLINE</span>
            @elseif($isOnline)
                {{-- Reachable but unable to trade. Distinct from OFFLINE on purpose:
                     the terminal is fine, the Algo Trading button is not. --}}
                <div class="h-3 w-3 rounded-full bg-amber-500 animate-pulse"></div>
                <span class="text-lg font-semibold text-amber-400">BLOCKED</span>
            @else
                <div class="h-3 w-3 rounded-full bg-red-500"></div>
                <span class="text-lg font-semibold text-red-400">OFFLINE</span>
            @endif
        </div>

        @if($blockedReason)
            <div class="rounded-md bg-amber-900/40 p-3">
                <p class="text-sm text-amber-300">{{ $blockedReason }}</p>
            </div>
        @endif

        {{-- A healthy terminal that still cannot produce a signal. Distinct from
             blockedReason, which is about the terminal itself: here the EA is running
             fine and the strategy layer is missing an input it cannot invent. --}}
        @if($dataWarning)
            <div class="rounded-md bg-amber-900/30 p-3">
                <p class="text-sm text-amber-200">{{ $dataWarning }}</p>
                <a href="{{ route('signals') }}" class="mt-1 inline-block text-xs text-yellow-400 hover:text-yellow-300">
                    See what the strategy decided &rarr;
                </a>
            </div>
        @endif

        <!-- Last Heartbeat -->
        <div>
            <span class="text-sm text-gray-500">Last heartbeat:</span>
            <span class="ml-2 text-sm text-gray-300">{{ $lastHeartbeat ?? 'Never' }}</span>
        </div>

        <!-- Active Broker -->
        <div>
            <span class="text-sm text-gray-500">Active broker:</span>
            <span class="ml-2 text-sm text-gray-300">{{ $activeBroker ?? 'None selected' }}</span>
        </div>

        {{-- The broker's actual symbol name (XAUUSDm, XAUUSD.a, ...). Worth showing:
             it is the value the strategy must be configured with. --}}
        @if($resolvedSymbol)
            <div>
                <span class="text-sm text-gray-500">Symbol:</span>
                <span class="ml-2 text-sm text-gray-300">{{ $resolvedSymbol }}</span>
            </div>
        @endif

        {{-- Bars are the input to the whole strategy layer, so their age is as much a
             liveness signal as the heartbeat itself. --}}
        <div>
            <span class="text-sm text-gray-500">Newest bar:</span>
            <span class="ml-2 text-sm text-gray-300">{{ $feedAge ?? 'None received' }}</span>
        </div>

        <div>
            <span class="text-sm text-gray-500">Open positions:</span>
            <span class="ml-2 text-sm text-gray-300">{{ $openPositions }}</span>
        </div>
    </div>
</div>

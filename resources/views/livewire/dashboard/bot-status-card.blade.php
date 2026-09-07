{{-- Polls every 10s so a dead terminal becomes visible without a manual refresh. --}}
<div class="rounded-lg border border-gray-700 bg-gray-800 p-6" wire:poll.10s="refreshStatus">
    <div class="flex items-center justify-between gap-4">
        <h3 class="text-sm font-medium text-gray-400">Execution</h3>

        <!-- Status Indicator -->
        <div class="flex items-center gap-x-2">
            @if($isOnline && ! $blockedReason)
                <div class="h-2.5 w-2.5 rounded-full bg-green-500 animate-pulse"></div>
                <span class="text-sm font-semibold text-green-400">ONLINE</span>
            @elseif($isOnline)
                {{-- Reachable but unable to trade. Distinct from OFFLINE on purpose:
                     the terminal is fine, the Algo Trading button is not. --}}
                <div class="h-2.5 w-2.5 rounded-full bg-amber-500 animate-pulse"></div>
                <span class="text-sm font-semibold text-amber-400">BLOCKED</span>
            @else
                <div class="h-2.5 w-2.5 rounded-full bg-red-500"></div>
                <span class="text-sm font-semibold text-red-400">OFFLINE</span>
            @endif
        </div>
    </div>

    <div class="mt-4 space-y-3">
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

        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <dt class="text-gray-500">Last heartbeat</dt>
            <dd class="text-right text-gray-300">{{ $lastHeartbeat ?? 'Never' }}</dd>

            <dt class="text-gray-500">Terminal</dt>
            <dd class="truncate text-right text-gray-300" title="{{ $activeBroker }}">{{ $activeBroker ?? 'None selected' }}</dd>

            {{-- What the terminal is carrying, named the way this dashboard names them.
                 The broker's own spelling (XAUUSDm, XAUUSD.a, ...) stays on hover: it
                 is the value the strategy must be configured with. --}}
            <dt class="text-gray-500">Instruments</dt>
            <dd class="text-right text-gray-300" title="{{ $resolvedSymbol }}">
                {{ $instruments === [] ? '—' : implode(' · ', $instruments) }}
            </dd>

            {{-- Bars are the input to the whole strategy layer, so their age is as much a
                 liveness signal as the heartbeat itself. --}}
            <dt class="text-gray-500">Newest bar</dt>
            <dd class="text-right text-gray-300">{{ $feedAge ?? 'None received' }}</dd>

            <dt class="text-gray-500">Open positions</dt>
            <dd class="text-right text-gray-300">{{ $openPositions }}</dd>
        </dl>

        {{-- One line on the calendar. The full list is on the Signals destination; here
             the only question is whether releases are holding entries right now. --}}
        @if($calendar)
            <p class="flex items-start gap-x-2 border-t border-gray-700 pt-3 text-xs {{ $calendarTone === 'stop' ? 'text-amber-300' : 'text-gray-400' }}">
                <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full {{ ['go' => 'bg-green-500', 'stop' => 'bg-red-500'][$calendarTone] ?? 'bg-gray-500' }}"></span>
                <span>{{ $calendar }}</span>
            </p>
        @endif

        {{-- The controls, in their own component so the kill switch and the flatten
             keep the same handlers and tests they had as the Quick Actions card. --}}
        <div class="border-t border-gray-700 pt-3">
            <livewire:dashboard.quick-actions-card />
        </div>
    </div>
</div>

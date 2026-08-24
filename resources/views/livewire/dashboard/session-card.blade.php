{{-- Polls every 60s. The windows have hour granularity, so anything faster redraws
     the same answer; the countdown is the only part that moves. --}}
<div class="rounded-lg bg-gray-800 p-6" wire:poll.60s="refreshSessions">
    <div class="flex items-baseline justify-between">
        <h3 class="text-sm font-medium text-gray-400">Trading Session</h3>
        <span class="font-mono text-xs text-gray-500">{{ $utcTime }}</span>
    </div>

    <div class="mt-4 flex items-center gap-x-3">
        @if($tradingWindowOpen)
            <div class="h-3 w-3 rounded-full bg-green-500 animate-pulse"></div>
            <span class="text-lg font-semibold text-green-400">OPEN</span>
        @else
            <div class="h-3 w-3 rounded-full bg-gray-500"></div>
            <span class="text-lg font-semibold text-gray-400">CLOSED</span>
        @endif
    </div>

    {{-- Open and allowed are different facts. London can be open while this account
         trades only the overlap, and the fix differs: wait, or change the setting. --}}
    <div class="mt-4 space-y-3">
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Open now</p>
            <div class="mt-1 flex flex-wrap gap-1.5">
                @forelse($openSessions as $session)
                    @php $isAllowed = $unrestricted || in_array($session, $allowedSessions, true); @endphp
                    <span class="rounded px-2 py-0.5 text-xs font-medium
                        {{ $isAllowed ? 'bg-green-400/10 text-green-400 ring-1 ring-inset ring-green-400/20'
                                      : 'bg-gray-700 text-gray-400' }}">
                        {{ ucfirst($session) }}
                    </span>
                @empty
                    <span class="text-xs text-gray-500">No session open &mdash; thin, illiquid hours.</span>
                @endforelse
            </div>
        </div>

        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">This account trades</p>
            <p class="mt-1 text-sm text-gray-300">
                @if($unrestricted)
                    {{-- allowed_sessions is nullable and TradingSession reads empty as
                         "always allowed", so say that rather than showing a blank row. --}}
                    Every session &mdash; no restriction configured.
                @else
                    {{ implode(', ', array_map('ucfirst', $allowedSessions)) }}
                @endif
            </p>
        </div>

        @if(! $tradingWindowOpen && $nextOpenAt)
            <div class="rounded-md bg-gray-900 p-3">
                <p class="text-sm text-gray-300">Next window opens {{ $nextOpenIn }}</p>
                <p class="mt-0.5 text-xs text-gray-500">at {{ $nextOpenAt }}</p>
            </div>
        @endif

        @if(! $tradingWindowOpen)
            <p class="text-xs text-gray-500">
                Setups outside the window are still recorded, skipped as
                <code class="text-gray-400">session_closed</code>.
                <a href="{{ route('signals') }}" class="text-yellow-500 hover:text-yellow-400">See signals &rarr;</a>
            </p>
        @endif
    </div>
</div>

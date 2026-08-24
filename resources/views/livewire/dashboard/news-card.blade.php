{{-- Polls every 60s: releases have minute granularity and the blackout edges move with
     the clock, so this is the slowest interval that keeps the state honest. --}}
<div class="rounded-lg bg-gray-800 p-6" wire:poll.60s="refreshNews">
    <div class="flex items-baseline justify-between">
        <h3 class="text-sm font-medium text-gray-400">Economic Calendar</h3>
        @if($currencies)
            <span class="font-mono text-xs text-gray-500">{{ implode(' / ', $currencies) }}</span>
        @endif
    </div>

    @if(! $filterEnabled)
        <div class="mt-4 flex items-center gap-x-3">
            <div class="h-3 w-3 rounded-full bg-gray-500"></div>
            <span class="text-lg font-semibold text-gray-400">FILTER OFF</span>
        </div>
        <p class="mt-2 text-xs text-gray-500">
            Releases are shown for context only. Entries are not held around them.
            <a href="{{ route('settings') }}" class="text-yellow-500 hover:text-yellow-400">Settings &rarr;</a>
        </p>

    @elseif($stale)
        {{-- Surfaced as loudly as a blackout, because the consequence is identical -
             entries are held - while the remedy is completely different. --}}
        <div class="mt-4 flex items-center gap-x-3">
            <div class="h-3 w-3 rounded-full bg-amber-500 animate-pulse"></div>
            <span class="text-lg font-semibold text-amber-400">NO CALENDAR</span>
        </div>
        <div class="mt-3 rounded-md bg-amber-900/40 p-3">
            <p class="text-sm text-amber-300">
                The news filter is on and the calendar is missing or more than
                {{ \App\Services\News\NewsBlackout::STALE_AFTER_HOURS }} hours old, so it cannot be
                checked. Entries are being held rather than taken unprotected.
            </p>
            <p class="mt-1 text-xs text-amber-200/70">
                Run <code>php artisan news:fetch</code>, or turn the filter off in settings.
            </p>
        </div>

    @elseif($inBlackout)
        <div class="mt-4 flex items-center gap-x-3">
            <div class="h-3 w-3 rounded-full bg-red-500 animate-pulse"></div>
            <span class="text-lg font-semibold text-red-400">BLACKOUT</span>
        </div>
        <p class="mt-2 text-sm text-gray-300">
            A high-impact release is inside the {{ $beforeMinutes }}m/{{ $afterMinutes }}m window.
            New entries are held.
        </p>

    @else
        <div class="mt-4 flex items-center gap-x-3">
            <div class="h-3 w-3 rounded-full bg-green-500"></div>
            <span class="text-lg font-semibold text-green-400">CLEAR</span>
        </div>
        @if($nextEventTitle)
            <p class="mt-2 text-sm text-gray-300">
                Next: <span class="text-gray-200">{{ $nextEventTitle }}</span>
                <span class="text-gray-500">in {{ $nextEventIn }}</span>
            </p>
            <p class="text-xs text-gray-500">{{ $nextEventAt }} &middot; holds entries {{ $beforeMinutes }}m before, {{ $afterMinutes }}m after</p>
        @else
            <p class="mt-2 text-xs text-gray-500">No high-impact releases scheduled for {{ implode(' or ', $currencies) }}.</p>
        @endif
    @endif

    @if($upcoming)
        <ul class="mt-4 space-y-2 border-t border-gray-700 pt-3">
            @foreach($upcoming as $event)
                <li class="flex items-baseline gap-x-2 text-xs {{ $event['past'] ? 'opacity-50' : '' }}">
                    <span class="w-2 shrink-0">
                        <span class="inline-block h-1.5 w-1.5 rounded-full {{ $event['impact'] === 'high' ? 'bg-red-500' : 'bg-amber-500' }}"></span>
                    </span>
                    <span class="w-20 shrink-0 font-mono text-gray-500">{{ $event['at'] }}</span>
                    <span class="w-8 shrink-0 font-medium text-gray-400">{{ $event['currency'] }}</span>
                    <span class="flex-1 truncate text-gray-300" title="{{ $event['title'] }}">{{ $event['title'] }}</span>
                    @if($event['actual'])
                        <span class="shrink-0 font-mono text-gray-200">{{ $event['actual'] }}</span>
                    @elseif($event['forecast'])
                        <span class="shrink-0 font-mono text-gray-600">{{ $event['forecast'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>

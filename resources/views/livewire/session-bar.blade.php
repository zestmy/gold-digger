{{-- Polled rather than pushed: this changes meaningfully a handful of times a day, and a
     websocket for that would be more moving parts than the value justifies. --}}
<div class="flex flex-wrap items-center gap-x-5 gap-y-2" wire:poll.60s>
    <!-- Sessions -->
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        @foreach($windows as $window)
            <div class="flex items-center gap-1.5" title="{{ $window['active']
                    ? $window['label'].' closes in '.$window['closes_in']
                    : $window['label'].' opens in '.$window['opens_in'] }}">
                <span class="h-1.5 w-1.5 rounded-full {{ $window['active'] ? 'bg-green-400' : 'bg-gray-600' }}"></span>

                <span class="text-xs {{ $window['active'] ? 'font-medium text-gray-100' : 'text-gray-500' }}">
                    {{ $window['label'] }}
                </span>

                {{-- Only for what is open. A countdown on a shut market is a countdown to
                     something that is not going to happen for hours. --}}
                @if($window['active'])
                    <span class="text-xs text-green-400/70">{{ $window['closes_in'] }}</span>
                @endif
            </div>
        @endforeach
    </div>

    @if($weekend)
        <span class="rounded bg-gray-700 px-2 py-0.5 text-xs text-gray-400">Market closed</span>
    @endif

    <!-- Clocks. UTC always, because sessions are defined in it and support conversations
         are conducted in it. -->
    <div class="ml-auto flex items-center gap-x-3 text-xs">
        @if($zone)
            <span class="text-gray-300">{{ $utc->copy()->setTimezone($zone)->format('H:i') }}</span>
        @endif
        <span class="text-gray-500">{{ $utc->format('H:i') }} UTC</span>
    </div>
</div>

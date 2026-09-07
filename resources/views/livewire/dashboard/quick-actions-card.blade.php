{{-- Compact controls for the Execution card. One toggle for the kill switch and one
     flatten, as outline buttons: these are things somebody reaches for a few times a
     month, and a row of full-colour blocks made them look like the point of the page. --}}
<div>
    <!-- Flash Message -->
    @if (session()->has('message'))
        <div class="mb-3 rounded-md bg-sky-900/50 p-3">
            <p class="text-sm text-sky-300">{{ session('message') }}</p>
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        @if($tradingEnabled)
            <button wire:click="stopBot"
                    type="button"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-x-1.5 rounded-md border border-gray-600 px-3 py-1.5 text-xs font-semibold text-gray-200 hover:border-gray-500 hover:bg-gray-700/60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-yellow-500 disabled:opacity-50">
                <svg class="h-4 w-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" />
                </svg>
                Pause auto-trade
            </button>
        @else
            <button wire:click="startBot"
                    type="button"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-x-1.5 rounded-md border border-gray-600 px-3 py-1.5 text-xs font-semibold text-gray-200 hover:border-gray-500 hover:bg-gray-700/60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-yellow-500 disabled:opacity-50">
                <svg class="h-4 w-4 text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" />
                </svg>
                Resume auto-trade
            </button>
        @endif

        <button wire:click="closeAllPositions"
                type="button"
                wire:loading.attr="disabled"
                wire:confirm="Close every open position at market? This cannot be undone."
                class="inline-flex items-center gap-x-1.5 rounded-md border border-gray-600 px-3 py-1.5 text-xs font-semibold text-gray-200 hover:border-red-500/60 hover:bg-red-900/20 hover:text-red-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-500 disabled:opacity-50">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
            Close all
        </button>

        <span class="text-xs {{ $tradingEnabled ? 'text-green-400' : 'text-gray-500' }}">
            {{ $tradingEnabled ? 'Auto-trade on' : 'Auto-trade paused' }}
        </span>
    </div>

    {{-- Commands are queued, not executed inline: the terminal polls outward from a
         VPS behind NAT, so there is always a poll interval of latency. Saying so
         beats leaving the user wondering whether the click registered. --}}
    <p class="mt-2 text-xs text-gray-600">
        Queued for the terminal; picked up on its next poll.
    </p>
</div>

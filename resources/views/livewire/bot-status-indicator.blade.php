{{-- Polls every 15s. Shares BotHeartbeat::status() with BotStatusCard so the sidebar and
     the dashboard cannot report different states for the same terminal. --}}
<div class="rounded-lg bg-gray-800 p-4" wire:poll.15s="refreshStatus">
    <div class="flex items-center gap-x-3">
        @if($status === \App\Models\BotHeartbeat::STATUS_ONLINE)
            <div class="h-2.5 w-2.5 rounded-full bg-green-500 animate-pulse"></div>
            <span class="text-sm font-medium text-green-400">Bot Online</span>
        @elseif($status === \App\Models\BotHeartbeat::STATUS_BLOCKED)
            {{-- Reachable but unable to trade. Amber, not red: nothing is broken in the
                 way "offline" implies, and sending someone to restart a healthy terminal
                 is the wrong repair. --}}
            <div class="h-2.5 w-2.5 rounded-full bg-amber-500 animate-pulse"></div>
            <span class="text-sm font-medium text-amber-400">Bot Blocked</span>
        @else
            <div class="h-2.5 w-2.5 rounded-full bg-red-500"></div>
            <span class="text-sm font-medium text-gray-300">Bot Offline</span>
        @endif
    </div>

    <p class="mt-1 text-xs text-gray-500">
        @if($status === \App\Models\BotHeartbeat::STATUS_ONLINE)
            <a href="{{ route('dashboard') }}" class="hover:text-gray-400">Executor reporting</a>
        @elseif($status === \App\Models\BotHeartbeat::STATUS_BLOCKED)
            <a href="{{ route('dashboard') }}" class="text-amber-500 hover:text-amber-400">Cannot trade &mdash; see dashboard</a>
        @elseif($hasEverReported)
            <a href="{{ route('dashboard') }}" class="hover:text-gray-400">Stopped reporting</a>
        @else
            <a href="{{ route('logs') }}" class="hover:text-gray-400">No executor has checked in</a>
        @endif
    </p>
</div>

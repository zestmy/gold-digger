<div class="rounded-lg bg-gray-800 p-6">
    <h3 class="text-sm font-medium text-gray-400">Bot Status</h3>

    <div class="mt-4 space-y-4">
        <!-- Status Indicator -->
        <div class="flex items-center gap-x-3">
            @if($isOnline)
                <div class="h-3 w-3 rounded-full bg-green-500 animate-pulse"></div>
                <span class="text-lg font-semibold text-green-400">ONLINE</span>
            @else
                <div class="h-3 w-3 rounded-full bg-red-500"></div>
                <span class="text-lg font-semibold text-red-400">OFFLINE</span>
            @endif
        </div>

        <!-- Last Heartbeat -->
        <div>
            <span class="text-sm text-gray-500">Last heartbeat:</span>
            <span class="ml-2 text-sm text-gray-300">
                {{ $lastHeartbeat ?? 'Never' }}
            </span>
        </div>

        <!-- Active Broker -->
        <div>
            <span class="text-sm text-gray-500">Active broker:</span>
            <span class="ml-2 text-sm text-gray-300">
                {{ $activeBroker ?? 'None selected' }}
            </span>
        </div>
    </div>
</div>

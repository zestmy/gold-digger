<div class="rounded-lg bg-gray-800 p-6">
    <h3 class="text-sm font-medium text-gray-400">Today's Performance</h3>

    <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <!-- Trades Count -->
        <div class="rounded-lg bg-gray-900 p-4">
            <p class="text-sm text-gray-500">Trades</p>
            <p class="mt-1 text-2xl font-semibold text-white">{{ $tradesCount }}</p>
        </div>

        <!-- Gross P&L -->
        <div class="rounded-lg bg-gray-900 p-4">
            <p class="text-sm text-gray-500">Gross P&L</p>
            <p class="mt-1 text-2xl font-semibold {{ $grossPnl >= 0 ? 'text-green-400' : 'text-red-400' }}">
                ${{ number_format($grossPnl, 2) }}
            </p>
        </div>

        <!-- Total Costs -->
        <div class="rounded-lg bg-gray-900 p-4">
            <p class="text-sm text-gray-500">Costs</p>
            <p class="mt-1 text-2xl font-semibold text-orange-400">
                -${{ number_format($totalCosts, 2) }}
            </p>
        </div>

        <!-- Net P&L (Gold highlighted) -->
        <div class="rounded-lg bg-gray-900 p-4 ring-1 ring-yellow-500/20">
            <p class="text-sm text-yellow-500">Net P&L</p>
            <p class="mt-1 text-2xl font-semibold {{ $netPnl >= 0 ? 'text-yellow-400' : 'text-red-400' }}">
                ${{ number_format($netPnl, 2) }}
            </p>
        </div>
    </div>
</div>

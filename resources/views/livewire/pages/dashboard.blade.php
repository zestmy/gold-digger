<div>
    <x-slot name="header">
        Home
    </x-slot>

    {{-- Home is a glance, not a console. Four numbers, today's signals, and how the
         month and the terminal are doing. Everything else is one destination away:
         the archive on Signals, the positions on Trades, the controls on Auto-Trade. --}}
    <div class="space-y-6">
        <!-- Row 1: signals today, open positions, net P&L today, AI fund -->
        <livewire:dashboard.today-stats-card />

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <!-- Row 2 left: what fired today, from both sources -->
            <div class="xl:col-span-2">
                <livewire:dashboard.today-signals-card />
            </div>

            <!-- Row 2 right: the month's curve, and the terminal that trades it -->
            <div class="space-y-6">
                <livewire:dashboard.daily-chart-card />
                <livewire:dashboard.bot-status-card />
            </div>
        </div>
    </div>
</div>

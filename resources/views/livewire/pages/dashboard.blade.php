<div>
    <x-slot name="header">
        Dashboard
    </x-slot>

    <!-- Dashboard Grid -->
    <div class="space-y-6">
        <!-- Top Row: Bot Status + Today's Stats -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Bot Status Card (1 column) -->
            <div class="lg:col-span-1">
                <livewire:dashboard.bot-status-card />
            </div>

            <!-- Today's Stats Card (2 columns) -->
            <div class="lg:col-span-2">
                <livewire:dashboard.today-stats-card />
            </div>
        </div>

        <!-- Quick Actions Row -->
        <livewire:dashboard.quick-actions-card />

        <!-- Bottom Row: Recent Trades + Daily Chart -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Recent Trades -->
            <livewire:dashboard.recent-trades-card />

            <!-- Daily Chart -->
            <livewire:dashboard.daily-chart-card />
        </div>
    </div>
</div>

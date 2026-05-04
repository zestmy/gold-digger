<?php

namespace App\Livewire\Dashboard;

use Livewire\Component;

/**
 * Daily Chart Card Component
 *
 * Placeholder for the equity curve chart.
 * Will be implemented in Phase 1C with Chart.js or ApexCharts.
 *
 * Features planned:
 * - Daily equity curve
 * - Cumulative P&L line
 * - Win/loss bars
 * - Drawdown overlay
 */
class DailyChartCard extends Component
{
    public function render()
    {
        return view('livewire.dashboard.daily-chart-card');
    }
}

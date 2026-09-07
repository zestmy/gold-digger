<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Pages\Analytics;
use App\Models\Trade;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Daily Chart Card Component
 *
 * The equity curve, which had been a "Coming in Phase 1C" placeholder while Analytics was
 * already computing the same cumulative series two pages away.
 *
 * ## A line, not bars
 *
 * Analytics draws its curve as bars whose height is the absolute cumulative total, which makes
 * a drawdown and a gain look alike apart from colour and puts the zero line nowhere in
 * particular. An equity curve is a path over time: what matters is the shape, where it crosses
 * zero, and how far it fell from its peak. So this is a polyline against a real zero baseline,
 * drawn as inline SVG - no chart library, nothing to load, and it survives the strict CSP the
 * dashboard runs under.
 *
 * ## Settled trades only
 *
 * Same definition as Analytics::SETTLED_STATUSES, shared rather than restated - the two pages
 * disagreeing about what counts as a result is exactly the bug that made the win rate omit
 * every stop-out.
 */
class DailyChartCard extends Component
{
    /** Days of history shown. Enough to see a drawdown; short enough to stay readable. */
    private const DAYS = 30;

    public function render()
    {
        $rows = Trade::where('user_id', Auth::id())
            ->whereIn('status', Analytics::SETTLED_STATUSES)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', now()->subDays(self::DAYS)->startOfDay())
            ->selectRaw('DATE(closed_at) as day, SUM(net_pnl_money) as pnl, COUNT(*) as trades')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $points = [];
        $running = 0.0;

        foreach ($rows as $row) {
            $running += (float) $row->pnl;

            $points[] = [
                'day' => $row->day,
                'pnl' => (float) $row->pnl,
                'trades' => (int) $row->trades,
                'cumulative' => round($running, 2),
            ];
        }

        return view('livewire.dashboard.daily-chart-card', [
            'points' => $points,
            'geometry' => $this->geometry($points),
            'days' => self::DAYS,
            'summary' => $this->summary(),
        ]);
    }

    /**
     * The three numbers under the curve: how many trades, how many won, and what the
     * winners made against what the losers cost.
     *
     * The same definitions Analytics uses for the same period, so the card and the
     * Performance tab never show two win rates for one month. Profit factor is on gross
     * P&L, as it is there; a factor on net would move with commission and say something
     * about the broker rather than the strategy.
     *
     * @return array{trades: int, win_rate: float|null, profit_factor: float|null}
     */
    private function summary(): array
    {
        $trades = Trade::where('user_id', Auth::id())
            ->whereIn('status', Analytics::SETTLED_STATUSES)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', now()->subDays(self::DAYS)->startOfDay())
            ->get(['net_pnl_money', 'gross_pnl_money']);

        $count = $trades->count();

        if ($count === 0) {
            return ['trades' => 0, 'win_rate' => null, 'profit_factor' => null];
        }

        $grossProfit = (float) $trades->where('gross_pnl_money', '>', 0)->sum('gross_pnl_money');
        $grossLoss = abs((float) $trades->where('gross_pnl_money', '<', 0)->sum('gross_pnl_money'));

        return [
            'trades' => $count,
            'win_rate' => round($trades->where('net_pnl_money', '>', 0)->count() / $count * 100, 1),
            // Null rather than infinity when nothing has lost yet: "no losses" is a fact
            // worth stating, and 999.99 is not a number anybody would believe.
            'profit_factor' => $grossLoss > 0.0 ? round($grossProfit / $grossLoss, 2) : null,
        ];
    }

    /**
     * Turn the series into SVG coordinates.
     *
     * Done here rather than in the template because it is arithmetic, and arithmetic in Blade
     * is where off-by-one bugs go to hide.
     *
     * The viewBox is a fixed 100x40 grid and the SVG scales to its container, so nothing here
     * depends on the rendered width.
     *
     * @param  array<int, array<string, mixed>>  $points
     * @return array<string, mixed>|null
     */
    private function geometry(array $points): ?array
    {
        if (count($points) < 2) {
            // One point is not a curve. The view shows the figure on its own instead of
            // drawing a line through a single coordinate.
            return null;
        }

        $values = array_column($points, 'cumulative');

        $high = max($values);
        $low = min($values);

        // The baseline is always in view, or a curve that never went negative would look
        // like it started at the bottom of the chart and a loss would look like a gain.
        $top = max($high, 0.0);
        $bottom = min($low, 0.0);
        $span = max($top - $bottom, 0.01);

        $lastIndex = count($values) - 1;

        $x = fn (int $i): float => round(($i / $lastIndex) * 100, 3);
        $y = fn (float $v): float => round(40 - (($v - $bottom) / $span) * 40, 3);

        $coordinates = [];

        foreach ($values as $i => $value) {
            $coordinates[] = $x($i).','.$y($value);
        }

        $line = implode(' ', $coordinates);

        return [
            'line' => $line,
            // Closed back down to the baseline, so the area under the curve can be filled.
            'area' => $line.' 100,'.$y($bottom).' 0,'.$y($bottom),
            'zero' => $y(0.0),
            'high' => $high,
            'low' => $low,
            'final' => end($values),
            'final_x' => $x($lastIndex),
            'final_y' => $y((float) end($values)),
            'positive' => end($values) >= 0,
        ];
    }
}

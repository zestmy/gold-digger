<?php

namespace App\Livewire\Pages;

use App\Models\Trade;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Analytics - FXSignalPro')]
class Analytics extends Component
{
    /**
     * Statuses whose profit or loss is final.
     *
     * A stop-out is as settled as a target hit - it is simply the losing half of the same
     * distribution, and leaving it out is what made every metric on this page optimistic.
     */
    public const SETTLED_STATUSES = ['fully_closed', 'stopped_out'];

    #[Url]
    public string $period = '30';

    public array $periods = [
        '7' => 'Last 7 Days',
        '30' => 'Last 30 Days',
        '90' => 'Last 90 Days',
        'all' => 'All Time',
    ];

    public function render()
    {
        $userId = Auth::id();

        // Build date constraint
        $dateConstraint = match ($this->period) {
            '7' => now()->subDays(7),
            '30' => now()->subDays(30),
            '90' => now()->subDays(90),
            default => null,
        };

        // Trades whose outcome is settled.
        //
        // `stopped_out` used to be missing from this list, and FillController writes it for
        // every close whose reason is `sl` - so every trade that hit its stop was excluded
        // from the win rate, the profit factor, the average and largest loss, the direction
        // and strategy breakdowns, and the equity curve. The page did not fail; it reported a
        // strategy that only ever wins.
        //
        // `partially_closed` stays out because the position is still open and its result is
        // not known yet, and `cancelled` because it never reached the market.
        $tradesQuery = Trade::where('user_id', $userId)
            ->whereIn('status', self::SETTLED_STATUSES);

        if ($dateConstraint) {
            $tradesQuery->where('closed_at', '>=', $dateConstraint);
        }

        $trades = $tradesQuery->get();

        // Calculate key metrics
        $totalTrades = $trades->count();
        $winningTrades = $trades->where('net_pnl_money', '>', 0)->count();
        $losingTrades = $trades->where('net_pnl_money', '<', 0)->count();
        $breakEvenTrades = $trades->where('net_pnl_money', '=', 0)->count();

        $grossPnl = $trades->sum('gross_pnl_money');
        $totalCosts = $trades->sum('total_costs_money');
        $netPnl = $trades->sum('net_pnl_money');

        $winRate = $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 1) : 0;

        // Profit Factor
        $grossProfit = $trades->where('gross_pnl_money', '>', 0)->sum('gross_pnl_money');
        $grossLoss = abs($trades->where('gross_pnl_money', '<', 0)->sum('gross_pnl_money'));
        $profitFactor = $grossLoss > 0 ? round($grossProfit / $grossLoss, 2) : ($grossProfit > 0 ? 999.99 : 0);

        // Average win/loss
        $avgWin = $winningTrades > 0 ? round($trades->where('net_pnl_money', '>', 0)->avg('net_pnl_money'), 2) : 0;
        $avgLoss = $losingTrades > 0 ? round($trades->where('net_pnl_money', '<', 0)->avg('net_pnl_money'), 2) : 0;

        // Largest win/loss
        $largestWin = $trades->max('net_pnl_money') ?? 0;
        $largestLoss = $trades->min('net_pnl_money') ?? 0;

        // Performance by direction
        $buyTrades = $trades->where('direction', 'buy');
        $sellTrades = $trades->where('direction', 'sell');

        $directionStats = [
            'buy' => [
                'count' => $buyTrades->count(),
                'pnl' => $buyTrades->sum('net_pnl_money'),
                'win_rate' => $buyTrades->count() > 0 ? round(($buyTrades->where('net_pnl_money', '>', 0)->count() / $buyTrades->count()) * 100, 1) : 0,
            ],
            'sell' => [
                'count' => $sellTrades->count(),
                'pnl' => $sellTrades->sum('net_pnl_money'),
                'win_rate' => $sellTrades->count() > 0 ? round(($sellTrades->where('net_pnl_money', '>', 0)->count() / $sellTrades->count()) * 100, 1) : 0,
            ],
        ];

        // Performance by strategy
        $strategyStats = $trades->groupBy('strategy_id')->map(function ($stratTrades, $stratId) {
            $strategy = $stratTrades->first()->strategy;

            return [
                'name' => $strategy?->name ?? 'Unknown',
                'count' => $stratTrades->count(),
                'pnl' => $stratTrades->sum('net_pnl_money'),
                'win_rate' => $stratTrades->count() > 0 ? round(($stratTrades->where('net_pnl_money', '>', 0)->count() / $stratTrades->count()) * 100, 1) : 0,
            ];
        })->values()->all();

        // Daily P&L for chart
        $dailyPnlQuery = Trade::where('user_id', $userId)
            ->whereIn('status', self::SETTLED_STATUSES)
            ->whereNotNull('closed_at')
            ->selectRaw('DATE(closed_at) as date, SUM(net_pnl_money) as pnl, COUNT(*) as trades')
            ->groupBy('date')
            ->orderBy('date', 'asc');

        if ($dateConstraint) {
            $dailyPnlQuery->where('closed_at', '>=', $dateConstraint);
        }

        $dailyPnl = $dailyPnlQuery->get();

        // Calculate cumulative P&L for equity curve
        $cumulativePnl = [];
        $runningTotal = 0;
        foreach ($dailyPnl as $day) {
            $runningTotal += $day->pnl;
            $cumulativePnl[] = [
                'date' => $day->date,
                'pnl' => round($day->pnl, 2),
                'cumulative' => round($runningTotal, 2),
                'trades' => $day->trades,
            ];
        }

        // Cost breakdown
        $costBreakdown = [
            'commission' => $trades->sum('commission_money'),
            'swap' => $trades->sum('swap_money'),
            'total' => $totalCosts,
        ];

        return view('livewire.pages.analytics', [
            'metrics' => [
                'total_trades' => $totalTrades,
                'winning_trades' => $winningTrades,
                'losing_trades' => $losingTrades,
                'break_even_trades' => $breakEvenTrades,
                'win_rate' => $winRate,
                'profit_factor' => $profitFactor,
                'gross_pnl' => $grossPnl,
                'total_costs' => $totalCosts,
                'net_pnl' => $netPnl,
                'avg_win' => $avgWin,
                'avg_loss' => $avgLoss,
                'largest_win' => $largestWin,
                'largest_loss' => $largestLoss,
            ],
            'directionStats' => $directionStats,
            'strategyStats' => $strategyStats,
            'dailyPnl' => $cumulativePnl,
            'costBreakdown' => $costBreakdown,
        ]);
    }
}

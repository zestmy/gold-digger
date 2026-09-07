<?php

namespace App\Livewire\Pages;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Home
 *
 * The page somebody lands on, built to answer four questions on the way in: did anything
 * fire today, am I in anything, how is the day and the month going, and is the terminal
 * there. Each answer is a card that reads its own tables and polls on its own clock:
 *
 * - TodayStatsCard: the four tiles across the top.
 * - TodaySignalsCard: what fired today, AI and copied, newest first.
 * - DailyChartCard: the month's equity curve with trades, win rate and profit factor.
 * - BotStatusCard: the terminal, what it carries, the calendar, and the controls.
 *
 * Nothing here is the archive. The Signals, Trades and Auto-Trade destinations are one
 * click from every card, and the composition stays at the size of a glance.
 */
#[Layout('layouts.app')]
#[Title('Home - FXSignalPro')]
class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.pages.dashboard');
    }
}

<?php

namespace App\Livewire\Pages;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Dashboard Page Component
 *
 * The main dashboard view that displays:
 * - Bot status card (online/offline, heartbeat)
 * - Today's trading statistics
 * - Quick action buttons
 * - Recent trades table
 * - Daily equity chart placeholder
 */
#[Layout('layouts.app')]
#[Title('Dashboard - Gold Digger')]
class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.pages.dashboard');
    }
}

<?php

namespace App\Livewire\Dashboard;

use Livewire\Component;

/**
 * Bot Status Card Component
 *
 * Displays the current status of the Python trading bot:
 * - Online/Offline status with colored indicator
 * - Last heartbeat timestamp
 * - Currently active broker account
 *
 * In Phase 3, this will poll the bot's health endpoint.
 */
class BotStatusCard extends Component
{
    // Bot status - will be updated via API in Phase 3
    public bool $isOnline = false;
    public ?string $lastHeartbeat = null;
    public ?string $activeBroker = null;

    public function render()
    {
        return view('livewire.dashboard.bot-status-card');
    }
}

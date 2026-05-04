<?php

namespace App\Livewire\Pages;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings Page
 *
 * Will allow configuration of bot settings.
 * Features planned for Phase 1B:
 * - Risk management settings
 * - Trading session filters
 * - News filter toggle
 * - Screenshot capture settings
 * - Notification preferences
 * - Python bot connection settings
 */
#[Layout('layouts.app')]
#[Title('Settings - Gold Digger')]
class Settings extends Component
{
    public string $pageTitle = 'Settings';
    public string $pageDescription = 'Configure your bot settings, risk management, and preferences.';
    public string $comingPhase = 'Phase 1B';

    public function render()
    {
        return view('livewire.pages.placeholder');
    }
}

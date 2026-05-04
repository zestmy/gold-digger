<?php

namespace App\Livewire\Pages;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Strategies Page
 *
 * Will allow viewing and editing trading strategies.
 * Features planned for Phase 1B:
 * - Strategy cards with key parameters
 * - Edit strategy modal
 * - Strategy performance metrics
 * - Activate/deactivate toggle
 * - Clone strategy functionality
 */
#[Layout('layouts.app')]
#[Title('Strategies - Gold Digger')]
class Strategies extends Component
{
    public string $pageTitle = 'Strategies';
    public string $pageDescription = 'Configure your trading strategies and their parameters.';
    public string $comingPhase = 'Phase 1B';

    public function render()
    {
        return view('livewire.pages.placeholder');
    }
}

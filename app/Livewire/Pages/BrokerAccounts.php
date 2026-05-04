<?php

namespace App\Livewire\Pages;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Broker Accounts Page
 *
 * Will manage MT5 broker account connections.
 * Features planned for Phase 2:
 * - Add/edit broker accounts
 * - Connection status indicators
 * - Balance sync functionality
 * - Select active trading account
 * - Demo vs Live account separation
 */
#[Layout('layouts.app')]
#[Title('Broker Accounts - Gold Digger')]
class BrokerAccounts extends Component
{
    public string $pageTitle = 'Broker Accounts';
    public string $pageDescription = 'Manage your MT5 broker account connections and credentials.';
    public string $comingPhase = 'Phase 2';

    public function render()
    {
        return view('livewire.pages.placeholder');
    }
}

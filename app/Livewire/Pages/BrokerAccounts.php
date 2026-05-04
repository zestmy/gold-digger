<?php

namespace App\Livewire\Pages;

use App\Models\BrokerAccount;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Broker Accounts - Gold Digger')]
class BrokerAccounts extends Component
{
    public bool $showModal = false;
    public ?int $editingId = null;

    #[Validate('required|string|max:255')]
    public string $label = '';

    #[Validate('required|string|max:255')]
    public string $broker_name = 'Octa';

    #[Validate('required|string|max:255')]
    public string $account_number = '';

    #[Validate('required|string|max:255')]
    public string $server = '';

    #[Validate('boolean')]
    public bool $is_demo = true;

    #[Validate('boolean')]
    public bool $is_active = false;

    #[Validate('required|string|max:10')]
    public string $account_currency = 'USD';

    #[Validate('required|integer|min:1|max:2000')]
    public int $leverage = 100;

    public array $currencies = ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CHF'];

    public array $brokers = [
        'Octa' => 'OctaFX',
        'ICMarkets' => 'IC Markets',
        'Pepperstone' => 'Pepperstone',
        'XM' => 'XM Global',
        'Exness' => 'Exness',
        'FXTM' => 'FXTM',
        'Other' => 'Other',
    ];

    public function openModal(?int $id = null): void
    {
        $this->resetForm();

        if ($id) {
            $account = BrokerAccount::where('user_id', Auth::id())->findOrFail($id);
            $this->editingId = $id;
            $this->label = $account->label;
            $this->broker_name = $account->broker_name;
            $this->account_number = $account->account_number;
            $this->server = $account->server;
            $this->is_demo = $account->is_demo;
            $this->is_active = $account->is_active;
            $this->account_currency = $account->account_currency;
            $this->leverage = $account->leverage;
        }

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->label = '';
        $this->broker_name = 'Octa';
        $this->account_number = '';
        $this->server = '';
        $this->is_demo = true;
        $this->is_active = false;
        $this->account_currency = 'USD';
        $this->leverage = 100;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate();

        // If setting this account as active, deactivate others
        if ($this->is_active) {
            BrokerAccount::where('user_id', Auth::id())->update(['is_active' => false]);
        }

        $data = [
            'user_id' => Auth::id(),
            'label' => $this->label,
            'broker_name' => $this->broker_name,
            'account_number' => $this->account_number,
            'server' => $this->server,
            'is_demo' => $this->is_demo,
            'is_active' => $this->is_active,
            'account_currency' => $this->account_currency,
            'leverage' => $this->leverage,
        ];

        if ($this->editingId) {
            BrokerAccount::where('user_id', Auth::id())
                ->where('id', $this->editingId)
                ->update($data);
            $message = 'Account updated successfully!';
        } else {
            BrokerAccount::create($data);
            $message = 'Account added successfully!';
        }

        $this->closeModal();
        $this->dispatch('notify', message: $message, type: 'success');
    }

    public function setActive(int $id): void
    {
        BrokerAccount::where('user_id', Auth::id())->update(['is_active' => false]);
        BrokerAccount::where('user_id', Auth::id())->where('id', $id)->update(['is_active' => true]);
        $this->dispatch('notify', message: 'Active account changed!', type: 'success');
    }

    public function delete(int $id): void
    {
        BrokerAccount::where('user_id', Auth::id())->where('id', $id)->delete();
        $this->dispatch('notify', message: 'Account deleted!', type: 'success');
    }

    public function render()
    {
        $accounts = BrokerAccount::where('user_id', Auth::id())
            ->withCount('trades')
            ->orderBy('is_active', 'desc')
            ->orderBy('is_demo')
            ->orderBy('label')
            ->get();

        return view('livewire.pages.broker-accounts', [
            'accounts' => $accounts,
        ]);
    }
}

<?php

namespace App\Livewire\Pages;

use App\Models\Strategy;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Strategies - Gold Digger')]
class Strategies extends Component
{
    public bool $showModal = false;
    public ?int $editingId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:20')]
    public string $symbol = 'XAUUSD';

    #[Validate('required|string')]
    public string $timeframe_entry = 'M15';

    #[Validate('required|string')]
    public string $timeframe_trend = 'H1';

    #[Validate('required|integer|min:1|max:100')]
    public int $ema_fast = 8;

    #[Validate('required|integer|min:1|max:200')]
    public int $ema_slow = 21;

    #[Validate('required|numeric|min:0|max:100')]
    public string $adx_threshold = '25.00';

    #[Validate('required|integer|min:1|max:100')]
    public int $atr_period = 14;

    #[Validate('required|numeric|min:0')]
    public string $tp1_pips = '10.00';

    #[Validate('required|numeric|min:0|max:100')]
    public string $tp1_close_pct = '50.00';

    #[Validate('required|numeric|min:0')]
    public string $tp2_pips = '20.00';

    #[Validate('required|numeric|min:0|max:100')]
    public string $tp2_close_pct = '30.00';

    #[Validate('required|numeric|min:0')]
    public string $tp3_pips = '30.00';

    #[Validate('required|numeric|min:0|max:100')]
    public string $tp3_close_pct = '20.00';

    #[Validate('required|numeric|min:0.1|max:10')]
    public string $sl_atr_multiplier = '1.50';

    #[Validate('boolean')]
    public bool $exit_on_reversal = true;

    #[Validate('required|integer|min:1|max:500')]
    public int $max_holding_bars = 100;

    #[Validate('boolean')]
    public bool $is_active = true;

    public array $timeframes = [
        'M1' => '1 Minute',
        'M5' => '5 Minutes',
        'M15' => '15 Minutes',
        'M30' => '30 Minutes',
        'H1' => '1 Hour',
        'H4' => '4 Hours',
        'D1' => 'Daily',
    ];

    public function openModal(?int $id = null): void
    {
        $this->resetForm();

        if ($id) {
            $strategy = Strategy::where('user_id', Auth::id())->findOrFail($id);
            $this->editingId = $id;
            $this->name = $strategy->name;
            $this->symbol = $strategy->symbol;
            $this->timeframe_entry = $strategy->timeframe_entry;
            $this->timeframe_trend = $strategy->timeframe_trend;
            $this->ema_fast = $strategy->ema_fast;
            $this->ema_slow = $strategy->ema_slow;
            $this->adx_threshold = $strategy->adx_threshold;
            $this->atr_period = $strategy->atr_period;
            $this->tp1_pips = $strategy->tp1_pips;
            $this->tp1_close_pct = $strategy->tp1_close_pct;
            $this->tp2_pips = $strategy->tp2_pips;
            $this->tp2_close_pct = $strategy->tp2_close_pct;
            $this->tp3_pips = $strategy->tp3_pips;
            $this->tp3_close_pct = $strategy->tp3_close_pct;
            $this->sl_atr_multiplier = $strategy->sl_atr_multiplier;
            $this->exit_on_reversal = $strategy->exit_on_reversal;
            $this->max_holding_bars = $strategy->max_holding_bars;
            $this->is_active = $strategy->is_active;
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
        $this->name = '';
        $this->symbol = 'XAUUSD';
        $this->timeframe_entry = 'M15';
        $this->timeframe_trend = 'H1';
        $this->ema_fast = 8;
        $this->ema_slow = 21;
        $this->adx_threshold = '25.00';
        $this->atr_period = 14;
        $this->tp1_pips = '10.00';
        $this->tp1_close_pct = '50.00';
        $this->tp2_pips = '20.00';
        $this->tp2_close_pct = '30.00';
        $this->tp3_pips = '30.00';
        $this->tp3_close_pct = '20.00';
        $this->sl_atr_multiplier = '1.50';
        $this->exit_on_reversal = true;
        $this->max_holding_bars = 100;
        $this->is_active = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'user_id' => Auth::id(),
            'name' => $this->name,
            'symbol' => $this->symbol,
            'timeframe_entry' => $this->timeframe_entry,
            'timeframe_trend' => $this->timeframe_trend,
            'ema_fast' => $this->ema_fast,
            'ema_slow' => $this->ema_slow,
            'adx_threshold' => $this->adx_threshold,
            'atr_period' => $this->atr_period,
            'tp1_pips' => $this->tp1_pips,
            'tp1_close_pct' => $this->tp1_close_pct,
            'tp2_pips' => $this->tp2_pips,
            'tp2_close_pct' => $this->tp2_close_pct,
            'tp3_pips' => $this->tp3_pips,
            'tp3_close_pct' => $this->tp3_close_pct,
            'sl_atr_multiplier' => $this->sl_atr_multiplier,
            'exit_on_reversal' => $this->exit_on_reversal,
            'max_holding_bars' => $this->max_holding_bars,
            'is_active' => $this->is_active,
        ];

        if ($this->editingId) {
            Strategy::where('user_id', Auth::id())
                ->where('id', $this->editingId)
                ->update($data);
            $message = 'Strategy updated successfully!';
        } else {
            Strategy::create($data);
            $message = 'Strategy created successfully!';
        }

        $this->closeModal();
        $this->dispatch('notify', message: $message, type: 'success');
    }

    public function toggleActive(int $id): void
    {
        $strategy = Strategy::where('user_id', Auth::id())->findOrFail($id);
        $strategy->update(['is_active' => !$strategy->is_active]);
    }

    public function delete(int $id): void
    {
        Strategy::where('user_id', Auth::id())->where('id', $id)->delete();
        $this->dispatch('notify', message: 'Strategy deleted!', type: 'success');
    }

    public function render()
    {
        $strategies = Strategy::where('user_id', Auth::id())
            ->withCount('trades')
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get();

        return view('livewire.pages.strategies', [
            'strategies' => $strategies,
        ]);
    }
}

<?php

namespace App\Livewire\Pages;

use App\Models\BotSettings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Settings - Gold Digger')]
class Settings extends Component
{
    #[Validate('boolean')]
    public bool $is_active = false;

    #[Validate('required|numeric|min:0.1|max:10')]
    public string $risk_percentage = '1.00';

    #[Validate('required|numeric|min:1|max:50')]
    public string $max_daily_loss_percentage = '5.00';

    #[Validate('required|integer|min:1|max:10')]
    public int $max_concurrent_trades = 3;

    #[Validate('array')]
    public array $allowed_sessions = [];

    #[Validate('required|numeric|min:0')]
    public string $min_atr_threshold = '0.50';

    #[Validate('boolean')]
    public bool $news_filter_enabled = true;

    #[Validate('boolean')]
    public bool $capture_screenshots = true;

    public array $availableSessions = [
        'asian' => 'Asian Session (Tokyo)',
        'london' => 'London Session',
        'newyork' => 'New York Session',
        'overlap' => 'London/NY Overlap',
    ];

    public function mount(): void
    {
        $settings = Auth::user()->botSettings;

        if ($settings) {
            $this->is_active = $settings->is_active ?? false;
            $this->risk_percentage = $settings->risk_percentage ?? '1.00';
            $this->max_daily_loss_percentage = $settings->max_daily_loss_percentage ?? '5.00';
            $this->max_concurrent_trades = $settings->max_concurrent_trades ?? 3;
            $this->allowed_sessions = $settings->allowed_sessions ?? [];
            $this->min_atr_threshold = $settings->min_atr_threshold ?? '0.50';
            $this->news_filter_enabled = $settings->news_filter_enabled ?? true;
            $this->capture_screenshots = $settings->capture_screenshots ?? true;
        }
    }

    public function save(): void
    {
        $this->validate();

        $settings = Auth::user()->botSettings;

        $settings->update([
            'is_active' => $this->is_active,
            'risk_percentage' => $this->risk_percentage,
            'max_daily_loss_percentage' => $this->max_daily_loss_percentage,
            'max_concurrent_trades' => $this->max_concurrent_trades,
            'allowed_sessions' => $this->allowed_sessions,
            'min_atr_threshold' => $this->min_atr_threshold,
            'news_filter_enabled' => $this->news_filter_enabled,
            'capture_screenshots' => $this->capture_screenshots,
        ]);

        $this->dispatch('notify', message: 'Settings saved successfully!', type: 'success');
    }

    public function toggleBot(): void
    {
        $this->is_active = !$this->is_active;
        $this->save();
    }

    public function render()
    {
        return view('livewire.pages.settings');
    }
}

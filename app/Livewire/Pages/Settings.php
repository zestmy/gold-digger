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

    // Editable because the filter now enforces them. They were columns nothing could set:
    // present in the database, absent from the migrations, and read by no code - so a
    // window nobody could configure gated trading the moment the filter became real.
    #[Validate('required|integer|min:0|max:240')]
    public int $news_blackout_before_minutes = 15;

    #[Validate('required|integer|min:0|max:240')]
    public int $news_blackout_after_minutes = 15;

    // The AI fund. Kept as a string so an empty box stays empty rather than becoming zero -
    // "no cap set" and "a cap of nothing" are different states and only one of them means
    // somebody has decided.
    #[Validate('boolean')]
    public bool $ai_trading_enabled = false;

    #[Validate('nullable|numeric|min:0')]
    public ?string $ai_capital_cap = null;

    #[Validate('required|numeric|min:0.1|max:100')]
    public string $ai_risk_percentage = '1.00';

    #[Validate('required|integer|min:1|max:10')]
    public int $ai_max_concurrent_trades = 1;

    // Whose stop and targets a copied signal trades with. Defaults to the provider's,
    // because changing what a signal means is not something to do by default.
    #[Validate('required|in:provider,strategy')]
    public string $copier_levels = 'provider';

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
            $this->news_blackout_before_minutes = (int) ($settings->news_blackout_before_minutes ?? 15);
            $this->news_blackout_after_minutes = (int) ($settings->news_blackout_after_minutes ?? 15);
            $this->ai_trading_enabled = (bool) ($settings->ai_trading_enabled ?? false);
            $this->ai_capital_cap = $settings->ai_capital_cap === null ? null : (string) $settings->ai_capital_cap;
            $this->ai_risk_percentage = (string) ($settings->ai_risk_percentage ?? '1.00');
            $this->ai_max_concurrent_trades = (int) ($settings->ai_max_concurrent_trades ?? 1);
            $this->copier_levels = (string) ($settings->copier_levels ?? 'provider');
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
            'news_blackout_before_minutes' => $this->news_blackout_before_minutes,
            'news_blackout_after_minutes' => $this->news_blackout_after_minutes,
            'ai_trading_enabled' => $this->ai_trading_enabled,
            // Empty stays null. Casting a blank box to 0.0 would read as "the AI may risk
            // nothing", which the fund treats as exhausted rather than unconfigured.
            'ai_capital_cap' => ($this->ai_capital_cap === null || $this->ai_capital_cap === '')
                ? null
                : (float) $this->ai_capital_cap,
            'ai_risk_percentage' => $this->ai_risk_percentage,
            'ai_max_concurrent_trades' => $this->ai_max_concurrent_trades,
            'copier_levels' => $this->copier_levels,
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
        return view('livewire.pages.settings', [
            // The fund's live state, so the cap is set beside what is actually left of it
            // rather than in isolation. A number typed into an empty box is a guess; a
            // number typed next to "$47.20 remaining of $200" is a decision.
            // The account's actual stop rule, so the choice above is made against a real
            // number rather than the phrase "ATR-based".
            'strategyStop' => ($m = \App\Models\Strategy::where('user_id', \Illuminate\Support\Facades\Auth::id())
                ->orderByDesc('is_active')->value('sl_atr_multiplier'))
                    ? rtrim(rtrim((string) $m, '0'), '.').' x ATR'
                    : null,
            'fund' => app(\App\Services\Ai\AiFund::class)->state(
                \Illuminate\Support\Facades\Auth::user()?->botSettings,
                (int) \Illuminate\Support\Facades\Auth::id(),
            ),
        ]);
    }
}

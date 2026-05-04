<?php

namespace App\Observers;

use App\Models\BotSettings;
use App\Models\Strategy;
use App\Models\User;

/**
 * User Observer
 *
 * Handles automatic setup when a new user registers.
 * Creates default BotSettings and a starter Strategy so users
 * don't have to configure everything from scratch.
 *
 * WHY use an observer instead of event listener?
 * - Simpler for model lifecycle events
 * - Keeps user registration logic in one place
 * - Easy to test in isolation
 */
class UserObserver
{
    /**
     * Handle the User "created" event.
     *
     * Creates default bot settings and a starter strategy for new users.
     * This ensures users can immediately see the dashboard without
     * manual configuration.
     */
    public function created(User $user): void
    {
        // Create default bot settings
        // These are conservative defaults - user can adjust later
        BotSettings::create([
            'user_id' => $user->id,
            'is_active' => false, // Bot starts inactive for safety
            'risk_percentage' => 1.00, // Risk 1% per trade
            'max_daily_loss_percentage' => 3.00, // Stop at 3% daily loss
            'max_concurrent_trades' => 3,
            'allowed_sessions' => ['london', 'newyork', 'overlap'],
            'news_filter_enabled' => true,
            'capture_screenshots' => true,
        ]);

        // Create default strategy based on "Fira-Style" gold scalping
        // This is a trend-following strategy with partial profit taking
        Strategy::create([
            'user_id' => $user->id,
            'name' => 'Fira-Style Gold Trend Scalp',
            'symbol' => 'XAUUSD',

            // Multi-timeframe: H1 for trend, M5 for entries
            'timeframe_entry' => 'M5',
            'timeframe_trend' => 'H1',

            // EMA crossover settings
            'ema_fast' => 20,
            'ema_slow' => 50,

            // ADX filter - only trade strong trends
            'adx_threshold' => 25.00,
            'atr_period' => 14,

            // Take profit levels with partial closes
            // TP1: 30 pips, close 50%
            // TP2: 100 pips, close 30%
            // TP3: 200 pips, close remaining 20%
            'tp1_pips' => 30.00,
            'tp1_close_pct' => 50.00,
            'tp2_pips' => 100.00,
            'tp2_close_pct' => 30.00,
            'tp3_pips' => 200.00,
            'tp3_close_pct' => 20.00,

            // Stop loss based on ATR
            'sl_atr_multiplier' => 1.50,

            // Exit on reversal signal
            'exit_on_reversal' => true,

            // Max 24 bars (2 hours on M5) before forced exit
            'max_holding_bars' => 24,

            // Start inactive - user activates after review
            'is_active' => false,
        ]);
    }
}

<?php

namespace App\Observers;

use App\Models\BotSettings;
use App\Models\Strategy;
use App\Models\User;
use App\Support\TradingMode;

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
        //
        // The copier protection defaults are deliberately absent here and live on the
        // columns instead, in protect_copied_positions_by_default. Setting them in both
        // places would give one setting two sources of truth, and the column is the one
        // that also covers rows this observer never sees.
        BotSettings::create([
            'user_id' => $user->id,
            'is_active' => false, // Bot starts inactive for safety
            // The values below are the moderate preset; see TradingMode, and the test that
            // fails if the two ever drift apart.
            'trading_mode' => TradingMode::MODERATE,
            'risk_percentage' => 1.00, // Risk 1% per trade
            'max_daily_loss_percentage' => 3.00, // Stop at 3% daily loss
            'max_concurrent_trades' => 3,
            'allowed_sessions' => ['london', 'newyork', 'overlap'],
            'news_filter_enabled' => true,
            // No capture_screenshots here: nothing has ever written a screenshot, no form
            // offers the flag any more, and the column's own default covers it.
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

            // Take profit levels with partial closes, as multiples of the stop distance:
            // TP1 at 1R closes 50%, TP2 at 2R closes 30%, TP3 at 3R closes the rest. The
            // first rung pays at least what a stop costs, which fixed pips against an
            // ATR stop did not guarantee - see the tp_r migration. The pip columns are
            // kept as the fallback for a strategy that clears its R values.
            'tp1_r' => 1.00,
            'tp2_r' => 2.00,
            'tp3_r' => 3.00,
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

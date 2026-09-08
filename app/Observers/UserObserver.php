<?php

namespace App\Observers;

use App\Models\BotSettings;
use App\Models\Strategy;
use App\Models\User;
use App\Support\StarterStrategies;
use App\Support\TradingMode;

/**
 * User Observer
 *
 * Handles automatic setup when a new user registers.
 * Creates default BotSettings and the starter Strategies so users
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
     * Creates default bot settings and the starter strategies for new users.
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

        // Create the starter strategies: the "Fira-Style" gold scalp and the same
        // trend-following trade on EURUSD and GBPUSD. All of them start inactive.
        //
        // The definitions live in StarterStrategies because this observer only fires on
        // registration, so it cannot be the only place that knows what a starter is -
        // `strategies:add-starters` reaches the accounts that already exist.
        foreach (StarterStrategies::definitions() as $definition) {
            Strategy::create(['user_id' => $user->id] + $definition);
        }
    }
}

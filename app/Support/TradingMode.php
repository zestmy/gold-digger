<?php

namespace App\Support;

use App\Models\BotSettings;

/**
 * Trading Mode
 *
 * One word for an appetite, and the settings it stands for.
 *
 * ## Why a mode and not just the settings
 *
 * The risk page has eighteen numbers on it, every one defensible, and together they are
 * unreadable as a stance. "Half a percent, one position, London only, two to one at the
 * exit, four factors" is a cautious account; nobody would recognise it as one from the
 * form. A mode is that stance said once, in a word the person would use themselves, and
 * the settings are what the word means.
 *
 * ## The settings stay the truth
 *
 * Choosing a mode writes its values into the same columns the form edits. Nothing reads
 * the mode to decide a trade: the generator reads the floors, the reviewer reads the
 * review setting, the fund reads its cap. That is what makes a mode honest - every signal
 * and every order reflects it because it *is* the settings, not a label beside them. Edit
 * any of those values by hand and the account is `custom`, which is a true description
 * and not a demotion.
 *
 * ## What each mode means
 *
 * Passive trades less and holds each signal to more: only the sessions with the best
 * measured record, a first target that must pay twice the stop, four factors agreeing
 * before an entry counts, and every copied signal put to the model. Aggressive trades
 * more and asks less: any session, no reward floor, the platform's minimum confluence,
 * the provider trusted as posted, positions trailed rather than banked. Moderate is the
 * platform default, which is the stance the numbers have been measured under.
 *
 * None of these is a claim about profit. Passive loses less when the signals are bad;
 * aggressive makes more when they are good. Which they are is measured on Trades ->
 * Performance, under every mode alike.
 */
final class TradingMode
{
    public const PASSIVE = 'passive';

    public const MODERATE = 'moderate';

    public const AGGRESSIVE = 'aggressive';

    /** The settings match no preset: somebody has set them by hand. */
    public const CUSTOM = 'custom';

    public const MODES = [self::PASSIVE, self::MODERATE, self::AGGRESSIVE];

    /**
     * The settings a mode stands for. Every key here is a `bot_settings` column and a
     * field on the risk page; a value that is not in this list is not part of the stance
     * and is left exactly as the account has it.
     *
     * Moderate is the platform's defaults, deliberately: a new account is moderate, and
     * the outcome numbers so far were measured under these values.
     *
     * @var array<string, array<string, mixed>>
     */
    private const PRESETS = [
        self::PASSIVE => [
            'risk_percentage' => 0.50,
            'max_daily_loss_percentage' => 2.00,
            'max_concurrent_trades' => 1,
            // The two sessions with the best measured record. New York alone was the
            // worst on the first month of outcomes.
            'allowed_sessions' => ['london', 'overlap'],
            'min_reward_ratio' => 2.00,
            'min_confluence' => 4.00,
            'min_directional' => 2.00,
            'news_blackout_before_minutes' => 30,
            'news_blackout_after_minutes' => 30,
            'ai_risk_percentage' => 0.50,
            'ai_max_concurrent_trades' => 1,
            'ai_max_trades_per_day' => 2,
            'copier_review' => BotSettings::COPIER_REVIEW_MODEL,
            'copier_protect_at_r' => 1.00,
            'copier_breakeven' => true,
            'copier_profit_lock_pct' => 50,
            'copier_trail_distance_r' => null,
            'copier_spread_buffer' => true,
        ],
        self::MODERATE => [
            'risk_percentage' => 1.00,
            'max_daily_loss_percentage' => 3.00,
            'max_concurrent_trades' => 3,
            'allowed_sessions' => ['london', 'newyork', 'overlap'],
            'min_reward_ratio' => null,
            'min_confluence' => null,
            'min_directional' => null,
            'news_blackout_before_minutes' => 15,
            'news_blackout_after_minutes' => 15,
            'ai_risk_percentage' => 1.00,
            'ai_max_concurrent_trades' => 1,
            'ai_max_trades_per_day' => null,
            'copier_review' => BotSettings::COPIER_REVIEW_MODEL,
            'copier_protect_at_r' => 1.00,
            'copier_breakeven' => true,
            'copier_profit_lock_pct' => 50,
            'copier_trail_distance_r' => 1.00,
            'copier_spread_buffer' => false,
        ],
        self::AGGRESSIVE => [
            'risk_percentage' => 2.00,
            'max_daily_loss_percentage' => 6.00,
            'max_concurrent_trades' => 5,
            // Empty means no session restriction.
            'allowed_sessions' => [],
            'min_reward_ratio' => null,
            'min_confluence' => 2.00,
            'min_directional' => 1.00,
            'news_blackout_before_minutes' => 10,
            'news_blackout_after_minutes' => 10,
            'ai_risk_percentage' => 2.00,
            'ai_max_concurrent_trades' => 3,
            'ai_max_trades_per_day' => null,
            'copier_review' => BotSettings::COPIER_REVIEW_GATES,
            'copier_protect_at_r' => 1.50,
            'copier_breakeven' => false,
            'copier_profit_lock_pct' => null,
            'copier_trail_distance_r' => 1.00,
            'copier_spread_buffer' => false,
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function values(string $mode): array
    {
        if (! isset(self::PRESETS[$mode])) {
            throw new \InvalidArgumentException("Unknown trading mode: {$mode}");
        }

        return self::PRESETS[$mode];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::PRESETS[self::MODERATE]);
    }

    /**
     * Which mode these settings are, or `custom` when they match none.
     *
     * Compared value by value on the keys a mode defines, with numbers compared as
     * numbers so "1.00" from a form and 1.0 from a preset are the same setting.
     *
     * @param  array<string, mixed>  $values
     */
    public static function detect(array $values): string
    {
        foreach (self::PRESETS as $mode => $preset) {
            $matches = true;

            foreach ($preset as $key => $expected) {
                if (! self::same($expected, $values[$key] ?? null)) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return $mode;
            }
        }

        return self::CUSTOM;
    }

    public static function of(?BotSettings $settings): string
    {
        if ($settings === null) {
            return self::CUSTOM;
        }

        // The column is a record of what was chosen; the values are the truth. A row edited
        // outside the form - the support console, a migration - reads as what it is.
        return self::detect($settings->only(self::keys()));
    }

    public static function label(string $mode): string
    {
        return match ($mode) {
            self::PASSIVE => 'Passive',
            self::MODERATE => 'Moderate',
            self::AGGRESSIVE => 'Aggressive',
            default => 'Custom',
        };
    }

    /**
     * What the mode means, in the terms a person would use to choose it.
     */
    public static function describe(string $mode): string
    {
        return match ($mode) {
            self::PASSIVE => 'Fewer trades, each held to more. Half a percent a trade, one position at a time, London and the overlap only, a first target that pays twice the stop, four factors agreeing, and every copied signal reviewed by the model.',
            self::MODERATE => 'The platform default, and the stance the numbers so far were measured under. One percent a trade, three positions, London, New York and the overlap, the standard floors, copied signals reviewed by the model.',
            self::AGGRESSIVE => 'More trades, fewer questions. Two percent a trade, five positions, any session, no reward floor, the lowest confluence bar, copied signals traded as posted while still valid, positions trailed rather than banked.',
            default => 'Set by hand. The values below match no preset; pick a mode to replace them, or leave them as they are.',
        };
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            $a = array_values((array) $a);
            $b = array_values((array) $b);
            sort($a);
            sort($b);

            return $a === $b;
        }

        if ($a === null || $b === null || $a === '' || $b === '') {
            return ($a === null || $a === '') && ($b === null || $b === '');
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }

        return (string) $a === (string) $b;
    }
}

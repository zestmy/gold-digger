<?php

namespace App\Services\Strategy;

use App\Models\BotSettings;
use App\Models\Strategy;
use App\Models\Trade;
use App\Services\News\NewsBlackout;

/**
 * Signal Quality
 *
 * Scores a prospective entry by how many independent things agree with it.
 *
 * ## Why confidence is computed rather than asserted
 *
 * The signal providers this copies all publish a confidence percentage, and so did the
 * reviewer before this existed. Both are numbers a writer chose. "84%" from a channel and
 * "80%" from a language model look like measurements and are opinions with a decimal
 * point, and the harm is specific: they are used to size and to sort, so an opinion that
 * dresses as a measurement ends up deciding money.
 *
 * Here confidence is a function of the factor count and nothing else. It can be recomputed
 * from stored data, it moves only when the market does, and two signals with the same
 * score genuinely had the same evidence behind them. That is a weaker claim than the
 * providers make and a more useful one.
 *
 * ## Why factors must be independent
 *
 * Counting agreement is only meaningful if the things agreeing could have disagreed. Trend
 * direction and DI direction are close to the same measurement twice, so they are weighted
 * as one and a half rather than two - otherwise every trending market scores highly on
 * what is really a single observation, which is exactly the market where a trend-following
 * entry is most likely to be late.
 *
 * ## What this is not
 *
 * It is not an edge. A five-factor signal is better evidenced than a two-factor one; that
 * is a statement about how much is known, not about expected value. The walk-forward
 * numbers remain the only thing that speaks to whether any of this makes money.
 */
final class SignalQuality
{
    /** Below this, an entry is not taken. The SOP's "three confluences" rule. */
    public const MIN_CONFLUENCE = 3.0;

    public const ENTRY_NOW = 'CAN ENTRY NOW';

    public const ENTRY_PULLBACK = 'WAIT FOR PULLBACK';

    public const ENTRY_CONFIRMATION = 'WAIT FOR CONFIRMATION';

    public const RISK_LOW = 'LOW';

    public const RISK_MEDIUM = 'MEDIUM';

    public const RISK_HIGH = 'HIGH';

    /**
     * ADX above which a trend is present rather than merely sloped.
     *
     * 20 by convention. The measurements taken on this strategy said loosening it trades
     * more and loses more - 25 gave +187 over 10 trades, 15 gave -505 over 63 - so this
     * is a floor for counting a factor, not a suggestion.
     */
    private const ADX_PRESENT = 20.0;

    /** Volatility band, as ATR over price. Outside it the stop distance stops meaning much. */
    private const ATR_FLOOR = 0.02;

    private const ATR_CEILING = 1.50;

    public function __construct(
        private readonly MarketContext $context = new MarketContext(new StrategyEvaluator),
        private readonly TradingSession $session = new TradingSession,
        private readonly NewsBlackout $news = new NewsBlackout,
    ) {}

    /**
     * Score an entry.
     *
     * The session and news factors consult the same objects the executor does, rather than
     * a second reading of the same idea - a quality score that disagreed with the gate it
     * describes would be worse than no score at all.
     *
     * @param  'buy'|'sell'  $direction
     * @param  float|null  $entryLow  Low end of the signal's entry zone, if it named one
     * @param  float|null  $entryHigh  High end of the zone
     * @return array{
     *     confluence: float,
     *     factors: array<int, array{name: string, weight: float, met: bool, note: string}>,
     *     confidence: int,
     *     risk: string,
     *     entry_status: string,
     *     tradeable: bool,
     *     why: string
     * }
     */
    public function assess(
        Strategy $strategy,
        ?int $brokerAccountId,
        string $symbol,
        string $direction,
        ?float $entryLow = null,
        ?float $entryHigh = null,
    ): array {
        $market = $this->context->for($strategy, $brokerAccountId, $symbol);

        $settings = BotSettings::where('user_id', $strategy->user_id)->first();

        $factors = $this->factors($market, $settings, $direction, $symbol, $entryLow, $entryHigh);

        $possible = array_sum(array_column($factors, 'weight'));
        $confluence = array_sum(array_map(
            fn (array $f) => $f['met'] ? $f['weight'] : 0.0,
            $factors,
        ));

        $status = $this->entryStatus($market, $confluence, $direction, $entryLow, $entryHigh);

        return [
            'confluence' => round($confluence, 1),
            'factors' => $factors,
            // A ratio of what agreed to what could have. Recomputable from stored data,
            // which is the whole point of it.
            'confidence' => $possible > 0.0 ? (int) round($confluence / $possible * 100) : 0,
            'risk' => $this->risk($confluence, $market),
            'entry_status' => $status,
            'tradeable' => $confluence >= self::MIN_CONFLUENCE && $status === self::ENTRY_NOW,
            'why' => $this->why($factors, $confluence, $status),
        ];
    }

    /**
     * Has this account already taken its allowance for the day?
     *
     * Counted on positions opened rather than signals approved, because the limit exists
     * to bound exposure and an approval that never filled cost nothing. Counted in UTC,
     * matching how every other date in this system is reckoned.
     *
     * @return array{reached: bool, taken: int, allowed: int|null}
     */
    public function dailyAllowance(?BotSettings $settings, int $userId, string $origin = 'ai'): array
    {
        $allowed = $settings?->ai_max_trades_per_day;

        if ($allowed === null || $allowed <= 0) {
            return ['reached' => false, 'taken' => 0, 'allowed' => null];
        }

        $taken = Trade::where('user_id', $userId)
            ->where('origin', $origin)
            ->whereDate('opened_at', now()->toDateString())
            ->count();

        return ['reached' => $taken >= $allowed, 'taken' => $taken, 'allowed' => (int) $allowed];
    }

    /**
     * @param  array<string, mixed>  $market
     * @return array<int, array{name: string, weight: float, met: bool, note: string}>
     */
    private function factors(array $market, ?BotSettings $settings, string $direction, string $symbol, ?float $entryLow, ?float $entryHigh): array
    {
        $trendAgrees = $market['trend'] !== null && $market['trend'] === $direction;
        $adx = $market['adx'];
        $atrPct = $market['atr_pct'];

        $diFavours = $market['plus_di'] !== null && $market['minus_di'] !== null
            && ($direction === 'buy'
                ? $market['plus_di'] > $market['minus_di']
                : $market['minus_di'] > $market['plus_di']);

        $sessionOpen = $this->session->isOpen($settings?->allowed_sessions, now());

        $newsObjection = $this->news->objection(
            $settings,
            $this->news->currenciesFor($symbol),
            now(),
        );

        return [
            [
                'name' => 'Higher-timeframe trend',
                'weight' => 1.0,
                'met' => $trendAgrees,
                'note' => $market['trend'] === null
                    ? 'No trend reading available.'
                    : ($trendAgrees
                        ? "The {$market['trend_timeframe']} trend is {$market['trend']}, matching."
                        : "The {$market['trend_timeframe']} trend is {$market['trend']}, against this."),
            ],
            [
                'name' => 'Entry-timeframe bias',
                'weight' => 1.0,
                'met' => $market['entry_bias'] !== null && $market['entry_bias'] === $direction,
                'note' => $market['entry_bias'] === null
                    ? 'No entry bias available.'
                    : "The {$market['entry_timeframe']} bias is {$market['entry_bias']}.",
            ],
            [
                // Half-weight beside the trend factor: direction measured twice is not two
                // independent agreements, and double-counting it flatters exactly the
                // trending market where a late entry hurts most.
                'name' => 'Directional strength (DI)',
                'weight' => 0.5,
                'met' => $diFavours,
                'note' => $market['plus_di'] === null
                    ? 'No DI reading.'
                    : sprintf('+DI %.1f against -DI %.1f.', $market['plus_di'], $market['minus_di']),
            ],
            [
                'name' => 'Trend is present (ADX)',
                'weight' => 1.0,
                'met' => $adx !== null && $adx >= self::ADX_PRESENT,
                'note' => $adx === null
                    ? 'No ADX reading.'
                    : sprintf('ADX %.1f against a floor of %.0f.', $adx, self::ADX_PRESENT),
            ],
            [
                'name' => 'Session open',
                'weight' => 1.0,
                'met' => $sessionOpen,
                'note' => $sessionOpen
                    ? 'A liquid session is open.'
                    : 'Outside the sessions this strategy is allowed to trade.',
            ],
            [
                'name' => 'Clear of high-impact news',
                'weight' => 1.0,
                'met' => $newsObjection === null,
                'note' => $newsObjection ?? 'No high-impact release nearby.',
            ],
            [
                'name' => 'Volatility is usable',
                'weight' => 0.5,
                'met' => $atrPct !== null && $atrPct >= self::ATR_FLOOR && $atrPct <= self::ATR_CEILING,
                'note' => $atrPct === null
                    ? 'No ATR reading.'
                    : sprintf('ATR is %.3f%% of price.', $atrPct),
            ],
        ];
    }

    /**
     * Where price is relative to the entry the signal asked for.
     *
     * The distinction that matters is between "not yet" and "no longer". Price short of a
     * buy zone is a wait; price already through it is a chase, and chasing a zone entry is
     * how a 1:3 setup becomes a 1:1 without anybody deciding to take a worse trade.
     *
     * @param  array<string, mixed>  $market
     */
    private function entryStatus(array $market, float $confluence, string $direction, ?float $entryLow, ?float $entryHigh): string
    {
        if ($confluence < self::MIN_CONFLUENCE) {
            return self::ENTRY_CONFIRMATION;
        }

        $price = $market['last_close'];

        // No zone named, or no price to compare: a market order is what was asked for.
        if ($price === null || $entryLow === null) {
            return self::ENTRY_NOW;
        }

        $low = min($entryLow, $entryHigh ?? $entryLow);
        $high = max($entryLow, $entryHigh ?? $entryLow);

        if ($price >= $low && $price <= $high) {
            return self::ENTRY_NOW;
        }

        // Through the zone in the trade's own direction - the move has left without us.
        $chasing = $direction === 'buy' ? $price > $high : $price < $low;

        return $chasing ? self::ENTRY_PULLBACK : self::ENTRY_NOW;
    }

    /**
     * @param  array<string, mixed>  $market
     */
    private function risk(float $confluence, array $market): string
    {
        // Volatility escalates risk independently of agreement: five factors agreeing
        // during a violent hour is still a violent hour, and the stop is what pays for it.
        $wild = $market['atr_pct'] !== null && $market['atr_pct'] > self::ATR_CEILING;

        if ($wild || $confluence < self::MIN_CONFLUENCE) {
            return self::RISK_HIGH;
        }

        return $confluence >= 5.0 ? self::RISK_LOW : self::RISK_MEDIUM;
    }

    /**
     * @param  array<int, array{name: string, weight: float, met: bool, note: string}>  $factors
     */
    private function why(array $factors, float $confluence, string $status): string
    {
        $missing = array_values(array_filter($factors, fn (array $f) => ! $f['met']));

        if ($status === self::ENTRY_PULLBACK) {
            return 'Price has already left the entry zone in the trade\'s own direction. Taking it here is a worse trade than the one that was published.';
        }

        if ($confluence < self::MIN_CONFLUENCE) {
            $names = implode(', ', array_map(fn (array $f) => strtolower($f['name']), array_slice($missing, 0, 3)));

            return sprintf('Only %.1f factors agree, against a floor of %.0f. Missing: %s.', $confluence, self::MIN_CONFLUENCE, $names);
        }

        return sprintf('%.1f factors agree.%s', $confluence, $missing === []
            ? ' Everything measured is aligned.'
            : ' Not aligned: '.implode(', ', array_map(fn (array $f) => strtolower($f['name']), $missing)).'.');
    }
}

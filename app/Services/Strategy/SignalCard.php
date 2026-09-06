<?php

namespace App\Services\Strategy;

use App\Models\Signal;
use Illuminate\Support\Carbon;

/**
 * Signal Card
 *
 * One stored signal, read the way a person about to place the trade needs to read it:
 * where the entry zone sits against the price right now, what kind of order that calls
 * for, how far the targets are against the stop, how long the setup is still the one
 * described, and what argued against it at the time.
 *
 * ## Everything here is derived, nothing is decided
 *
 * The card publishes no number the row does not already carry or that arithmetic on the
 * row cannot reproduce. Confidence is SignalQuality's ratio, stored at generation. The
 * zone was fixed at generation. The guidance is a comparison of two prices. That is the
 * difference between this and the signal cards it resembles: theirs assert "78%", this
 * one can show its working.
 *
 * ## Why the guidance is about the *reader's* order
 *
 * The strategy itself entered at the bar close, or declined to, and that is recorded in
 * the row. The guidance answers a different question - somebody looking at this card a
 * few minutes later, deciding whether to take it by hand or on another account - and it
 * says the thing signal cards usually leave out: when the move has already gone, a limit
 * order at the zone is the trade, and a market order is a different, worse one.
 *
 * Pure: takes the row and a price, touches no storage, so every branch is a unit test.
 */
final class SignalCard
{
    public const ENTER_NOW = 'ENTER AT MARKET';

    public const SET_LIMIT = 'SET LIMIT ORDER';

    public const WAIT_RECLAIM = 'WAIT FOR RECLAIM';

    public const WAIT_CONFIRMATION = SignalQuality::ENTRY_CONFIRMATION;

    public const TOO_LATE = 'TOO LATE - SKIP';

    public const INVALIDATED = 'INVALIDATED';

    public const EXPIRED = 'EXPIRED';

    public const NO_PRICE = 'NO LIVE PRICE';

    /**
     * How far past the zone, as a share of the way to the first target, a limit order
     * stops being worth resting. Past this the pullback needed to fill it is most of the
     * move the trade was for.
     */
    private const TOO_LATE_SHARE_OF_TP1 = 0.5;

    /** RSI conventions, offered as a description and never as a rule. */
    private const RSI_OVERBOUGHT = 70.0;

    private const RSI_OVERSOLD = 30.0;

    /** ADX conventions, the same bands MarketContext labels with. */
    private const ADX_PRESENT = 20.0;

    private const ADX_STRONG = 40.0;

    /** Volatility band as ATR over price, the same one SignalQuality scores. */
    private const ATR_QUIET_PCT = 0.02;

    private const ATR_WILD_PCT = 1.50;

    /**
     * @return array<string, mixed>
     */
    public function for(Signal $signal, ?float $currentPrice, ?Carbon $priceAt = null, ?Carbon $now = null): array
    {
        $now ??= now();
        $features = $signal->features ?? [];
        $quality = is_array($features['quality'] ?? null) ? $features['quality'] : null;

        $entry = (float) $signal->entry_price;
        $stop = (float) $signal->sl_price;
        $zone = $this->zone($signal, $features);
        $validUntil = $this->validUntil($signal, $features);
        $expired = $validUntil !== null && $now->greaterThan($validUntil);

        $position = $this->position($signal->direction, $zone, $currentPrice);
        $guidance = $this->guidance($signal, $features, $quality, $zone, $stop, $currentPrice, $position, $expired);

        $atr = isset($features['atr']) ? (float) $features['atr'] : null;
        $atrPct = ($atr !== null && $entry > 0.0) ? $atr / $entry * 100 : null;

        return [
            'symbol' => $signal->symbol,
            'direction' => $signal->direction,
            'timeframe' => $signal->timeframe,
            'generated_at' => $signal->generated_at,
            'skip_reason' => $signal->skip_reason,
            'was_executed' => (bool) $signal->was_executed,

            'confidence' => $quality['confidence'] ?? null,
            'grade' => $quality['grade'] ?? null,
            'risk' => $quality['risk'] ?? null,
            'entry_status' => $quality['entry_status'] ?? null,
            'confluence' => $quality['confluence'] ?? null,
            'possible' => $quality['possible'] ?? null,
            'factors' => $quality['factors'] ?? [],
            'why' => $quality['why'] ?? null,

            'momentum' => $this->momentum($features),

            'entry' => $entry,
            'zone_low' => $zone['low'],
            'zone_high' => $zone['high'],
            'stop' => $stop,
            'targets' => $this->targets($signal, $entry, $stop),
            'reward_ratio' => $this->rewardRatio($signal, $entry, $stop),
            'stop_pips' => isset($features['sl_pips']) && (float) $features['sl_pips'] > 0 ? (float) $features['sl_pips'] : null,

            'current_price' => $currentPrice,
            'price_at' => $priceAt,
            'position' => $position,
            'guidance' => $guidance,

            'valid_until' => $validUntil,
            'expired' => $expired,

            'indicators' => [
                'rsi' => $this->rsi($features, $signal->direction),
                'macd' => $this->macd($features, $signal->direction),
                'trend' => $this->trend($features, $signal->direction),
                'adx' => $this->adx($features),
                'volatility' => $this->volatility($atr, $atrPct),
            ],

            'risk_notes' => $this->riskNotes($signal, $features, $quality, $atrPct),
        ];
    }

    // =========================================================================
    // PRICES
    // =========================================================================

    /**
     * The zone the row carries, or the entry itself for rows written before zones were.
     *
     * @param  array<string, mixed>  $features
     * @return array{low: float, high: float}
     */
    private function zone(Signal $signal, array $features): array
    {
        $entry = (float) $signal->entry_price;

        $low = isset($features['entry_zone_low']) ? (float) $features['entry_zone_low'] : $entry;
        $high = isset($features['entry_zone_high']) ? (float) $features['entry_zone_high'] : $entry;

        return ['low' => min($low, $high), 'high' => max($low, $high)];
    }

    /**
     * Where the zone sits against the price now: the thing the card says first.
     *
     * "Above" and "below" are the zone's position relative to price, as a reader scanning
     * for the order to place would say it - "entry above current" means the whole zone is
     * higher than the market.
     *
     * @param  array{low: float, high: float}  $zone
     * @return 'above'|'below'|'inside'|null
     */
    private function position(string $direction, array $zone, ?float $price): ?string
    {
        if ($price === null) {
            return null;
        }

        return match (true) {
            $price < $zone['low'] => 'above',
            $price > $zone['high'] => 'below',
            default => 'inside',
        };
    }

    /**
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>|null  $quality
     * @param  array{low: float, high: float}  $zone
     * @return array{headline: string, detail: string, tone: 'go'|'wait'|'stop'|'muted', order: string|null}
     */
    private function guidance(Signal $signal, array $features, ?array $quality, array $zone, float $stop, ?float $price, ?string $position, bool $expired): array
    {
        $direction = $signal->direction;
        $buy = $direction === 'buy';
        $side = strtoupper($direction);
        $zoneText = $this->range($zone['low'], $zone['high']);

        if ($expired) {
            return [
                'headline' => self::EXPIRED,
                'detail' => 'The window for this setup has passed. The next bar is a different decision - wait for the next signal.',
                'tone' => 'muted',
                'order' => null,
            ];
        }

        if ($price === null || $position === null) {
            return [
                'headline' => self::NO_PRICE,
                'detail' => 'No bar has arrived for this instrument to compare the zone against. Check the price feed above.',
                'tone' => 'muted',
                'order' => null,
            ];
        }

        // Past the stop, in the wrong direction, there is no setup left to enter.
        $beyondStop = $buy ? $price <= $stop : $price >= $stop;

        if ($beyondStop) {
            return [
                'headline' => self::INVALIDATED,
                'detail' => sprintf('Price is at %s, past the stop at %s. The setup has failed; do not enter.', $this->price($price), $this->price($stop)),
                'tone' => 'stop',
                'order' => null,
            ];
        }

        // The floors were not met at the time: the score is permission, not evidence.
        if (($quality['entry_status'] ?? null) === SignalQuality::ENTRY_CONFIRMATION) {
            return [
                'headline' => self::WAIT_CONFIRMATION,
                'detail' => $quality['why'] ?? 'Not enough agreed about direction when this fired.',
                'tone' => 'wait',
                'order' => null,
            ];
        }

        if ($position === 'inside') {
            return [
                'headline' => self::ENTER_NOW,
                'detail' => sprintf('Price is inside the entry zone %s. A market %s here is the trade that was described.', $zoneText, $side),
                'tone' => 'go',
                'order' => "market {$direction}",
            ];
        }

        // Through the zone in the trade's own direction: the move has started without us.
        $chasing = $buy ? $position === 'below' : $position === 'above';

        if ($chasing) {
            $edge = $buy ? $zone['high'] : $zone['low'];
            $tp1 = $signal->tp1_price !== null ? (float) $signal->tp1_price : null;
            $gone = abs($price - $edge);
            $toTarget = $tp1 !== null ? abs($tp1 - $edge) : null;

            if ($toTarget !== null && $toTarget > 0.0 && $gone / $toTarget >= self::TOO_LATE_SHARE_OF_TP1) {
                return [
                    'headline' => self::TOO_LATE,
                    'detail' => sprintf(
                        'Price has already run %s past the zone - most of the way to TP1 at %s. A limit order here may never fill, and a market order buys the move after it happened. Wait for the next setup.',
                        $this->price($gone),
                        $this->price($tp1),
                    ),
                    'tone' => 'stop',
                    'order' => null,
                ];
            }

            return [
                'headline' => self::SET_LIMIT,
                'detail' => sprintf(
                    'Price has moved past the entry zone. Set a %s limit at %s and let price come back to it. Don\'t chase - a market order here turns a %s setup into a worse trade than the one published.',
                    $side,
                    $zoneText,
                    $this->rewardRatioText($signal),
                ),
                'tone' => 'wait',
                'order' => "{$direction} limit at {$zoneText}",
            ];
        }

        // The other side: price has slipped away from the zone toward the stop. The setup
        // is being tested and may yet fail; entering here is entering a setup that has not
        // held.
        return [
            'headline' => self::WAIT_RECLAIM,
            'detail' => sprintf(
                'Price is %s the entry zone, on the stop side. Enter only if it reclaims %s; if it reaches the stop at %s first, the setup is invalid.',
                $buy ? 'below' : 'above',
                $zoneText,
                $this->price($stop),
            ),
            'tone' => 'wait',
            'order' => "{$direction} stop at ".$this->price($buy ? $zone['low'] : $zone['high']),
        ];
    }

    /**
     * @return array<int, array{name: string, price: float, r: float|null}>
     */
    private function targets(Signal $signal, float $entry, float $stop): array
    {
        $risk = abs($entry - $stop);
        $targets = [];

        foreach (['tp1_price' => 'TP1', 'tp2_price' => 'TP2', 'tp3_price' => 'TP3'] as $column => $name) {
            if ($signal->{$column} === null) {
                continue;
            }

            $price = (float) $signal->{$column};

            $targets[] = [
                'name' => $name,
                'price' => $price,
                'r' => $risk > 0.0 ? round(abs($price - $entry) / $risk, 2) : null,
            ];
        }

        return $targets;
    }

    /**
     * Reward against risk, judged on the target the order actually carries - the final
     * rung - which is the same one RewardFloor judges.
     */
    private function rewardRatio(Signal $signal, float $entry, float $stop): ?float
    {
        $final = $signal->tp3_price ?? $signal->tp2_price ?? $signal->tp1_price;
        $risk = abs($entry - $stop);

        if ($final === null || $risk <= 0.0) {
            return null;
        }

        return round(abs((float) $final - $entry) / $risk, 2);
    }

    private function rewardRatioText(Signal $signal): string
    {
        $ratio = $this->rewardRatio($signal, (float) $signal->entry_price, (float) $signal->sl_price);

        return $ratio === null ? 'planned' : '1:'.rtrim(rtrim(number_format($ratio, 1), '0'), '.');
    }

    /**
     * Rows written before `valid_until` was stored get the same rule reconstructed: the
     * bar after the signal bar, which is when the open command would have expired.
     *
     * @param  array<string, mixed>  $features
     */
    private function validUntil(Signal $signal, array $features): ?Carbon
    {
        if (! empty($features['valid_until'])) {
            return Carbon::parse($features['valid_until']);
        }

        if ($signal->generated_at === null) {
            return null;
        }

        $seconds = $this->timeframeSeconds((string) $signal->timeframe);

        // generated_at is the signal bar's open; it closed one bar later, and the entry
        // was good for one bar after that.
        return $signal->generated_at->copy()->addSeconds(2 * $seconds);
    }

    // =========================================================================
    // READINGS
    // =========================================================================

    /**
     * @param  array<string, mixed>  $features
     * @return array{label: string, adx: float|null}
     */
    private function momentum(array $features): array
    {
        $adx = isset($features['adx']) ? (float) $features['adx'] : null;

        return [
            'adx' => $adx,
            'label' => match (true) {
                $adx === null => 'UNKNOWN',
                $adx >= self::ADX_STRONG => 'STRONG',
                $adx >= self::ADX_PRESENT => 'MODERATE',
                default => 'WEAK',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $features
     * @return array{value: float|null, label: string, agrees: bool|null}
     */
    private function rsi(array $features, string $direction): array
    {
        $rsi = isset($features['rsi']) ? (float) $features['rsi'] : null;

        $label = match (true) {
            $rsi === null => 'no reading',
            $rsi >= self::RSI_OVERBOUGHT => 'overbought',
            $rsi <= self::RSI_OVERSOLD => 'oversold',
            default => 'neutral',
        };

        // Momentum on the trade's side: above the midline for a buy, below for a sell.
        $agrees = $rsi === null ? null : ($direction === 'buy' ? $rsi > 50.0 : $rsi < 50.0);

        return ['value' => $rsi, 'label' => $label, 'agrees' => $agrees];
    }

    /**
     * @param  array<string, mixed>  $features
     * @return array{histogram: float|null, label: string, agrees: bool|null}
     */
    private function macd(array $features, string $direction): array
    {
        $hist = isset($features['macd_histogram']) ? (float) $features['macd_histogram'] : null;

        $label = match (true) {
            $hist === null => 'no reading',
            $hist > 0.0 => 'bullish',
            $hist < 0.0 => 'bearish',
            default => 'flat',
        };

        $agrees = $hist === null ? null : ($direction === 'buy' ? $hist > 0.0 : $hist < 0.0);

        return ['histogram' => $hist, 'label' => $label, 'agrees' => $agrees];
    }

    /**
     * @param  array<string, mixed>  $features
     * @return array{direction: string|null, timeframe: string|null, label: string, agrees: bool|null}
     */
    private function trend(array $features, string $direction): array
    {
        $trend = $features['trend_direction'] ?? null;

        return [
            'direction' => $trend,
            'timeframe' => $features['trend_timeframe'] ?? null,
            'label' => match ($trend) {
                'buy' => 'uptrend',
                'sell' => 'downtrend',
                default => 'no reading',
            },
            'agrees' => $trend === null ? null : $trend === $direction,
        ];
    }

    /**
     * @param  array<string, mixed>  $features
     * @return array{value: float|null, label: string}
     */
    private function adx(array $features): array
    {
        $adx = isset($features['adx']) ? (float) $features['adx'] : null;

        return [
            'value' => $adx,
            'label' => match (true) {
                $adx === null => 'no reading',
                $adx < self::ADX_PRESENT => 'ranging',
                $adx < self::ADX_STRONG => 'trending',
                default => 'strong trend',
            },
        ];
    }

    /**
     * @return array{atr: float|null, atr_pct: float|null, label: string}
     */
    private function volatility(?float $atr, ?float $atrPct): array
    {
        return [
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'label' => match (true) {
                $atrPct === null => 'no reading',
                $atrPct < self::ATR_QUIET_PCT => 'quiet',
                $atrPct > self::ATR_WILD_PCT => 'wild',
                default => 'normal',
            },
        ];
    }

    // =========================================================================
    // RISK NOTES
    // =========================================================================

    /**
     * What argued against this entry, in the order a reader would weigh it.
     *
     * Every note is a measurement the row carries. There is no note that says "the market
     * looks weak" - there is one that says which factor was not met and what it read.
     *
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>|null  $quality
     * @return array<int, string>
     */
    private function riskNotes(Signal $signal, array $features, ?array $quality, ?float $atrPct): array
    {
        $notes = [];
        $buy = $signal->direction === 'buy';

        // Whatever the strategy itself refused it for comes first: that is the one gate
        // that would have to change.
        if ($signal->skip_reason !== null) {
            $notes[] = 'Not traded by the strategy: '.str_replace('_', ' ', $signal->skip_reason).'.';
        }

        foreach ($quality['factors'] ?? [] as $factor) {
            if (! ($factor['met'] ?? true)) {
                $notes[] = "{$factor['name']} not met - {$factor['note']}";
            }
        }

        $rsi = $this->rsi($features, $signal->direction);

        if ($rsi['value'] !== null) {
            if ($buy && $rsi['label'] === 'overbought') {
                $notes[] = sprintf('RSI %.1f is overbought: stretched in the trade\'s direction, so a pullback before continuation is likely - favour the limit entry over a market one.', $rsi['value']);
            } elseif (! $buy && $rsi['label'] === 'oversold') {
                $notes[] = sprintf('RSI %.1f is oversold: stretched in the trade\'s direction, so a bounce before continuation is likely - favour the limit entry over a market one.', $rsi['value']);
            } elseif ($rsi['agrees'] === false) {
                $notes[] = sprintf('RSI %.1f is on the wrong side of 50 for a %s; momentum had not confirmed at the signal bar.', $rsi['value'], $signal->direction);
            }
        }

        $macd = $this->macd($features, $signal->direction);

        if ($macd['agrees'] === false) {
            $notes[] = sprintf('MACD histogram is %s against a %s; momentum had not confirmed at the signal bar.', $macd['label'], $signal->direction);
        }

        if ($atrPct !== null && $atrPct > self::ATR_WILD_PCT) {
            $notes[] = sprintf('ATR is %.2f%% of price: volatility is wild, and the stop is what pays for it.', $atrPct);
        } elseif ($atrPct !== null && $atrPct < self::ATR_QUIET_PCT) {
            $notes[] = sprintf('ATR is %.3f%% of price: a quiet market limits how far the targets are likely to be reached.', $atrPct);
        }

        if ($notes === []) {
            $notes[] = 'No measured objection. The stop is still the only guarantee this trade has.';
        }

        return $notes;
    }

    // =========================================================================
    // FORMATTING
    // =========================================================================

    private function price(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2);
    }

    private function range(float $low, float $high): string
    {
        return $this->price($low).' – '.$this->price($high);
    }

    private function timeframeSeconds(string $timeframe): int
    {
        $timeframe = strtoupper($timeframe);
        $count = (int) substr($timeframe, 1);

        if ($count < 1) {
            return 300;
        }

        return match (substr($timeframe, 0, 1)) {
            'M' => $count * 60,
            'H' => $count * 3600,
            'D' => $count * 86400,
            default => 300,
        };
    }
}

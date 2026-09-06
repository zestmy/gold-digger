<?php

namespace Tests\Feature\Signals;

use App\Models\Signal;
use App\Services\Strategy\SignalCard;
use App\Services\Strategy\SignalQuality;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The signal card.
 *
 * What a person about to place the trade is told, from a stored row and one price. Every
 * branch of the guidance is a comparison the reader could do themselves; these pin down
 * which comparison produces which instruction, because "set a limit" and "enter now" are
 * different trades and the card must never say one when it means the other.
 *
 * No database: the card is a pure function of the row, which is the property that makes
 * it trustworthy and is worth keeping.
 */
class SignalCardTest extends TestCase
{
    private SignalCard $card;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->card = new SignalCard;
        $this->now = Carbon::parse('2026-03-10 13:07:00', 'UTC');
    }

    // =====================================================================
    // GUIDANCE
    // =====================================================================

    public function test_price_inside_the_zone_is_a_market_entry(): void
    {
        $card = $this->read($this->buy(), price: 2000.10);

        $this->assertSame('inside', $card['position']);
        $this->assertSame(SignalCard::ENTER_NOW, $card['guidance']['headline']);
        $this->assertSame('go', $card['guidance']['tone']);
        $this->assertSame('market buy', $card['guidance']['order']);
    }

    /**
     * The screenshot case: a sell whose zone now sits above the market. The move has
     * started; the instruction is a limit at the zone, not a market order after it.
     */
    public function test_a_sell_whose_zone_is_above_the_market_is_a_limit_order(): void
    {
        $card = $this->read($this->sell(), price: 4575.82);

        $this->assertSame('above', $card['position']);
        $this->assertSame(SignalCard::SET_LIMIT, $card['guidance']['headline']);
        $this->assertSame('wait', $card['guidance']['tone']);
        $this->assertStringStartsWith('sell limit at', $card['guidance']['order']);
        $this->assertStringContainsString("Don't chase", $card['guidance']['detail']);
    }

    public function test_a_buy_the_market_has_run_past_is_a_limit_order(): void
    {
        // Zone 1999.20 - 2000.30; price a little above it, TP1 at 2003.
        $card = $this->read($this->buy(), price: 2001.00);

        $this->assertSame('below', $card['position']);
        $this->assertSame(SignalCard::SET_LIMIT, $card['guidance']['headline']);
    }

    /**
     * Past half the way to TP1 a resting limit is a hope, not a plan.
     */
    public function test_a_move_most_of_the_way_to_the_first_target_is_too_late(): void
    {
        $card = $this->read($this->buy(), price: 2002.50);

        $this->assertSame(SignalCard::TOO_LATE, $card['guidance']['headline']);
        $this->assertSame('stop', $card['guidance']['tone']);
        $this->assertNull($card['guidance']['order']);
    }

    /**
     * The other side of the zone: price has slipped toward the stop. Not a limit order -
     * that would fill on the way to the stop - but a wait for the zone to be reclaimed.
     */
    public function test_price_on_the_stop_side_of_the_zone_waits_for_a_reclaim(): void
    {
        $card = $this->read($this->buy(), price: 1997.50);

        $this->assertSame('above', $card['position']);
        $this->assertSame(SignalCard::WAIT_RECLAIM, $card['guidance']['headline']);
        $this->assertStringContainsString('buy stop at', $card['guidance']['order']);
    }

    public function test_price_through_the_stop_invalidates_the_setup(): void
    {
        $card = $this->read($this->buy(), price: 1994.80);

        $this->assertSame(SignalCard::INVALIDATED, $card['guidance']['headline']);
        $this->assertSame('stop', $card['guidance']['tone']);
    }

    public function test_a_signal_past_its_window_is_expired_whatever_price_does(): void
    {
        $card = $this->read($this->buy(), price: 2000.10, now: $this->now->copy()->addHour());

        $this->assertTrue($card['expired']);
        $this->assertSame(SignalCard::EXPIRED, $card['guidance']['headline']);
    }

    public function test_no_stored_price_is_said_rather_than_guessed(): void
    {
        $card = $this->read($this->buy(), price: null);

        $this->assertNull($card['position']);
        $this->assertSame(SignalCard::NO_PRICE, $card['guidance']['headline']);
    }

    /**
     * A score that did not clear the floors at the time is permission, not evidence, and
     * the card says so before it says anything about where price is.
     */
    public function test_a_signal_that_did_not_clear_the_floors_waits_for_confirmation(): void
    {
        $signal = $this->buy(quality: [
            'entry_status' => SignalQuality::ENTRY_CONFIRMATION,
            'why' => 'Nothing much agrees about direction.',
        ]);

        $card = $this->read($signal, price: 2000.10);

        $this->assertSame(SignalCard::WAIT_CONFIRMATION, $card['guidance']['headline']);
        $this->assertSame('Nothing much agrees about direction.', $card['guidance']['detail']);
    }

    // =====================================================================
    // ARITHMETIC
    // =====================================================================

    public function test_reward_is_judged_on_the_final_target_and_each_rung_carries_its_r(): void
    {
        $card = $this->read($this->buy(), price: 2000.10);

        // Stop 5.00 away; targets 3, 10 and 20 away.
        $this->assertEqualsWithDelta(4.0, $card['reward_ratio'], 1e-9);
        $this->assertSame(['TP1', 'TP2', 'TP3'], array_column($card['targets'], 'name'));
        $this->assertEqualsWithDelta(0.6, $card['targets'][0]['r'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $card['targets'][1]['r'], 1e-9);
        $this->assertEqualsWithDelta(4.0, $card['targets'][2]['r'], 1e-9);
    }

    /**
     * Rows written before the window was stored get the same rule reconstructed: the
     * signal bar opened at generated_at, closed one bar later, and the entry was good for
     * the bar after that.
     */
    public function test_the_window_of_an_old_row_is_reconstructed_from_its_timeframe(): void
    {
        $signal = $this->buy();
        $signal->features = array_diff_key($signal->features, ['valid_until' => true]);

        $card = $this->read($signal, price: 2000.10);

        $this->assertTrue($card['valid_until']->equalTo(Carbon::parse('2026-03-10 13:10:00', 'UTC')));
        $this->assertFalse($card['expired']);
    }

    public function test_a_row_with_no_stored_zone_uses_the_entry_itself(): void
    {
        $signal = $this->buy();
        $signal->features = array_diff_key($signal->features, ['entry_zone_low' => true, 'entry_zone_high' => true]);

        $card = $this->read($signal, price: 2000.00);

        $this->assertEqualsWithDelta(2000.00, $card['zone_low'], 1e-9);
        $this->assertEqualsWithDelta(2000.00, $card['zone_high'], 1e-9);
        $this->assertSame('inside', $card['position']);
    }

    // =====================================================================
    // READINGS AND RISK NOTES
    // =====================================================================

    public function test_momentum_and_indicator_labels_follow_the_conventional_bands(): void
    {
        $card = $this->read($this->buy(features: ['adx' => 45.0, 'rsi' => 62.0, 'macd_histogram' => 0.4]), price: 2000.10);

        $this->assertSame('STRONG', $card['momentum']['label']);
        $this->assertSame('neutral', $card['indicators']['rsi']['label']);
        $this->assertTrue($card['indicators']['rsi']['agrees']);
        $this->assertSame('bullish', $card['indicators']['macd']['label']);
        $this->assertTrue($card['indicators']['macd']['agrees']);
        $this->assertSame('strong trend', $card['indicators']['adx']['label']);
        $this->assertSame('uptrend', $card['indicators']['trend']['label']);
        $this->assertSame('normal', $card['indicators']['volatility']['label']);
    }

    public function test_stretched_momentum_on_the_trades_side_favours_the_limit_entry(): void
    {
        $card = $this->read($this->buy(features: ['rsi' => 78.0]), price: 2000.10);

        $this->assertSame('overbought', $card['indicators']['rsi']['label']);
        $this->assertStringContainsString('favour the limit entry', implode(' ', $card['risk_notes']));
    }

    public function test_momentum_against_the_trade_is_a_risk_note(): void
    {
        $card = $this->read($this->buy(features: ['rsi' => 44.0, 'macd_histogram' => -0.3]), price: 2000.10);

        $notes = implode(' | ', $card['risk_notes']);

        $this->assertStringContainsString('RSI 44.0 is on the wrong side of 50', $notes);
        $this->assertStringContainsString('MACD histogram is bearish against a buy', $notes);
    }

    public function test_unmet_factors_and_the_strategys_own_refusal_are_risk_notes(): void
    {
        $signal = $this->buy(quality: [
            'factors' => [
                ['key' => 'trend_htf', 'name' => 'Higher-timeframe trend', 'weight' => 1.0, 'met' => true, 'directional' => true, 'note' => 'Matching.'],
                ['key' => 'news_clear', 'name' => 'Clear of high-impact news', 'weight' => 1.0, 'met' => false, 'directional' => false, 'note' => 'NFP in 20 minutes.'],
            ],
        ]);
        $signal->skip_reason = 'news_blackout';

        $notes = $this->read($signal, price: 2000.10)['risk_notes'];

        $this->assertSame('Not traded by the strategy: news blackout.', $notes[0]);
        $this->assertSame('Clear of high-impact news not met - NFP in 20 minutes.', $notes[1]);
    }

    public function test_with_nothing_against_it_the_card_still_names_the_stop(): void
    {
        $card = $this->read($this->buy(features: ['rsi' => 58.0, 'macd_histogram' => 0.2]), price: 2000.10);

        $this->assertCount(1, $card['risk_notes']);
        $this->assertStringContainsString('The stop is still the only guarantee', $card['risk_notes'][0]);
    }

    /**
     * Every signal recorded before quality was stored still has to render: the guidance
     * and the arithmetic need only the prices, and the score is simply absent.
     */
    public function test_a_row_with_no_quality_assessment_renders_without_a_score(): void
    {
        $signal = $this->buy();
        $signal->features = array_diff_key($signal->features, ['quality' => true]);

        $card = $this->read($signal, price: 2000.10);

        $this->assertNull($card['confidence']);
        $this->assertNull($card['grade']);
        $this->assertSame([], $card['factors']);
        $this->assertSame(SignalCard::ENTER_NOW, $card['guidance']['headline']);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
    private function read(Signal $signal, ?float $price, ?Carbon $now = null): array
    {
        return $this->card->for($signal, $price, $price === null ? null : $this->now->copy()->subMinutes(5), $now ?? $this->now);
    }

    /**
     * A buy at 2000 with the stop 5.00 below, the ladder at +3 / +10 / +20, and a zone
     * from a 0.80 pullback to 0.30 beyond the close.
     *
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>  $quality
     */
    private function buy(array $features = [], array $quality = []): Signal
    {
        return $this->signal([
            'direction' => 'buy',
            'entry_price' => 2000.00,
            'sl_price' => 1995.00,
            'tp1_price' => 2003.00,
            'tp2_price' => 2010.00,
            'tp3_price' => 2020.00,
        ], ['entry_zone_low' => 1999.20, 'entry_zone_high' => 2000.30] + $features, $quality);
    }

    /**
     * The screenshot's sell: zone 4579.82 - 4585.82, stop 4591.82, ladder 4570.82 /
     * 4561.82 / 4552.82.
     */
    private function sell(): Signal
    {
        return $this->signal([
            'direction' => 'sell',
            'entry_price' => 4579.82,
            'sl_price' => 4591.82,
            'tp1_price' => 4570.82,
            'tp2_price' => 4561.82,
            'tp3_price' => 4552.82,
        ], ['entry_zone_low' => 4579.82, 'entry_zone_high' => 4585.82, 'trend_direction' => 'sell'], []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>  $quality
     */
    private function signal(array $attributes, array $features, array $quality): Signal
    {
        $signal = new Signal($attributes + [
            'symbol' => 'XAUUSDm',
            'timeframe' => 'M5',
            'generated_at' => Carbon::parse('2026-03-10 13:00:00', 'UTC'),
            'was_executed' => false,
        ]);

        $signal->features = $features + [
            'adx' => 28.0,
            'atr' => 3.20,
            'sl_pips' => 50.0,
            'trend_direction' => 'buy',
            'trend_timeframe' => 'H1',
            // The bar closed at 13:05; the entry is good for the bar after.
            'valid_until' => '2026-03-10T13:10:00+00:00',
            'quality' => $quality + [
                'confidence' => 78,
                'grade' => 'B',
                'risk' => 'MEDIUM',
                'entry_status' => SignalQuality::ENTRY_NOW,
                'tradeable' => true,
                'confluence' => 5.0,
                'possible' => 6.5,
                'directional' => 3.5,
                'why' => 'Five of six and a half agree.',
                'factors' => [],
            ],
        ];

        return $signal;
    }
}

<?php

namespace Tests\Feature\Telegram;

use App\Services\Telegram\SignalParser;
use Tests\TestCase;

/**
 * Parsing signals copied from Telegram.
 *
 * These are mostly tests about refusing. A copier that guesses produces positions whose
 * risk nobody chose, opened automatically, from a message nobody read - so the parser is
 * built to decline anything ambiguous and the tests are built to prove it declines.
 */
class SignalParserTest extends TestCase
{
    private SignalParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SignalParser;
    }

    // =====================================================================
    // THE SHAPES PROVIDERS ACTUALLY POST
    // =====================================================================

    public function test_it_parses_a_plain_signal(): void
    {
        $result = $this->parser->parse(<<<'MSG'
        XAUUSD BUY @ 2650.50
        SL: 2645.00
        TP1: 2655.00
        TP2: 2660.00
        TP3: 2670.00
        MSG);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('XAUUSD', $result['symbol']);
        $this->assertSame('buy', $result['direction']);
        $this->assertSame(2650.50, $result['entry_price']);
        $this->assertSame(2645.00, $result['sl_price']);
        $this->assertSame([2655.00, 2660.00, 2670.00], $result['tp_prices']);
    }

    public function test_it_parses_emoji_and_decoration(): void
    {
        $result = $this->parser->parse(<<<'MSG'
        🔴🔴 SELL GOLD 🔴🔴
        Entry: 2650.00
        ❌ Stop Loss: 2658.00
        ✅ Take Profit: 2640.00, 2630.00
        MSG);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('XAUUSD', $result['symbol'], 'GOLD is the one alias worth handling.');
        $this->assertSame('sell', $result['direction']);
        $this->assertSame(2658.00, $result['sl_price']);
        $this->assertSame([2640.00, 2630.00], $result['tp_prices']);
    }

    public function test_it_keeps_both_sides_of_an_entry_zone(): void
    {
        // The far side is what decides whether price has already run past the signal.
        $result = $this->parser->parse("EURUSD BUY\nEntry 1.0850 - 1.0860\nSL 1.0820\nTP 1.0900");

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame(1.0850, $result['entry_price']);
        $this->assertSame(1.0860, $result['entry_zone_high']);
    }

    public function test_a_market_order_with_no_entry_price_is_valid(): void
    {
        // "at market" is a real instruction, and the executor already knows how to do it.
        $result = $this->parser->parse("BUY US30 NOW\nStop Loss 38500\nTarget 39000");

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('US30', $result['symbol']);
        $this->assertNull($result['entry_price']);
        $this->assertSame(38500.0, $result['sl_price']);
    }

    public function test_it_handles_long_and_short_wording(): void
    {
        $long = $this->parser->parse("LONG BTCUSD\nSL 61000\nTP 66000");
        $short = $this->parser->parse("SHORT USDJPY\nS/L 158.20\nTP 156.00");

        $this->assertSame('buy', $long['direction']);
        $this->assertSame('sell', $short['direction']);
    }

    // =====================================================================
    // WHAT IT REFUSES, AND WHY
    // =====================================================================

    /**
     * The rule this class exists for.
     */
    public function test_a_signal_with_no_stop_is_refused(): void
    {
        $result = $this->parser->parse("XAUUSD BUY @ 2650\nTP1: 2660\nTP2: 2670");

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('stop', strtolower($result['error']));
        $this->assertNull($result['sl_price'], 'A missing stop must never be filled in.');
    }

    public function test_a_message_naming_both_directions_is_refused(): void
    {
        // "BUY above, SELL below" is a plan, not a signal. Picking one invents an order.
        $result = $this->parser->parse("XAUUSD: BUY above 2655, SELL below 2645. SL 2650");

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('direction', strtolower($result['error']));
    }

    public function test_a_message_with_no_direction_is_refused(): void
    {
        $this->assertFalse($this->parser->parse("XAUUSD looking interesting here, SL 2645")['ok']);
    }

    public function test_a_message_with_no_instrument_is_refused(): void
    {
        $this->assertFalse($this->parser->parse("BUY NOW! SL 2645 TP 2660")['ok']);
    }

    /**
     * A stop on the wrong side of entry means the message was misread, and executing it
     * would place the stop where the target belongs.
     */
    public function test_a_buy_with_the_stop_above_entry_is_refused(): void
    {
        $result = $this->parser->parse("XAUUSD BUY @ 2650\nSL: 2680\nTP: 2700");

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('misread', $result['error']);
    }

    public function test_a_sell_with_the_stop_below_entry_is_refused(): void
    {
        $result = $this->parser->parse("XAUUSD SELL @ 2650\nSL: 2620\nTP: 2600");

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('misread', $result['error']);
    }

    public function test_an_unrecognised_instrument_is_refused(): void
    {
        // Better to miss a signal than to trade a symbol the system cannot classify.
        $this->assertFalse($this->parser->parse("BUY WIDGET500\nSL 100\nTP 120")['ok']);
    }

    public function test_chatter_is_refused_rather_than_half_understood(): void
    {
        foreach ([
            'Good morning traders! Market looking bullish today 📈',
            'Yesterday XAUUSD gave us +120 pips! Join VIP for more',
            'SL to breakeven on the gold trade',
        ] as $chatter) {
            $this->assertFalse($this->parser->parse($chatter)['ok'], "Parsed chatter: {$chatter}");
        }
    }

    // =====================================================================
    // A REAL PROVIDER MESSAGE
    // =====================================================================

    /**
     * Captured verbatim from a live desk, and it broke the parser in two ways at once.
     */
    public function test_it_parses_a_real_provider_signal(): void
    {
        $result = $this->parser->parse(<<<'MSG'
        FIRA SMART DESK
        AI-Powered Trading Signal

        📊 MACRO OUTLOOK: B (BEARISH)
        ⚪ FA | 🔴 Pred | ⚪ Sent
        💡 Macro sederhana — berhati-hati

        📈 ANALISIS TEKNIKAL
        🔴 XAU/USD | SELL | M30
        Confidence: 84%

        Entry: 4633.96 - 4637.96
        💡 Set Sell Limit order di best entry

        Targets:
        TP1: 4626.46
        TP2: 4618.96
        TP3: 4611.46

        Stop Loss: 4642.96
        R:R Ratio: 1:3.8

        📊 MTF Check:
        SELL M30 ✅
        SELL H1 ✅
        BUY H4 ⚠️
        🟡 Penjajaran separa

        TAYOR (Trade At Your Own Risk)
        MSG);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('XAUUSD', $result['symbol']);
        $this->assertSame('sell', $result['direction']);
        $this->assertSame(4642.96, $result['sl_price']);
        $this->assertSame([4626.46, 4618.96, 4611.46], $result['tp_prices']);
    }

    /**
     * The slash split the pair, and 'XAU' alone classifies as a metal - so this would have
     * traded a symbol that does not exist.
     */
    public function test_a_slash_separated_pair_is_one_instrument(): void
    {
        $this->assertSame('XAUUSD', $this->parser->parse("XAU/USD SELL
SL 4642
TP 4620")['symbol']);
        $this->assertSame('EURUSD', $this->parser->parse("EUR/USD BUY
SL 1.08
TP 1.09")['symbol']);
    }

    /**
     * A confluence section being honest about a disagreement must not refuse the signal.
     */
    public function test_a_multi_timeframe_checklist_does_not_make_the_direction_ambiguous(): void
    {
        $result = $this->parser->parse(<<<'MSG'
        XAUUSD | SELL | M30
        Stop Loss: 4642.96
        TP1: 4626.46
        MTF Check:
        SELL M30
        SELL H1
        BUY H4
        MSG);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('sell', $result['direction'], 'The direction beside the instrument is the instruction.');
    }

    /**
     * A limit order fills at the better price, and taking the wrong end of the zone
     * misstates the trade badly enough to flip the verdict.
     */
    public function test_the_entry_zone_end_depends_on_the_direction(): void
    {
        // Sell: fills at the high end. Risk 5.00 against 11.50 reward, not 9.00 against 7.50.
        $sell = $this->parser->parse("XAUUSD SELL
Entry: 4633.96 - 4637.96
Stop Loss: 4642.96
TP1: 4626.46");

        $this->assertSame(4637.96, $sell['entry_price']);
        $this->assertSame(4633.96, $sell['entry_zone_high']);

        $risk = abs($sell['entry_price'] - $sell['sl_price']);
        $reward = abs($sell['tp_prices'][0] - $sell['entry_price']);
        $this->assertEqualsWithDelta(2.30, $reward / $risk, 0.01, 'The wrong end reports 0.83:1 and gets declined.');

        // Buy: fills at the low end.
        $buy = $this->parser->parse("XAUUSD BUY
Entry: 2650 - 2654
Stop Loss: 2640
TP1: 2680");
        $this->assertSame(2650.0, $buy['entry_price']);
    }
}

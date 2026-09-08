<?php

namespace Tests\Unit\Services\Trading;

use App\Models\Trade;
use App\Services\Trading\StopRules;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic every stop-moving engine agrees on.
 *
 * Each rule here was once written twice - in TradeManager and in PositionManager - and
 * the two disagreed. These pin the one answer both now share.
 */
class StopRulesTest extends TestCase
{
    private StopRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new StopRules;
    }

    // --- tightens ---------------------------------------------------------------

    public function test_a_stop_only_moves_toward_profit(): void
    {
        $buy = $this->trade('buy', sl: 1995.0);
        $this->assertTrue($this->rules->tightens($buy, 1998.0, 0.10));
        $this->assertFalse($this->rules->tightens($buy, 1990.0, 0.10));

        $sell = $this->trade('sell', sl: 2005.0);
        $this->assertTrue($this->rules->tightens($sell, 2002.0, 0.10));
        $this->assertFalse($this->rules->tightens($sell, 2010.0, 0.10));
    }

    /**
     * A recorded stop of zero is "no stop", not a price. Compared as a price it sat
     * below every level a sell could be moved to, so a sell whose fill never carried a
     * stop read as already protected and was never protected at all.
     */
    public function test_a_zero_or_missing_stop_is_always_improved_on(): void
    {
        $this->assertTrue($this->rules->tightens($this->trade('sell', sl: 0.0), 1998.0, 0.10));
        $this->assertTrue($this->rules->tightens($this->trade('sell', sl: null), 1998.0, 0.10));
        $this->assertTrue($this->rules->tightens($this->trade('buy', sl: 0.0), 1990.0, 0.10));
    }

    /**
     * Under a twentieth of a pip is not a move worth a command round trip - in the
     * instrument's own pip, so a five-digit pair is not held to a gold-sized tolerance.
     */
    public function test_a_move_inside_a_twentieth_of_a_pip_is_not_a_move(): void
    {
        $gold = $this->trade('buy', sl: 1995.00);
        $this->assertFalse($this->rules->tightens($gold, 1995.004, 0.10));
        $this->assertTrue($this->rules->tightens($gold, 1995.01, 0.10));

        $fx = $this->trade('buy', sl: 1.10000);
        $this->assertFalse($this->rules->tightens($fx, 1.100004, 0.0001));
        $this->assertTrue($this->rules->tightens($fx, 1.10001, 0.0001));
    }

    // --- bucket -----------------------------------------------------------------

    /**
     * Rounding to two decimals gave every trail on a five-digit pair the same key, so
     * only the first was ever sent. The bucket is in the instrument's pip.
     */
    public function test_a_level_is_keyed_in_the_instruments_own_pip(): void
    {
        $this->assertSame('1995.3', $this->rules->bucket(1995.27, 0.10));
        $this->assertSame('1995.3', $this->rules->bucket(1995.33, 0.10));
        $this->assertSame('1.1033', $this->rules->bucket(1.10331, 0.0001));
        $this->assertNotSame($this->rules->bucket(1.1033, 0.0001), $this->rules->bucket(1.1034, 0.0001));
        // Without a pip size, the old two-decimal rendering.
        $this->assertSame('1995.27', $this->rules->bucket(1995.27, null));
    }

    // --- breakEven --------------------------------------------------------------

    public function test_break_even_is_the_entry_plus_the_offset_in_the_profitable_direction(): void
    {
        $this->assertEqualsWithDelta(2002.0, $this->rules->breakEven($this->trade('buy'), 20.0, 0.10, best: 2010.0, last: 2008.0), 1e-9);
        $this->assertEqualsWithDelta(1998.0, $this->rules->breakEven($this->trade('sell'), 20.0, 0.10, best: 1990.0, last: 1992.0), 1e-9);
    }

    public function test_no_offset_or_no_pip_size_means_the_entry(): void
    {
        $this->assertEqualsWithDelta(2000.0, $this->rules->breakEven($this->trade('buy'), 0.0, 0.10, 2010.0, 2008.0), 1e-9);
        $this->assertEqualsWithDelta(2000.0, $this->rules->breakEven($this->trade('buy'), 20.0, null, 2010.0, 2008.0), 1e-9);
    }

    /**
     * A padded stop past the market is a stop on the wrong side of price: the broker
     * refuses it or fills it as an immediate exit. Either reading - the best price
     * since entry, or the last close - being behind the padding sends it to the entry.
     */
    public function test_a_padded_stop_that_would_land_past_the_market_falls_back_to_the_entry(): void
    {
        // Never earned: the best price is inside the padding.
        $this->assertEqualsWithDelta(2000.0, $this->rules->breakEven($this->trade('buy'), 100.0, 0.10, best: 2003.0, last: 2002.0), 1e-9);
        // Earned, then retraced behind it.
        $this->assertEqualsWithDelta(2000.0, $this->rules->breakEven($this->trade('buy'), 20.0, 0.10, best: 2010.0, last: 2001.0), 1e-9);
        // Earned and still ahead of it.
        $this->assertEqualsWithDelta(2002.0, $this->rules->breakEven($this->trade('buy'), 20.0, 0.10, best: 2010.0, last: 2005.0), 1e-9);
        // The same on a sell.
        $this->assertEqualsWithDelta(2000.0, $this->rules->breakEven($this->trade('sell'), 20.0, 0.10, best: 1990.0, last: 1999.0), 1e-9);
    }

    private function trade(string $direction, ?float $sl = 1995.0): Trade
    {
        return new Trade(['direction' => $direction, 'entry_price' => 2000.0, 'sl_price' => $sl]);
    }
}

<?php

namespace Tests\Feature\Instruments;

use App\Services\Instruments\InstrumentProfile;
use App\Services\News\NewsBlackout;
use Tests\TestCase;

/**
 * Instrument classification.
 *
 * Every assumption this pins down was previously hardcoded for gold, and every one of them
 * fails silently rather than loudly on something else: an index that never blacks out for
 * news, a crypto pair whose weekend outage raises no alert, a session gate applied to a
 * market that has no sessions.
 */
class InstrumentProfileTest extends TestCase
{
    private InstrumentProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profile = new InstrumentProfile;
    }

    public function test_it_classifies_each_asset_class(): void
    {
        $expected = [
            'XAUUSD' => InstrumentProfile::METAL,
            'XAGUSD' => InstrumentProfile::METAL,
            'EURUSD' => InstrumentProfile::FX,
            'USDJPY' => InstrumentProfile::FX,
            'US30' => InstrumentProfile::INDEX,
            'NAS100' => InstrumentProfile::INDEX,
            'GER40' => InstrumentProfile::INDEX,
            'USOIL' => InstrumentProfile::ENERGY,
            'BTCUSD' => InstrumentProfile::CRYPTO,
        ];

        foreach ($expected as $symbol => $kind) {
            $this->assertSame($kind, $this->profile->for($symbol)['kind'], "{$symbol} misclassified");
        }
    }

    public function test_it_survives_broker_decoration(): void
    {
        // The same instrument reaches this from four directions: the strategy's generic
        // name, the terminal's resolved name, a candle push, and a heartbeat.
        foreach (['XAUUSDm', 'XAUUSD.a', 'XAUUSD_i', 'xauusd'] as $variant) {
            $this->assertSame(InstrumentProfile::METAL, $this->profile->for($variant)['kind'], $variant);
            $this->assertSame(['USD'], $this->profile->for($variant)['currencies'], $variant);
        }

        $this->assertSame(InstrumentProfile::INDEX, $this->profile->for('US30Cash')['kind']);
    }

    /**
     * The bug this whole class was written for.
     */
    public function test_an_index_is_exposed_to_its_own_calendar(): void
    {
        // Reading a currency pair off a six-letter name returns nothing for US30, so the
        // news filter found no currencies and never blacked out - through the US calendar,
        // which is exactly what moves a US index hardest.
        $this->assertSame(['USD'], (new NewsBlackout)->currenciesFor('US30'));
        $this->assertSame(['EUR'], (new NewsBlackout)->currenciesFor('GER40'));
        $this->assertSame(['GBP'], (new NewsBlackout)->currenciesFor('UK100'));
        $this->assertSame(['JPY'], (new NewsBlackout)->currenciesFor('JP225'));
    }

    public function test_fx_is_exposed_to_both_legs(): void
    {
        $this->assertSame(['EUR', 'USD'], (new NewsBlackout)->currenciesFor('EURUSD'));
        $this->assertSame(['GBP', 'JPY'], (new NewsBlackout)->currenciesFor('GBPJPY'));
    }

    public function test_a_metal_is_exposed_only_to_its_quote_currency(): void
    {
        // Gold has no economic calendar of its own; the dollar it is priced in does.
        $this->assertSame(['USD'], (new NewsBlackout)->currenciesFor('XAUUSD'));
    }

    public function test_crypto_trades_at_the_weekend_and_is_not_session_gated(): void
    {
        $btc = $this->profile->for('BTCUSD');

        $this->assertTrue($btc['trades_weekend'], 'A silent Saturday outage on BTCUSD must still alert.');
        $this->assertFalse($btc['session_gated'], 'London and New York describe nothing for crypto.');
    }

    public function test_everything_else_stops_at_the_weekend(): void
    {
        foreach (['XAUUSD', 'EURUSD', 'US30', 'USOIL'] as $symbol) {
            $this->assertFalse($this->profile->for($symbol)['trades_weekend'], $symbol);
            $this->assertTrue($this->profile->for($symbol)['session_gated'], $symbol);
        }
    }

    /**
     * A wrong guess here means trading an index through gold's assumptions.
     */
    public function test_an_unrecognised_symbol_is_not_guessed_at(): void
    {
        $unknown = $this->profile->for('WIDGET500');

        $this->assertSame(InstrumentProfile::UNKNOWN, $unknown['kind']);
        $this->assertSame([], $unknown['currencies']);
    }

    public function test_configuration_overrides_the_rules(): void
    {
        // Broker naming is too varied to classify by pattern alone, and the failure is
        // silent, so an operator must be able to state what something is.
        config(['instruments.overrides.WIDGET500' => ['kind' => 'index', 'currencies' => ['USD']]]);

        $this->assertSame(InstrumentProfile::INDEX, $this->profile->for('WIDGET500')['kind']);
        $this->assertSame(['USD'], $this->profile->for('WIDGET500')['currencies']);
    }

    public function test_an_index_is_not_mistaken_for_a_currency_pair(): void
    {
        // 'US500' starts with 'US' and is six characters with 'US5'/'00' - close enough to
        // a pair to be worth asserting it is not treated as one.
        $this->assertSame(InstrumentProfile::INDEX, $this->profile->for('US500')['kind']);
        $this->assertSame(['USD'], $this->profile->for('US500')['currencies']);
    }
}

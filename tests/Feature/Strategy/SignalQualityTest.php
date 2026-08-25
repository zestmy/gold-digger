<?php

namespace Tests\Feature\Strategy;

use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\User;
use App\Services\Strategy\SignalQuality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Scoring an entry by how much agrees with it.
 *
 * The property under test throughout is that the number means something: it is derived
 * from stored data, it can be recomputed, and it moves only when the market does. A
 * confidence figure somebody chose would pass no test here, which is the point.
 */
class SignalQualityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Strategy $strategy;

    private BrokerAccount $account;

    private BotSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);

        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();
        $this->settings->update([
            'is_active' => true, 'allowed_sessions' => null, 'news_filter_enabled' => false,
        ]);
    }

    // =====================================================================
    // THE SCORE IS A MEASUREMENT
    // =====================================================================

    public function test_a_rising_market_scores_a_buy_higher_than_a_sell(): void
    {
        $this->candles(rising: true);

        $buy = $this->assess('buy');
        $sell = $this->assess('sell');

        // The same bars, the same instant, opposite directions. If the score did not
        // separate these it would not be reading the market at all.
        $this->assertGreaterThan($sell['confluence'], $buy['confluence']);
    }

    public function test_confidence_is_the_factor_count_expressed_as_a_ratio(): void
    {
        $this->candles(rising: true);

        $result = $this->assess('buy');

        $possible = array_sum(array_column($result['factors'], 'weight'));
        $expected = (int) round($result['confluence'] / $possible * 100);

        // Recomputable from what is returned. A number a model picked could not satisfy this.
        $this->assertSame($expected, $result['confidence']);
    }

    public function test_every_factor_states_its_own_reading(): void
    {
        $this->candles(rising: true);

        foreach ($this->assess('buy')['factors'] as $factor) {
            $this->assertNotSame('', $factor['note'], "{$factor['name']} gave no reading");
            $this->assertIsBool($factor['met']);
        }
    }

    /**
     * Direction measured twice is not two independent agreements.
     */
    public function test_directional_strength_is_weighted_below_a_full_factor(): void
    {
        $this->candles(rising: true);

        $di = collect($this->assess('buy')['factors'])->firstWhere('name', 'Directional strength (DI)');

        $this->assertSame(0.5, $di['weight']);
    }

    // =====================================================================
    // WHAT IT REFUSES
    // =====================================================================

    public function test_too_little_agreement_asks_for_confirmation(): void
    {
        // Flat bars: no trend, no ADX, nothing to agree with.
        $this->candles(rising: null);

        $result = $this->assess('buy');

        $this->assertLessThan(SignalQuality::MIN_CONFLUENCE, $result['confluence']);
        $this->assertSame(SignalQuality::ENTRY_CONFIRMATION, $result['entry_status']);
        $this->assertSame(SignalQuality::RISK_HIGH, $result['risk']);
        $this->assertFalse($result['tradeable']);
    }

    /**
     * The distinction that matters: not yet, versus no longer.
     */
    public function test_price_through_the_zone_is_a_chase_not_an_entry(): void
    {
        $this->candles(rising: true);

        // Last close is 2650. A buy zone of 2600-2610 has already been left behind.
        $result = $this->assess('buy', entryLow: 2600.0, entryHigh: 2610.0);

        $this->assertSame(SignalQuality::ENTRY_PULLBACK, $result['entry_status']);
        $this->assertFalse($result['tradeable'], 'chasing a zone entry turns 1:3 into 1:1');
        $this->assertStringContainsString('already left the entry zone', $result['why']);
    }

    public function test_price_short_of_the_zone_is_not_a_chase(): void
    {
        $this->candles(rising: true);

        // A buy zone above the market has not been reached; that is a resting order, not
        // a missed move.
        $result = $this->assess('buy', entryLow: 2700.0, entryHigh: 2710.0);

        $this->assertSame(SignalQuality::ENTRY_NOW, $result['entry_status']);
    }

    public function test_price_inside_the_zone_can_enter(): void
    {
        $this->candles(rising: true);

        $result = $this->assess('buy', entryLow: 2645.0, entryHigh: 2655.0);

        $this->assertSame(SignalQuality::ENTRY_NOW, $result['entry_status']);
    }

    // =====================================================================
    // HOW FAST, AS DISTINCT FROM HOW MUCH
    // =====================================================================

    public function test_the_daily_allowance_counts_positions_not_approvals(): void
    {
        $this->settings->update(['ai_max_trades_per_day' => 2]);

        $this->trade();
        $this->trade();

        $allowance = (new SignalQuality)->dailyAllowance($this->settings->fresh(), $this->user->id);

        $this->assertTrue($allowance['reached']);
        $this->assertSame(2, $allowance['taken']);
    }

    public function test_yesterdays_trades_do_not_count_against_today(): void
    {
        $this->settings->update(['ai_max_trades_per_day' => 2]);

        $this->trade(now()->subDay());
        $this->trade(now()->subDay());

        $this->assertFalse((new SignalQuality)->dailyAllowance($this->settings->fresh(), $this->user->id)['reached']);
    }

    public function test_the_strategys_own_trades_do_not_consume_the_copiers_allowance(): void
    {
        $this->settings->update(['ai_max_trades_per_day' => 1]);

        // The cap governs copied signals. The strategy path has its own controls and is
        // not bound by a document about reading somebody else's signals.
        $this->trade(origin: 'bot');

        $this->assertFalse((new SignalQuality)->dailyAllowance($this->settings->fresh(), $this->user->id)['reached']);
    }

    public function test_no_cap_set_means_no_ceiling(): void
    {
        $this->trade();
        $this->trade();
        $this->trade();

        $allowance = (new SignalQuality)->dailyAllowance($this->settings->fresh(), $this->user->id);

        // A deployment predating this setting was not capped, and upgrading must not
        // silently start refusing trades.
        $this->assertFalse($allowance['reached']);
        $this->assertNull($allowance['allowed']);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
    private function assess(string $direction, ?float $entryLow = null, ?float $entryHigh = null): array
    {
        return (new SignalQuality)->assess(
            $this->strategy,
            $this->account->id,
            'XAUUSD',
            $direction,
            $entryLow,
            $entryHigh,
        );
    }

    /**
     * @param  bool|null  $rising  true for a clean uptrend, null for a flat market
     */
    private function candles(?bool $rising): void
    {
        foreach (['M5', 'H1'] as $timeframe) {
            for ($i = 300; $i >= 0; $i--) {
                $drift = $rising === null ? 0.0 : (300 - $i) * ($rising ? 0.5 : -0.5);
                $base = $rising === null ? 2650.0 : 2650.0 - (300 * 0.5) + $drift;

                Candle::create([
                    'user_id' => $this->user->id,
                    'broker_account_id' => $this->account->id,
                    'symbol' => 'XAUUSD',
                    'timeframe' => $timeframe,
                    'open_time' => now()->subMinutes(($timeframe === 'M5' ? 5 : 60) * $i),
                    'open' => $base - 0.5, 'high' => $base + 1.0,
                    'low' => $base - 1.0, 'close' => $base,
                ]);
            }
        }
    }

    private function trade(?Carbon $when = null, string $origin = 'ai'): Trade
    {
        return Trade::create([
            'user_id' => $this->user->id,
            'strategy_id' => $this->strategy->id,
            'broker_account_id' => $this->account->id,
            'mt5_ticket' => random_int(100000, 999999),
            'symbol' => 'XAUUSD', 'direction' => 'buy',
            'initial_lot_size' => 0.01, 'remaining_lot_size' => 0.01,
            'entry_price' => 2650.0, 'sl_price' => 2645.0,
            'status' => 'open', 'origin' => $origin,
            'opened_at' => $when ?? now(),
        ]);
    }
}

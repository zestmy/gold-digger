<?php

namespace Tests\Feature\Telegram;

use App\Models\BotSettings;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\SymbolSpec;
use App\Models\TelegramSignal;
use App\Models\User;
use App\Services\Telegram\SignalPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which levels a copied signal is actually traded with.
 *
 * The property that matters most is that one plan is produced and everything downstream
 * uses it. The reviewer judges reward against risk; the executor sizes from the stop
 * distance. If those two looked at different numbers, the reviewer would be approving a
 * trade that never existed.
 */
class SignalPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BotSettings $settings;

    private Strategy $strategy;

    private BrokerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '230070844', 'server' => 'Elev8-Demo2',
            'is_demo' => true, 'is_active' => true,
        ]);

        $this->settings = BotSettings::where('user_id', $this->user->id)->firstOrFail();

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();
        $this->strategy->update([
            'symbol' => 'XAUUSD', 'atr_period' => 14, 'sl_atr_multiplier' => 1.5,
            'tp1_pips' => 30, 'tp2_pips' => 100, 'tp3_pips' => 200,
        ]);

        SymbolSpec::updateOrCreate(
            ['broker_account_id' => $this->account->id, 'symbol' => 'XAUUSD'],
            ['base_symbol' => 'XAUUSD', 'pip_size' => 0.10, 'digits' => 2,
                'pip_value_per_lot' => 10.0, 'volume_min' => 0.01, 'volume_step' => 0.01],
        );

        // A steady 4-wide range, so ATR is a round number to reason about.
        for ($i = 120; $i > 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'M5',
                'open_time' => now()->subMinutes(5 * $i),
                'open' => 4640, 'high' => 4642, 'low' => 4638, 'close' => 4640,
            ]);
        }
    }

    private function signal(array $overrides = []): TelegramSignal
    {
        return TelegramSignal::create($overrides + [
            'user_id' => $this->user->id, 'source' => 'bot_api',
            'external_id' => 'bot:'.random_int(1, 999999999), 'chat_id' => '316745398',
            'raw_text' => 'x', 'posted_at' => now(),
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => 'XAUUSD', 'direction' => 'sell',
            'entry_price' => 4637.96, 'sl_price' => 4642.96,
            'tp_prices' => [4626.46, 4618.96, 4611.46],
            'review_status' => TelegramSignal::REVIEW_PENDING,
        ]);
    }

    private function plan(TelegramSignal $signal): array
    {
        return (new SignalPlan)->for($signal, $this->settings->fresh());
    }

    public function test_by_default_the_providers_levels_are_used_unchanged(): void
    {
        // Changing what a signal means is not something to do by default.
        $plan = $this->plan($this->signal());

        $this->assertSame(SignalPlan::SOURCE_PROVIDER, $plan['source']);
        $this->assertSame(4637.96, $plan['entry']);
        $this->assertSame(4642.96, $plan['sl']);
        $this->assertSame([4626.46, 4618.96, 4611.46], $plan['tps']);
    }

    public function test_strategy_levels_keep_the_entry_and_replace_the_rest(): void
    {
        $this->settings->update(['copier_levels' => SignalPlan::SOURCE_STRATEGY]);

        $plan = $this->plan($this->signal());

        $this->assertSame(SignalPlan::SOURCE_STRATEGY, $plan['source']);
        // The entry is the part being copied - the provider's read on where to get in.
        $this->assertSame(4637.96, $plan['entry']);

        // ATR on a steady 4-wide range is 4. Stop is 1.5 x that, above entry on a sell.
        $this->assertEqualsWithDelta(4637.96 + 6.0, $plan['sl'], 0.01);

        // Ladder from the configured pips, below entry on a sell, at 0.10 a pip.
        $this->assertEqualsWithDelta(4637.96 - 3.0, $plan['tps'][0], 0.01);
        $this->assertEqualsWithDelta(4637.96 - 10.0, $plan['tps'][1], 0.01);
        $this->assertEqualsWithDelta(4637.96 - 20.0, $plan['tps'][2], 0.01);
    }

    public function test_strategy_levels_run_the_right_way_for_a_buy(): void
    {
        $this->settings->update(['copier_levels' => SignalPlan::SOURCE_STRATEGY]);

        $plan = $this->plan($this->signal([
            'direction' => 'buy', 'entry_price' => 4630.0, 'sl_price' => 4625.0, 'tp_prices' => [4640.0],
        ]));

        $this->assertLessThan($plan['entry'], $plan['sl'], 'A buy stop sits below entry.');
        $this->assertGreaterThan($plan['entry'], $plan['tps'][0], 'A buy target sits above entry.');
    }

    /**
     * Refusing to invent a level, the same rule the strategy path follows.
     */
    public function test_it_falls_back_to_the_provider_when_atr_cannot_be_measured(): void
    {
        $this->settings->update(['copier_levels' => SignalPlan::SOURCE_STRATEGY]);
        Candle::where('symbol', 'XAUUSD')->delete();

        $plan = $this->plan($this->signal());

        $this->assertSame(SignalPlan::SOURCE_PROVIDER, $plan['source']);
        $this->assertSame(4642.96, $plan['sl']);
        $this->assertStringContainsString('ATR', $plan['why']);
    }

    public function test_a_market_order_falls_back_because_there_is_no_entry_to_measure_from(): void
    {
        $this->settings->update(['copier_levels' => SignalPlan::SOURCE_STRATEGY]);

        $plan = $this->plan($this->signal(['entry_price' => null]));

        $this->assertSame(SignalPlan::SOURCE_PROVIDER, $plan['source']);
        $this->assertStringContainsString('market', $plan['why']);
    }

    /**
     * The whole point of computing this once.
     */
    public function test_the_reviewer_and_the_executor_see_the_same_numbers(): void
    {
        $this->settings->update(['copier_levels' => SignalPlan::SOURCE_STRATEGY]);
        $signal = $this->signal();

        $first = $this->plan($signal);
        $second = $this->plan($signal->fresh());

        $this->assertSame($first['sl'], $second['sl']);
        $this->assertSame($first['tps'], $second['tps']);
        $this->assertNotSame($signal->sl_price, $first['sl'], 'Fixture sanity: the plan differs from the message.');
    }

    /**
     * The ATR that sizes the stop comes from one timeframe, not from every bar stored.
     *
     * An account pushing M5 and H1 has twelve M5 bars per H1 bar, all with distinct open
     * times, so an unfiltered "newest 300 bars" is a blend of the two. The blend's ATR is
     * between the two real ones and describes neither market - and it went straight into a
     * live stop distance.
     */
    public function test_the_stop_is_measured_on_one_timeframe(): void
    {
        $this->settings->update(['copier_levels' => SignalPlan::SOURCE_STRATEGY]);

        // Four times as wide as the M5 series seeded in setUp, over the same period.
        for ($i = 120; $i > 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id, 'broker_account_id' => $this->account->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'open_time' => now()->subHours($i),
                'open' => 4640, 'high' => 4648, 'low' => 4632, 'close' => 4640,
            ]);
        }

        $plan = $this->plan($this->signal());

        // M5 ATR is 4, so 1.5 x ATR is 6. The H1 series would give 24, and a blend of the
        // two something in between - which is what this used to produce.
        $this->assertEqualsWithDelta(4637.96 + 6.0, $plan['sl'], 0.01);
    }

    /**
     * Bars belonging to another account are not this account's market.
     */
    public function test_another_accounts_bars_do_not_size_this_stop(): void
    {
        $this->settings->update(['copier_levels' => SignalPlan::SOURCE_STRATEGY]);

        $other = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Other', 'broker_name' => 'Elsewhere',
            'account_number' => '999', 'server' => 'Other-Live', 'is_demo' => false, 'is_active' => false,
        ]);

        for ($i = 400; $i > 0; $i--) {
            Candle::create([
                'user_id' => $this->user->id, 'broker_account_id' => $other->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'M5',
                'open_time' => now()->subMinutes(5 * $i),
                'open' => 2300, 'high' => 2340, 'low' => 2260, 'close' => 2300,
            ]);
        }

        $plan = $this->plan($this->signal());

        $this->assertEqualsWithDelta(4637.96 + 6.0, $plan['sl'], 0.01);
    }

    /**
     * Providers do not reliably post their targets nearest-first.
     *
     * The executor sends `end($targets)` to the broker as the position's take-profit. On a
     * list that arrived furthest-first that is the nearest target, and the order gives up
     * the distance the provider actually asked for.
     */
    public function test_targets_are_ordered_by_distance_whatever_order_they_arrived_in(): void
    {
        $plan = $this->plan($this->signal([
            'direction' => 'buy', 'entry_price' => 4614.0, 'sl_price' => 4609.0,
            // As posted: "TP1: 4622, TP2: 4620".
            'tp_prices' => [4622.0, 4620.0],
        ]));

        $this->assertSame([4620.0, 4622.0], $plan['tps']);
        $this->assertSame(4622.0, end($plan['tps']), 'The order must carry the furthest target.');
    }

    /**
     * A "target" the trade would have to reverse to reach is a typo, not a target.
     */
    public function test_targets_behind_the_entry_are_dropped(): void
    {
        $plan = $this->plan($this->signal([
            'direction' => 'buy', 'entry_price' => 4614.0, 'sl_price' => 4609.0,
            'tp_prices' => [4622.0, 4600.0],
        ]));

        $this->assertSame([4622.0], $plan['tps']);
    }

    /**
     * What the card needs in order to explain a verdict about substituted levels.
     */
    public function test_the_summary_states_the_reward_to_risk_the_substitution_produces(): void
    {
        $this->settings->update(['copier_levels' => SignalPlan::SOURCE_STRATEGY]);

        $signal = $this->signal();
        $plan = $this->plan($signal);
        $summary = (new SignalPlan)->summary($plan);

        // 20.00 to the last rung against a 6.00 stop.
        $this->assertStringContainsString('3.33 : 1', $summary);
        $this->assertStringContainsString('M5', $summary);
    }
}

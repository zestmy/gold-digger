<?php

namespace Tests\Feature\Outcomes;

use App\Models\BotHeartbeat;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\SignalOutcome;
use App\Models\Strategy;
use App\Models\SymbolSpec;
use App\Models\TelegramSignal;
use App\Models\User;
use App\Services\Outcomes\OutcomeTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Signal outcome tracking.
 *
 * The rules are stated in the tracker's docblock; these pin each one, because a scoring
 * rule that drifts flatters or punishes every strategy at once and nothing else notices.
 */
class OutcomeTrackerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private Strategy $strategy;

    private Carbon $bar;

    private const SYMBOL = 'XAUUSDm';

    protected function setUp(): void
    {
        parent::setUp();

        config(['outcomes.horizon_bars' => 10]);

        $this->user = User::factory()->create();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);

        BotHeartbeat::create([
            'user_id' => $this->user->id, 'broker_account_id' => $this->account->id, 'source' => 'mql5_ea',
            'resolved_symbol' => self::SYMBOL, 'pip_size' => 0.10, 'last_seen_at' => now(),
        ]);

        $this->strategy = Strategy::where('user_id', $this->user->id)->firstOrFail();

        // The signal bar. Counting starts on the bar after it.
        $this->bar = Carbon::parse('2026-03-10 13:00:00', 'UTC');
    }

    // =====================================================================
    // OPENING
    // =====================================================================

    public function test_a_signal_opens_an_outcome_measured_from_the_bar_after_it(): void
    {
        $outcome = $this->tracker()->openForSignal($this->signal());

        $this->assertNotNull($outcome);
        $this->assertSame(SignalOutcome::SOURCE_AI, $outcome->source);
        $this->assertSame($this->account->id, $outcome->broker_account_id);
        $this->assertEqualsWithDelta(5.0, $outcome->risk, 1e-6);
        $this->assertTrue($outcome->started_at->equalTo($this->bar->copy()->addMinutes(5)));
        $this->assertSame(10, $outcome->horizon_bars);
        $this->assertSame('gold', $outcome->context['instrument']);
        $this->assertSame(13, $outcome->context['hour_utc']);
        $this->assertSame(78, $outcome->context['confidence']);
    }

    public function test_a_signal_with_no_stop_cannot_be_scored_and_is_not_opened(): void
    {
        $this->assertNull($this->tracker()->openForSignal($this->signal(['sl_price' => 2000.00])));
    }

    /**
     * A copied signal names no timeframe and the provider's symbol, not the broker's. It
     * is measured on the configured timeframe under the broker's own name.
     */
    public function test_a_copied_signal_opens_under_the_brokers_symbol_from_its_post_time(): void
    {
        SymbolSpec::updateOrCreate(
            ['broker_account_id' => $this->account->id, 'symbol' => self::SYMBOL],
            ['base_symbol' => 'XAUUSD', 'pip_size' => 0.10, 'digits' => 2, 'pip_value_per_lot' => 10.0, 'volume_min' => 0.01, 'volume_step' => 0.01],
        );

        $posted = $this->bar->copy()->addMinutes(2);

        $outcome = $this->tracker()->openForCopied($this->copied([
            'symbol' => 'XAUUSD', 'entry_price' => 2001.00, 'sl_price' => 1996.00, 'tp_prices' => [2004.0, 2011.0],
            'posted_at' => $posted,
        ]));

        $this->assertNotNull($outcome);
        $this->assertSame(SignalOutcome::SOURCE_COPIED, $outcome->source);
        $this->assertSame(self::SYMBOL, $outcome->symbol);
        $this->assertSame('M5', $outcome->timeframe);
        $this->assertTrue($outcome->started_at->equalTo($posted));
        $this->assertEqualsWithDelta(2004.0, $outcome->tp1_price, 1e-6);
        $this->assertNull($outcome->tp3_price);
    }

    /**
     * A market-order signal names no entry; the reference is where it would have filled.
     */
    public function test_a_copied_signal_without_an_entry_is_measured_from_the_last_close(): void
    {
        $this->bars([2000.0, 2002.0]); // 13:05 and 13:10

        $outcome = $this->tracker()->openForCopied($this->copied([
            'symbol' => self::SYMBOL, 'entry_price' => null, 'sl_price' => 1997.00, 'tp_prices' => [2006.0],
            'posted_at' => $this->bar->copy()->addMinutes(11),
        ]));

        $this->assertNotNull($outcome);
        $this->assertEqualsWithDelta(2002.0, $outcome->reference_price, 1e-6);
    }

    public function test_open_pending_opens_every_signal_that_has_no_outcome_once(): void
    {
        // Inside the backfill window, which the fixture bar in March is not.
        $recent = now()->subHour();

        $this->signal(['generated_at' => $recent]);
        $this->signal(['generated_at' => $recent->copy()->addMinutes(5)]);
        $this->copied(['symbol' => self::SYMBOL, 'entry_price' => 2000.0, 'sl_price' => 1995.0, 'tp_prices' => [2003.0], 'posted_at' => $recent]);

        $this->assertSame(3, $this->tracker()->openPending());
        $this->assertSame(0, $this->tracker()->openPending());
        $this->assertSame(3, SignalOutcome::count());
    }

    // =====================================================================
    // THE WALK
    // =====================================================================

    public function test_the_first_target_before_the_stop_is_a_win_and_the_walk_continues(): void
    {
        $outcome = $this->tracker()->openForSignal($this->signal());

        // Bar 1 drifts, bar 2 reaches TP1 (2003), bar 5 reaches TP2 (2010), bar 7 hits the stop.
        $this->bars([2001.0, 2002.5, 2004.0, 2006.0, 2009.5, 2007.0, 1996.0]);
        $this->tracker()->advance($this->account->id, self::SYMBOL, 'M5');

        $outcome->refresh();

        $this->assertSame(SignalOutcome::WON, $outcome->status);
        $this->assertSame('tp1', $outcome->first_hit);
        $this->assertSame(2, $outcome->tp1_bars);
        $this->assertSame(5, $outcome->tp2_bars);
        $this->assertNull($outcome->tp3_bars);
        $this->assertSame(7, $outcome->sl_bars);
        $this->assertNotNull($outcome->resolved_at);
        // Best: bar 5's high of 2010.5 is 2.1R; worst: bar 7's low of 1995.0 is -1R.
        $this->assertEqualsWithDelta(2.1, $outcome->mfe_r, 1e-3);
        $this->assertEqualsWithDelta(-1.0, $outcome->mae_r, 1e-3);
        $this->assertEqualsWithDelta(0.2, $outcome->r_at_1, 1e-3);
        $this->assertEqualsWithDelta(1.9, $outcome->r_at_5, 1e-3);
    }

    public function test_the_stop_before_the_first_target_is_a_loss(): void
    {
        $outcome = $this->tracker()->openForSignal($this->signal());

        $this->bars([2001.0, 1996.0, 2005.0]);
        $this->tracker()->advance($this->account->id, self::SYMBOL, 'M5');

        $outcome->refresh();

        $this->assertSame(SignalOutcome::LOST, $outcome->status);
        $this->assertSame('sl', $outcome->first_hit);
        $this->assertSame(2, $outcome->sl_bars);
        $this->assertNull($outcome->tp1_bars, 'A target reached after the stop is not credited.');
        $this->assertSame(2, $outcome->bars_seen, 'The walk ends at the stop.');
    }

    /**
     * The one rule that decides most arguments: when a bar spans both levels, the stop
     * is taken to have come first.
     */
    public function test_a_bar_that_reaches_both_levels_counts_as_the_stop_first(): void
    {
        $outcome = $this->tracker()->openForSignal($this->signal());

        Candle::create($this->candle($this->bar->copy()->addMinutes(5), open: 2000.0, high: 2004.0, low: 1994.0, close: 2001.0));
        $this->tracker()->advance($this->account->id, self::SYMBOL, 'M5');

        $outcome->refresh();

        $this->assertSame(SignalOutcome::LOST, $outcome->status);
        $this->assertNull($outcome->tp1_bars);
        $this->assertSame(1, $outcome->sl_bars);
    }

    public function test_neither_level_within_the_horizon_is_expired(): void
    {
        $outcome = $this->tracker()->openForSignal($this->signal());

        $this->bars(array_fill(0, 12, 2001.0));
        $this->tracker()->advance($this->account->id, self::SYMBOL, 'M5');

        $outcome->refresh();

        $this->assertSame(SignalOutcome::EXPIRED, $outcome->status);
        $this->assertNull($outcome->first_hit);
        $this->assertSame(10, $outcome->bars_seen, 'The walk stops at the horizon.');
        $this->assertNotNull($outcome->resolved_at);
    }

    public function test_a_sell_is_measured_the_other_way_up(): void
    {
        $outcome = $this->tracker()->openForSignal($this->signal([
            'direction' => 'sell', 'sl_price' => 2005.00, 'tp1_price' => 1997.00, 'tp2_price' => 1990.00, 'tp3_price' => 1980.00,
        ]));

        $this->bars([1999.0, 1997.5]); // bar 2's low of 1996.5 reaches TP1 at 1997
        $this->tracker()->advance($this->account->id, self::SYMBOL, 'M5');

        $outcome->refresh();

        $this->assertSame(SignalOutcome::WON, $outcome->status);
        $this->assertSame(2, $outcome->tp1_bars);
        $this->assertEqualsWithDelta(0.7, $outcome->mfe_r, 1e-3);
    }

    /**
     * The push and the schedule both advance; a bar must never be counted twice.
     */
    public function test_advancing_twice_over_the_same_bars_changes_nothing(): void
    {
        $outcome = $this->tracker()->openForSignal($this->signal());

        $this->bars([2001.0, 2002.0]);
        $this->tracker()->advance($this->account->id, self::SYMBOL, 'M5');
        $changed = $this->tracker()->advance($this->account->id, self::SYMBOL, 'M5');

        $this->assertSame(0, $changed);
        $this->assertSame(2, $outcome->fresh()->bars_seen);

        // A later bar is picked up from where the walk left off.
        Candle::create($this->candle($this->bar->copy()->addMinutes(15), 2002.0, 2003.5, 2001.0, 2003.0));
        $this->tracker()->advance($this->account->id, self::SYMBOL, 'M5');

        $this->assertSame(3, $outcome->fresh()->bars_seen);
        $this->assertSame(SignalOutcome::WON, $outcome->fresh()->status);
    }

    public function test_bars_before_the_signal_are_never_counted(): void
    {
        $outcome = $this->tracker()->openForSignal($this->signal());

        // A bar from before the signal that would have hit the stop.
        Candle::create($this->candle($this->bar->copy()->subMinutes(5), 2000.0, 2001.0, 1990.0, 2000.0));
        $this->bars([2001.0]);
        $this->tracker()->advance($this->account->id, self::SYMBOL, 'M5');

        $this->assertSame(SignalOutcome::OPEN, $outcome->fresh()->status);
        $this->assertSame(1, $outcome->fresh()->bars_seen);
    }

    public function test_advance_all_covers_every_series_with_open_outcomes(): void
    {
        $this->tracker()->openForSignal($this->signal());
        $this->bars([2001.0, 2004.0]);

        $this->assertSame(1, $this->tracker()->advanceAll());
        $this->assertSame(SignalOutcome::WON, SignalOutcome::sole()->status);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function tracker(): OutcomeTracker
    {
        return app(OutcomeTracker::class);
    }

    /**
     * A buy at 2000 with the stop 5.00 below and the ladder at 2003 / 2010 / 2020.
     */
    private function signal(array $overrides = []): Signal
    {
        return Signal::create(array_merge([
            'strategy_id' => $this->strategy->id,
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'direction' => 'buy',
            'entry_price' => 2000.00,
            'sl_price' => 1995.00,
            'tp1_price' => 2003.00,
            'tp2_price' => 2010.00,
            'tp3_price' => 2020.00,
            'features' => ['adx' => 28.0, 'atr' => 3.2, 'quality' => ['confidence' => 78, 'grade' => 'B', 'risk' => 'MEDIUM']],
            'was_executed' => false,
            'generated_at' => $this->bar,
        ], $overrides));
    }

    private function copied(array $overrides = []): TelegramSignal
    {
        $messageId = random_int(1000, 99999);

        return TelegramSignal::create(array_merge([
            'user_id' => $this->user->id,
            'external_id' => "-1001:{$messageId}",
            'chat_id' => '-1001',
            'chat_title' => 'FTC 2026',
            'message_id' => $messageId,
            'raw_text' => 'GOLD SELL',
            'kind' => TelegramSignal::KIND_SIGNAL,
            'parse_status' => TelegramSignal::PARSE_OK,
            'direction' => 'buy',
            'posted_at' => $this->bar->copy()->addMinutes(1),
        ], $overrides));
    }

    /**
     * Closes for consecutive M5 bars after the signal bar; each bar's high is +1 and low
     * -1 around its close, so a level is reached when a close comes within 1.0 of it.
     *
     * @param  array<int, float>  $closes
     */
    private function bars(array $closes): void
    {
        foreach ($closes as $i => $close) {
            Candle::create($this->candle($this->bar->copy()->addMinutes(5 * ($i + 1)), $close, $close + 1.0, $close - 1.0, $close));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function candle(Carbon $openTime, float $open, float $high, float $low, float $close): array
    {
        return [
            'user_id' => $this->user->id,
            'broker_account_id' => $this->account->id,
            'symbol' => self::SYMBOL,
            'timeframe' => 'M5',
            'open_time' => $openTime,
            'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close,
        ];
    }
}

<?php

namespace Tests\Feature\Bot;

use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A position is recorded with the stop it actually has.
 *
 * The strategy path deliberately keeps `sl_price` off the wire - the EA places the stop
 * relative to the fill, and a bar-close level would override that - and carries the
 * intended level under a different key. The fill controller read only the wire key, so
 * every bot trade landed with sl_price 0 and initial_sl_price null. For a sell, a stop of
 * zero is below every level it could be moved to, which read as "already beyond
 * break-even": neither the break-even move nor the trail was ever queued.
 */
class OpenFillStopTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);

        [$this->token] = BotToken::generate($this->user, 'Terminal', $this->account);
    }

    public function test_the_stop_the_strategy_intended_is_recorded_when_the_terminal_sends_none(): void
    {
        $command = TradeCommand::enqueue($this->user, 'open', [
            'symbol' => 'XAUUSD', 'direction' => 'sell', 'sl_pips' => 30, 'intended_sl_price' => 4003.00,
        ], $this->account);

        $this->open($command, ['direction' => 'sell', 'price' => 4000.00])->assertCreated();

        $trade = Trade::sole();

        $this->assertEqualsWithDelta(4003.00, (float) $trade->sl_price, 1e-6);
        $this->assertEqualsWithDelta(4003.00, (float) $trade->initial_sl_price, 1e-6);
    }

    /**
     * The terminal's figure is the one the broker accepted, after clamping - so where it
     * sends one, that is the truth and the intention is only a fallback.
     */
    public function test_the_stop_the_terminal_placed_wins_over_the_intention(): void
    {
        $command = TradeCommand::enqueue($this->user, 'open', [
            'symbol' => 'XAUUSD', 'direction' => 'sell', 'intended_sl_price' => 4003.00,
        ], $this->account);

        $this->open($command, ['direction' => 'sell', 'price' => 4000.00, 'sl' => 4003.40])->assertCreated();

        $trade = Trade::sole();

        $this->assertEqualsWithDelta(4003.40, (float) $trade->sl_price, 1e-6);
        $this->assertEqualsWithDelta(4003.40, (float) $trade->initial_sl_price, 1e-6);
    }

    /**
     * Zero from the terminal means "this position has no stop". It is not a price, and
     * stored as one it would be measured from.
     */
    public function test_a_zero_stop_from_the_terminal_falls_back_to_the_intention(): void
    {
        $command = TradeCommand::enqueue($this->user, 'open', [
            'symbol' => 'XAUUSD', 'direction' => 'buy', 'intended_sl_price' => 3997.00,
        ], $this->account);

        $this->open($command, ['direction' => 'buy', 'price' => 4000.00, 'sl' => 0])->assertCreated();

        $this->assertEqualsWithDelta(3997.00, (float) Trade::sole()->initial_sl_price, 1e-6);
    }

    /**
     * The copier sends an absolute `sl_price` for a resting order, and that key must keep
     * working as it did.
     */
    public function test_an_absolute_stop_on_the_command_is_still_read(): void
    {
        $command = TradeCommand::enqueue($this->user, 'open_pending', [
            'symbol' => 'XAUUSD', 'direction' => 'buy', 'sl_price' => 3990.00, 'entry_price' => 3998.00,
        ], $this->account);

        $this->open($command, ['direction' => 'buy', 'price' => 3998.00])->assertCreated();

        $this->assertEqualsWithDelta(3990.00, (float) Trade::sole()->initial_sl_price, 1e-6);
    }

    public function test_with_no_stop_anywhere_the_opening_risk_is_unknown_rather_than_zero(): void
    {
        $command = TradeCommand::enqueue($this->user, 'open', ['symbol' => 'XAUUSD'], $this->account);

        $this->open($command, ['direction' => 'buy', 'price' => 4000.00])->assertCreated();

        $trade = Trade::sole();

        $this->assertNull($trade->initial_sl_price);
        $this->assertEqualsWithDelta(0.0, (float) $trade->sl_price, 1e-6);
    }

    private function open(TradeCommand $command, array $overrides)
    {
        return $this->postJson('/api/v1/bot/fills', array_merge([
            'event' => 'opened',
            'command_id' => $command->id,
            'ticket' => 910001,
            'symbol' => 'XAUUSDm',
            'direction' => 'buy',
            'volume' => 0.05,
            'price' => 4000.00,
        ], $overrides), ['Authorization' => 'Bearer '.$this->token]);
    }
}

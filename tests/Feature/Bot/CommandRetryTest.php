<?php

namespace Tests\Feature\Bot;

use App\Models\BrokerAccount;
use App\Models\TradeCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A command that came to nothing can be asked for again.
 *
 * The strategy's idempotency keys are fixed for the life of a position - one close per
 * rung, one break-even move - and enqueue collapsed unconditionally into whatever row held
 * the key. So a close the broker rejected once, or one a terminal claimed and then died
 * holding, was never re-issued: every later bar found the dead row and returned it, and
 * the position ran on to its broker stop while the strategy believed it had acted.
 */
class CommandRetryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Demo', 'broker_name' => 'Elev8',
            'account_number' => '1', 'server' => 'Elev8-Demo2', 'is_demo' => true, 'is_active' => true,
        ]);
    }

    public function test_a_failed_command_is_re_armed_when_asked_for_again(): void
    {
        $first = $this->enqueueClose(volume: 0.50);
        $first->markFailed('10016 invalid stops', ['retcode' => 10016]);

        $again = $this->enqueueClose(volume: 0.40);

        $this->assertSame($first->id, $again->id, 'Still one row per key - re-armed, not duplicated.');
        $this->assertSame('pending', $again->status);
        $this->assertNull($again->error);
        $this->assertNull($again->result);
        $this->assertNull($again->completed_at);
        $this->assertEqualsWithDelta(0.40, (float) $again->payload['volume'], 1e-9, 'The fresh payload replaces the stale one.');
        $this->assertSame(1, TradeCommand::count());
    }

    public function test_an_expired_command_is_re_armed_with_a_fresh_expiry(): void
    {
        $first = TradeCommand::enqueue($this->user, 'open', ['symbol' => 'XAUUSD'], $this->account, 'signal:1', 60);

        $this->travel(2)->minutes();
        $this->assertSame(1, TradeCommand::sweepExpired());
        $this->assertSame('expired', $first->fresh()->status);

        $again = TradeCommand::enqueue($this->user, 'open', ['symbol' => 'XAUUSD'], $this->account, 'signal:1', 60);

        $this->assertSame('pending', $again->status);
        $this->assertTrue($again->expires_at->isFuture());
    }

    public function test_the_attempt_count_survives_a_re_arm(): void
    {
        $command = $this->enqueueClose(volume: 0.50);
        TradeCommand::claimBatch($this->user->id, $this->account->id);
        $command->fresh()->markFailed('rejected');

        $again = $this->enqueueClose(volume: 0.50);

        $this->assertSame(1, $again->attempts, 'A re-arm is a new attempt at the same instruction, not a new instruction.');
    }

    public function test_a_done_command_is_not_re_armed(): void
    {
        $first = $this->enqueueClose(volume: 0.50);
        $first->markDone(['retcode' => 10009]);

        $again = $this->enqueueClose(volume: 0.50);

        $this->assertSame('done', $again->status, 'A close that happened must not be asked for twice.');
    }

    public function test_pending_and_claimed_commands_still_collapse(): void
    {
        $pending = $this->enqueueClose(volume: 0.50);
        $this->assertSame($pending->id, $this->enqueueClose(volume: 0.50)->id);
        $this->assertSame('pending', $pending->fresh()->status);

        TradeCommand::claimBatch($this->user->id, $this->account->id);
        $this->assertSame('claimed', $this->enqueueClose(volume: 0.50)->status);
    }

    /**
     * Closes and stop moves carry no expiry on purpose - a late exit is still the exit -
     * which leaves one way for them to stick: claimed by a terminal that never reported.
     */
    public function test_a_claim_nobody_ever_completed_is_swept_after_ten_minutes(): void
    {
        $command = $this->enqueueClose(volume: 0.50);
        TradeCommand::claimBatch($this->user->id, $this->account->id);

        $this->travel(TradeCommand::STALE_CLAIM_MINUTES - 1)->minutes();
        $this->assertSame(0, TradeCommand::sweepExpired(), 'Executing a command takes seconds, but not zero.');
        $this->assertSame('claimed', $command->fresh()->status);

        $this->travel(2)->minutes();
        $this->assertSame(1, TradeCommand::sweepExpired());

        $swept = $command->fresh();
        $this->assertSame('expired', $swept->status);
        $this->assertStringContainsString('never completed', $swept->error);

        // And the next evaluation can re-issue it.
        $this->assertSame('pending', $this->enqueueClose(volume: 0.50)->status);
    }

    public function test_a_pending_command_with_no_expiry_is_never_swept(): void
    {
        $this->enqueueClose(volume: 0.50);

        $this->travel(1)->day();

        $this->assertSame(0, TradeCommand::sweepExpired());
    }

    private function enqueueClose(float $volume): TradeCommand
    {
        return TradeCommand::enqueue(
            user: $this->user,
            type: 'close',
            payload: ['symbol' => 'XAUUSD', 'ticket' => 900001, 'volume' => $volume, 'reason' => 'tp1', 'trade_id' => null],
            account: $this->account,
            idempotencyKey: 'close:1:tp1',
            expiresInSeconds: null,
        );
    }
}

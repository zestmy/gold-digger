<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Services\Strategy\SignalGenerator;
use App\Services\Strategy\TradeManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Evaluate New Bars
 *
 * Manage open positions and look for entries, for every active strategy whose entry timeframe
 * just closed a bar.
 *
 * The same work `CandleController` does inline - deliberately the same call sequence rather
 * than a reimplementation, so switching `trading.queue_evaluation` changes when the work
 * happens and never what it does.
 *
 * ## Positions before entries
 *
 * A reversal or timeout exit on this bar should be queued ahead of the same bar's new entry,
 * so the executor claims them in the order the strategy meant: out of the old trade, then into
 * the new one.
 *
 * ## Why it is unique rather than merely queued
 *
 * The executor re-pushes a trailing window on every poll, and a burst of pushes for the same
 * account and timeframe would otherwise queue a job apiece - all doing identical work against
 * identical data. Uniqueness collapses them, and the underlying operations are idempotent
 * anyway: one signal per strategy per bar, and fixed idempotency keys on every command.
 *
 * ## Failure
 *
 * Retried three times and then left in `failed_jobs`. A permanently failing evaluation is a
 * bug worth seeing rather than a job worth retrying forever - and the bars are already stored,
 * so nothing is lost by giving up: the next bar's push evaluates the same series again.
 */
class EvaluateNewBars implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * Long enough to outlive a slow evaluation, short enough that a worker killed mid-job
     * does not block the next push for the rest of the day.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $userId,
        public readonly string $timeframe,
        public readonly ?int $brokerAccountId,
    ) {
        $this->onQueue(config('trading.queue', 'strategy'));
    }

    /**
     * One in flight per account and timeframe. Not per user: two accounts are two independent
     * executors, and making one wait for the other would be a queue of its own making.
     */
    public function uniqueId(): string
    {
        return $this->userId.':'.($this->brokerAccountId ?? 'any').':'.$this->timeframe;
    }

    public function handle(TradeManager $manager, SignalGenerator $generator): void
    {
        foreach ($this->strategies() as $strategy) {
            $manager->manage($strategy, $this->brokerAccountId);
        }

        foreach ($this->strategies() as $strategy) {
            $generator->generate($strategy, $this->brokerAccountId);
        }
    }

    /**
     * Active strategies of this user whose *entry* timeframe just closed a bar.
     *
     * Re-read for each pass rather than cached: managing a position can change a strategy's
     * open trades, and the entry pass should see that.
     *
     * @return Collection<int, Strategy>
     */
    private function strategies()
    {
        return Strategy::where('user_id', $this->userId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Strategy $s) => strtoupper($s->timeframe_entry) === $this->timeframe);
    }
}

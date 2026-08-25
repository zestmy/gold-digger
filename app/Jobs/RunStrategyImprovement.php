<?php

namespace App\Jobs;

use App\Models\StrategyImprovement as ImprovementRun;
use App\Services\Ai\StrategyImprovement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run the improver off the request.
 *
 * A walk-forward over twenty thousand bars takes minutes. Doing it inside a Livewire
 * request would hit PHP's execution limit, the web server's, and the user's patience, in
 * that order - and the dashboard this runs beside is the one someone watches a live
 * account on.
 *
 * ## Memory
 *
 * This is the heaviest thing in the application by a wide margin, and it runs on a 1GB
 * droplet whose MySQL already holds 400MB. The bar window is bounded by
 * StrategyImprovement::DEFAULT_BARS for that reason, and the worker is configured with a
 * memory ceiling that kills it cleanly rather than letting the kernel choose what to
 * reap - because what the kernel usually chooses is MySQL, and the trading dashboard goes
 * with it.
 */
class RunStrategyImprovement implements ShouldQueue
{
    use Queueable;

    /** One attempt. A run that died on memory will die the same way again, twice as slowly. */
    public int $tries = 1;

    /** Generous, because the work genuinely takes minutes and a timeout mid-fold tells you nothing. */
    public int $timeout = 1800;

    public function __construct(public readonly int $runId) {}

    public function handle(StrategyImprovement $improver): void
    {
        $run = ImprovementRun::with('strategy')->find($this->runId);

        if ($run === null || $run->strategy === null) {
            return;
        }

        $run->update(['status' => ImprovementRun::STATUS_RUNNING, 'started_at' => now()]);

        try {
            $result = $improver->run($run->strategy, $run->options ?? []);
        } catch (Throwable $e) {
            // Including the message: "it failed" on a dashboard is the same as silence,
            // and an out-of-memory kill reads very differently from a rejected API key.
            $this->fail($run, $e->getMessage());

            return;
        }

        if (! ($result['ok'] ?? false)) {
            $this->fail($run, $result['error'] ?? 'The run produced no result.');

            return;
        }

        $run->update([
            'status' => ImprovementRun::STATUS_DONE,
            'baseline' => $result['baseline'],
            'proposed' => $result['proposed'],
            'proposals' => $result['proposals'],
            'thin' => $result['thin'],
            'verdict' => $result['verdict'],
            'model' => $result['model'],
            'options' => array_merge($run->options ?? [], [
                'symbol' => $result['symbol'],
                'bars' => $result['bars'],
                'from' => $result['from'],
                'to' => $result['to'],
            ]),
            'finished_at' => now(),
        ]);
    }

    /**
     * A worker killed for memory never reaches handle()'s catch, so the row would sit at
     * `running` for ever. Laravel calls this on failure, including that one.
     */
    public function failed(Throwable $e): void
    {
        $run = ImprovementRun::find($this->runId);

        if ($run !== null && ! $run->isFinished()) {
            $this->fail($run, $e->getMessage());
        }
    }

    private function fail(ImprovementRun $run, string $message): void
    {
        Log::warning("[improvement {$run->id}] {$message}");

        $run->update([
            'status' => ImprovementRun::STATUS_FAILED,
            'error' => mb_substr($message, 0, 1000),
            'finished_at' => now(),
        ]);
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Outcomes\OutcomeTracker;
use Illuminate\Console\Command;

/**
 * Open tracking for every signal that lacks it, and walk every unresolved one forward.
 *
 * Scheduled every five minutes as the correction; the candle push is the prompt trigger
 * and advances the series that just grew. Neither depends on the other being there.
 */
class TrackSignalOutcomes extends Command
{
    protected $signature = 'signals:track
                            {--backfill-days= : Look this far back for signals never opened for tracking (default: config outcomes.backfill_days)}';

    protected $description = 'Record what became of every signal, from the bars that followed it';

    public function handle(OutcomeTracker $tracker): int
    {
        $days = $this->option('backfill-days');

        $opened = $tracker->openPending($days === null ? null : (int) $days);
        $advanced = $tracker->advanceAll();

        $this->info("Opened {$opened} outcome(s), advanced {$advanced}.");

        return self::SUCCESS;
    }
}

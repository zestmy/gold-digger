<?php

namespace App\Console\Commands;

use App\Models\TelegramSignal;
use App\Services\Telegram\SignalReviewer;
use Illuminate\Console\Command;

/**
 * Review captured signals that have not been reviewed.
 *
 * Separate from ingest so a review failure never costs a capture. A message that arrived
 * is worth keeping whatever happens next, and re-reviewing is cheap where re-fetching is
 * impossible.
 */
class ReviewTelegramSignals extends Command
{
    protected $signature = 'telegram:review {--limit=10 : Most signals to review in one pass}';

    protected $description = 'Have the AI review captured Telegram signals';

    public function handle(SignalReviewer $reviewer): int
    {
        $pending = TelegramSignal::awaitingReview()
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nothing awaiting review.');

            return self::SUCCESS;
        }

        $approved = 0;

        foreach ($pending as $signal) {
            $verdict = $reviewer->review($signal);

            $signal->update([
                'review_status' => $verdict['status'],
                'review_reasoning' => $verdict['reasoning'],
                'review_confidence' => $verdict['confidence'],
                'review_model' => $verdict['model'],
                'reviewed_at' => now(),
            ]);

            $approved += $verdict['status'] === TelegramSignal::REVIEW_APPROVED ? 1 : 0;

            $this->line(sprintf(
                '  #%d %s %s -> <options=bold>%s</>%s',
                $signal->id,
                $signal->symbol,
                strtoupper((string) $signal->direction),
                strtoupper($verdict['status']),
                $verdict['confidence'] === null ? '' : " ({$verdict['confidence']}%)",
            ));
            $this->line('     <fg=gray>'.$verdict['reasoning'].'</>');
        }

        $this->newLine();
        $this->info(sprintf('%d reviewed, %d approved.', $pending->count(), $approved));

        // Stated because it is the number that matters. A reviewer approving most of what
        // it sees is a finding about the reviewer, not about the signals.
        if ($pending->count() > 0 && $approved === $pending->count()) {
            $this->warn('  Everything was approved. That is worth being suspicious of.');
        }

        return self::SUCCESS;
    }
}

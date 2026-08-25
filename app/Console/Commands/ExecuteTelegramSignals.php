<?php

namespace App\Console\Commands;

use App\Models\TelegramSignal;
use App\Services\Monitoring\AlertNotifier;
use App\Services\Telegram\SignalExecutor;
use Illuminate\Console\Command;

/**
 * Queue orders for approved signals.
 *
 * Separate from review so approval and execution are distinct acts, and so the step that
 * places real orders can be run deliberately - or scheduled, once you trust it.
 *
 * ## Why this announces and the dashboard button does not
 *
 * The Execute button on the Copier page calls the executor directly. Somebody who pressed
 * it already knows, and telling them would be noise.
 *
 * This path is the unattended one. An autonomous copier that opens positions silently is
 * indistinguishable from one that has stopped working, and the difference should not
 * require opening the dashboard to discover - so every order this queues is announced,
 * with the numbers that let it be judged without going and looking.
 */
class ExecuteTelegramSignals extends Command
{
    protected $signature = 'telegram:execute {--limit=5 : Most signals to act on in one pass}
                            {--quiet-announce : Queue without announcing, for testing}';

    protected $description = 'Queue orders for approved Telegram signals';

    public function handle(SignalExecutor $executor, AlertNotifier $notifier): int
    {
        $approved = TelegramSignal::with('channel')
            ->where('review_status', TelegramSignal::REVIEW_APPROVED)
            ->where('execution_status', TelegramSignal::EXEC_NONE)
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($approved->isEmpty()) {
            $this->info('Nothing approved and waiting.');

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($approved as $signal) {
            $result = $executor->execute($signal);
            $queued += $result['ok'] ? 1 : 0;

            if ($result['ok'] && ! $this->option('quiet-announce')) {
                $this->announce($notifier, $signal->fresh());
            }

            $this->line(sprintf(
                '  #%d %s %s -> <options=bold>%s</>',
                $signal->id,
                $signal->symbol,
                strtoupper((string) $signal->direction),
                strtoupper($result['status']),
            ));
            $this->line('     <fg=gray>'.$result['note'].'</>');
        }

        $this->newLine();
        $this->info(sprintf('%d approved, %d queued.', $approved->count(), $queued));

        return self::SUCCESS;
    }

    /**
     * Say what was opened, on whose signal, and what it risks.
     *
     * Delivery failure is swallowed by the notifier and the line is still written to
     * /logs, so an unreachable Telegram cannot stop the copier from trading or from
     * recording that it did.
     */
    private function announce(AlertNotifier $notifier, TelegramSignal $signal): void
    {
        $notifier->announce(
            sprintf(
                'Copier opened %s %s',
                strtoupper((string) $signal->direction),
                $signal->symbol,
            ),
            implode("\n", array_filter([
                $signal->execution_note,
                'Source: '.($signal->channel?->label() ?? $signal->chat_title ?? 'unknown'),
                $signal->review_confidence === null
                    ? null
                    : "Reviewer confidence: {$signal->review_confidence}%",
                'Nobody pressed anything - this was the scheduler.',
            ])),
            '🟡',
            [
                'telegram_signal_id' => $signal->id,
                'symbol' => $signal->symbol,
                'direction' => $signal->direction,
                'channel' => $signal->channel?->label(),
            ],
        );
    }
}

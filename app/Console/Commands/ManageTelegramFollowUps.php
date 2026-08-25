<?php

namespace App\Console\Commands;

use App\Models\TelegramSignal;
use App\Services\Monitoring\AlertNotifier;
use App\Services\Telegram\FollowUpExecutor;
use App\Services\Telegram\FollowUpInterpreter;
use Illuminate\Console\Command;

/**
 * Interpret replies to signals, and carry out what they instruct.
 *
 * Interpretation and execution are one command rather than two because, unlike a signal, a
 * management instruction has a short useful life and no review stage between them. "Secure
 * half" is worth acting on within the minute it was posted; a minute later the price that
 * made it worth securing may be gone.
 *
 * Every order this places is announced for the same reason the copier's own executions
 * are: nobody is watching, and a position that silently halved is indistinguishable from
 * one that was stopped out.
 */
class ManageTelegramFollowUps extends Command
{
    protected $signature = 'telegram:follow-up {--limit=10 : Most replies to handle in one pass}
                            {--quiet-announce : Act without announcing, for testing}';

    protected $description = 'Interpret and carry out follow-up instructions on copied positions';

    public function handle(FollowUpInterpreter $interpreter, FollowUpExecutor $executor, AlertNotifier $notifier): int
    {
        $pending = TelegramSignal::with(['parent.trade', 'channel'])
            ->awaitingInterpretation()
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No follow-ups awaiting interpretation.');

            return self::SUCCESS;
        }

        $acted = 0;

        foreach ($pending as $followUp) {
            $reading = $interpreter->interpret($followUp);

            $followUp->update([
                'follow_up_action' => $reading['action'],
                'follow_up_fraction' => $reading['fraction'],
                'follow_up_price' => $reading['price'],
                'review_reasoning' => $reading['reasoning'],
                'review_confidence' => $reading['confidence'],
                'review_model' => $reading['model'],
                'reviewed_at' => now(),
            ]);

            $this->line(sprintf(
                '  #%d -> <options=bold>%s</>%s',
                $followUp->id,
                strtoupper($reading['action']),
                $reading['confidence'] === null ? '' : " ({$reading['confidence']}%)",
            ));
            $this->line('     <fg=gray>'.$reading['reasoning'].'</>');

            if ($reading['action'] === TelegramSignal::FOLLOW_NONE) {
                // Commentary. Marked so it is not reconsidered every minute for ever.
                $followUp->update([
                    'execution_status' => TelegramSignal::EXEC_NONE,
                    'execution_note' => 'Nothing instructed.',
                ]);

                continue;
            }

            $result = $executor->execute($followUp->fresh());
            $acted += $result['ok'] ? 1 : 0;

            $this->line('     <fg=gray>'.$result['note'].'</>');

            if ($result['ok'] && ! $this->option('quiet-announce')) {
                $this->announce($notifier, $followUp->fresh(), $reading, $result['note']);
            }
        }

        $this->newLine();
        $this->info(sprintf('%d interpreted, %d acted on.', $pending->count(), $acted));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $reading
     */
    private function announce(AlertNotifier $notifier, TelegramSignal $followUp, array $reading, string $note): void
    {
        $notifier->announce(
            sprintf('Copier managed %s', $followUp->parent?->symbol ?? 'a position'),
            implode("\n", array_filter([
                $note,
                'Instruction: "'.mb_substr(trim($followUp->raw_text), 0, 160).'"',
                'Read as: '.$reading['action'].($reading['confidence'] === null ? '' : " ({$reading['confidence']}%)"),
                'Source: '.($followUp->channel?->label() ?? $followUp->chat_title ?? 'unknown'),
            ])),
            '🔵',
            [
                'telegram_signal_id' => $followUp->id,
                'action' => $reading['action'],
                'parent_signal_id' => $followUp->parent_signal_id,
            ],
        );
    }
}

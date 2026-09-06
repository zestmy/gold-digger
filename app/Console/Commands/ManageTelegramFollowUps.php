<?php

namespace App\Console\Commands;

use App\Models\TelegramSignal;
use App\Services\Monitoring\AlertNotifier;
use App\Services\Telegram\FollowUpExecutor;
use App\Services\Telegram\FollowUpInterpreter;
use App\Support\Tenancy\Tenant;
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
    /**
     * Below this, a reading is commentary however it was labelled.
     *
     * The interpreter's prompt tells the model that anything under sixty should almost
     * always be `none`, and the schema says the same - but a prompt is a request, not a
     * gate. A model that answers `secure_partial` at 35% has told you it is guessing, and
     * a guess about somebody else's position is exactly what this path must not act on.
     */
    public const MIN_CONFIDENCE = 60;

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
            // Interpretation is a model call, so it runs as the tenant whose position the
            // instruction concerns - putting it on their allowance rather than on nobody's.
            $reading = $this->gated(
                Tenant::for($followUp->user_id, fn () => $interpreter->interpret($followUp)),
            );

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

            // A layer goes through the signal executor, which re-runs the reviewer - a
            // model call, and one that has to be charged to the position's owner for the
            // same reason interpretation is.
            $result = Tenant::for($followUp->user_id, fn () => $executor->execute($followUp->fresh()));
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
     * Demote a low-confidence reading to `none`, saying so.
     *
     * The action is replaced rather than the row discarded, so the record still shows
     * what the model thought it read and why it was not acted on. A provider whose
     * instructions keep landing just under the line is something worth being able to see.
     *
     * @param  array{action: string, fraction: float|null, price: float|null, confidence: int|null, reasoning: string, model: string|null}  $reading
     * @return array{action: string, fraction: float|null, price: float|null, confidence: int|null, reasoning: string, model: string|null}
     */
    private function gated(array $reading): array
    {
        if ($reading['action'] === TelegramSignal::FOLLOW_NONE || $reading['confidence'] === null) {
            return $reading;
        }

        if ($reading['confidence'] >= self::MIN_CONFIDENCE) {
            return $reading;
        }

        return [
            'action' => TelegramSignal::FOLLOW_NONE,
            'fraction' => null,
            'price' => null,
            'confidence' => $reading['confidence'],
            'reasoning' => sprintf(
                'Read as %s at %d%% confidence, below the %d%% needed to act on it. %s',
                $reading['action'],
                $reading['confidence'],
                self::MIN_CONFIDENCE,
                $reading['reasoning'],
            ),
            'model' => $reading['model'],
        ];
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
            // Whose position was just moved - see the note in ExecuteTelegramSignals.
            $followUp->user_id,
        );
    }
}

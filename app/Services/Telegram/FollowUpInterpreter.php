<?php

namespace App\Services\Telegram;

use App\Models\TelegramSignal;
use App\Services\Ai\OpenRouter;

/**
 * Follow-Up Interpreter
 *
 * Turns a reply like "secure half" into one of a closed set of actions.
 *
 * ## Why the output is an enum and never prose
 *
 * The model reads a sentence written by a stranger and its answer moves real positions.
 * Letting it return anything expressive would mean execution has to interpret text, and
 * the failure mode of interpreting text about money is not a wrong number - it is an
 * action nobody has considered. So the schema admits six actions and two operands, and
 * anything the model cannot map onto them comes back as `none`.
 *
 * "Unrecognised" is therefore a safe outcome by construction: the position is simply left
 * as the signal set it up, which is what would have happened without this feature.
 *
 * ## Ambiguity resolves toward doing less
 *
 * "Secure profit", "close some", "TP1 hit" all plausibly mean take part off, and the
 * fraction is rarely stated. Guessing high books more of a winner than instructed;
 * guessing low leaves risk on that the provider wanted gone. Neither is free, but only one
 * of them can lose money that was already made, so an unstated fraction is a half.
 *
 * ## What it is never allowed to conclude
 *
 * There is no action for widening a stop, and none for reversing direction. A provider who
 * posts "move SL to 4590" on a long entered at 4608 is asking for more room, and a copier
 * that obliges has converted a bounded loss into a larger one on the strength of a
 * sentence. `move_stop` exists, and the executor refuses any level that increases risk -
 * this class does not have to be the thing that gets that right, and deliberately is not.
 */
final class FollowUpInterpreter
{
    public function __construct(private readonly OpenRouter $router = new OpenRouter) {}

    /**
     * @return array{action: string, fraction: float|null, price: float|null, confidence: int|null, reasoning: string, model: string|null}
     */
    public function interpret(TelegramSignal $followUp): array
    {
        $parent = $followUp->parent;

        if ($parent === null) {
            return $this->nothing('The message it replies to was never captured, so there is no position it could refer to.');
        }

        if ($parent->trade === null) {
            return $this->nothing('The signal this replies to never became a position on this account.');
        }

        if (! $this->router->configured()) {
            // Consistent with the reviewer: no key means no action, never a guessed one.
            return $this->nothing('No OPENROUTER_API_KEY is configured, so the instruction cannot be interpreted.');
        }

        $result = $this->router->structured(
            model: (string) config('ai.reviewer_model'),
            system: $this->systemPrompt(),
            brief: $this->brief($followUp, $parent),
            schemaName: 'follow_up_instruction',
            schema: $this->schema(),
        );

        if (! $result['ok']) {
            return $this->nothing('The instruction could not be interpreted: '.($result['error'] ?? 'unknown error').'.');
        }

        $action = (string) ($result['data']['action'] ?? TelegramSignal::FOLLOW_NONE);

        if (! in_array($action, $this->actions(), true)) {
            return $this->nothing("The model returned an action this copier does not implement ({$action}).");
        }

        $fraction = $result['data']['fraction'] ?? null;
        $price = $result['data']['price'] ?? null;

        return [
            'action' => $action,
            // An unstated fraction on a partial is a half; see the class note. Clamped
            // because a model returning 1.0 for "secure half" would close the position.
            'fraction' => $action === TelegramSignal::FOLLOW_PARTIAL
                ? min(0.9, max(0.1, (float) ($fraction ?: 0.5)))
                : null,
            'price' => $action === TelegramSignal::FOLLOW_MOVE_STOP && $price !== null
                ? (float) $price
                : null,
            'confidence' => (int) ($result['data']['confidence'] ?? 0),
            'reasoning' => trim((string) ($result['data']['reasoning'] ?? '')) ?: 'No reasoning given.',
            'model' => $result['model'] ?? null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function actions(): array
    {
        return [
            TelegramSignal::FOLLOW_NONE,
            TelegramSignal::FOLLOW_PARTIAL,
            TelegramSignal::FOLLOW_BREAKEVEN,
            TelegramSignal::FOLLOW_CLOSE,
            TelegramSignal::FOLLOW_MOVE_STOP,
            TelegramSignal::FOLLOW_ADD,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You read follow-up messages from a trading signal channel and decide which single
        position-management action, if any, the provider is instructing.

        You are not judging whether the instruction is wise. You are reading what it says.
        Somebody is already in this trade; your job is to say what they were told to do.

        The actions, and nothing else:

        - none            Commentary, encouragement, a screenshot, a question, an update
                          with no instruction. This is the correct answer far more often
                          than any other. "Running nicely", "told you", "+30 pips" are all
                          none.
        - secure_partial  Take part of the position off. "Secure half", "book some",
                          "close 50%", "take partial", "TP1 hit - secure". Set `fraction`
                          if a proportion is stated; leave it null if not.
        - breakeven       Move the stop to the entry price. "SL to BE", "move to
                          breakeven", "risk free now", "secure entry".
        - close           Close whatever is left. "Close all", "out", "exit now", "flat".
        - move_stop       Move the stop to a specific level the message names. Set `price`
                          to that level. If no level is named, this is not move_stop.
        - add_entry       A further entry on the same idea, at a new level. "Add here",
                          "second entry", "layer at 4590", "buy more".

        Rules that matter more than fluency:

        - If the message does not clearly instruct one of these, answer none. A copier that
          does nothing is correct far more often than one that guesses.
        - "TP1 hit" alone is a report, not an instruction: none. "TP1 hit, secure half and
          move to BE" instructs two things - choose the one that reduces risk most, which
          is secure_partial, because the stop can be moved on the next message and a
          missed partial cannot be taken again.
        - A reply that is itself a fresh, complete signal for a different instrument is
          none. It is not managing this position.
        - Never infer an instruction from a chart image caption or an emoji alone.
        - Confidence below about 60 should almost always be none.
        TXT;
    }

    private function brief(TelegramSignal $followUp, TelegramSignal $parent): string
    {
        $trade = $parent->trade;

        $lines = [
            'THE POSITION THIS REPLIES TO',
            sprintf('  %s %s, entered at %s', strtoupper((string) $trade->direction), $trade->symbol, $trade->entry_price),
            sprintf('  Stop %s, opened %s', $trade->sl_price ?? 'none recorded', $trade->opened_at),
            sprintf('  Size %s of an original %s, status %s', $trade->remaining_lot_size, $trade->initial_lot_size, $trade->status),
            '',
            'THE ORIGINAL SIGNAL MESSAGE',
            $this->indent($parent->raw_text),
            '',
        ];

        // Earlier instructions, so "secure half" twice does not book half twice over
        // without the model knowing the first one happened.
        $earlier = $parent->followUps()
            ->whereNotNull('follow_up_action')
            ->where('follow_up_action', '!=', TelegramSignal::FOLLOW_NONE)
            ->where('id', '!=', $followUp->id)
            ->orderBy('id')
            ->get();

        if ($earlier->isNotEmpty()) {
            $lines[] = 'INSTRUCTIONS ALREADY CARRIED OUT ON THIS POSITION';

            foreach ($earlier as $done) {
                $lines[] = sprintf('  %s: %s (%s)', $done->posted_at, $done->follow_up_action, $done->execution_status);
            }

            $lines[] = '';
        }

        $lines[] = 'THE MESSAGE TO INTERPRET';
        $lines[] = $this->indent($followUp->raw_text);

        return implode("\n", $lines);
    }

    private function indent(string $text): string
    {
        return '  '.str_replace("\n", "\n  ", trim(mb_substr($text, 0, 1500)));
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => $this->actions(),
                    'description' => 'The single action instructed. Use none when the message does not clearly instruct one of the others.',
                ],
                'fraction' => [
                    'type' => ['number', 'null'],
                    'description' => 'For secure_partial only: the proportion to close, 0 to 1. Null if the message does not state one.',
                ],
                'price' => [
                    'type' => ['number', 'null'],
                    'description' => 'For move_stop only: the level named in the message. Null otherwise.',
                ],
                'confidence' => [
                    'type' => 'integer',
                    'description' => 'How clearly the message instructs this action, 0-100. Below 60 should almost always be none.',
                ],
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'One or two sentences quoting the words that carry the instruction.',
                ],
            ],
            'required' => ['action', 'fraction', 'price', 'confidence', 'reasoning'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{action: string, fraction: null, price: null, confidence: null, reasoning: string, model: null}
     */
    private function nothing(string $reasoning): array
    {
        return [
            'action' => TelegramSignal::FOLLOW_NONE,
            'fraction' => null,
            'price' => null,
            'confidence' => null,
            'reasoning' => $reasoning,
            'model' => null,
        ];
    }
}

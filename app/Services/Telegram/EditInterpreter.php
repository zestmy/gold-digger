<?php

namespace App\Services\Telegram;

use App\Models\TelegramSignal;
use App\Services\Ai\OpenRouter;

/**
 * Edit Interpreter
 *
 * Reads what a provider changed when they edited a signal that has already been traded.
 *
 * ## Why this is reading and not acting
 *
 * The order has gone. Its levels are on the broker's book and cannot be un-sent, so
 * nothing this class concludes can undo the trade that was taken - at most it describes a
 * management action somebody could now choose to apply. It therefore returns a reading and
 * writes it down. It does not execute, and `SignalIngest` does not either.
 *
 * That is a deliberate limit rather than an unfinished one. Acting automatically on an
 * edit means letting a stranger silently rewrite the terms of a position you are already
 * in, which is a different and much larger permission than "copy this signal" - and the
 * one case where the provider gets to change their mind after you have committed money.
 *
 * ## What it is for
 *
 * The alert this feeds used to say only that something changed. A provider tightening a
 * stop from 2390 to 2380 and one widening it to 2450 produced the same sentence, so the
 * alert could not be triaged without opening Telegram and comparing two messages by eye.
 * Naming the direction of the change is the whole value here.
 *
 * ## Ambiguity resolves toward `unclear`, never toward a number
 *
 * The input is two versions of a message written by somebody who was not being careful.
 * Formatting churn, a fixed typo and a genuinely moved stop all look similar in a diff.
 * Where the model cannot tell, it says so - `unclear` is a supported answer and a far
 * better one than a confident reading of a cosmetic edit.
 */
final class EditInterpreter
{
    /** The edit made the position safer - stop tightened, target trimmed, size reduced. */
    public const RISK_REDUCED = 'reduced';

    /** The edit gives the trade more room to lose. Never acted on; always surfaced. */
    public const RISK_INCREASED = 'increased';

    /** Wording, formatting, a typo. The numbers that matter are the same. */
    public const RISK_UNCHANGED = 'unchanged';

    public const RISK_UNCLEAR = 'unclear';

    public function __construct(private readonly OpenRouter $router = new OpenRouter) {}

    /**
     * @return array{action: string, risk: string, confidence: int, reasoning: string, model: string|null}
     */
    public function interpret(TelegramSignal $signal): array
    {
        $before = (string) $signal->original_text;
        $after = (string) $signal->raw_text;

        if (trim($before) === '' || trim($before) === trim($after)) {
            return $this->nothing('There is no earlier version to compare against.');
        }

        if (! $this->router->configured()) {
            // Same posture as the reviewer and the follow-up interpreter: no key means no
            // reading, never a guessed one.
            return $this->nothing('No OPENROUTER_API_KEY is configured, so the edit cannot be read.');
        }

        $result = $this->router->structured(
            model: (string) config('ai.model'),
            system: $this->systemPrompt(),
            brief: $this->brief($signal, $before, $after),
            schemaName: 'signal_edit',
            schema: $this->schema(),
        );

        if (! $result['ok']) {
            return $this->nothing('The edit could not be read: '.($result['error'] ?? 'unknown error').'.');
        }

        $action = (string) ($result['data']['action'] ?? TelegramSignal::FOLLOW_NONE);
        $risk = (string) ($result['data']['risk'] ?? self::RISK_UNCLEAR);

        return [
            // Anything outside the closed set becomes none. The set is what the executor
            // understands, and a value it does not is indistinguishable from an invention.
            'action' => in_array($action, $this->actions(), true) ? $action : TelegramSignal::FOLLOW_NONE,
            'risk' => in_array($risk, $this->risks(), true) ? $risk : self::RISK_UNCLEAR,
            'confidence' => max(0, min(100, (int) ($result['data']['confidence'] ?? 0))),
            'reasoning' => mb_substr(trim((string) ($result['data']['reasoning'] ?? '')) ?: 'No reasoning given.', 0, 480),
            'model' => $result['model'] ?? null,
        ];
    }

    /** @return array<int, string> */
    private function actions(): array
    {
        return [
            TelegramSignal::FOLLOW_NONE,
            TelegramSignal::FOLLOW_PARTIAL,
            TelegramSignal::FOLLOW_BREAKEVEN,
            TelegramSignal::FOLLOW_CLOSE,
            TelegramSignal::FOLLOW_MOVE_STOP,
        ];
    }

    /** @return array<int, string> */
    private function risks(): array
    {
        return [self::RISK_REDUCED, self::RISK_INCREASED, self::RISK_UNCHANGED, self::RISK_UNCLEAR];
    }

    private function nothing(string $why): array
    {
        return [
            'action' => TelegramSignal::FOLLOW_NONE,
            'risk' => self::RISK_UNCLEAR,
            'confidence' => 0,
            'reasoning' => $why,
            'model' => null,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You compare two versions of a trading signal: the message as first posted, and the
        message after the provider edited it. A position was already opened on the ORIGINAL
        version. Your job is to say what the edit changed and which way it moves risk.

        You are not judging whether the edit is reasonable, and you are not deciding
        whether to obey it. You are reading a diff.

        RISK, from the position holder's point of view:

        - reduced    The stop is closer to entry, a target was trimmed, or size was cut.
                     Less can now be lost than before the edit.
        - increased  The stop is further from entry, or removed, or the direction was
                     reversed. More can now be lost. Say this plainly even though it is the
                     one that will not be acted on - it is the case worth waking somebody.
        - unchanged  Wording, spelling, emoji, formatting, a re-stated level identical to
                     the one already there. The numbers that matter did not move.
        - unclear    You genuinely cannot tell. Prefer this over a confident guess.

        ACTION - the single management step, if any, the edited version would now imply for
        a position already open. Almost always none.

        - none            The edit implies nothing to do now. This is the usual answer.
        - move_stop       The edit names a different stop that is TIGHTER than the original.
        - secure_partial  The edit now asks for part of the position to be taken off.
        - breakeven       The edit now puts the stop at entry.
        - close           The edit now says to exit entirely, or cancels the setup.

        Never answer with an action when risk is increased. A provider giving a trade more
        room after it was taken is a thing to report, never a thing to carry out.

        A signal edited from BUY to SELL, or with its stop removed, is increased risk and
        action none. Do not attempt to reverse or re-enter anything.
        TXT;
    }

    private function brief(TelegramSignal $signal, string $before, string $after): string
    {
        $trade = $signal->trade;

        $lines = ['THE POSITION THAT WAS ALREADY OPENED ON THE ORIGINAL'];

        $lines[] = $trade === null
            ? '  Recorded as acted on, but no trade row is attached.'
            : sprintf(
                '  %s %s entered at %s, stop %s, size %s of %s, status %s',
                strtoupper((string) $trade->direction), $trade->symbol, $trade->entry_price,
                $trade->sl_price ?? 'none recorded', $trade->remaining_lot_size,
                $trade->initial_lot_size, $trade->status,
            );

        return implode("
", array_merge($lines, [
            '',
            'THE MESSAGE AS FIRST POSTED - this is what was traded',
            $this->indent($before),
            '',
            sprintf('THE MESSAGE NOW, after %d edit(s)', $signal->edit_count),
            $this->indent($after),
        ]));
    }

    private function indent(string $text): string
    {
        return collect(preg_split('/?
/', mb_substr($text, 0, 1500)) ?: [])
            ->map(fn (string $line) => '  '.$line)
            ->implode("
");
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'risk' => [
                    'type' => 'string',
                    'enum' => $this->risks(),
                    'description' => 'Which way the edit moves risk for a position already open on the original.',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => $this->actions(),
                    'description' => 'The single management step the edited version now implies. Use none unless it is unmistakable, and always none when risk is increased.',
                ],
                'confidence' => [
                    'type' => 'integer',
                    'description' => 'How clearly the diff supports this reading, 0-100. Below 60 should be unclear.',
                ],
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'One or two sentences naming the values that changed, e.g. "stop moved 2390 -> 2380".',
                ],
            ],
            'required' => ['risk', 'action', 'confidence', 'reasoning'],
            'additionalProperties' => false,
        ];
    }
}

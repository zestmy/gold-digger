<?php

namespace App\Services\Ai;

/**
 * The shape of an analysis.
 *
 * The two-section split is enforced by the JSON schema below rather than by asking the
 * model to format its prose a certain way. That is the whole point: `reading` describes
 * numbers the dashboard already computed and can be checked against the trend card, while
 * `outlook` is opinion about what might happen next and can be checked against nothing.
 *
 * A prompt asking for both in one field would produce a paragraph where the boundary
 * between them is a matter of tone, and tone is exactly what makes a speculative claim
 * read as a factual one.
 */
final readonly class PairAnalysis
{
    public function __construct(
        public string $headline,
        public string $reading,
        public string $outlook,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['headline', 'reading', 'outlook'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || trim($data[$field]) === '') {
                return null;
            }
        }

        return new self(
            headline: trim($data['headline']),
            reading: trim($data['reading']),
            outlook: trim($data['outlook']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'headline' => [
                    'type' => 'string',
                    'description' => 'One short sentence, under 90 characters, stating the current market state. No advice.',
                ],
                'reading' => [
                    'type' => 'string',
                    'description' => 'What the indicators currently say and why the bot has or has not traded, in 2-4 plain sentences. Reference only the numbers supplied. Do not predict, do not advise, do not speculate about causes outside the data.',
                ],
                'outlook' => [
                    'type' => 'string',
                    'description' => 'What might happen next and what would change the picture, in 2-3 sentences. This is explicitly speculative. Say plainly when the data does not support a view rather than manufacturing one.',
                ],
            ],
            'required' => ['headline', 'reading', 'outlook'],
            'additionalProperties' => false,
        ];
    }
}

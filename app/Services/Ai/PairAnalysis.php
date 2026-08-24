<?php

namespace App\Services\Ai;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

/**
 * The shape of an analysis.
 *
 * The two-section split is enforced here, in the schema, rather than by asking the model
 * to format its prose a certain way. That is the whole point: `reading` describes numbers
 * the dashboard already computed and can be checked against the trend card, while
 * `outlook` is opinion about what might happen next and can be checked against nothing.
 *
 * A prompt asking for both in one field would produce a paragraph where the boundary
 * between them is a matter of tone, and tone is exactly what makes a speculative claim
 * read as a factual one. Separate fields let the UI mark them differently and let a reader
 * skip the half that is guessing.
 */
class PairAnalysis implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'One short sentence, under 90 characters, stating the current market state. No advice.')]
    public string $headline;

    #[Constrained(description: 'What the indicators currently say and why the bot has or has not traded, in 2-4 plain sentences. Reference only the numbers supplied. Do not predict, do not advise, do not speculate about causes outside the data.')]
    public string $reading;

    #[Constrained(description: 'What might happen next and what would change the picture, in 2-3 sentences. This is explicitly speculative. Say plainly when the data does not support a view rather than manufacturing one.')]
    public string $outlook;
}

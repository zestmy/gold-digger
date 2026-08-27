<?php

namespace App\Services\Ai;

use App\Models\Strategy;
use App\Services\Backtest\ParameterGrid;
use Illuminate\Support\Facades\Log;

/**
 * Strategy Proposer
 *
 * Asks a model for candidate parameter sets. That is all it does.
 *
 * ## It replaces the grid, not the judge
 *
 * `backtest:optimise` searches an exhaustive grid and validates the winner on bars the
 * search never saw. A grid is thorough and completely blind: it cannot know that every
 * recent signal died on `adx_below_threshold`, so it spends the same effort on ATR periods
 * as on the one axis that is actually binding.
 *
 * A model can read the skip reasons and propose targeted combinations. What it must never
 * do is decide whether they are good - that is what WalkForward is for, on out-of-sample
 * bars, exactly as before. BACKTESTING.md opens by saying every strategy setting used to
 * be an opinion; a proposer whose suggestions were applied on the strength of its reasoning
 * would put them straight back.
 *
 * So the output here is deliberately narrow: parameter sets and a sentence of rationale.
 * The rationale is shown to a human beside the measured result, and it never outranks it.
 *
 * ## Constrained to what can actually be swept
 *
 * Proposals are filtered against ParameterGrid::SWEEPABLE and its coherence rules before
 * anything is backtested. A model that suggests `ema_fast` above `ema_slow`, or a TP ladder
 * that runs backwards, has proposed a strategy that cannot exist - and finding that out
 * from a backtest that silently produced no trades would waste the run.
 */
final class StrategyProposer
{
    /** Proposals to ask for. Enough to explore, few enough to walk-forward in reasonable time. */
    private const PROPOSALS = 6;

    public function __construct(private readonly OpenRouter $router = new OpenRouter) {}

    public function configured(): bool
    {
        return $this->router->configured();
    }

    /**
     * @param  array<string, mixed>  $evidence  Baseline metrics, skip reasons, data range
     * @return array{ok: bool, proposals: array<int, array{parameters: array<string, float>, rationale: string}>, error: string|null, model: string|null}
     */
    public function propose(Strategy $strategy, array $evidence): array
    {
        if (! $this->configured()) {
            return $this->failure('No OPENROUTER_API_KEY is configured.');
        }

        $result = $this->router->structured(
            model: (string) config('ai.proposer_model'),
            system: $this->systemPrompt(),
            brief: $this->brief($strategy, $evidence),
            schemaName: 'strategy_proposals',
            schema: $this->schema(),
            callSite: 'strategy_proposer',
        );

        if (! $result['ok']) {
            return $this->failure($result['error'] ?? 'The proposer failed.');
        }

        $raw = $result['data']['proposals'] ?? null;

        if (! is_array($raw) || $raw === []) {
            return $this->failure('The model returned no proposals.');
        }

        $proposals = [];
        $rejected = 0;

        foreach ($raw as $entry) {
            $clean = $this->sanitise($entry, $strategy);

            if ($clean === null) {
                $rejected++;

                continue;
            }

            $proposals[] = $clean;
        }

        if ($proposals === []) {
            return $this->failure("All {$rejected} proposals were incoherent or referenced parameters that cannot be swept.");
        }

        if ($rejected > 0) {
            Log::info("[proposer] discarded {$rejected} incoherent proposal(s).");
        }

        return ['ok' => true, 'proposals' => $proposals, 'error' => null, 'model' => $result['model']];
    }

    /**
     * Keep only sweepable, numeric, coherent parameter sets.
     *
     * @param  mixed  $entry
     * @return array{parameters: array<string, float>, rationale: string}|null
     */
    private function sanitise($entry, Strategy $strategy): ?array
    {
        if (! is_array($entry) || ! isset($entry['parameters']) || ! is_array($entry['parameters'])) {
            return null;
        }

        $parameters = [];

        foreach ($entry['parameters'] as $name => $value) {
            if (! in_array($name, ParameterGrid::SWEEPABLE, true)) {
                continue;
            }

            if (! is_numeric($value)) {
                continue;
            }

            $parameters[$name] = (float) $value;
        }

        if ($parameters === []) {
            return null;
        }

        // Coherence is checked against the strategy's own values for anything the proposal
        // left out - "ema_fast 60" is only incoherent once you know ema_slow is 50.
        if (! $this->coherent($parameters, $strategy)) {
            return null;
        }

        return [
            'parameters' => $parameters,
            'rationale' => is_string($entry['rationale'] ?? null)
                ? trim($entry['rationale'])
                : 'No rationale given.',
        ];
    }

    /**
     * @param  array<string, float>  $parameters
     */
    private function coherent(array $parameters, Strategy $strategy): bool
    {
        $get = fn (string $name) => $parameters[$name] ?? ($strategy->{$name} === null ? null : (float) $strategy->{$name});

        $fast = $get('ema_fast');
        $slow = $get('ema_slow');

        if ($fast !== null && $slow !== null && $fast >= $slow) {
            return false;
        }

        $tp1 = $get('tp1_pips');
        $tp2 = $get('tp2_pips');
        $tp3 = $get('tp3_pips');

        if ($tp1 !== null && $tp2 !== null && $tp1 >= $tp2) {
            return false;
        }

        if ($tp2 !== null && $tp3 !== null && $tp2 >= $tp3) {
            return false;
        }

        // A stop at zero is not a stop, and a negative period is not a period.
        foreach ($parameters as $name => $value) {
            if ($value < 0) {
                return false;
            }

            if (in_array($name, ['ema_fast', 'ema_slow', 'atr_period'], true) && $value < 2) {
                return false;
            }

            if ($name === 'sl_atr_multiplier' && $value <= 0) {
                return false;
            }
        }

        return true;
    }

    private function systemPrompt(): string
    {
        $sweepable = implode(', ', ParameterGrid::SWEEPABLE);

        return <<<PROMPT
        You propose candidate parameter sets for an EMA-crossover XAUUSD scalping strategy.
        Each proposal will be backtested with walk-forward validation on bars the search
        never saw. You are not deciding anything - you are generating hypotheses worth the
        compute of testing.

        The strategy: an EMA cross on the entry timeframe, taken only when the higher
        timeframe's EMAs agree, filtered by ADX, with the stop at a multiple of ATR and a
        three-rung take-profit ladder closing fixed percentages.

        You may propose only these columns: {$sweepable}

        Constraints that make a proposal invalid - it will be discarded before testing:
        - ema_fast must be below ema_slow
        - tp1_pips < tp2_pips < tp3_pips
        - no negative values; periods at least 2; sl_atr_multiplier above 0

        How to be useful:

        - Vary a small number of axes per proposal. Changing everything at once produces a
          result nobody can attribute to anything.
        - Read the skip reasons. If most setups die on one gate, the parameter behind that
          gate is where the information is. If almost nothing is skipped, the entry rule is
          not selective enough and the ladder or the stop is more likely to be the problem.
        - Span a range. Six proposals clustered around the current values tests almost
          nothing; include at least one that is deliberately different in kind, such as a
          much wider stop with a nearer first target, or a far more selective entry.
        - Respect the instrument. Gold's ATR is large and its spread widens on news; a
          take-profit inside a typical spread is not a target, and a stop inside the
          broker's stops level cannot be placed at all.
        - The rationale is one sentence naming what you changed and what you expect it to
          do. It is shown beside the measured result and will be judged against it.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function brief(Strategy $strategy, array $evidence): string
    {
        $lines = [
            'CURRENT PARAMETERS',
        ];

        foreach (ParameterGrid::SWEEPABLE as $name) {
            $value = $strategy->{$name};
            $lines[] = sprintf('  %-24s %s', $name, $value === null ? 'not set' : $value);
        }

        $lines[] = '';
        $lines[] = sprintf('  %-24s %s / %s', 'timeframes', $strategy->timeframe_entry, $strategy->timeframe_trend);
        $lines[] = '';
        $lines[] = 'DATA AVAILABLE';
        $lines[] = '  '.($evidence['data_range'] ?? 'unknown');
        $lines[] = '';
        $lines[] = 'BASELINE - these parameters, walk-forward validated out of sample';
        $lines[] = '  '.($evidence['baseline'] ?? 'no baseline available');

        if (! empty($evidence['skip_reasons'])) {
            $lines[] = '';
            $lines[] = 'WHY RECENT SETUPS DID NOT TRADE';

            foreach ($evidence['skip_reasons'] as $reason => $count) {
                $lines[] = sprintf('  %-24s %s', $reason, $count);
            }
        }

        $lines[] = '';
        $lines[] = 'Propose '.self::PROPOSALS.' parameter sets.';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        // Every sweepable column is offered, all nullable, so a proposal can change one
        // axis without restating the rest. `additionalProperties: false` is what stops the
        // model inventing a column name that would be silently dropped later.
        $properties = [];

        foreach (ParameterGrid::SWEEPABLE as $name) {
            $properties[$name] = ['type' => ['number', 'null']];
        }

        return [
            'type' => 'object',
            'properties' => [
                'proposals' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => self::PROPOSALS,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'rationale' => [
                                'type' => 'string',
                                'description' => 'One sentence: what changed and what it is expected to do.',
                            ],
                            'parameters' => [
                                'type' => 'object',
                                'properties' => $properties,
                                'required' => array_keys($properties),
                                'additionalProperties' => false,
                            ],
                        ],
                        'required' => ['rationale', 'parameters'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['proposals'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{ok: false, proposals: array<int, never>, error: string, model: null}
     */
    private function failure(string $message): array
    {
        Log::info("[proposer] {$message}");

        return ['ok' => false, 'proposals' => [], 'error' => $message, 'model' => null];
    }
}

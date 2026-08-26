<?php

namespace App\Services\Ai;

use App\Models\CotReport;
use App\Services\Analysis\Opportunity;
use Illuminate\Support\Facades\Cache;

/**
 * Scan Analyst
 *
 * Given a scan the dashboard already measured and ranked, says which of it is worth taking.
 *
 * ## One call for the whole shortlist, not one per instrument
 *
 * A scan across twenty instruments that asked the model about each one would cost twenty
 * calls to answer a question that is comparative - "which of these" cannot be answered by
 * twenty separate opinions that never saw each other. Ranking against a shortlist is
 * exactly the shape of judgement a model is good at, and it happens to be the cheap shape
 * too.
 *
 * ## What the model may decide, and what is already decided
 *
 * It picks among the candidates and says how strongly. It does not choose the direction:
 * that came from the EMAs, and the stop and target were measured against levels anchored
 * to it, so flipping it would leave a plan whose prices refer to a trade nobody proposed.
 * It cannot write a price at all - it names candidates by their number, and the prices are
 * substituted back here from the measured plan.
 *
 * So the worst it can do is prefer a worse real setup, which is an ordering to argue with.
 * It cannot invent an instrument, a level, or a ratio.
 *
 * ## This proposes; it does not trade
 *
 * Nothing here reaches `SignalGenerator` or the copier's executor. Taking one of these is
 * a separate deliberate act by a person, which is what lets it be more speculative than
 * anything that opens a position unasked.
 */
final class ScanAnalyst
{
    /** How many of the ranked candidates the model is shown. */
    public const SHORTLIST = 6;

    /** Cached this long: the answer cannot change faster than the bars it was read from. */
    private const CACHE_MINUTES = 5;

    public function __construct(private readonly OpenRouter $router = new OpenRouter) {}

    public function configured(): bool
    {
        return $this->router->configured();
    }

    /**
     * Rank a shortlist.
     *
     * @param  array<int, Opportunity>  $candidates  Already ordered by the scanner
     * @return array{
     *     ok: bool,
     *     error: string|null,
     *     verdict: string|null,
     *     picks: array<int, array<string, mixed>>,
     *     passed_on: string|null,
     *     model: string|null
     * }
     */
    public function rank(array $candidates, string $timeframe, bool $fresh = false): array
    {
        if ($candidates === []) {
            return $this->fail('The scan found no instrument with a direction to test.');
        }

        if (! $this->configured()) {
            // The scan itself is unaffected: it was measured, not generated, and it is the
            // half that can be checked.
            return $this->fail('No OPENROUTER_API_KEY is configured, so the ranking is the measured one only.');
        }

        $shortlist = array_slice($candidates, 0, self::SHORTLIST);

        $key = $this->key($shortlist, $timeframe);

        if ($fresh) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), function () use ($shortlist, $timeframe) {
            $result = $this->router->structured(
                model: (string) config('ai.model'),
                system: $this->systemPrompt(),
                brief: $this->brief($shortlist, $timeframe),
                schemaName: 'scan_ranking',
                schema: $this->schema(),
            );

            return $result['ok'] ? ['data' => $result['data'], 'model' => $result['model']] : null;
        });

        if ($data === null) {
            return $this->fail('The ranking could not be produced; the measured scan is shown without it.');
        }

        return [
            'ok' => true,
            'error' => null,
            'verdict' => (string) ($data['data']['verdict'] ?? ''),
            'picks' => $this->resolve($data['data']['picks'] ?? [], $shortlist),
            'passed_on' => isset($data['data']['passed_on']) ? (string) $data['data']['passed_on'] : null,
            'model' => $data['model'] ?? null,
        ];
    }

    /**
     * Attach each pick to the candidate it names, and drop the ones that name nothing.
     *
     * An out-of-range index is discarded rather than rendered as a row about an instrument
     * that was not in the scan. There is no sensible price to show for a pick that refers
     * to no candidate, and showing it with blanks would suggest the scan found something
     * it did not.
     *
     * @param  array<int, mixed>  $picks
     * @param  array<int, Opportunity>  $shortlist
     * @return array<int, array<string, mixed>>
     */
    private function resolve(array $picks, array $shortlist): array
    {
        $resolved = [];
        $seen = [];

        foreach ($picks as $pick) {
            if (! is_array($pick)) {
                continue;
            }

            $index = is_numeric($pick['candidate'] ?? null) ? (int) $pick['candidate'] : null;

            if ($index === null || ! isset($shortlist[$index]) || isset($seen[$index])) {
                continue;
            }

            $seen[$index] = true;

            $resolved[] = [
                'opportunity' => $shortlist[$index],
                'verdict' => in_array($pick['verdict'] ?? null, ['take', 'watch', 'pass'], true)
                    ? $pick['verdict']
                    : 'watch',
                'conviction' => in_array($pick['conviction'] ?? null, ['low', 'medium', 'high'], true)
                    ? $pick['conviction']
                    : 'low',
                'reasoning' => trim((string) ($pick['reasoning'] ?? '')),
                'invalidation' => trim((string) ($pick['invalidation'] ?? '')),
            ];
        }

        return $resolved;
    }

    /**
     * Keyed on the last bar of every shortlisted instrument.
     *
     * Any of them printing a new bar is a different scan and deserves a fresh answer;
     * none of them doing so means a reload should not be paid for twice.
     *
     * @param  array<int, Opportunity>  $shortlist
     */
    private function key(array $shortlist, string $timeframe): string
    {
        $parts = array_map(
            fn (Opportunity $o) => $o->symbol.'@'.($o->lastBarAt?->timestamp ?? 0),
            $shortlist,
        );

        return 'scan-ranking:'.$timeframe.':'.md5(implode('|', $parts));
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You are ranking a shortlist of trade setups that have already been measured.

        Each candidate arrives with its direction, its confluence score, the structure of
        its chart, and - where one exists - a plan whose entry, stop and target are prices
        this instrument actually traded at or turned at. You did not produce any of those
        numbers and you cannot change them. You choose among candidates by their number.

        Your job is to say which of these are worth a person's attention now, and why one
        beats another. Rank them, and be willing to rank none of them: a shortlist where
        everything is mixed structure and thin confluence has no trade in it, and picking
        the least bad one to fill the field is the failure this task is most prone to.

        How to judge:

        - Evidence beats a good-looking ratio. A candidate with four independent factors
          agreeing and 1.6 to 1 is a better proposition than one with two factors and 4 to
          1, because the second one's reward is only large because its stop is far away.
        - A candidate with no measured plan cannot be taken, whatever its confluence. Say
          so and move on; do not suggest a price for it.
        - Timeframes disagreeing is a serious mark against a candidate. The strategy that
          runs this account will not enter against its own higher timeframe, so a setup
          that needs it to is a setup nobody here will take.
        - Under about 1.5 to 1, say the reward does not justify the risk rather than
          proposing it anyway.
        - Prefer instruments whose structure agrees with the direction. A buy into falling
          structure is a counter-trend trade and should be named as one.

        Use `verdict` honestly: `take` means you would act on it now, `watch` means the
        setup is real but not ready, `pass` means it is on the list only because it scored
        into the shortlist. Most scans in a quiet market are watch and pass.

        Be concrete and brief. Name levels by their price. Do not restate the confluence
        numbers back - the reader has them in a table. Do not mention risk of ruin, do not
        add disclaimers, and never say how much to stake: sizing is not yours.
        TXT;
    }

    /**
     * @param  array<int, Opportunity>  $shortlist
     */
    private function brief(array $shortlist, string $timeframe): string
    {
        $lines = [
            sprintf('SCAN on %s, %d candidates, already ordered by measured evidence.', $timeframe, count($shortlist)),
            '',
        ];

        foreach ($shortlist as $i => $candidate) {
            $lines[] = $candidate->brief($i);

            $cot = CotReport::contextFor($candidate->symbol);

            if ($cot !== null) {
                // Weekly and stale by design: context for a bias, never a reason to enter
                // on a five-minute chart.
                $lines[] = '     Futures positioning (weekly, stale): '.$cot['summary'];
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'verdict' => [
                    'type' => 'string',
                    'description' => 'One or two sentences on what this scan as a whole looks like. Saying there is nothing here is a legitimate verdict.',
                ],
                'picks' => [
                    'type' => 'array',
                    'description' => 'The candidates worth commenting on, best first. May be empty when none are.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'candidate' => [
                                'type' => 'integer',
                                'description' => 'The number of the candidate in the list you were given.',
                            ],
                            'verdict' => [
                                'type' => 'string',
                                'enum' => ['take', 'watch', 'pass'],
                            ],
                            'conviction' => [
                                'type' => 'string',
                                'enum' => ['low', 'medium', 'high'],
                            ],
                            'reasoning' => [
                                'type' => 'string',
                                'description' => 'Why this one, and why here in the order. Two or three sentences. Name levels by price.',
                            ],
                            'invalidation' => [
                                'type' => 'string',
                                'description' => 'What would have to happen for this reading to be wrong.',
                            ],
                        ],
                        'required' => ['candidate', 'verdict', 'conviction', 'reasoning', 'invalidation'],
                        'additionalProperties' => false,
                    ],
                ],
                'passed_on' => [
                    'type' => 'string',
                    'description' => 'One sentence on what was left out of the picks and why.',
                ],
            ],
            'required' => ['verdict', 'picks', 'passed_on'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{ok: false, error: string, verdict: null, picks: array<int, mixed>, passed_on: null, model: null}
     */
    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'verdict' => null, 'picks' => [], 'passed_on' => null, 'model' => null];
    }
}

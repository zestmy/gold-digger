<?php

namespace App\Services\Ai;

use App\Models\Candle;
use App\Models\CotReport;
use App\Models\Strategy;
use App\Services\Indicators\Indicators;
use App\Services\Indicators\Structure;
use App\Services\Strategy\MarketContext;
use App\Services\Strategy\StrategyEvaluator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Chart Analyst
 *
 * Reads an instrument's structure and proposes a plan, on request rather than on a timer.
 *
 * ## The model chooses among levels, it does not invent them
 *
 * Ask a language model for "key levels" and it returns round numbers - 2650, 2700 -
 * because those are the figures text about markets contains. They are not where this
 * instrument turned. `Structure` finds the actual pivots by definition and merges the ones
 * that are the same level tested twice, and the model is handed that list.
 *
 * Its plan then references levels by their number in the list. It cannot name a price at
 * all, so it cannot name a wrong one - the worst it can do is choose a less sensible real
 * level, which is an argument to disagree with rather than a number nobody can check.
 *
 * ## Why this is not the same as the autonomous trader
 *
 * That one decides and acts within a fund. This explains, and stops. The plan it produces
 * is a proposal to read, and taking it is a separate deliberate act - which is why it is
 * allowed to be more speculative than anything that spends money without being asked.
 */
final class ChartAnalyst
{
    /** Bars of context. Enough for structure to exist, few enough to stay readable. */
    private const BARS = 120;

    /** Analyses are cached this long: the answer cannot change faster than the bars do. */
    private const CACHE_MINUTES = 5;

    public function __construct(
        private readonly OpenRouter $router = new OpenRouter,
        private readonly MarketContext $context = new MarketContext(new StrategyEvaluator),
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     error: string|null,
     *     levels: array<int, array<string, mixed>>,
     *     structure: string|null,
     *     reading: array<string, mixed>|null
     * }
     */
    public function analyse(Strategy $strategy, ?int $brokerAccountId, string $symbol, string $timeframe, bool $fresh = false): array
    {
        $market = $this->context->for($strategy, $brokerAccountId, $symbol);

        if (! $market['warm'] || $market['atr'] === null) {
            return $this->fail("Not enough {$symbol} history on {$timeframe} to read structure from.");
        }

        $bars = Candle::where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->orderByDesc('open_time')
            ->limit(self::BARS)
            ->get()
            ->reverse()
            ->values();

        if ($bars->count() < 30) {
            return $this->fail("Only {$bars->count()} {$timeframe} bars stored for {$symbol}.");
        }

        $structure = Structure::of(
            $bars->map(fn (Candle $c) => (float) $c->high)->all(),
            $bars->map(fn (Candle $c) => (float) $c->low)->all(),
            $bars->map(fn (Candle $c) => (float) $c->close)->all(),
            (float) $market['atr'],
        );

        if (! $this->router->configured()) {
            // The levels are still worth returning: they were measured, not generated, and
            // they are the more useful half.
            return [
                'ok' => true,
                'error' => 'No OPENROUTER_API_KEY is configured, so only the measured levels are shown.',
                'levels' => $structure['levels'],
                'structure' => $structure['structure'],
                'reading' => null,
            ];
        }

        // Keyed on the newest bar, so a repeated request inside one bar returns the same
        // answer instead of paying for a differently-worded version of it.
        $key = 'chart-analysis:'.$symbol.':'.$timeframe.':'.$bars->last()->open_time->timestamp;

        if ($fresh) {
            Cache::forget($key);
        }

        $reading = Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), function () use ($symbol, $timeframe, $market, $structure, $bars) {
            $result = $this->router->structured(
                model: (string) config('ai.model'),
                system: $this->systemPrompt(),
                brief: $this->brief($symbol, $timeframe, $market, $structure, $bars),
                schemaName: 'chart_analysis',
                schema: $this->schema(),
            );

            return $result['ok'] ? $result['data'] : null;
        });

        if ($reading === null) {
            return [
                'ok' => true,
                'error' => 'The reading could not be produced; the measured levels are shown without it.',
                'levels' => $structure['levels'],
                'structure' => $structure['structure'],
                'reading' => null,
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'levels' => $structure['levels'],
            'structure' => $structure['structure'],
            'reading' => $this->resolve($reading, $structure['levels']),
        ];
    }

    /**
     * Turn the level numbers the model chose back into prices.
     *
     * Out-of-range indices become null rather than an exception: the plan is then visibly
     * incomplete, which is a truthful thing for it to be, and nothing downstream trades it.
     *
     * @param  array<string, mixed>  $reading
     * @param  array<int, array<string, mixed>>  $levels
     * @return array<string, mixed>
     */
    private function resolve(array $reading, array $levels): array
    {
        $price = function (mixed $index) use ($levels): ?float {
            $i = is_numeric($index) ? (int) $index : null;

            return ($i !== null && isset($levels[$i])) ? (float) $levels[$i]['price'] : null;
        };

        return $reading + [
            'entry_price' => $price($reading['entry_level'] ?? null),
            'stop_price' => $price($reading['stop_level'] ?? null),
            'target_price' => $price($reading['target_level'] ?? null),
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You read market structure and propose a trade plan.

        The levels have already been measured from the chart: each is a price this
        instrument actually turned at, with a count of how many times. You choose among
        them by number. You cannot write a price, and should not try - a level not in the
        list is not a level anybody found.

        Say what the structure is doing, which levels matter and why, and then either
        propose a plan or say plainly that there is not one worth taking. "Wait" is a
        legitimate and frequent answer; a market with mixed structure has no plan in it, and
        inventing one to fill the field is the failure this is most prone to.

        When you do propose one:
        - The stop belongs on the far side of a level, not at a round number.
        - The target should be a level price has to reach, not a hope.
        - If the reward against the risk is under about 1.5 to 1, say so and propose
          waiting instead.

        Be concrete and brief. Name the levels by their price when you discuss them. Do not
        restate the indicator values back - the reader can see them.
        TXT;
    }

    /**
     * @param  array<string, mixed>  $market
     * @param  array<string, mixed>  $structure
     * @param  Collection<int, Candle>  $bars
     */
    private function brief(string $symbol, string $timeframe, array $market, array $structure, $bars): string
    {
        $closes = $bars->map(fn (Candle $c) => (float) $c->close)->all();
        $squeeze = Indicators::squeeze($closes, 20, self::BARS);

        $lines = [
            "{$symbol} on {$timeframe}",
            sprintf('  Last close %s, ATR %s', $market['last_close'], $market['atr']),
            sprintf('  %s trend %s, %s bias %s, ADX %s',
                $market['trend_timeframe'], $market['trend'] ?? 'none',
                $market['entry_timeframe'], $market['entry_bias'] ?? 'none',
                $market['adx'] ?? '?'),
            '  '.$structure['structure'],
            sprintf('  Window high %s, low %s', $structure['range_high'], $structure['range_low']),
            '  Bollinger width is '.($squeeze['squeezed'] ? 'compressed.' : 'not compressed.'),
            '',
            'MEASURED LEVELS, choose by number',
        ];

        foreach ($structure['levels'] as $i => $level) {
            $lines[] = sprintf(
                '  [%d] %s  %s, touched %d time%s',
                $i,
                $level['price'],
                $level['kind'],
                $level['touches'],
                $level['touches'] === 1 ? '' : 's',
            );
        }

        $cot = CotReport::contextFor($symbol);

        if ($cot !== null) {
            $lines[] = '';
            $lines[] = 'FUTURES POSITIONING (weekly, stale by design - context, not timing)';
            $lines[] = '  '.$cot['summary'];
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
                'headline' => [
                    'type' => 'string',
                    'description' => 'One sentence on what this chart is doing.',
                ],
                'structure' => [
                    'type' => 'string',
                    'description' => 'Two or three sentences on the structure and which levels matter.',
                ],
                'bias' => [
                    'type' => 'string',
                    'enum' => ['bullish', 'bearish', 'neutral'],
                ],
                'plan' => [
                    'type' => 'string',
                    'enum' => ['buy', 'sell', 'wait'],
                    'description' => 'Wait is a legitimate and frequent answer.',
                ],
                'entry_level' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Index into the measured levels. Null when waiting.',
                ],
                'stop_level' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Index of the level the stop sits beyond. Null when waiting.',
                ],
                'target_level' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Index of the level being aimed at. Null when waiting.',
                ],
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'Why this plan, or why waiting. Name levels by price.',
                ],
                'invalidation' => [
                    'type' => 'string',
                    'description' => 'What would have to happen for this reading to be wrong.',
                ],
            ],
            'required' => ['headline', 'structure', 'bias', 'plan', 'entry_level', 'stop_level', 'target_level', 'reasoning', 'invalidation'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{ok: false, error: string, levels: array<int, mixed>, structure: null, reading: null}
     */
    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'levels' => [], 'structure' => null, 'reading' => null];
    }
}

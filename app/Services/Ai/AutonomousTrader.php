<?php

namespace App\Services\Ai;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\CotReport;
use App\Models\Strategy;
use App\Models\TelegramSignal;
use App\Models\User;
use App\Services\Indicators\Indicators;
use App\Services\Strategy\MarketContext;
use App\Services\Strategy\SignalQuality;
use App\Services\Strategy\StrategyEvaluator;

/**
 * Autonomous Trader
 *
 * Forms its own opinion rather than copying somebody else's.
 *
 * ## What the model decides, and what it emphatically does not
 *
 * It decides two things: whether there is a trade here at all, and in which direction.
 *
 * It does not choose prices. Stop distance comes from ATR exactly as the strategy's does,
 * and targets are multiples of that stop. This is the whole design, and the reason is
 * specific: a language model asked for "a stop around 2643" produces a plausible number
 * with no relationship to this instrument's volatility, and a plausible wrong number is
 * far worse here than a refusal - it sizes a position, and the position is real.
 *
 * Direction is a judgement about evidence, which is a thing language models can do. A
 * price level is a measurement, which is a thing they cannot. The split follows that line
 * and not the line of what would be impressive.
 *
 * ## Why it goes through the copier's own executor
 *
 * The output is a signal in the same table, carrying the same gates: the fund cap, the
 * daily limit, the session and news checks, the refusal to size below the broker's
 * minimum. A second execution path would be a second place for those to be got wrong, on
 * the one path where being wrong opens a position.
 *
 * ## An honest statement of what this is worth
 *
 * Nothing here demonstrates an edge. The walk-forward numbers on the mechanical strategy
 * are the only evidence this project has about whether any of its ideas make money, and
 * they say the baseline trades rarely and thinly. A model reading the same indicators is
 * not obviously better and may be worse; what it is, is bounded - it spends the AI fund
 * and can lose no more than that. Run it as an experiment with a number attached, which is
 * what the fund cap makes it.
 */
final class AutonomousTrader
{
    /** How many closed bars of price action to describe. */
    private const BARS = 40;

    /** Targets, as multiples of the stop the system computed. */
    private const TARGETS = [1.0, 2.0, 3.0];

    public function __construct(
        private readonly OpenRouter $router = new OpenRouter,
        private readonly SignalQuality $quality = new SignalQuality,
        private readonly MarketContext $context = new MarketContext(new StrategyEvaluator),
    ) {}

    /**
     * Consider one instrument, and produce a signal if there is a case for one.
     *
     * @return array{traded: bool, signal: TelegramSignal|null, why: string}
     */
    public function consider(User $user, string $symbol): array
    {
        $settings = BotSettings::where('user_id', $user->id)->first();

        if ($settings === null || ! $settings->is_active || ! $settings->ai_autonomous) {
            return $this->pass('Autonomous trading is switched off.');
        }

        if (! $this->router->configured()) {
            return $this->pass('No OPENROUTER_API_KEY is configured.');
        }

        $strategy = Strategy::where('user_id', $user->id)
            ->orderByDesc('is_active')->orderBy('id')->first();

        if ($strategy === null) {
            return $this->pass('No strategy to take indicator settings from.');
        }

        // The broker account comes from the terminal that is actually connected, which is
        // where every other part of this system gets it. Candles are stored per account,
        // so asking the wrong one returns an empty series and looks like missing history.
        $heartbeat = BotHeartbeat::where('user_id', $user->id)->orderByDesc('last_seen_at')->first();

        $market = $this->context->for($strategy, $heartbeat?->broker_account_id, $symbol);

        if (! $market['warm']) {
            return $this->pass("Not enough {$symbol} history for the indicators to mean anything.");
        }

        $atr = $market['atr'];

        // The stop is the thing that makes a position sizeable, and ATR is where it comes
        // from. Without it there is no trade to propose, whatever the model might think.
        if ($atr === null || $atr <= 0.0) {
            return $this->pass("No ATR for {$symbol}, so no stop distance exists.");
        }

        $verdict = $this->router->structured(
            model: (string) config('ai.model'),
            system: $this->systemPrompt(),
            brief: $this->brief($symbol, $market, $strategy, $heartbeat?->broker_account_id),
            schemaName: 'autonomous_decision',
            schema: $this->schema(),
        );

        if (! $verdict['ok']) {
            return $this->pass('The decision could not be made: '.($verdict['error'] ?? 'unknown error').'.');
        }

        $data = $verdict['data'];

        if (($data['trade'] ?? false) !== true) {
            return $this->pass(trim((string) ($data['reasoning'] ?? '')) ?: 'No case for a trade here.');
        }

        $direction = strtolower((string) ($data['direction'] ?? ''));

        if (! in_array($direction, ['buy', 'sell'], true)) {
            return $this->pass('The model asked to trade without naming a direction.');
        }

        // Checked against this account's own measurements after the model has spoken, not
        // before. The model argues its case; the confluence floor decides whether the case
        // clears the bar, and it is not the model's to lower.
        $assessment = $this->quality->assess($strategy, $heartbeat?->broker_account_id, $symbol, $direction);

        if (! $assessment['tradeable']) {
            return $this->pass('The measured evidence does not support it: '.$assessment['why']);
        }

        return [
            'traded' => true,
            'signal' => $this->store($user, $symbol, $direction, $market, $strategy, $data, $assessment),
            'why' => trim((string) ($data['reasoning'] ?? '')) ?: 'No reasoning given.',
        ];
    }

    /**
     * Write the decision as a signal, so the copier's executor can act on it.
     *
     * @param  array<string, mixed>  $market
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $assessment
     */
    private function store(
        User $user,
        string $symbol,
        string $direction,
        array $market,
        Strategy $strategy,
        array $data,
        array $assessment,
    ): TelegramSignal {
        $price = (float) $market['last_close'];
        $stop = (float) $market['atr'] * (float) ($strategy->sl_atr_multiplier ?? 1.5);

        $sl = $direction === 'buy' ? $price - $stop : $price + $stop;

        $targets = array_map(
            fn (float $r) => round($direction === 'buy' ? $price + $stop * $r : $price - $stop * $r, 6),
            self::TARGETS,
        );

        return TelegramSignal::create([
            'user_id' => $user->id,
            'source' => 'autonomous',
            'kind' => TelegramSignal::KIND_AUTONOMOUS,
            // One decision per instrument per bar. The bar's own time is what stops a
            // scheduler running twice in a minute from opening two positions on one idea.
            'external_id' => 'ai:'.$symbol.':'.$market['last_bar_at']?->timestamp,
            'chat_id' => '',
            'chat_title' => 'FXSignalPro AI',
            'raw_text' => trim((string) ($data['reasoning'] ?? '')),
            'posted_at' => now(),
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => $symbol,
            'direction' => $direction,
            // At market: the decision is about now, and a resting order would be about a
            // price the case was not made at.
            'entry_price' => null,
            'sl_price' => round($sl, 6),
            'tp_prices' => $targets,
            // Reviewed by the measurement rather than by a second model call. Asking the
            // same model to approve what it just proposed is not a review.
            'review_status' => TelegramSignal::REVIEW_APPROVED,
            'review_confidence' => $assessment['confidence'],
            'review_reasoning' => $assessment['why'],
            'review_model' => (string) config('ai.model'),
            'reviewed_at' => now(),
            'execution_status' => TelegramSignal::EXEC_NONE,
        ]);
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You decide whether a trading setup exists right now, and in which direction. You do
        not choose prices: the stop comes from this instrument's ATR and the targets are
        multiples of it, both computed before you are asked.

        Say no by default. A market with no clear structure is the normal state, and an
        account that trades only when there is a real case will trade rarely. Refusing costs
        nothing; a marginal trade costs a real position.

        Reasons to say no, which are all common:
        - Trend and momentum disagree.
        - The move has already run and entering now is chasing it.
        - Volatility is so low that any stop is inside the noise, or so high that a
          sensible stop is unaffordable.
        - The reading is mixed and you are reaching for a story that fits it.

        Do not weigh the positioning data as though it were current. It describes where
        futures positioning was on a Tuesday and is published three days later; it is
        context for how crowded a trade is, never a timing signal.

        Give your reasoning in two or three concrete sentences naming the deciding factor.
        No hedging, and no restating the numbers back.
        TXT;
    }

    /**
     * @param  array<string, mixed>  $market
     */
    private function brief(string $symbol, array $market, Strategy $strategy, ?int $brokerAccountId): string
    {
        $lines = [
            "INSTRUMENT  {$symbol}",
            sprintf('  Last close %s, ATR %s (%.3f%% of price)', $market['last_close'], $market['atr'], $market['atr_pct'] ?? 0),
            sprintf('  %s trend: %s', $market['trend_timeframe'], $market['trend'] ?? 'none'),
            sprintf('  %s bias: %s', $market['entry_timeframe'], $market['entry_bias'] ?? 'none'),
            sprintf('  ADX %s (+DI %s, -DI %s)', $market['adx'] ?? '?', $market['plus_di'] ?? '?', $market['minus_di'] ?? '?'),
            sprintf('  EMA %s over %s, gap %s%%', $market['ema_fast'] ?? '?', $market['ema_slow'] ?? '?', $market['ema_gap_pct'] ?? '?'),
            '',
            'THE STOP THAT WOULD BE USED',
            sprintf('  %s x ATR = %s of price, in whichever direction you name.',
                $strategy->sl_atr_multiplier ?? 1.5,
                round((float) $market['atr'] * (float) ($strategy->sl_atr_multiplier ?? 1.5), 5)),
            '  Targets are 1R, 2R and 3R from there.',
            '',
        ];

        $cot = CotReport::contextFor($symbol);

        if ($cot !== null) {
            $lines[] = 'FUTURES POSITIONING (weekly, and stale by design)';
            $lines[] = '  '.$cot['summary'];
            $lines[] = '';
        }

        $lines[] = 'RECENT PRICE ACTION, oldest first';
        $lines[] = $this->recentBars($brokerAccountId, $symbol, $market['entry_timeframe']);

        return implode("\n", $lines);
    }

    private function recentBars(?int $brokerAccountId, string $symbol, string $timeframe): string
    {
        // Scoped to the account, as `MarketContext` above it already is. Two accounts'
        // gold printed as one window is two price paths pretending to be one.
        $bars = Candle::query()
            ->series($brokerAccountId, $symbol, $timeframe)
            ->orderByDesc('open_time')
            ->limit(self::BARS)
            ->get()
            ->reverse()
            ->values();

        if ($bars->isEmpty()) {
            return '  (none)';
        }

        $closes = $bars->map(fn (Candle $c) => (float) $c->close)->all();
        $squeeze = Indicators::squeeze($closes, 20, self::BARS);

        $rows = $bars->map(fn (Candle $c) => sprintf(
            '  %s  O %s  H %s  L %s  C %s',
            $c->open_time->format('d M H:i'),
            $c->open, $c->high, $c->low, $c->close,
        ))->implode("\n");

        return $rows."\n\n  Bollinger width is ".
            ($squeeze['squeezed'] ? 'in the quietest quarter of this window - compressed.' : 'not compressed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'trade' => [
                    'type' => 'boolean',
                    'description' => 'True only if there is a positive case for entering now. Absence of objection is not a case.',
                ],
                'direction' => [
                    'type' => ['string', 'null'],
                    'enum' => ['buy', 'sell', null],
                    'description' => 'Which way, when trade is true. Null otherwise.',
                ],
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'Two or three concrete sentences naming the deciding factor.',
                ],
            ],
            'required' => ['trade', 'direction', 'reasoning'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{traded: false, signal: null, why: string}
     */
    private function pass(string $why): array
    {
        return ['traded' => false, 'signal' => null, 'why' => $why];
    }
}

<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Pair Analyst
 *
 * Turns the market context the dashboard already computed into prose.
 *
 * ## What this is not
 *
 * It is not a signal source. Nothing it returns reaches `SignalGenerator`, and nothing it
 * says can open, close or size a position. That separation is deliberate and worth stating
 * because the card sits on the same screen as a Start button: a fluent paragraph about
 * gold looks like analysis, arrives with no error bars, and would be the most confident
 * thing on the page. The strategy trades EMAs and ADX, measured against stored bars and
 * backtestable. This explains that; it does not join it.
 *
 * ## Grounding
 *
 * The model is given only numbers this system computed - the same ones on the trend card -
 * and is told to describe them. It has no price feed, no news wire, and no memory of
 * previous analyses. The `reading` field is therefore checkable against the card beside
 * it, which is the property that makes it worth showing at all.
 *
 * `outlook` has no such property. It is opinion, the schema keeps it in its own field, and
 * the card labels it.
 */
final class PairAnalyst
{
    public function __construct(private readonly OpenRouter $router = new OpenRouter) {}

    public function configured(): bool
    {
        return $this->router->configured();
    }

    /**
     * @param  array<string, mixed>  $context  A MarketContext snapshot
     * @param  array<string, mixed>  $situation  Session, news and recent-signal state
     * @return array{ok: bool, analysis: PairAnalysis|null, error: string|null}
     */
    public function analyse(array $context, array $situation): array
    {
        if (! $this->configured()) {
            return $this->failure('No OPENROUTER_API_KEY is configured.');
        }

        if (! ($context['warm'] ?? false)) {
            // Nothing to describe. Asking anyway would produce a confident paragraph
            // about an empty series, which is the failure mode this whole class is
            // written to avoid.
            return $this->failure('Not enough price history to describe yet.');
        }

        $result = $this->router->structured(
            model: (string) config('ai.model'),
            system: $this->systemPrompt(),
            brief: $this->brief($context, $situation),
            schemaName: 'pair_analysis',
            schema: PairAnalysis::schema(),
        );

        if (! $result['ok']) {
            return $this->failure($result['error'] ?? 'The analysis could not be generated.');
        }

        $analysis = PairAnalysis::fromArray($result['data']);

        if ($analysis === null) {
            return $this->failure('The model returned an analysis with a missing section.');
        }

        // A failed analysis is cosmetic - the trend, session and news cards carry the
        // same facts without it, and OpenRouter never throws past this point.
        return ['ok' => true, 'analysis' => $analysis, 'error' => null];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are explaining an automated XAUUSD (gold) scalping bot's current state to the
        person who owns it. They can already see the raw numbers; your job is to say what
        they mean.

        You will be given indicator readings this system computed, plus the state of the
        gates that decide whether it may trade. That is all you know. You have no price
        feed, no news wire, no chart, and no memory of any previous analysis.

        Rules:

        - Use only the figures supplied. If something is not in the brief, you do not know
          it. Never invent a price level, a support or resistance line, a pattern, or a
          macro narrative.
        - `reading` must be checkable against the numbers given. It is the half the reader
          can verify, so it must contain nothing they cannot.
        - `outlook` is the only place for speculation, and it must read as speculation. If
          the data does not support a view, say so - "there is no directional edge in this
          data" is a better answer than a manufactured one, and the reader is better served
          by it.
        - Never tell them to buy, sell, hold, or adjust a setting. They configure the
          strategy; you describe it.
        - No hedging boilerplate, no disclaimers, no "as an AI". They know what you are.
        - Plain language. A skipped signal is not "a suboptimal entry condition", it is
          "the trend was too weak to qualify".
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $situation
     */
    private function brief(array $context, array $situation): string
    {
        $lines = [
            'INSTRUMENT: '.($context['symbol'] ?? 'unknown'),
            '',
            'INDICATORS (computed by this system from stored bars)',
            sprintf('  Higher timeframe (%s) trend: %s', $context['trend_timeframe'], $this->direction($context['trend'])),
            sprintf('  Entry timeframe (%s) EMA bias: %s', $context['entry_timeframe'], $this->direction($context['entry_bias'])),
            sprintf('  Timeframes aligned: %s (the strategy only enters when they agree)', $context['aligned'] ? 'yes' : 'no'),
            sprintf('  ADX: %s (%s)', $this->number($context['adx'], 1), $context['adx_label'] ?? 'unknown'),
            sprintf('  +DI / -DI: %s / %s', $this->number($context['plus_di'], 1), $this->number($context['minus_di'], 1)),
            sprintf('  ATR: %s (%s%% of price)', $this->number($context['atr'], 2), $this->number($context['atr_pct'], 2)),
            sprintf('  Last close: %s', $this->number($context['last_close'], 2)),
            '',
            'GATES (why it may or may not trade right now)',
            sprintf('  Kill switch: %s', ($situation['trading_enabled'] ?? false) ? 'on - entries allowed' : 'OFF - no entry will be taken'),
            sprintf('  Session: %s', $situation['session'] ?? 'unknown'),
            sprintf('  News filter: %s', $situation['news'] ?? 'unknown'),
            sprintf('  ADX threshold this strategy requires: %s', $this->number($situation['adx_threshold'] ?? null, 1)),
            sprintf('  Open positions: %s of a maximum %s', $situation['open_positions'] ?? '?', $situation['max_positions'] ?? '?'),
        ];

        if (! empty($situation['recent_skips'])) {
            $lines[] = '';
            $lines[] = 'RECENT SIGNALS (most recent first; a skip reason names the one gate that stopped it)';

            foreach ($situation['recent_skips'] as $skip) {
                $lines[] = '  '.$skip;
            }
        }

        return implode("\n", $lines);
    }

    private function direction(?string $value): string
    {
        return match ($value) {
            'buy' => 'up',
            'sell' => 'down',
            default => 'flat / undetermined',
        };
    }

    private function number(?float $value, int $decimals): string
    {
        return $value === null ? 'unavailable' : number_format($value, $decimals);
    }

    /**
     * @return array{ok: false, analysis: null, error: string}
     */
    private function failure(string $message): array
    {
        Log::info("[analyst] {$message}");

        return ['ok' => false, 'analysis' => null, 'error' => $message];
    }
}

<?php

namespace App\Services\Telegram;

use App\Models\BotSettings;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\TelegramSignal;
use App\Services\Ai\AiFund;
use App\Services\Ai\OpenRouter;
use App\Services\Indicators\Indicators;
use App\Services\Instruments\InstrumentProfile;
use App\Services\News\NewsBlackout;
use App\Services\Strategy\SignalQuality;
use App\Services\Strategy\TradingSession;
use Illuminate\Support\Carbon;

/**
 * Signal Reviewer
 *
 * Decides whether a copied signal is worth executing.
 *
 * ## The gates run first, in code
 *
 * Every deterministic objection - closed session, news blackout, exhausted fund, a signal
 * too old to still be about this market, a price that has already run past the entry - is
 * checked here before the model is asked anything.
 *
 * That ordering is the design, not an optimisation. The model can only ever decline
 * something the gates already allowed; it is never in a position to approve something they
 * blocked. An LLM that could talk its way past a risk control would make every risk control
 * in this system advisory, and they are not advisory.
 *
 * It also means a blocked signal costs nothing. There is no reason to pay for an opinion
 * about a trade that cannot be taken.
 *
 * ## It is built to decline
 *
 * The backtests on this account say the same thing repeatedly: loosening filters trades
 * more and loses more. A reviewer that looked for reasons to take things would be the same
 * mistake wearing a different hat, so the prompt requires a positive case rather than the
 * absence of a negative one, and "not enough evidence" is a decline.
 *
 * A high decline rate is the expected result. If this approves most of what it sees, that
 * is a finding about the reviewer, not about the signals.
 */
final class SignalReviewer
{
    /** Beyond this, the market that produced the signal is not the market you would enter. */
    public const MAX_AGE_MINUTES = 45;

    /**
     * How far price may run past the entry before a resting order stops being worth placing.
     *
     * Only resting orders can drift meaningfully. An order that fills at market has an
     * entry within a tenth of its stop distance of the current price - that is what makes
     * it a market order - so it cannot have drifted half a stop by definition. There was a
     * separate market threshold here and it was unreachable; the test written to cover it
     * could not be constructed, which is how the dead branch was found.
     *
     * Three stop distances is wide enough for an ordinary retrace and tight enough that an
     * order is not left waiting on a move that has already finished.
     */
    public const MAX_DRIFT_OF_STOP = 3.0;

    public function __construct(
        private readonly OpenRouter $router = new OpenRouter,
        private readonly AiFund $fund = new AiFund,
    ) {}

    /**
     * @return array{status: string, reasoning: string, confidence: int|null, model: string|null}
     */
    public function review(TelegramSignal $signal): array
    {
        if ($signal->parse_status !== TelegramSignal::PARSE_OK) {
            return $this->decline('The message never parsed into a signal, so there is nothing to review.');
        }

        $settings = BotSettings::where('user_id', $signal->user_id)->first();

        $objection = $this->gate($signal, $settings);

        if ($objection !== null) {
            // Declined without asking. A gate is not a consideration to be weighed.
            return $this->decline($objection);
        }

        if (! $this->router->configured()) {
            return $this->decline('No OPENROUTER_API_KEY is configured, so the signal cannot be reviewed. Nothing is executed unreviewed.');
        }

        $result = $this->router->structured(
            model: (string) config('ai.model'),
            system: $this->systemPrompt(),
            brief: $this->brief($signal, $settings),
            schemaName: 'signal_review',
            schema: $this->schema(),
        );

        if (! $result['ok']) {
            // A failed review is a decline, never a default-approve. The one direction this
            // must never fail in is "we could not check, so we traded it".
            return $this->decline('The review could not be completed: '.($result['error'] ?? 'unknown error').'.');
        }

        $approved = (bool) ($result['data']['approve'] ?? false);
        $reasoning = trim((string) ($result['data']['reasoning'] ?? ''));
        $confidence = (int) ($result['data']['confidence'] ?? 0);

        return [
            'status' => $approved ? TelegramSignal::REVIEW_APPROVED : TelegramSignal::REVIEW_DECLINED,
            'reasoning' => $reasoning !== '' ? $reasoning : 'No reasoning given.',
            'confidence' => max(0, min(100, $confidence)),
            'model' => $result['model'],
        ];
    }

    /**
     * The deterministic objections, in the order that costs least to check.
     */
    private function gate(TelegramSignal $signal, ?BotSettings $settings): ?string
    {
        if ($settings === null || ! $settings->is_active) {
            return 'The kill switch is off, so nothing may be opened.';
        }

        // How fast, as distinct from how much. The fund cap alone lets a provider having
        // a bad day exhaust a month's budget between breakfast and lunch while every
        // individual trade sizes correctly.
        $channel = $signal->channel;

        // Instruments this provider may trade for. A channel that is good at gold and
        // careless with indices is a common shape, and switching the whole channel off
        // over its indices loses the gold too.
        if ($channel !== null && ! $channel->allowsSymbol($signal->symbol)) {
            return "{$signal->symbol} is not an instrument this channel is allowed to trade.";
        }

        $allowance = app(SignalQuality::class)->dailyAllowance($settings, $signal->user_id);

        if ($allowance['reached']) {
            return sprintf(
                'This account has already opened %d of %d copied positions today.',
                $allowance['taken'],
                $allowance['allowed'],
            );
        }

        if (! $this->fund->canOpen($settings, $signal->user_id)) {
            $reason = $this->fund->state($settings, $signal->user_id)['blocked_reason'];

            return $this->fund->explain((string) $reason);
        }

        $profile = app(InstrumentProfile::class)->for((string) $signal->symbol);

        if ($profile['kind'] === InstrumentProfile::UNKNOWN) {
            return "{$signal->symbol} is not an instrument this system can classify, so its risk cannot be sized.";
        }

        $now = Carbon::now('UTC');
        $posted = $signal->posted_at ?? $signal->created_at;

        if ($posted !== null && $posted->diffInMinutes($now) > self::MAX_AGE_MINUTES) {
            return sprintf(
                'The signal is %d minutes old. Past %d minutes the market that produced it is not the market you would be entering.',
                $posted->diffInMinutes($now),
                self::MAX_AGE_MINUTES,
            );
        }

        if ($profile['session_gated'] && ! app(TradingSession::class)->isOpen($settings->allowed_sessions, $now)) {
            return 'No allowed trading session is open.';
        }

        $news = app(NewsBlackout::class)->objection($settings, $profile['currencies'], $now);

        if ($news !== null) {
            return $news === NewsBlackout::REASON_BLACKOUT
                ? 'A high-impact release for this instrument falls inside the blackout window.'
                : 'The economic calendar is unavailable, so the news filter cannot be checked.';
        }

        return $this->driftObjection($signal, app(SignalPlan::class)->for($signal, $settings));
    }

    /**
     * Has the trade that was posted stopped being available?
     *
     * A signal is a package: this entry, that stop, those targets. Price moving toward the
     * target before you are in shrinks the reward while leaving the risk exactly where it
     * was - so the trade you would take is not the trade that was posted, even though every
     * number in the message is still the same.
     */
    /**
     * @param  array<string, mixed>  $plan
     */
    private function driftObjection(TelegramSignal $signal, array $plan): ?string
    {
        if ($signal->entry_price === null || $signal->sl_price === null) {
            // A market order has no entry to drift from.
            return null;
        }

        $last = Candle::where('symbol', $signal->symbol)
            ->orderByDesc('open_time')
            ->value('close');

        if ($last === null) {
            // No price for this instrument. Not evidence against the signal, but nothing
            // downstream could size it either.
            return "No stored price for {$signal->symbol}, so the entry cannot be checked against the market.";
        }

        $last = (float) $last;
        $stopDistance = abs($signal->entry_price - $signal->sl_price);

        if ($stopDistance <= 0.0) {
            return 'The signal\'s stop sits on its entry, which is not a stop.';
        }

        $movement = $signal->direction === 'buy'
            ? $last - $signal->entry_price
            : $signal->entry_price - $last;

        // Past the stop already: the trade is over before it was taken.
        if ($movement <= -$stopDistance) {
            return 'Price has already passed the signal\'s stop loss.';
        }

        if ($movement > self::MAX_DRIFT_OF_STOP * $stopDistance) {
            return sprintf(
                'Price is %.1f stop distances past the entry. Resting an order there is waiting for a move that has already happened to reverse.',
                $movement / $stopDistance,
            );
        }

        return null;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You review trade signals copied from a Telegram provider, for an automated system
        that will execute what you approve on a real account.

        Everything you are told has already passed the mechanical checks: the session is
        open, no high-impact release is near, the fund has capital, and price has not run
        away from the entry. Those are not yours to re-litigate. Your job is the judgement
        those checks cannot make - whether this particular trade is worth taking.

        Decline unless there is a positive case for taking it.

        "Nothing looks wrong" is not a positive case. Absence of objection is not evidence,
        and approving on it is how a copier ends up taking everything a provider posts,
        which is indistinguishable from having no reviewer at all.

        What makes a positive case:

        - The reward justifies the risk. Compare the distance to the first target against
          the distance to the stop. Less than roughly 1:1 needs a specific reason.
        - The direction is not fighting the higher-timeframe trend you are shown. Taking a
          buy against a clearly falling trend needs to be argued for, not assumed.
        - The stop is somewhere defensible rather than an arbitrary round number a few
          points away, and it is wide enough that ordinary noise will not take it out. You
          are given ATR; use it.
        - The instrument is one the account already has price history for, so the system
          can size the position honestly.

        Reasons to decline that are worth stating plainly:

        - Reward that does not justify risk, however confident the provider sounds.
        - A stop so tight relative to ATR that it is likely to be hit by noise alone.
        - A direction opposing the trend with nothing offered to justify it.
        - Anything you cannot assess from what you were given. Say so rather than guessing.

        Never assume information you were not given. You cannot see a chart, the provider's
        record, or anything outside this brief. If the deciding factor is something you do
        not have, that is a decline and the reason is that you do not have it.

        Be brief and concrete. Two or three sentences. No hedging boilerplate.
        PROMPT;
    }

    private function brief(TelegramSignal $signal, ?BotSettings $settings): string
    {
        $profile = app(InstrumentProfile::class)->for((string) $signal->symbol);

        $last = Candle::where('symbol', $signal->symbol)->orderByDesc('open_time')->value('close');
        $last = $last === null ? null : (float) $last;

        // The levels that would actually be traded, which under `copier_levels = strategy`
        // are not the ones in the message. Judging the posted package and executing a
        // different one would be approving a trade that never existed.
        $plan = app(SignalPlan::class)->for($signal, $settings);

        $entry = $plan['entry'];
        $stopDistance = ($entry !== null && $plan['sl'] !== null)
            ? abs($entry - $plan['sl'])
            : null;

        $tps = $plan['tps'];
        $firstTarget = $tps[0] ?? null;

        // Measured here rather than left to the model. A confidence percentage a writer
        // chose looks like a measurement and is an opinion with a decimal point, and it
        // ends up deciding money - so the model is handed the count and told what it is.
        $strategy = Strategy::where('user_id', $signal->user_id)->where('is_active', true)->first()
            ?? Strategy::where('user_id', $signal->user_id)->first();

        $quality = $strategy === null ? null : app(SignalQuality::class)->assess(
            $strategy,
            $settings?->broker_account_id,
            (string) $signal->symbol,
            (string) $signal->direction,
            $signal->entry_price,
            $signal->entry_zone_high,
        );

        $lines = [
            $plan['source'] === SignalPlan::SOURCE_STRATEGY
                ? "THE TRADE, using the provider's entry with this account's stop and ladder"
                : 'THE SIGNAL, as posted',
            "  Instrument      {$signal->symbol} ({$profile['kind']})",
            '  Direction       '.strtoupper((string) $signal->direction),
            '  Entry           '.($entry === null ? 'at market' : $this->num($entry)),
            '  Stop loss       '.$this->num($plan['sl']),
            '  Targets         '.($tps === [] ? 'none given' : implode(', ', array_map(fn ($t) => $this->num((float) $t), $tps))),
            '  Posted          '.($signal->posted_at?->diffForHumans() ?? 'unknown'),
            '',
            'RISK AND REWARD',
            '  Stop distance   '.($stopDistance === null ? 'unknown (market order)' : $this->num($stopDistance)),
        ];

        if ($stopDistance !== null && $firstTarget !== null && $entry !== null) {
            $rewardDistance = abs((float) $firstTarget - $entry);
            $lines[] = '  To first target '.$this->num($rewardDistance);
            $lines[] = sprintf('  Reward:risk     %.2f : 1 (to the first target)', $rewardDistance / max($stopDistance, 1e-9));
        }

        $lines[] = '';
        $lines[] = 'THE MARKET NOW, from this system\'s own stored bars';
        $lines[] = '  Last price      '.($last === null ? 'unknown' : $this->num($last));

        $context = $this->marketContext($signal, $plan);

        foreach ($context as $label => $value) {
            $lines[] = sprintf('  %-15s %s', $label, $value);
        }

        if ($settings !== null) {
            $state = $this->fund->state($settings, $signal->user_id);
            $lines[] = '';
            $lines[] = 'THE FUND';
            $lines[] = '  Remaining       '.$this->num($state['remaining']).' of '.$this->num($state['cap']);
            $lines[] = '  Risk this trade '.$this->num($state['risk_per_trade']);
        }

        $lines[] = '';
        $lines[] = 'Approve only if there is a positive case. Absence of objection is not one.';

        return implode("\n", $lines);
    }

    /**
     * Trend and volatility for the signal's instrument, where the system has bars for it.
     *
     * @return array<string, string>
     */
    private function marketContext(TelegramSignal $signal, ?array $plan = null): array
    {
        $bars = Candle::where('symbol', $signal->symbol)
            ->orderByDesc('open_time')
            ->limit(300)
            ->get()
            ->reverse()
            ->values()
            ->all();

        if (count($bars) < 60) {
            return ['History' => 'too few stored bars for this instrument to describe its trend'];
        }

        $closes = Candle::closes($bars);
        $highs = Candle::highs($bars);
        $lows = Candle::lows($bars);

        $fast = Indicators::last(Indicators::ema($closes, 20));
        $slow = Indicators::last(Indicators::ema($closes, 50));
        $atr = Indicators::last(Indicators::atr($highs, $lows, $closes, 14));
        $adx = Indicators::last(Indicators::adx($highs, $lows, $closes, 14)['adx']);

        $trend = ($fast === null || $slow === null || $fast === $slow)
            ? 'flat'
            : ($fast > $slow ? 'up' : 'down');

        $context = [
            'Trend' => $trend.' (EMA 20 vs 50 on the stored series)',
            'ADX' => $adx === null ? 'unknown' : number_format($adx, 1),
            'ATR' => $atr === null ? 'unknown' : $this->num($atr),
        ];

        // The most useful single comparison the model can make: is this stop inside the
        // instrument's ordinary noise?
        if ($atr !== null && $atr > 0.0 && $plan !== null && $plan['entry'] !== null && $plan['sl'] !== null) {
            $context['Stop vs ATR'] = number_format(abs($plan['entry'] - $plan['sl']) / $atr, 2).' x ATR';
        }

        return $context;
    }

    private function num(?float $value): string
    {
        return $value === null ? 'unknown' : rtrim(rtrim(number_format($value, 5, '.', ''), '0'), '.');
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'approve' => [
                    'type' => 'boolean',
                    'description' => 'True only if there is a positive case for taking this trade. Absence of objection is not a positive case.',
                ],
                'confidence' => [
                    'type' => 'integer',
                    'description' => 'How strongly the evidence supports the verdict, 0-100. Low confidence on an approval means it should probably be a decline.',
                ],
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'Two or three concrete sentences naming the deciding factor. No hedging.',
                ],
            ],
            'required' => ['approve', 'confidence', 'reasoning'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{status: string, reasoning: string, confidence: null, model: null}
     */
    private function decline(string $reasoning): array
    {
        return [
            'status' => TelegramSignal::REVIEW_DECLINED,
            'reasoning' => $reasoning,
            'confidence' => null,
            'model' => null,
        ];
    }
}

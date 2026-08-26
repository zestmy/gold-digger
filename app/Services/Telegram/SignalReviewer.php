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
use App\Services\Strategy\MarketContext;
use App\Services\Strategy\SignalQuality;
use App\Services\Strategy\StrategyEvaluator;
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
        private readonly SignalSeries $series = new SignalSeries,
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
            model: (string) config('ai.reviewer_model'),
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

        $last = $this->series->lastClose($signal);

        if ($last === null) {
            // No price on this account's own series for this instrument. Not evidence
            // against the signal, but nothing downstream could size it either.
            $series = $this->series->for($signal);

            return $series['account'] === null
                ? "No broker account is reporting bars for this user, so {$signal->symbol} cannot be checked against the market."
                : "No stored price for {$series['symbol']} on this account, so the entry cannot be checked against the market.";
        }

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

        - The reward justifies the risk. The brief names the one target the order will
          actually carry and states it as a multiple of the risk. Judge against that.
          Intermediate targets are context and are never where this position exits - the
          copier does not take partials at them - so a signal is not "0.2:1" because its
          nearest target is close. Below roughly 1:1 at the exit needs a specific reason.
        - The direction is not fighting the higher-timeframe trend you are shown. Taking a
          buy against a clearly falling trend needs to be argued for, not assumed.
        - The stop is somewhere defensible rather than an arbitrary round number a few
          points away, and it is wide enough that ordinary noise will not take it out. You
          are given ATR; use it.
        - The instrument is one the account already has price history for, so the system
          can size the position honestly.

        Reasons to decline that are worth stating plainly:

        - Reward at the exit target that does not justify risk, however confident the
          provider sounds.
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

        $last = $this->series->lastClose($signal);

        // The levels that would actually be traded, which under `copier_levels = strategy`
        // are not the ones in the message. Judging the posted package and executing a
        // different one would be approving a trade that never existed.
        $plan = app(SignalPlan::class)->for($signal, $settings);

        $entry = $plan['entry'] ?? $last;
        $stopDistance = ($entry !== null && $plan['sl'] !== null)
            ? abs($entry - $plan['sl'])
            : null;

        $tps = $plan['tps'];

        // Measured here rather than left to the model. A confidence percentage a writer
        // chose looks like a measurement and is an opinion with a decimal point, and it
        // ends up deciding money - so the model is handed the count and told what it is.
        $strategy = Strategy::where('user_id', $signal->user_id)->where('is_active', true)->first()
            ?? Strategy::where('user_id', $signal->user_id)->first();

        // The account whose bars everything here is measured on. `bot_settings` has no
        // `broker_account_id` column, so the old `$settings?->broker_account_id` was
        // always null and every quality score was computed over an unscoped series.
        $series = $this->series->for($signal);

        $quality = $strategy === null ? null : app(SignalQuality::class)->assess(
            $strategy,
            $series['account'],
            $series['symbol'],
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
            '  Stop distance   '.($stopDistance === null ? 'unknown' : $this->num($stopDistance).'   - this is 1R, the whole risk on the trade'),
        ];

        foreach ($this->rewardLines($tps, $entry, $stopDistance, $settings) as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = 'THE MARKET NOW, from this system\'s own stored bars';
        $lines[] = '  Last price      '.($last === null ? 'unknown' : $this->num($last));

        $context = $this->marketContext($signal, $strategy, $plan);

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
     * What this trade risks and where it can exit, in the units the decision is made in.
     *
     * ## Why the exit target, and not the first one
     *
     * The executor sends the broker one take-profit and it is `end($targets)` - the
     * furthest rung. The intermediate levels are not managed: `PositionManager` protects a
     * copied position in multiples of R, it does not close a share at TP1 and another at
     * TP2 the way the strategy path does. So a position opened from a signal exits at its
     * final target, at its stop, or at whatever the protection has moved the stop to.
     *
     * This block used to quote the *first* target against the full stop and label it
     * "Reward:risk". On a strategy-levels plan that compares a fixed 30-pip rung against
     * an ATR-scaled stop, which on gold is under 1:1 no matter what the provider posted
     * and no matter how the trade goes - a permanent decline, for a level the order never
     * carries. Whatever else the model gets wrong, it should be told about the trade the
     * account will actually hold.
     *
     * @param  array<int, float>  $tps
     * @return array<int, string>
     */
    private function rewardLines(array $tps, ?float $entry, ?float $stopDistance, ?BotSettings $settings): array
    {
        if ($entry === null || $stopDistance === null || $stopDistance <= 0.0 || $tps === []) {
            return [];
        }

        $lines = [];
        $rungs = [];

        foreach ($tps as $i => $tp) {
            $distance = abs((float) $tp - $entry);
            $rungs[] = sprintf('TP%d %s = %.2fR', $i + 1, $this->num($distance), $distance / $stopDistance);
        }

        $exit = abs((float) end($tps) - $entry);

        $lines[] = '  Targets         '.implode(', ', $rungs);
        $lines[] = sprintf(
            '  Order exits at  TP%d, %s away - the only take-profit the order carries',
            count($tps),
            $this->num($exit),
        );
        $lines[] = sprintf('  Reward:risk     %.2f : 1 at that exit', $exit / $stopDistance);

        if (count($tps) > 1) {
            $lines[] = '  Note            The copier takes no partials at the intermediate targets.';
            $lines[] = '                  They are context; the position runs to the last one.';
        }

        foreach ($this->protectionLines($settings) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * What happens to the position before it reaches either end.
     *
     * The model was being asked to judge a naked entry-stop-target triangle for a position
     * that is in fact managed: a share banked at a configured R, the stop moved to entry or
     * trailed behind the high. That materially changes what the risk on this trade is, and
     * it was simply absent from the brief.
     *
     * @return array<int, string>
     */
    private function protectionLines(?BotSettings $settings): array
    {
        $unmanaged = ['  Unmanaged       Nothing moves this stop once open. It is the full risk, start to finish.'];
        $trigger = $settings?->copier_protect_at_r;

        if ($settings === null || $trigger === null || (float) $trigger <= 0.0) {
            return $unmanaged;
        }

        $steps = [];

        if ($settings->copier_profit_lock_pct !== null && (int) $settings->copier_profit_lock_pct > 0) {
            $steps[] = sprintf('%d%% of it is closed', (int) $settings->copier_profit_lock_pct);
        }

        if ($settings->copier_trail_distance_r !== null && (float) $settings->copier_trail_distance_r > 0.0) {
            $steps[] = sprintf('the stop trails %.2fR behind the best price', (float) $settings->copier_trail_distance_r);
        } elseif ($settings->copier_breakeven) {
            $offset = $settings->copier_breakeven_offset_pips;

            $steps[] = ($offset !== null && (float) $offset > 0.0)
                ? sprintf('the stop moves to break-even plus %s pips of costs', rtrim(rtrim(number_format((float) $offset, 2, '.', ''), '0'), '.'))
                : 'the stop moves to break-even';
        }

        if ($steps === []) {
            return $unmanaged;
        }

        return [sprintf(
            '  Once %.2fR up    %s.',
            (float) $trigger,
            ucfirst(implode(', then ', $steps)),
        )];
    }

    /**
     * Trend and volatility for the signal's instrument, on this account's own series.
     *
     * Read through `MarketContext`, which is the same snapshot the dashboard and the
     * strategy path use: trend on the strategy's trend timeframe, ATR and ADX on its entry
     * timeframe, all scoped to one account and one symbol.
     *
     * What was here before computed EMA 20/50, ATR 14 and ADX 14 over
     * `Candle::where('symbol', ...)->limit(300)`, which on an account pushing two
     * timeframes is a blend of both - and it labelled the result "the higher-timeframe
     * trend" in a prompt that asks the model to decline trades fighting it. On this
     * account's stored gold that blend read ADX 19.6 where M5 alone read 16.8 and H1 alone
     * read 40.6, and it reported a direction from a mostly-M5 window while the strategy's
     * own H1 trend pointed the other way.
     *
     * @return array<string, string>
     */
    private function marketContext(TelegramSignal $signal, ?Strategy $strategy, ?array $plan = null): array
    {
        $series = $this->series->for($signal);

        if ($series['account'] === null) {
            return ['History' => 'no broker account is reporting bars for this user, so its trend cannot be described'];
        }

        if ($strategy === null) {
            return ['History' => 'no strategy is configured, so this account has no trend definition to read'];
        }

        $market = (new MarketContext(new StrategyEvaluator))->for($strategy, $series['account'], $series['symbol']);

        if (! $market['warm']) {
            // The higher timeframe is missing or short. That is worth saying rather than
            // returning nothing: volatility on the entry series is still measurable, and
            // it is what decides whether this stop sits inside ordinary noise. Refusing
            // to describe the market at all would decline every signal on an account that
            // only pushes one timeframe, which is a bug wearing a risk control's clothes.
            return $this->entryOnlyContext($signal, $strategy, $market, $plan);
        }

        $word = fn (?string $direction) => match ($direction) {
            'buy' => 'up',
            'sell' => 'down',
            default => 'flat',
        };

        $context = [
            'Trend' => sprintf(
                '%s on %s (EMA %d vs %d) - the higher timeframe',
                $word($market['trend']),
                $market['trend_timeframe'],
                (int) $strategy->ema_fast,
                (int) $strategy->ema_slow,
            ),
            'Entry bias' => sprintf('%s on %s', $word($market['entry_bias']), $market['entry_timeframe']),
            'ADX' => sprintf(
                '%s on %s (%s)',
                $market['adx'] === null ? 'unknown' : number_format($market['adx'], 1),
                $market['entry_timeframe'],
                $market['adx_label'] ?? 'unreadable',
            ),
            'ATR' => sprintf(
                '%s on %s',
                $market['atr'] === null ? 'unknown' : $this->num($market['atr']),
                $market['entry_timeframe'],
            ),
        ];

        // The most useful single comparison the model can make: is this stop inside the
        // instrument's ordinary noise?
        $context += $this->stopVsAtr($market['atr'], $plan);

        return $context;
    }

    /**
     * Volatility and bias from the entry series alone, when the trend series is not there.
     *
     * Still one timeframe and still this account's - the property that was broken. What is
     * missing here is the higher-timeframe trend, and the brief says so in those words
     * rather than quietly presenting an entry-timeframe reading under the label the prompt
     * tells the model is the higher timeframe. That mislabelling is what produced "buying
     * into a down trend" from a hundred minutes of gold.
     *
     * @param  array<string, mixed>  $market
     * @return array<string, string>
     */
    private function entryOnlyContext(TelegramSignal $signal, Strategy $strategy, array $market, ?array $plan): array
    {
        $period = (int) $strategy->atr_period;

        $bars = $this->series->bars(
            $signal,
            (string) $market['entry_timeframe'],
            StrategyEvaluator::LOOKBACK_BARS,
        );

        if (count($bars) < ($period * 2) + 1) {
            return ['History' => sprintf(
                'too few stored bars for %s to describe its trend (%d on %s, %d on %s)',
                $this->series->for($signal)['symbol'],
                $market['bars_entry'],
                $market['entry_timeframe'],
                $market['bars_trend'],
                $market['trend_timeframe'],
            )];
        }

        $closes = Candle::closes($bars);
        $highs = Candle::highs($bars);
        $lows = Candle::lows($bars);

        $fast = Indicators::last(Indicators::ema($closes, (int) $strategy->ema_fast));
        $slow = Indicators::last(Indicators::ema($closes, (int) $strategy->ema_slow));
        $atr = Indicators::last(Indicators::atr($highs, $lows, $closes, $period));
        $adx = Indicators::last(Indicators::adx($highs, $lows, $closes, $period)['adx']);

        $bias = ($fast === null || $slow === null || $fast === $slow)
            ? 'flat'
            : ($fast > $slow ? 'up' : 'down');

        $context = [
            'Trend' => sprintf(
                'unknown - only %d %s bars stored, and the %s trend needs %d',
                $market['bars_trend'],
                $market['trend_timeframe'],
                $market['trend_timeframe'],
                (int) $strategy->ema_slow + 1,
            ),
            'Entry bias' => sprintf('%s on %s (EMA %d vs %d)', $bias, $market['entry_timeframe'], (int) $strategy->ema_fast, (int) $strategy->ema_slow),
            'ADX' => sprintf(
                '%s on %s',
                $adx === null ? 'unknown' : number_format($adx, 1),
                $market['entry_timeframe'],
            ),
            'ATR' => sprintf(
                '%s on %s',
                $atr === null ? 'unknown' : $this->num($atr),
                $market['entry_timeframe'],
            ),
        ];

        return $context + $this->stopVsAtr($atr, $plan);
    }

    /**
     * Is this stop inside the instrument's ordinary noise?
     *
     * @return array<string, string>
     */
    private function stopVsAtr(?float $atr, ?array $plan): array
    {
        if ($atr === null || $atr <= 0.0 || $plan === null || $plan['entry'] === null || $plan['sl'] === null) {
            return [];
        }

        return ['Stop vs ATR' => number_format(abs($plan['entry'] - $plan['sl']) / $atr, 2).' x ATR'];
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

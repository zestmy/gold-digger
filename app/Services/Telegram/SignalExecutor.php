<?php

namespace App\Services\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\TelegramSignal;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Ai\AiFund;
use App\Services\Strategy\SymbolResolver;

/**
 * Signal Executor
 *
 * Turns an approved signal into a queued order, or explains why it did not.
 *
 * ## The gates are checked again, here
 *
 * `SignalReviewer` already checked them. That was minutes ago, and minutes are enough for
 * the session to close, a release to come into range, the fund to be spent by another
 * position, or the executor to go offline. An approval is a statement about the moment it
 * was made, not a licence that stays valid.
 *
 * Re-checking looks redundant and is not. The alternative is a queue of approvals that
 * execute on conditions nobody has verified since - which is how a system ends up entering
 * during the news blackout it correctly identified twenty minutes earlier.
 *
 * ## Sizing comes from the fund, never the balance
 *
 * The risk on an AI position is a percentage of what remains of `ai_capital_cap`, not of
 * the account. That is the whole meaning of the cap: the account balance is not this
 * feature's to spend, and a sizing bug that read the balance instead would quietly remove
 * the only bound on something that cannot be backtested.
 *
 * ## Nothing is executed twice
 *
 * The idempotency key is the signal's own id, so a retry, a double poll, or two workers
 * racing produce one command and one position.
 */
final class SignalExecutor
{
    public function __construct(
        private readonly AiFund $fund = new AiFund,
        private readonly SignalReviewer $reviewer = new SignalReviewer,
    ) {}

    /**
     * @return array{ok: bool, status: string, note: string}
     */
    public function execute(TelegramSignal $signal): array
    {
        if (! $signal->isActionable()) {
            return $this->blocked($signal, 'Not approved, or already acted on.');
        }

        $user = User::find($signal->user_id);
        $settings = BotSettings::where('user_id', $signal->user_id)->first();

        if ($user === null || $settings === null) {
            return $this->blocked($signal, 'No account settings for this signal.');
        }

        // Everything the reviewer checked, checked again against now. See the class note:
        // an approval describes the moment it was made.
        $objection = $this->reviewer->review($signal);

        if ($objection['status'] !== TelegramSignal::REVIEW_APPROVED) {
            return $this->blocked($signal, 'Conditions changed since approval: '.$objection['reasoning']);
        }

        $heartbeat = BotHeartbeat::where('user_id', $user->id)->orderByDesc('last_seen_at')->first();

        if ($heartbeat === null || ! $heartbeat->isOnline()) {
            return $this->blocked($signal, 'No executor is online to place the order.');
        }

        if (! $heartbeat->algo_trading_enabled) {
            return $this->blocked($signal, 'Algo Trading is off in the terminal; the order would be refused with 10027.');
        }

        $spec = app(SymbolResolver::class)->for(
            $heartbeat->broker_account_id,
            (string) $signal->symbol,
            $heartbeat,
        );

        $sizing = $this->size($signal, $settings, $spec);

        if ($sizing['lots'] === null) {
            return $this->blocked($signal, $sizing['why']);
        }

        $command = TradeCommand::enqueue(
            user: $user,
            type: 'open',
            account: $heartbeat->brokerAccount,
            payload: [
                'symbol' => $spec['symbol'],
                'direction' => $signal->direction,
                'volume' => $sizing['lots'],
                'sl_pips' => $sizing['sl_pips'],
                'tp_pips' => $sizing['tp_pips'],
                'comment' => 'tg-'.$signal->id,
                // Read back by FillController. Without it the resulting position records
                // as `bot` and the fund never learns it spent anything.
                'origin' => AiFund::ORIGIN,
                'telegram_signal_id' => $signal->id,
            ],
            // One command per signal, whatever races to create it.
            idempotencyKey: "telegram:{$signal->id}",
            // A copied entry that sat in the queue is no longer the entry that was copied,
            // for the same reason the drift check exists.
            expiresInSeconds: 120,
        );

        $signal->update([
            'execution_status' => TelegramSignal::EXEC_QUEUED,
            'execution_note' => sprintf(
                'Queued %s lots, stop %s pips (%s levels), risking %s of the fund.',
                $sizing['lots'],
                round($sizing['sl_pips'], 1),
                $sizing['source'],
                round($sizing['risk'], 2),
            ),
        ]);

        return [
            'ok' => true,
            'status' => TelegramSignal::EXEC_QUEUED,
            'note' => "Queued command #{$command->id} for {$sizing['lots']} lots.",
        ];
    }

    /**
     * Position size, from the fund and the instrument's own numbers.
     *
     * @param  array<string, mixed>  $spec
     * @return array{lots: float|null, sl_pips: float, tp_pips: float|null, risk: float, source: string, why: string}
     */
    private function size(TelegramSignal $signal, BotSettings $settings, array $spec): array
    {
        $none = fn (string $why) => ['lots' => null, 'sl_pips' => 0.0, 'tp_pips' => null, 'risk' => 0.0, 'source' => '', 'why' => $why];

        $pipSize = $spec['pip_size'] ?? null;
        $pipValue = $spec['pip_value_per_lot'] ?? null;

        // The dashboard refuses to size rather than guess - the same rule the strategy
        // path follows, and the reason the pip trap never became a live bug.
        if ($pipSize === null || $pipSize <= 0.0) {
            return $none("No pip size known for {$spec['symbol']}, so no honest stop distance exists.");
        }

        if ($pipValue === null || $pipValue <= 0.0) {
            return $none("No pip value known for {$spec['symbol']}, so the position cannot be sized.");
        }

        // The same plan the reviewer judged. With `copier_levels = strategy` these are not
        // the numbers in the message, and sizing off the message while the reviewer
        // approved something else would open a trade nobody assessed.
        $plan = app(SignalPlan::class)->for($signal, $settings);

        $reference = $plan['entry'] ?? $plan['sl'];

        if ($plan['sl'] === null || $reference === null) {
            return $none('The signal carries no stop, so it cannot be sized.');
        }

        $slPips = abs($reference - $plan['sl']) / $pipSize;

        if ($slPips <= 0.0) {
            return $none('The stop distance works out to zero pips.');
        }

        $state = $this->fund->state($settings, $signal->user_id);
        $risk = $state['risk_per_trade'];

        if ($risk <= 0.0) {
            return $none('The fund has nothing left to risk on this trade.');
        }

        $lots = $risk / ($slPips * $pipValue);

        // Down onto the broker's grid, never up: rounding up takes more risk than the
        // fund allows, which is the one thing the cap exists to prevent.
        $step = (float) ($spec['volume_step'] ?? 0.01);
        $min = (float) ($spec['volume_min'] ?? 0.01);
        $lots = floor($lots / $step) * $step;

        if ($lots < $min) {
            return $none(sprintf(
                'Risking %s over a %s pip stop works out below the %s lot minimum. The fund is too small for this stop distance.',
                round($risk, 2),
                round($slPips, 1),
                $min,
            ));
        }

        $targets = $plan['tps'];
        $finalTarget = $targets === [] ? null : (float) end($targets);

        return [
            'lots' => round($lots, 2),
            'sl_pips' => $slPips,
            // The final rung, matching what the strategy path sends: an order stopped out
            // at TP1 would close the whole position at a level meant to take part of it.
            'tp_pips' => $finalTarget === null ? null : abs($finalTarget - $reference) / $pipSize,
            'risk' => $risk,
            'source' => $plan['source'],
            'why' => '',
        ];
    }

    /**
     * @return array{ok: false, status: string, note: string}
     */
    private function blocked(TelegramSignal $signal, string $note): array
    {
        $signal->update([
            'execution_status' => TelegramSignal::EXEC_BLOCKED,
            'execution_note' => mb_substr($note, 0, 250),
        ]);

        return ['ok' => false, 'status' => TelegramSignal::EXEC_BLOCKED, 'note' => $note];
    }
}

<?php

namespace App\Services\Telegram;

use App\Models\BotHeartbeat;
use App\Models\BotSettings;
use App\Models\TelegramSignal;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\User;
use App\Services\Ai\AiFund;
use App\Services\Strategy\SymbolResolver;

/**
 * Follow-Up Executor
 *
 * Carries out a management instruction against the position it refers to.
 *
 * ## Reducing risk and adding it are not the same act
 *
 * Every action here except one makes the position smaller or its stop closer. Those are
 * safe to automate almost unconditionally: the worst outcome of a wrongly-taken partial is
 * a smaller winner, and the worst outcome of a wrongly-moved stop toward entry is being
 * taken out at breakeven. Neither can lose more than doing nothing would have.
 *
 * `add_entry` is the exception and is treated as a different kind of thing throughout.
 *
 * ## A stop may only ever move toward the entry
 *
 * The single most dangerous message a provider can send is one asking for more room. "SL
 * to 4590" on a long entered at 4608 with a stop at 4603 sounds like management and is
 * the opposite: it converts a known, sized loss into a larger one, and it is exactly the
 * instruction that arrives when a trade is going wrong. So a stop that would sit further
 * from the entry than the current one is refused, always, whatever the message says and
 * however confident the model was.
 *
 * This is enforced here rather than in the interpreter deliberately. The interpreter reads
 * English; this knows the numbers.
 *
 * ## Layering is bounded by the fund, not by the provider's judgement
 *
 * Providers add entries to improve an average, and "with proper money management" is their
 * claim about themselves - not a property this copier can verify or should assume. What it
 * can do is make the claim irrelevant: every layer is sized independently from the fund's
 * remaining budget at its own stop distance, so N layers risk N shares of a pot that was
 * fixed in advance rather than N times the first position.
 *
 * The failure mode this rules out is the one that empties accounts: averaging into a loser
 * until the position is large enough that the eventual stop is unsurvivable. Here it
 * cannot be, because the money was capped before any of it was committed. What it does not
 * rule out is several layers stopping out together, which is a real cost and is why
 * MAX_LAYERS is small.
 */
final class FollowUpExecutor
{
    /**
     * Additional entries permitted on one signal.
     *
     * Small because layers are correlated: they enter the same idea and typically stop out
     * together, so three of them is one loss of three units, not three independent trades.
     */
    private const MAX_LAYERS = 2;

    /** How long the terminal has to carry out a management instruction. */
    private const EXPIRY_SECONDS = 120;

    public function __construct(private readonly SignalExecutor $executor = new SignalExecutor) {}

    /**
     * @return array{ok: bool, status: string, note: string}
     */
    public function execute(TelegramSignal $followUp): array
    {
        if (! $followUp->isActionableFollowUp()) {
            return $this->blocked($followUp, 'Not an actionable instruction, or already acted on.');
        }

        $trade = $followUp->parent->trade;
        $user = User::find($followUp->user_id);
        $settings = BotSettings::where('user_id', $followUp->user_id)->first();

        if ($user === null || $settings === null) {
            return $this->blocked($followUp, 'No account settings for this signal.');
        }

        // The kill switch governs opening and managing alike. Somebody who has switched
        // trading off has not asked for their positions to keep being adjusted.
        if (! $settings->is_active) {
            return $this->blocked($followUp, 'Trading is switched off, so nothing is being managed.');
        }

        if (! $trade->isOpen()) {
            return $this->blocked($followUp, 'The position is already closed; there is nothing left to manage.');
        }

        $heartbeat = BotHeartbeat::where('user_id', $user->id)->orderByDesc('last_seen_at')->first();

        if ($heartbeat === null || ! $heartbeat->isOnline()) {
            return $this->blocked($followUp, 'No executor is online to carry out the instruction.');
        }

        if (! $heartbeat->algo_trading_enabled) {
            return $this->blocked($followUp, 'Algo Trading is off in the terminal; the instruction would be refused with 10027.');
        }

        return match ($followUp->follow_up_action) {
            TelegramSignal::FOLLOW_PARTIAL => $this->partial($followUp, $trade, $heartbeat),
            TelegramSignal::FOLLOW_CLOSE => $this->closeOut($followUp, $trade, $heartbeat),
            TelegramSignal::FOLLOW_BREAKEVEN => $this->moveStop($followUp, $trade, $heartbeat, (float) $trade->entry_price, 'breakeven'),
            TelegramSignal::FOLLOW_MOVE_STOP => $this->moveStop($followUp, $trade, $heartbeat, (float) $followUp->follow_up_price, 'the level named'),
            TelegramSignal::FOLLOW_ADD => $this->layer($followUp, $trade, $settings, $heartbeat),
            default => $this->blocked($followUp, 'No action to take.'),
        };
    }

    // =========================================================================
    // REDUCING
    // =========================================================================

    /**
     * Take part of the position off.
     */
    private function partial(TelegramSignal $followUp, Trade $trade, BotHeartbeat $heartbeat): array
    {
        $spec = app(SymbolResolver::class)->for($heartbeat->broker_account_id, $trade->symbol, $heartbeat);

        $step = (float) ($spec['volume_step'] ?? 0.01);
        $min = (float) ($spec['volume_min'] ?? 0.01);
        $remaining = (float) $trade->remaining_lot_size;

        // Snapped down: closing more than instructed books a winner early, and the
        // instruction said part.
        $volume = floor(($remaining * (float) $followUp->follow_up_fraction) / $step) * $step;

        if ($volume < $min) {
            return $this->blocked($followUp, sprintf(
                'A %s%% partial of %s lots is below the %s minimum, so the position is left whole.',
                round((float) $followUp->follow_up_fraction * 100),
                $remaining,
                $min,
            ));
        }

        // What would be left has to be a tradeable size too. A remainder below the minimum
        // cannot exist at the broker, and the close would be rejected - or worse, silently
        // become a full exit.
        if (($remaining - $volume) < $min) {
            return $this->blocked($followUp, sprintf(
                'Taking %s of %s lots would leave less than the %s minimum behind. Closing part is not possible at this size.',
                $volume,
                $remaining,
                $min,
            ));
        }

        return $this->queue($followUp, $heartbeat, 'close', [
            'ticket' => $trade->mt5_ticket,
            'volume' => round($volume, 2),
            'reason' => 'tg-followup-partial',
        ], sprintf('Closing %s of %s lots on #%s.', round($volume, 2), $remaining, $trade->mt5_ticket));
    }

    private function closeOut(TelegramSignal $followUp, Trade $trade, BotHeartbeat $heartbeat): array
    {
        return $this->queue($followUp, $heartbeat, 'close', [
            'ticket' => $trade->mt5_ticket,
            // The whole remainder, whatever it is now - a partial may have run first.
            'volume' => round((float) $trade->remaining_lot_size, 2),
            'reason' => 'tg-followup-close',
        ], sprintf('Closing all %s lots on #%s.', round((float) $trade->remaining_lot_size, 2), $trade->mt5_ticket));
    }

    /**
     * Move the stop, but only ever toward the entry.
     *
     * See the class note: a stop moving away is the instruction that arrives when a trade
     * is going wrong, and obeying it is how a sized loss becomes an unsized one.
     */
    private function moveStop(TelegramSignal $followUp, Trade $trade, BotHeartbeat $heartbeat, float $target, string $describedAs): array
    {
        if ($target <= 0.0) {
            return $this->blocked($followUp, 'No stop level to move to.');
        }

        $isBuy = strtolower((string) $trade->direction) === 'buy';
        $current = $trade->sl_price === null ? null : (float) $trade->sl_price;

        // Toward the entry means up for a long and down for a short. With no stop
        // recorded, any stop is an improvement on none.
        $improves = $current === null
            || ($isBuy ? $target > $current : $target < $current);

        if (! $improves) {
            return $this->blocked($followUp, sprintf(
                'That stop (%s) is further from the entry than the current one (%s). A stop is only ever moved toward the entry, whatever the message says.',
                $target,
                $current,
            ));
        }

        return $this->queue($followUp, $heartbeat, 'modify', [
            'ticket' => $trade->mt5_ticket,
            'sl_price' => round($target, (int) ($heartbeat->digits ?? 2)),
            // Zero means "leave the target alone"; see CFXSExecutor::ModifyPosition.
            'tp_price' => 0.0,
            'reason' => 'tg-followup-stop',
        ], sprintf('Moving the stop on #%s to %s (%s).', $trade->mt5_ticket, round($target, 2), $describedAs));
    }

    // =========================================================================
    // ADDING
    // =========================================================================

    /**
     * A further entry on the same idea.
     *
     * Sized from the fund exactly as a fresh signal would be, which is what keeps N layers
     * risking N shares of a fixed pot rather than N times the first position.
     */
    private function layer(TelegramSignal $followUp, Trade $trade, BotSettings $settings, BotHeartbeat $heartbeat): array
    {
        $already = TelegramSignal::where('parent_signal_id', $followUp->parent_signal_id)
            ->where('follow_up_action', TelegramSignal::FOLLOW_ADD)
            ->where('execution_status', TelegramSignal::EXEC_QUEUED)
            ->where('id', '!=', $followUp->id)
            ->count();

        if ($already >= self::MAX_LAYERS) {
            return $this->blocked($followUp, sprintf(
                'This signal already has %d added entries, which is the limit. Layers stop out together, so more of them is one larger loss rather than more chances.',
                $already,
            ));
        }

        // The layer inherits the original's stop. A provider who wanted a different one
        // would have said so, and inventing one would be inventing the risk.
        $parent = $followUp->parent;

        if ($parent->sl_price === null) {
            return $this->blocked($followUp, 'The original signal carried no stop, so an added entry cannot be sized.');
        }

        // Sized, gated and queued by the same path a first entry takes - including the
        // fund cap, the concurrency limit and the refusal to round up below the minimum.
        // A layer is a position; it gets a position's scrutiny.
        $synthetic = new TelegramSignal($followUp->only([
            'user_id', 'source', 'chat_id', 'telegram_channel_id', 'chat_title',
        ]) + [
            'kind' => TelegramSignal::KIND_LAYER,
            'external_id' => "layer:{$followUp->id}",
            'raw_text' => $followUp->raw_text,
            'posted_at' => $followUp->posted_at,
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => $parent->symbol,
            'direction' => $parent->direction,
            // At market: "add here" means here, and a level the message did not name is
            // not one to guess.
            'entry_price' => null,
            'sl_price' => $parent->sl_price,
            'tp_prices' => $parent->tp_prices,
            'review_status' => TelegramSignal::REVIEW_APPROVED,
            'review_confidence' => $followUp->review_confidence,
            'execution_status' => TelegramSignal::EXEC_NONE,
        ]);

        $synthetic->save();

        $result = $this->executor->execute($synthetic);

        $followUp->update([
            'execution_status' => $result['ok'] ? TelegramSignal::EXEC_QUEUED : TelegramSignal::EXEC_BLOCKED,
            'execution_note' => 'Added entry: '.$result['note'],
            'trade_id' => $synthetic->fresh()->trade_id,
        ]);

        return $result;
    }

    // =========================================================================
    // PLUMBING
    // =========================================================================

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, status: string, note: string}
     */
    private function queue(TelegramSignal $followUp, BotHeartbeat $heartbeat, string $type, array $payload, string $note): array
    {
        $command = TradeCommand::enqueue(
            user: User::find($followUp->user_id),
            type: $type,
            account: $heartbeat->brokerAccount,
            payload: $payload + [
                'symbol' => $followUp->parent->symbol,
                'origin' => AiFund::ORIGIN,
                'telegram_signal_id' => $followUp->id,
            ],
            // One command per follow-up, whatever races to create it. Without this a retry
            // could take half off twice.
            idempotencyKey: "telegram-followup:{$followUp->id}",
            expiresInSeconds: self::EXPIRY_SECONDS,
        );

        $followUp->update([
            'execution_status' => TelegramSignal::EXEC_QUEUED,
            'execution_note' => $note,
            'trade_id' => $followUp->parent->trade_id,
        ]);

        return ['ok' => true, 'status' => TelegramSignal::EXEC_QUEUED, 'note' => $note." (command #{$command->id})"];
    }

    /**
     * @return array{ok: false, status: string, note: string}
     */
    private function blocked(TelegramSignal $followUp, string $note): array
    {
        $followUp->update([
            'execution_status' => TelegramSignal::EXEC_BLOCKED,
            'execution_note' => $note,
        ]);

        return ['ok' => false, 'status' => TelegramSignal::EXEC_BLOCKED, 'note' => $note];
    }
}

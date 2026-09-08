<?php

namespace App\Services\Trading;

use App\Models\BrokerAccount;
use App\Models\Trade;
use App\Models\TradeCommand;

/**
 * Protection Queue
 *
 * The two commands a protection engine issues - move the stop, close part or all of the
 * position - built one way for every engine.
 *
 * ## Idempotence is the whole point
 *
 * Every instruction carries a fixed key, so re-checking a rule on every bar or every
 * minute is safe: the second enqueue is a lookup, not a second close. A key that is
 * already held by a live row - pending, claimed, or completed - is not sent again. A row
 * the terminal failed or let expire is an attempt that is over, and TradeCommand::enqueue
 * re-arms it on the same key, which is what lets a trail the broker refused with 10016 be
 * proposed again once price has moved.
 *
 * ## Expiry is the caller's decision
 *
 * A strategy exit never expires: an exit that is late is still the exit, and expiring it
 * would leave open a position something decided should not be. A copier protection move
 * expires in a couple of minutes, because the same rule proposes it again on the next
 * pass from fresher bars, and a stale level the terminal picks up an hour later can be
 * behind the market. Both are right for what they are.
 */
final class ProtectionQueue
{
    /**
     * Queue a stop move. Returns the command when one was queued or re-armed, null when
     * a live row already held the key.
     *
     * @param  array<string, mixed>  $extra  Payload fields particular to the caller
     */
    public function moveStop(
        Trade $trade,
        float $level,
        string $reason,
        string $key,
        ?BrokerAccount $account,
        ?int $expiresInSeconds,
        array $extra = [],
    ): ?TradeCommand {
        return $this->send($trade, 'modify', [
            'symbol' => $trade->symbol,
            'ticket' => $trade->mt5_ticket,
            'sl_price' => round($level, 5),
            'trade_id' => $trade->id,
            'reason' => $reason,
        ] + $extra, $key, $account, $expiresInSeconds);
    }

    /**
     * Queue a close, whole or partial. A null volume means the whole remaining position.
     *
     * `reason` travels on the wire so the fill can be recorded as the rung or rule it
     * was, rather than as the "manual" that a broker-side deal reason flattens every
     * commanded close into.
     *
     * @param  array<string, mixed>  $extra
     */
    public function close(
        Trade $trade,
        ?float $volume,
        string $reason,
        string $key,
        ?BrokerAccount $account,
        ?int $expiresInSeconds,
        array $extra = [],
    ): ?TradeCommand {
        return $this->send($trade, 'close', [
            'symbol' => $trade->symbol,
            'ticket' => $trade->mt5_ticket,
            'volume' => $volume ?? (float) $trade->remaining_lot_size,
            'trade_id' => $trade->id,
            'reason' => $reason,
        ] + $extra, $key, $account, $expiresInSeconds);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(Trade $trade, string $type, array $payload, string $key, ?BrokerAccount $account, ?int $expiresInSeconds): ?TradeCommand
    {
        $live = TradeCommand::where('idempotency_key', $key)
            ->whereNotIn('status', TradeCommand::RETRYABLE_STATUSES)
            ->exists();

        if ($live) {
            return null;
        }

        return TradeCommand::enqueue(
            user: $trade->user,
            type: $type,
            payload: $payload,
            account: $account,
            idempotencyKey: $key,
            expiresInSeconds: $expiresInSeconds,
        );
    }
}

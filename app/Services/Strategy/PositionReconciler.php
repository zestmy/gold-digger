<?php

namespace App\Services\Strategy;

use App\Models\BotToken;
use App\Models\Strategy;
use App\Models\Trade;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Position Reconciler
 *
 * Makes `trades` agree with what the terminal actually holds.
 *
 * The dashboard only ever learned about positions it opened, and only when the report got
 * through. Anything else - a position opened by hand, one opened while the API was
 * unreachable, one closed at the broker while nothing was running - left the two out of step
 * with no way to notice.
 *
 * That matters more now than it used to. `TradeManager` reads `trades` and issues close
 * commands from it, so a row claiming a position that no longer exists produces commands
 * against a dead ticket for ever, and a live position with no row is managed by nothing at
 * all.
 *
 * ## The snapshot is authoritative, and only for what it covers
 *
 * The EA sends every position carrying its magic number on the account the token is bound
 * to. Within that scope the terminal is right and the table is wrong. Outside it - another
 * account, another EA's magic, a position opened before this bot existed - the snapshot says
 * nothing, and nothing is concluded.
 *
 * The magic number is the whole of that boundary, which is why a snapshot must state the one
 * it was taken with rather than letting this side assume.
 *
 * ## Adopted positions are never managed
 *
 * A position found on the terminal is recorded with `origin = 'adopted'`, and `TradeManager`
 * only manages `origin = 'bot'`. Adopting a position someone opened by hand and then closing
 * it because `max_holding_bars` elapsed would be the worst thing this feature could do.
 *
 * ## What this does not invent
 *
 * A trade that has vanished from the snapshot is closed, because leaving it open would keep
 * producing commands. Its P&L is left alone rather than zeroed: the money columns default to
 * 0 and a reconciled close writes no figures, so `closure_reason` is what says the numbers
 * were never observed. The EA replays recent closing deals through `/fills` on attach, and
 * that path - keyed on the deal ticket - is what fills in real P&L when the history reaches
 * far enough back.
 */
final class PositionReconciler
{
    /**
     * Reconcile one account against a snapshot of its open positions.
     *
     * @param  array<int, array<string, mixed>>  $positions  Every open position carrying $magic
     * @return array{adopted: array<int, int>, updated: array<int, int>, closed: array<int, int>}
     */
    public function reconcile(BotToken $token, array $positions, ?int $magic): array
    {
        $seen = [];
        $adopted = [];
        $updated = [];

        foreach ($positions as $position) {
            $ticket = (int) $position['ticket'];
            $seen[] = $ticket;

            $trade = Trade::where('mt5_ticket', $ticket)->first();

            if ($trade === null) {
                $new = $this->adopt($token, $position, $magic);

                if ($new !== null) {
                    $adopted[] = $new->id;
                }

                continue;
            }

            if ($this->refresh($trade, $position)) {
                $updated[] = $trade->id;
            }
        }

        return [
            'adopted' => $adopted,
            'updated' => $updated,
            'closed' => $this->closeVanished($token, $seen, $magic),
        ];
    }

    /**
     * Record a position the dashboard had never heard of.
     *
     * `strategy_id` is NOT NULL and an adopted position belongs to no strategy, so it is
     * attributed to the user's active one purely to satisfy the constraint. `origin` is what
     * carries the truth, and it is what `TradeManager` reads - the strategy named here never
     * gets to act on it.
     *
     * @param  array<string, mixed>  $position
     */
    private function adopt(BotToken $token, array $position, ?int $magic): ?Trade
    {
        $strategyId = Strategy::where('user_id', $token->user_id)->where('is_active', true)->value('id')
            ?? Strategy::where('user_id', $token->user_id)->value('id');

        if ($strategyId === null || $token->broker_account_id === null) {
            // Nothing to attach it to. Reported rather than guessed: the alternative is
            // inventing a strategy row for a position nobody asked this system to hold.
            return null;
        }

        $volume = (float) $position['volume'];

        return Trade::create([
            'user_id' => $token->user_id,
            'strategy_id' => $strategyId,
            'broker_account_id' => $token->broker_account_id,
            'mt5_ticket' => (int) $position['ticket'],
            'magic_number' => $magic,
            'origin' => 'adopted',
            'symbol' => $position['symbol'],
            'direction' => $position['direction'],
            'initial_lot_size' => $volume,
            'remaining_lot_size' => $volume,
            'entry_price' => $position['entry_price'],
            // 0.0 from MT5 means "no level set", which is not the same as a level at zero.
            'sl_price' => $this->level($position['sl'] ?? null),
            'tp1_price' => $this->level($position['tp'] ?? null),
            'tp2_price' => null,
            'tp3_price' => null,
            'gross_pnl_money' => $position['profit'] ?? 0,
            'status' => 'open',
            'opened_at' => isset($position['opened_at'])
                ? Carbon::createFromTimestampUTC((int) $position['opened_at'])
                : now(),
            'notes' => 'Adopted by reconciliation: this position was open on the terminal with no '
                .'matching row. It is not managed by any strategy.',
        ]);
    }

    /**
     * Bring a known trade back in line with the broker.
     *
     * The terminal is right about volume and levels: a stop moved by hand in MT5, or a
     * partial close whose report never arrived, are both invisible here otherwise. P&L is
     * refreshed too, so an open position's floating figure on the dashboard is the broker's.
     *
     * @param  array<string, mixed>  $position
     */
    private function refresh(Trade $trade, array $position): bool
    {
        $volume = (float) $position['volume'];

        $changes = [];

        if (abs((float) $trade->remaining_lot_size - $volume) > 0.00005) {
            $changes['remaining_lot_size'] = $volume;

            // Less open than recorded means something closed part of it that was never
            // reported. The row must not keep claiming lots the account does not hold.
            if ($volume < (float) $trade->initial_lot_size) {
                $changes['status'] = 'partially_closed';
            }
        }

        $sl = $this->level($position['sl'] ?? null);

        if ($sl !== null && abs((float) $trade->sl_price - $sl) > 0.00001) {
            $changes['sl_price'] = $sl;
        }

        if (isset($position['profit']) && abs((float) $trade->gross_pnl_money - (float) $position['profit']) > 0.005) {
            $changes['gross_pnl_money'] = $position['profit'];
        }

        if ($changes === []) {
            return false;
        }

        $trade->update($changes);

        return true;
    }

    /**
     * Close the trades this account believes are open but the terminal did not report.
     *
     * Scoped hard: same account, and same magic number as the snapshot was taken with. A
     * snapshot of one EA's positions says nothing about another's, and closing rows on that
     * basis would silently erase a second bot's trades.
     *
     * Trades with a null magic are included when a magic is given: those are rows this
     * system wrote before magic numbers were recorded, and they belong to this bot.
     *
     * @param  array<int, int>  $seen
     * @return array<int, int>
     */
    private function closeVanished(BotToken $token, array $seen, ?int $magic): array
    {
        // Without a magic the snapshot's scope is unknown, so its silence carries no
        // information. Falling through to an unfiltered query would do the exact opposite
        // of concluding nothing: it would close every open trade on the account.
        if ($token->broker_account_id === null || $magic === null) {
            return [];
        }

        $query = Trade::query()
            ->where('broker_account_id', $token->broker_account_id)
            ->whereIn('status', ['open', 'partially_closed'])
            ->whereNotNull('mt5_ticket');

        if ($seen !== []) {
            $query->whereNotIn('mt5_ticket', $seen);
        }

        $query->where(fn ($q) => $q->where('magic_number', $magic)->orWhereNull('magic_number'));

        $vanished = $query->get();

        if ($vanished->isEmpty()) {
            return [];
        }

        $ids = [];

        DB::transaction(function () use ($vanished, &$ids) {
            foreach ($vanished as $trade) {
                $trade->update([
                    'status' => 'fully_closed',
                    'remaining_lot_size' => 0,
                    'closure_reason' => 'reconciled_closed',
                    'closed_at' => now(),
                    'notes' => trim(($trade->notes ?? '')."\n".
                        'Closed by reconciliation: the terminal no longer holds this position. '.
                        'Any P&L figures here are whatever was last reported, not the final result.'),
                ]);

                $ids[] = $trade->id;
            }
        });

        return $ids;
    }

    /**
     * MT5 reports an unset stop or target as 0.0, which is not a level.
     */
    private function level(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = (float) $value;

        return $value > 0.0 ? $value : null;
    }
}

<?php

namespace App\Http\Controllers\Api\Bot;

use App\Http\Controllers\Controller;
use App\Models\BotToken;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradeCommand;
use App\Models\TradePartial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fill Controller
 *
 * Where the executor reports what the broker actually did, so the dashboard reflects
 * reality rather than intent.
 *
 * The EA reports pips itself rather than letting this endpoint derive them. Only the
 * terminal knows the symbol's point size, and the pips-vs-points ambiguity on gold is
 * the single most common source of wrong numbers in this system - so the value is
 * computed once, where the truth lives, instead of being re-derived here from prices
 * and a guessed multiplier.
 */
class FillController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var BotToken $token */
        $token = $request->attributes->get('bot_token');

        $data = $request->validate([
            'event' => ['required', 'in:opened,partial,closed'],
            'command_id' => ['nullable', 'integer'],
            'ticket' => ['required', 'integer'],
            'symbol' => ['required_if:event,opened', 'string', 'max:20'],
            'direction' => ['required_if:event,opened', 'in:buy,sell'],
            'volume' => ['required', 'numeric', 'gt:0'],
            'price' => ['required', 'numeric'],
            'sl' => ['nullable', 'numeric'],
            'tp1' => ['nullable', 'numeric'],
            'tp2' => ['nullable', 'numeric'],
            'tp3' => ['nullable', 'numeric'],
            'magic' => ['nullable', 'integer'],
            'spread_pips' => ['nullable', 'numeric'],
            'slippage_pips' => ['nullable', 'numeric'],
            'commission' => ['nullable', 'numeric'],
            'swap' => ['nullable', 'numeric'],
            'profit' => ['nullable', 'numeric'],
            // Required on closes: only the terminal knows the symbol's point size.
            'pips_profit' => ['required_unless:event,opened', 'numeric'],
            'reason' => ['nullable', 'in:tp1,tp2,tp3,sl,reversal_exit,time_exit,manual'],
            // trade_partials.close_reason is a fixed enum, but MT5's DEAL_REASON is
            // richer than it (and a broker-side TP fill does not say which ladder step
            // it was). This free-text note keeps the precise reason instead of
            // flattening everything into the nearest enum value.
            'closure_note' => ['nullable', 'string', 'max:255'],
            'deal_ticket' => ['nullable', 'integer'],
        ]);

        $command = isset($data['command_id'])
            ? TradeCommand::where('user_id', $token->user_id)->find($data['command_id'])
            : null;

        return $data['event'] === 'opened'
            ? $this->recordOpen($token, $data, $command)
            : $this->recordClose($token, $data, $command);
    }

    /**
     * Persist a newly opened position.
     *
     * Keyed on mt5_ticket (unique), so an EA that retries after a network timeout
     * updates the existing row instead of creating a duplicate trade.
     */
    private function recordOpen(BotToken $token, array $data, ?TradeCommand $command): JsonResponse
    {
        $payload = $command?->payload ?? [];

        $strategyId = $payload['strategy_id']
            ?? Strategy::where('user_id', $token->user_id)->where('is_active', true)->value('id')
            ?? Strategy::where('user_id', $token->user_id)->value('id');

        if ($strategyId === null) {
            return response()->json([
                'message' => 'No strategy exists for this user; cannot record a trade.',
            ], 422);
        }

        $brokerAccountId = $token->broker_account_id ?? $command?->broker_account_id;

        if ($brokerAccountId === null) {
            return response()->json([
                'message' => 'This token is not bound to a broker account and the command did not name one.',
            ], 422);
        }

        $trade = Trade::updateOrCreate(
            ['mt5_ticket' => $data['ticket']],
            [
                'user_id' => $token->user_id,
                'strategy_id' => $strategyId,
                'broker_account_id' => $brokerAccountId,
                'magic_number' => $data['magic'] ?? null,
                'symbol' => $data['symbol'],
                'direction' => $data['direction'],
                'initial_lot_size' => $data['volume'],
                'remaining_lot_size' => $data['volume'],
                'entry_price' => $data['price'],
                'sl_price' => $data['sl'] ?? $payload['sl_price'] ?? 0,
                'tp1_price' => $data['tp1'] ?? $payload['tp1_price'] ?? null,
                'tp2_price' => $data['tp2'] ?? $payload['tp2_price'] ?? null,
                'tp3_price' => $data['tp3'] ?? $payload['tp3_price'] ?? null,
                'entry_spread_pips' => $data['spread_pips'] ?? null,
                'slippage_pips' => $data['slippage_pips'] ?? null,
                'commission_money' => $data['commission'] ?? 0,
                'status' => 'open',
                'opened_at' => now(),
            ],
        );

        // Link the command to the trade it produced, so /logs can trace intent to fill.
        $command?->update(['trade_id' => $trade->id]);

        // Close the loop on the signal that asked for this position.
        //
        // SignalGenerator deliberately leaves was_executed false when it enqueues: a
        // queued command is an intention, and it can still expire or be rejected. The
        // broker confirming a fill is the first moment the signal has actually been
        // traded, so that is where the flag is set and the trade linked.
        if (isset($payload['signal_id'])) {
            Signal::where('id', $payload['signal_id'])->update([
                'was_executed' => true,
                'resulting_trade_id' => $trade->id,
            ]);
        }

        return response()->json(['trade_id' => $trade->id, 'status' => $trade->status], 201);
    }

    /**
     * Persist a full or partial close.
     *
     * Wrapped in a transaction because the partial row and the parent trade's
     * remaining volume must move together - a crash between them would leave a trade
     * claiming lots it no longer holds.
     */
    private function recordClose(BotToken $token, array $data, ?TradeCommand $command): JsonResponse
    {
        $trade = Trade::where('user_id', $token->user_id)
            ->where('mt5_ticket', $data['ticket'])
            ->first();

        if ($trade === null) {
            return response()->json([
                'message' => "No trade recorded for ticket {$data['ticket']}.",
            ], 404);
        }

        // The EA labels a broker-side take-profit fill "tp3", because the order always
        // carries the *final* rung of the ladder. When the strategy set no TP3 that final
        // rung is TP2, and the terminal has no way to know which - it never saw the ladder.
        // TradeManager only ever commands tp1/tp2, so a "tp3" here is always the broker's
        // own target and correcting it cannot collide with a commanded close.
        if (($data['reason'] ?? null) === 'tp3' && $trade->tp3_price === null) {
            $data['reason'] = 'tp2';
        }

        $partial = DB::transaction(function () use ($trade, $data) {
            $gross = $data['profit'] ?? 0;
            $commission = $data['commission'] ?? 0;
            $swap = $data['swap'] ?? 0;

            $partial = TradePartial::updateOrCreate(
                ['mt5_deal_ticket' => $data['deal_ticket'] ?? null],
                [
                    'trade_id' => $trade->id,
                    'closed_lot_size' => $data['volume'],
                    'close_price' => $data['price'],
                    'close_reason' => $data['reason'] ?? 'manual',
                    'pips_profit' => $data['pips_profit'],
                    'gross_money_profit' => $gross,
                    'commission_money' => $commission,
                    'swap_money' => $swap,
                    'net_money_profit' => $gross + $commission + $swap,
                    'closed_at' => now(),
                ],
            );

            // Costs arrive per-close, so accumulate rather than overwrite.
            $remaining = round((float) $trade->remaining_lot_size - (float) $data['volume'], 4);

            $trade->fill([
                'remaining_lot_size' => max($remaining, 0),
                'gross_pnl_pips' => (float) $trade->gross_pnl_pips + (float) $data['pips_profit'],
                'gross_pnl_money' => (float) $trade->gross_pnl_money + $gross,
                'net_pnl_money' => (float) $trade->net_pnl_money + $gross + $commission + $swap,
                'commission_money' => (float) $trade->commission_money + $commission,
                'swap_money' => (float) $trade->swap_money + $swap,
            ]);

            if ($remaining > 0.00005) {
                $trade->status = 'partially_closed';
            } else {
                // A stop-out is worth distinguishing from a target exit: the analytics
                // page separates them, and "how often do we get stopped" is the first
                // question asked of a scalping strategy.
                $trade->status = ($data['reason'] ?? null) === 'sl' ? 'stopped_out' : 'fully_closed';
                $trade->closure_reason = $data['closure_note'] ?? $data['reason'] ?? 'manual';
                $trade->closed_at = now();
            }

            $trade->save();

            return $partial;
        });

        return response()->json([
            'trade_id' => $trade->id,
            'partial_id' => $partial->id,
            'status' => $trade->status,
            'remaining_lot_size' => (float) $trade->remaining_lot_size,
        ]);
    }
}

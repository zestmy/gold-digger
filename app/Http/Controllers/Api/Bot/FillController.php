<?php

namespace App\Http\Controllers\Api\Bot;

use App\Http\Controllers\Controller;
use App\Models\BotToken;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\TelegramSignal;
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
                // Where this position came from, carried on the command that asked for it.
                // Defaults to 'bot' so nothing that predates AI trading changes meaning -
                // and it has to be right, because AiFund decides what the AI has left to
                // lose by summing exactly this column.
                'origin' => in_array($payload['origin'] ?? 'bot', ['bot', 'ai'], true)
                    ? ($payload['origin'] ?? 'bot')
                    : 'bot',
                'symbol' => $data['symbol'],
                'direction' => $data['direction'],
                'initial_lot_size' => $data['volume'],
                'remaining_lot_size' => $data['volume'],
                'entry_price' => $data['price'],
                'sl_price' => $data['sl'] ?? $payload['sl_price'] ?? 0,
                // The risk this position was opened with, written once and never revised.
                // `sl_price` above is live - PositionReconciler overwrites it with the
                // terminal's actual stop - so it stops being the opening risk the moment
                // anything moves the stop, and R is measured against this instead.
                'initial_sl_price' => $data['sl'] ?? $payload['sl_price'] ?? null,
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

        // Same idea for a copied signal: the broker confirming a fill is the first moment
        // it has actually been traded, so that is where the row stops saying `queued`.
        if (isset($payload['telegram_signal_id'])) {
            TelegramSignal::where('id', $payload['telegram_signal_id'])->update([
                'execution_status' => TelegramSignal::EXEC_EXECUTED,
                'trade_id' => $trade->id,
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

            $key = ['mt5_deal_ticket' => $data['deal_ticket'] ?? null];

            $partial = TradePartial::updateOrCreate(
                $key,
                $this->keepingWhatIsKnown(TradePartial::where($key)->first(), [
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
                ]),
            );

            // Derived from the partials, never accumulated.
            //
            // The partial row is keyed on the broker's deal ticket and so is idempotent;
            // the totals were not. Any re-delivery of a deal - a retried report, or the
            // replay the EA performs on attach - updated the row and added the money a
            // second time. A position closed in two deals showed exactly twice its profit,
            // and nothing about the number looked wrong.
            //
            // Summing what is stored is idempotent by construction, which is the only way
            // this stays right under a protocol that is allowed to repeat itself.
            $partials = $trade->partials()->get();

            $remaining = round(
                (float) $trade->initial_lot_size - (float) $partials->sum('closed_lot_size'),
                4,
            );

            $trade->fill([
                'remaining_lot_size' => max($remaining, 0),
                'gross_pnl_pips' => round((float) $partials->sum('pips_profit'), 2),
                'gross_pnl_money' => round((float) $partials->sum('gross_money_profit'), 2),
                'net_pnl_money' => round((float) $partials->sum('net_money_profit'), 2),
                'commission_money' => round((float) $partials->sum('commission_money'), 2),
                'swap_money' => round((float) $partials->sum('swap_money'), 2),
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

    /**
     * Merge a re-report onto a row that already exists, without losing what it knows.
     *
     * A deal can arrive here more than once: the report queue retries, and the EA replays
     * recent closes every time it attaches. Idempotence was solved for the money - the
     * totals are summed from the stored rows rather than accumulated - but not for the
     * fields themselves, and a replay can carry less than the live report it lands on:
     *
     *   - it sends `pips_profit` 0.00 when it cannot reach the position's opening deal,
     *     because a wrong pip figure would be worse than an absent one;
     *   - it reads DEAL_REASON, which cannot say which rung of the ladder a close was,
     *     so anything that was not a broker stop or target arrives as `manual`.
     *
     * Both are the right call in the terminal. Neither is a correction, and letting them
     * overwrite is how ticket 89795022 lost the pips on both its partials: +5.02 and
     * -0.18 in money against 0.00 pips, a commanded TP1 close filed as `manual`, and
     * `gross_pnl_pips` summing to zero on a position that made 48.4 pips.
     *
     * FXSReplayClosedDeals now works the pips out wherever it can, which is the other
     * half of this fix - but that is a property of one build of one file, running on
     * machines this server does not control, and every terminal still on an older EA
     * replays zeros. So the rule is enforced here as well, and it runs one way only: a
     * later report may fill in what is missing, and may replace one real value with
     * another, but it may never replace a known value with an absent one.
     */
    private function keepingWhatIsKnown(?TradePartial $existing, array $attributes): array
    {
        if ($existing === null) {
            return $attributes;
        }

        // Zero here is the EA saying it could not work the figure out, not a measurement.
        // A genuine scratch close writes zero over zero and is unchanged, so this only
        // ever protects a real number.
        if ((float) $attributes['pips_profit'] === 0.0 && (float) $existing->pips_profit !== 0.0) {
            $attributes['pips_profit'] = $existing->pips_profit;
        }

        // The same loss in a different field: `manual` is the replay's "cannot tell", and
        // a stored `tp1` is something only the live path - which saw the command that
        // asked for it - was ever in a position to know.
        if ($attributes['close_reason'] === 'manual' && $existing->close_reason !== 'manual') {
            $attributes['close_reason'] = $existing->close_reason;
        }

        // A deal closes once. A second report is news about an old event, not a new one,
        // so the timestamp stays where the first report put it. Left at now() the replay
        // moved the TP1 partial's close from 02:40 to 18:18 - which is not when it filled,
        // it is when the terminal reconnected.
        unset($attributes['closed_at']);

        return $attributes;
    }
}

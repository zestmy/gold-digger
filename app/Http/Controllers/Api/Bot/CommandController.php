<?php

namespace App\Http\Controllers\Api\Bot;

use App\Http\Controllers\Controller;
use App\Models\BotLog;
use App\Models\BotToken;
use App\Models\TradeCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Command Controller
 *
 * The executor's polling endpoint. The MQL5 EA calls index() every few seconds and
 * posts back to result() once the broker has answered.
 */
class CommandController extends Controller
{
    /**
     * Claim the next batch of commands for this executor.
     *
     * Content negotiation exists for one reason: MQL5 ships no JSON parser, and an
     * EA is a bad place to debug a hand-rolled one. `Accept: text/plain` returns the
     * fixed-column format documented on TradeCommand; everything else gets JSON.
     */
    public function index(Request $request): JsonResponse|Response
    {
        /** @var BotToken $token */
        $token = $request->attributes->get('bot_token');

        $limit = (int) $request->integer('limit', 10);
        $limit = max(1, min($limit, 50));

        $commands = TradeCommand::claimBatch(
            userId: $token->user_id,
            brokerAccountId: $token->broker_account_id,
            limit: $limit,
        );

        if ($this->wantsWireFormat($request)) {
            return response(TradeCommand::toWireBatch($commands), 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'X-GD-Wire-Version' => TradeCommand::WIRE_VERSION,
            ]);
        }

        return response()->json([
            'wire_version' => TradeCommand::WIRE_VERSION,
            'commands' => collect($commands)->map(fn (TradeCommand $c) => [
                'id' => $c->id,
                'type' => $c->type,
                'payload' => $c->payload ?? [],
                'attempts' => $c->attempts,
            ])->values(),
        ]);
    }

    /**
     * Record what the broker actually did with a command.
     *
     * `ok=false` carries the MT5 retcode, which is the whole point: a rejection is
     * stored with its reason attached rather than disappearing into a terminal log on
     * a VPS nobody is watching.
     */
    public function result(Request $request, TradeCommand $command): JsonResponse
    {
        /** @var BotToken $token */
        $token = $request->attributes->get('bot_token');

        // A token must not be able to complete another user's commands.
        if ($command->user_id !== $token->user_id) {
            return response()->json(['message' => 'Command not found.'], 404);
        }

        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'retcode' => ['nullable', 'integer'],
            'ticket' => ['nullable', 'integer'],
            'price' => ['nullable', 'numeric'],
            'volume' => ['nullable', 'numeric'],
            'error' => ['nullable', 'string', 'max:500'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $result = array_filter([
            'retcode' => $data['retcode'] ?? null,
            'ticket' => $data['ticket'] ?? null,
            'price' => $data['price'] ?? null,
            'volume' => $data['volume'] ?? null,
            'comment' => $data['comment'] ?? null,
        ], fn ($v) => $v !== null);

        if ($data['ok']) {
            $command->markDone($result);
        } else {
            $error = $data['error'] ?? 'Executor reported failure without a reason.';
            $command->markFailed($error, $result);

            BotLog::create([
                'level' => 'error',
                'source' => 'mql5_ea',
                'message' => "Command {$command->id} ({$command->type}) failed: {$error}",
                'context' => $result + ['command_id' => $command->id],
                'related_trade_id' => $command->trade_id,
            ]);
        }

        return response()->json(['status' => $command->status]);
    }

    /**
     * Should this client receive the tab-separated wire format?
     */
    private function wantsWireFormat(Request $request): bool
    {
        if ($request->query('format') === 'lines') {
            return true;
        }

        return str_contains((string) $request->header('Accept'), 'text/plain');
    }
}

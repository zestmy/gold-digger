<?php

namespace App\Http\Controllers\Api\Bot;

use App\Http\Controllers\Controller;
use App\Models\BotToken;
use App\Services\Strategy\PositionReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Position Controller
 *
 * Where the executor reports everything it currently holds, so `trades` can be made to agree
 * with the account.
 *
 * ## Why a full snapshot rather than events
 *
 * Events are what `/fills` already carries, and events are exactly what goes missing: a
 * position opened while the API was unreachable, or closed while nothing was running, has no
 * event anyone received. A snapshot is self-correcting - whatever was missed, the next one
 * states the truth outright.
 *
 * ## The magic number is the scope
 *
 * A snapshot means "these are all the positions carrying this magic on this account". That
 * boundary is what lets a position missing from the list be treated as closed. Sent without
 * a magic, the snapshot still adopts and refreshes what it names, but nothing is concluded
 * from absence - a second EA on the same account would otherwise have its positions closed
 * by this one's report.
 *
 * ## Deliberately not throttled here
 *
 * The EA sends this on attach and then rarely. It is a correction, not a feed: the ordinary
 * path is still `/fills`, reporting each event as it happens.
 */
class PositionController extends Controller
{
    /**
     * An account holding more open positions than this is not a scenario this bot creates,
     * and a request claiming otherwise is malformed rather than interesting.
     */
    private const MAX_POSITIONS = 500;

    public function store(Request $request, PositionReconciler $reconciler): JsonResponse
    {
        /** @var BotToken $token */
        $token = $request->attributes->get('bot_token');

        if ($token->broker_account_id === null) {
            return response()->json([
                'message' => 'This token is not bound to a broker account; positions cannot be reconciled.',
            ], 422);
        }

        $data = $request->validate([
            // Nullable, and its absence is meaningful - see the class docblock.
            'magic' => ['nullable', 'integer'],
            // present, not required: an account with nothing open sends an empty array, and
            // that is the report that closes rows for positions which have gone.
            'positions' => ['present', 'array', 'max:'.self::MAX_POSITIONS],
            'positions.*.ticket' => ['required', 'integer'],
            'positions.*.symbol' => ['required', 'string', 'max:32'],
            'positions.*.direction' => ['required', 'in:buy,sell'],
            'positions.*.volume' => ['required', 'numeric', 'gt:0'],
            'positions.*.entry_price' => ['required', 'numeric'],
            'positions.*.sl' => ['nullable', 'numeric'],
            'positions.*.tp' => ['nullable', 'numeric'],
            'positions.*.profit' => ['nullable', 'numeric'],
            'positions.*.opened_at' => ['nullable', 'integer'],
        ]);

        $result = $reconciler->reconcile($token, $data['positions'], $data['magic'] ?? null);

        return response()->json([
            'adopted' => count($result['adopted']),
            'updated' => count($result['updated']),
            'closed' => count($result['closed']),
            'adopted_trade_ids' => $result['adopted'],
            'closed_trade_ids' => $result['closed'],
        ]);
    }
}

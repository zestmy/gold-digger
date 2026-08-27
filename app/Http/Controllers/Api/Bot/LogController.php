<?php

namespace App\Http\Controllers\Api\Bot;

use App\Http\Controllers\Controller;
use App\Models\BotLog;
use App\Models\BotToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Log Controller
 *
 * Lets the executor write into bot_logs, which the /logs page already renders. The
 * table was designed for cross-system logging and nothing had ever written to it -
 * which is why an EA failing on a VPS was invisible from the dashboard.
 *
 * Accepts a batch so an EA that was briefly offline can flush its backlog in one
 * WebRequest instead of blocking its tick handler on a dozen round trips.
 */
class LogController extends Controller
{
    /** Cap per request: enough for a backlog flush, small enough to bound a bad actor. */
    private const MAX_ENTRIES = 100;

    public function store(Request $request): JsonResponse
    {
        /** @var BotToken $token */
        $token = $request->attributes->get('bot_token');

        // Accept either a single entry or {"entries": [...]} so the EA can use one
        // code path for both.
        $entries = $request->has('entries') ? $request->input('entries') : [$request->all()];

        if (! is_array($entries) || $entries === []) {
            return response()->json(['message' => 'No log entries supplied.'], 422);
        }

        if (count($entries) > self::MAX_ENTRIES) {
            return response()->json([
                'message' => 'Too many entries; maximum is '.self::MAX_ENTRIES.' per request.',
            ], 422);
        }

        $validator = validator(['entries' => $entries], [
            'entries.*.level' => ['required', 'in:debug,info,warning,error,critical'],
            'entries.*.message' => ['required', 'string', 'max:2000'],
            'entries.*.source' => ['nullable', 'string', 'max:50'],
            'entries.*.context' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid log entries.', 'errors' => $validator->errors()], 422);
        }

        $written = 0;

        foreach ($entries as $entry) {
            BotLog::create([
                // The token names the account, and the account names the tenant. Without
                // this the row belongs to nobody and appears on everybody's /logs.
                'user_id' => $token->user_id,
                'level' => $entry['level'],
                'source' => $entry['source'] ?? 'mql5_ea',
                'message' => $entry['message'],
                // Stamp the account so logs from two terminals stay tellable apart.
                'context' => ($entry['context'] ?? []) + ['broker_account_id' => $token->broker_account_id],
            ]);
            $written++;
        }

        return response()->json(['written' => $written], 201);
    }
}

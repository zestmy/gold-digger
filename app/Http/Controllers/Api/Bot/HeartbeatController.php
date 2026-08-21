<?php

namespace App\Http\Controllers\Api\Bot;

use App\Http\Controllers\Controller;
use App\Models\BotHeartbeat;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Heartbeat Controller
 *
 * Called on every executor poll. Upserts one row per user+source - see the migration
 * for why this overwrites rather than appends.
 *
 * The response carries bot_settings.is_active back to the executor, so the kill switch
 * takes effect on the next poll without needing a queued stop command to be delivered.
 * A kill switch that depends on a queue being drained is not a kill switch.
 */
class HeartbeatController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var BotToken $token */
        $token = $request->attributes->get('bot_token');
        $user = $token->user;

        $data = $request->validate([
            'source' => ['nullable', 'string', 'max:50'],
            'version' => ['nullable', 'string', 'max:32'],
            'terminal_build' => ['nullable', 'integer'],
            'algo_trading_enabled' => ['nullable', 'boolean'],
            'broker_connected' => ['nullable', 'boolean'],
            'resolved_symbol' => ['nullable', 'string', 'max:32'],
            // Symbol truth. The dashboard cannot derive any of these and must not guess
            // them - see the migration that adds the columns for what each one decides.
            'pip_size' => ['nullable', 'numeric', 'gt:0'],
            'digits' => ['nullable', 'integer', 'min:0', 'max:10'],
            'pip_value_per_lot' => ['nullable', 'numeric', 'gt:0'],
            // Needed before a position can be split into a TP ladder: a partial smaller
            // than volume_min is snapped to zero by the executor and never sent.
            'volume_min' => ['nullable', 'numeric', 'gt:0'],
            'volume_step' => ['nullable', 'numeric', 'gt:0'],
            'balance' => ['nullable', 'numeric'],
            'equity' => ['nullable', 'numeric'],
            'margin_free' => ['nullable', 'numeric'],
            'open_positions' => ['nullable', 'integer', 'min:0'],
        ]);

        $source = $data['source'] ?? 'mql5_ea';

        BotHeartbeat::updateOrCreate(
            ['user_id' => $user->id, 'source' => $source],
            [
                'broker_account_id' => $token->broker_account_id,
                'version' => $data['version'] ?? null,
                'terminal_build' => $data['terminal_build'] ?? null,
                'algo_trading_enabled' => $data['algo_trading_enabled'] ?? false,
                'broker_connected' => $data['broker_connected'] ?? false,
                'resolved_symbol' => $data['resolved_symbol'] ?? null,
                'pip_size' => $data['pip_size'] ?? null,
                'digits' => $data['digits'] ?? null,
                'pip_value_per_lot' => $data['pip_value_per_lot'] ?? null,
                'volume_min' => $data['volume_min'] ?? null,
                'volume_step' => $data['volume_step'] ?? null,
                'balance' => $data['balance'] ?? null,
                'equity' => $data['equity'] ?? null,
                'margin_free' => $data['margin_free'] ?? null,
                'open_positions' => $data['open_positions'] ?? 0,
                'last_seen_at' => now(),
            ],
        );

        // Keep the cached balance on the broker account fresh too - these columns
        // exist in the schema and were never written by anything before now.
        if ($token->broker_account_id && isset($data['balance'])) {
            BrokerAccount::where('id', $token->broker_account_id)->update([
                'last_balance' => $data['balance'],
                'last_equity' => $data['equity'] ?? $data['balance'],
                'last_synced_at' => now(),
            ]);
        }

        $settings = $user->botSettings;

        return response()->json([
            // The executor must halt new entries when this is false.
            'trading_enabled' => (bool) ($settings?->is_active ?? false),
            'max_concurrent_trades' => (int) ($settings?->max_concurrent_trades ?? 0),
            'poll_seconds' => 5,
        ]);
    }
}

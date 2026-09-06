<?php

namespace App\Http\Controllers\Api\Bot;

use App\Http\Controllers\Controller;
use App\Models\BotHeartbeat;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Heartbeat Controller
 *
 * Called on every executor poll. Upserts one row per user + broker account + source - see
 * the migrations for why this overwrites rather than appends, and why the account is part
 * of the key: keyed on user + source alone, two executors under one user took turns
 * overwriting each other's account snapshot, and the strategy layer's lookup by account
 * found no row for whichever had lost the race.
 *
 * The account comes from the token binding first and the payload second. The binding is
 * what the dashboard was told this token is for and cannot be spoofed by the executor;
 * the payload is a fallback for a token issued without one.
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
            // Every instrument this terminal will accept an order on. The dashboard can
            // generate a signal for anything it has candles for; only the terminal knows
            // what it can actually trade, and silent disagreement surfaces as an order
            // refused hours later.
            'symbols' => ['nullable', 'array', 'max:16'],
            'symbols.*.base' => ['required_with:symbols', 'string', 'max:32'],
            'symbols.*.resolved' => ['required_with:symbols', 'string', 'max:32'],
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
            // Only consulted when the token carries no binding, and only for this user's
            // own accounts - a heartbeat must not be able to describe somebody else's.
            'broker_account_id' => [
                'nullable',
                'integer',
                Rule::exists('broker_accounts', 'id')->where('user_id', $user->id),
            ],
        ]);

        $source = $data['source'] ?? 'mql5_ea';
        $accountId = $token->broker_account_id ?? ($data['broker_account_id'] ?? null);

        BotHeartbeat::updateOrCreate(
            ['user_id' => $user->id, 'broker_account_id' => $accountId, 'source' => $source],
            [
                'version' => $data['version'] ?? null,
                'terminal_build' => $data['terminal_build'] ?? null,
                'algo_trading_enabled' => $data['algo_trading_enabled'] ?? false,
                'broker_connected' => $data['broker_connected'] ?? false,
                'resolved_symbol' => $data['resolved_symbol'] ?? null,
                'symbols' => $data['symbols'] ?? null,
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
        if ($accountId && isset($data['balance'])) {
            BrokerAccount::where('id', $accountId)->update([
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

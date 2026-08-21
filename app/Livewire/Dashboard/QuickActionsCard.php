<?php

namespace App\Livewire\Dashboard;

use App\Models\BrokerAccount;
use App\Models\TradeCommand;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Quick Actions Card Component
 *
 * Start, stop, and emergency-flatten controls. These enqueue rows in trade_commands;
 * the MQL5 EA picks them up on its next poll. Nothing here talks to MT5 directly -
 * the terminal sits on a VPS behind NAT and can only poll outward.
 *
 * Start/stop also flip bot_settings.is_active, which the heartbeat response carries
 * back to the executor. That matters for stop in particular: the kill switch must not
 * depend on a queued command being successfully delivered.
 */
class QuickActionsCard extends Component
{
    /** Seconds within which repeated clicks collapse onto one command. */
    private const DEDUPE_WINDOW = 5;

    public function startBot(): void
    {
        $user = Auth::user();

        $user->botSettings?->update(['is_active' => true]);
        $this->enqueue('start');

        session()->flash('message', 'Bot started. The executor will pick this up on its next poll.');
    }

    public function stopBot(): void
    {
        $user = Auth::user();

        // Flip the flag first. Even if the queued command is never claimed, the next
        // heartbeat tells the executor to stop opening new positions.
        $user->botSettings?->update(['is_active' => false]);
        $this->enqueue('stop');

        session()->flash('message', 'Bot stopped. No new positions will be opened; open trades are left alone.');
    }

    public function closeAllPositions(): void
    {
        $this->enqueue('close_all', expiresInSeconds: 120);

        session()->flash('message', 'Close All queued. Watch Live Trades to confirm each position closes.');
    }

    /**
     * Queue a command against the active broker account.
     *
     * The idempotency key buckets by a few seconds so a double-clicked button produces
     * one command rather than two - which for close_all is the difference between one
     * flatten and a second one racing it.
     */
    private function enqueue(string $type, ?int $expiresInSeconds = null): void
    {
        $user = Auth::user();

        $account = BrokerAccount::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        $bucket = (int) floor(now()->timestamp / self::DEDUPE_WINDOW);

        TradeCommand::enqueue(
            user: $user,
            type: $type,
            account: $account,
            idempotencyKey: "{$type}-{$user->id}-{$bucket}",
            expiresInSeconds: $expiresInSeconds,
        );
    }

    public function render()
    {
        return view('livewire.dashboard.quick-actions-card', [
            'tradingEnabled' => (bool) (Auth::user()->botSettings?->is_active ?? false),
        ]);
    }
}

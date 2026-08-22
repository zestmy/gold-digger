<?php

namespace App\Livewire\Pages;

use App\Models\BotHeartbeat;
use App\Models\BrokerAccount;
use App\Models\Trade;
use App\Models\TradeCommand;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Live Trades Page
 *
 * Open positions, and the controls to close them.
 *
 * ## Closing queues a command; it does not mark the row closed
 *
 * These buttons used to update `trades` directly - the position stayed open at the broker
 * while the dashboard showed it closed, which is the most misleading state this screen can
 * be in. They now enqueue `close` commands for the EA to claim, exactly as QuickActionsCard
 * does, and the row changes only when a fill is reported.
 *
 * The consequence worth stating in the UI: pressing Close means "asked", not "done". The
 * position keeps showing until the terminal confirms.
 */
#[Layout('layouts.app')]
#[Title('Live Trades - Gold Digger')]
class LiveTrades extends Component
{
    public ?int $closingTradeId = null;

    /** Seconds within which repeated clicks collapse onto one command. */
    private const DEDUPE_WINDOW = 5;

    public function closeAllTrades(): void
    {
        $user = Auth::user();
        $bucket = (int) floor(now()->timestamp / self::DEDUPE_WINDOW);

        TradeCommand::enqueue(
            user: $user,
            type: 'close_all',
            account: $this->activeAccount(),
            idempotencyKey: "close_all-{$user->id}-{$bucket}",
            // A flatten that sat in the queue for ten minutes is not the flatten that was
            // asked for; by then the operator has either retried or changed their mind.
            expiresInSeconds: 120,
        );

        $this->dispatch('notify', message: 'Close All queued. Positions clear as the terminal confirms each one.', type: 'success');
    }

    public function closeTrade(int $id): void
    {
        $trade = Trade::where('user_id', Auth::id())
            ->whereIn('status', ['open', 'partially_closed'])
            ->findOrFail($id);

        TradeCommand::enqueue(
            user: Auth::user(),
            type: 'close',
            payload: [
                'symbol' => $trade->symbol,
                'ticket' => $trade->mt5_ticket,
                'volume' => (float) $trade->remaining_lot_size,
                'reason' => 'manual',
                'trade_id' => $trade->id,
            ],
            account: $trade->brokerAccount,
            // One manual close per position, however many times the button is pressed.
            // A second command would try to close lots the first one already took.
            idempotencyKey: "close:{$trade->id}:manual",
            expiresInSeconds: 120,
        );

        $this->closingTradeId = null;

        $this->dispatch('notify', message: "Close queued for #{$trade->mt5_ticket}. It clears when the terminal confirms.", type: 'success');
    }

    private function activeAccount(): ?BrokerAccount
    {
        return BrokerAccount::where('user_id', Auth::id())
            ->where('is_active', true)
            ->first();
    }

    public function render()
    {
        $trades = Trade::where('user_id', Auth::id())
            ->with(['strategy', 'brokerAccount', 'partials'])
            ->whereIn('status', ['pending', 'open', 'partially_closed'])
            ->orderBy('opened_at', 'desc')
            ->get();

        // Which close commands are still in flight, so a position that has been asked to
        // close can say so rather than looking like the button did nothing.
        $pendingCloses = TradeCommand::where('user_id', Auth::id())
            ->whereIn('type', ['close', 'close_all'])
            // inFlight, not merely pending: a close that lapsed unfilled must give the Close
            // button back, or the position is stuck showing "closing" with no way to retry.
            ->inFlight()
            ->pluck('trade_id')
            ->filter()
            ->all();

        $heartbeat = BotHeartbeat::where('user_id', Auth::id())
            ->orderByDesc('last_seen_at')
            ->first();

        $summary = [
            'total_positions' => $trades->count(),
            'total_lots' => $trades->sum('remaining_lot_size'),
            'unrealized_pnl' => $trades->sum('gross_pnl_money'),
            'buy_positions' => $trades->where('direction', 'buy')->count(),
            'sell_positions' => $trades->where('direction', 'sell')->count(),
            'adopted' => $trades->where('origin', 'adopted')->count(),
        ];

        return view('livewire.pages.live-trades', [
            'trades' => $trades,
            'activeAccount' => $this->activeAccount(),
            'summary' => $summary,
            'pendingCloses' => $pendingCloses,
            'heartbeat' => $heartbeat,
        ]);
    }
}

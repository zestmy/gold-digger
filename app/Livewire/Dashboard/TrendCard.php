<?php

namespace App\Livewire\Dashboard;

use App\Models\BotHeartbeat;
use App\Models\BrokerAccount;
use App\Models\Strategy;
use App\Services\Strategy\MarketContext;
use App\Services\Strategy\SymbolResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Trend Card
 *
 * What the strategy currently sees on the higher timeframe and the entry timeframe.
 *
 * Reads `MarketContext`, which derives everything through the same evaluator that decides
 * entries. This card must never compute a trend of its own: a dashboard calling gold
 * bullish while the strategy underneath it is short is worse than a dashboard that says
 * nothing, because it is the version the human remembers.
 *
 * Alignment is the headline rather than direction, because alignment is the condition the
 * strategy actually requires - a cross on the entry timeframe is only taken when the
 * higher timeframe agrees. "Trend up, entry bias down" explains a quiet afternoon exactly.
 */
class TrendCard extends Component
{
    /** @var array<string, mixed>|null */
    public ?array $context = null;

    public ?string $strategyName = null;

    public bool $hasStrategy = false;

    public function mount(): void
    {
        $this->refreshTrend();
    }

    public function refreshTrend(): void
    {
        $strategy = Strategy::where('user_id', Auth::id())
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        $this->hasStrategy = $strategy !== null;

        if ($strategy === null) {
            $this->context = null;

            return;
        }

        $this->strategyName = $strategy->name;

        $heartbeat = BotHeartbeat::where('user_id', Auth::id())
            ->orderByDesc('last_seen_at')
            ->first();

        // Falling back to the active broker account matters when the executor is offline:
        // the bars it already pushed are still the last thing known about the market, and
        // a trend card that goes blank the moment the EA disconnects would hide the state
        // the account is actually sitting in.
        $accountId = $heartbeat?->broker_account_id
            ?? BrokerAccount::where('user_id', Auth::id())->where('is_active', true)->value('id');

        // The strategy names the instrument in the abstract; the resolver says what this
        // broker publishes it as. Reading candles under the generic name would show an
        // empty card on any broker that adds a suffix.
        $spec = app(SymbolResolver::class)->for($accountId, $strategy->symbol, $heartbeat);

        $this->context = app(MarketContext::class)->for($strategy, $accountId, $spec['symbol']);
    }

    public function render()
    {
        return view('livewire.dashboard.trend-card');
    }
}

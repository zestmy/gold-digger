<?php

namespace App\Livewire\Dashboard;

use App\Models\BotSettings;
use App\Models\Signal;
use App\Models\Strategy;
use App\Models\TelegramSignal;
use App\Models\Trade;
use App\Models\User;
use App\Services\Ai\AiFund;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Today's Stats Card
 *
 * The four numbers at the top of Home: how many signals fired today, what is open, what
 * today made, and what the AI is still allowed to lose.
 *
 * ## Why these four
 *
 * This used to be trades / gross / costs / net for the day, which is a broker statement
 * and answers a question nobody asks on the way in. What somebody opening the app wants
 * is: did anything fire, am I in anything, how is the day going, and is the fund still
 * funded. Costs are on the Trades destination, beside the trades that paid them.
 *
 * ## Same definitions as the pages
 *
 * Signals today counts the same rows TodaySignalsCard lists; net P&L is settled trades
 * closed today, the same rule DailyChartCard and Analytics use; the fund figures are
 * AiFund's own. A tile disagreeing with the page it summarises is the bug this avoids.
 */
class TodayStatsCard extends Component
{
    public int $aiSignals = 0;

    public int $copiedSignals = 0;

    public int $openPositions = 0;

    /** @var array<int, string> */
    public array $openSymbols = [];

    public float $netPnl = 0;

    public float $netPnl30d = 0;

    public bool $fundConfigured = false;

    public float $fundCap = 0;

    public float $fundRemaining = 0;

    public float $fundCommitted = 0;

    public function mount(): void
    {
        $this->loadStats();
    }

    /**
     * Re-read every tile. Called by wire:poll from the view.
     */
    public function loadStats(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        // The reader's day, converted back to UTC for the columns. Same boundary the
        // signals list uses, or the tile could say three and the list show two.
        $start = Carbon::now($user->zone())->startOfDay()->utc();

        $strategyIds = Strategy::where('user_id', $user->id)->pluck('id');

        $this->aiSignals = Signal::whereIn('strategy_id', $strategyIds)
            ->where('generated_at', '>=', $start)
            ->count();

        // Dated by when the provider posted, as the list beneath dates them; capture
        // time only where the message came without one.
        $this->copiedSignals = TelegramSignal::where('user_id', $user->id)
            ->where('kind', TelegramSignal::KIND_SIGNAL)
            ->where(fn ($q) => $q
                ->where('posted_at', '>=', $start)
                ->orWhere(fn ($q) => $q->whereNull('posted_at')->where('created_at', '>=', $start)))
            ->count();

        $open = Trade::where('user_id', $user->id)
            ->whereIn('status', ['open', 'partially_closed'])
            ->get();

        $this->openPositions = $open->count();
        $this->openSymbols = $open->pluck('symbol')->unique()->values()->all();

        // Settled only: a partial close has a result that is not yet final, and counting it
        // would make the day's figure move backwards when the remainder stops out.
        $settled = Trade::where('user_id', $user->id)
            ->whereIn('status', ['fully_closed', 'stopped_out'])
            ->whereNotNull('closed_at');

        $this->netPnl = (float) (clone $settled)->where('closed_at', '>=', $start)->sum('net_pnl_money');
        $this->netPnl30d = (float) (clone $settled)->where('closed_at', '>=', now()->subDays(30)->startOfDay())->sum('net_pnl_money');

        $fund = app(AiFund::class)->state(BotSettings::where('user_id', $user->id)->first(), $user->id);

        $this->fundConfigured = $fund['configured'];
        $this->fundCap = $fund['cap'];
        $this->fundRemaining = $fund['remaining'];
        $this->fundCommitted = $fund['committed'];
    }

    public function render()
    {
        return view('livewire.dashboard.today-stats-card');
    }
}

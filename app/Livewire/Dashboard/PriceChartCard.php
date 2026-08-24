<?php

namespace App\Livewire\Dashboard;

use App\Models\BotHeartbeat;
use App\Models\BrokerAccount;
use App\Models\Candle;
use App\Models\Strategy;
use App\Models\Trade;
use App\Services\Strategy\SymbolResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Price Chart Card
 *
 * Candles from `candles`, with every open position's entry, stop and ladder drawn on them.
 *
 * ## Why the levels matter more than the candles
 *
 * A price chart alone is available in the terminal, better. What is not available there is
 * the answer to "where does the dashboard think my stop is" - and that is exactly the
 * question worth asking, because `trades.sl_price` is the dashboard's belief and the
 * broker's stop is the truth. Drawing them here makes a disagreement visible instead of
 * something you discover when a position closes somewhere unexpected.
 *
 * Nullable target prices are drawn as absent rather than as zero. `tp1_price`/`tp2_price`
 * were made nullable deliberately - a position may run on a trailing stop with no fixed
 * target - and plotting a line at 0.00 would chart a level that does not exist.
 */
class PriceChartCard extends Component
{
    /** Bars to send to the browser. Enough for context without shipping the whole table. */
    private const BARS = 300;

    public string $timeframe = 'M5';

    /** @var array<int, string> */
    public array $timeframes = ['M5', 'H1'];

    public ?string $symbol = null;

    public bool $hasData = false;

    /** @var array<int, array<string, mixed>> */
    public array $candles = [];

    /** @var array<int, array<string, mixed>> */
    public array $levels = [];

    /** @var array<int, array<string, mixed>> */
    public array $markers = [];

    public function mount(): void
    {
        $strategy = Strategy::where('user_id', Auth::id())->orderByDesc('is_active')->orderBy('id')->first();
        $this->timeframe = $strategy?->timeframe_entry ?? 'M5';
        $this->timeframes = array_values(array_unique(array_filter([
            $strategy?->timeframe_entry,
            $strategy?->timeframe_trend,
        ]))) ?: ['M5'];

        $this->load();
    }

    public function selectTimeframe(string $timeframe): void
    {
        $this->timeframe = $timeframe;
        $this->load();
    }

    /**
     * Reloaded on the poll and whenever a position changes.
     */
    #[On('trade-updated')]
    public function load(): void
    {
        $userId = Auth::id();

        $strategy = Strategy::where('user_id', $userId)->orderByDesc('is_active')->orderBy('id')->first();
        $heartbeat = BotHeartbeat::where('user_id', $userId)->orderByDesc('last_seen_at')->first();

        $accountId = $heartbeat?->broker_account_id
            ?? BrokerAccount::where('user_id', $userId)->where('is_active', true)->value('id');

        if ($strategy === null || $accountId === null) {
            $this->hasData = false;

            return;
        }

        $this->symbol = app(SymbolResolver::class)
            ->for($accountId, $strategy->symbol, $heartbeat)['symbol'];

        $bars = Candle::recentSeries($accountId, $this->symbol, $this->timeframe, self::BARS);

        $this->candles = array_map(static fn (Candle $c) => [
            // Lightweight Charts wants a UTC epoch in seconds for an intraday series.
            'time' => $c->open_time->getTimestamp(),
            'open' => (float) $c->open,
            'high' => (float) $c->high,
            'low' => (float) $c->low,
            'close' => (float) $c->close,
        ], $bars);

        $this->hasData = $this->candles !== [];

        $this->buildOverlays($userId);
    }

    /**
     * Price lines and entry markers for every position still on the book.
     */
    private function buildOverlays(int $userId): void
    {
        $trades = Trade::where('user_id', $userId)
            ->where('symbol', $this->symbol)
            ->whereIn('status', ['open', 'partially_closed'])
            ->get();

        $levels = [];
        $markers = [];

        foreach ($trades as $trade) {
            $isBuy = $trade->direction === 'buy';
            $ticket = $trade->mt5_ticket ?? $trade->id;

            $levels[] = $this->level((float) $trade->entry_price, "Entry #{$ticket}", '#9ca3af', 'solid');

            // Nullable on purpose - see the class comment. Absent means "no fixed level",
            // which is a different statement from "a level at zero".
            if ($trade->sl_price !== null) {
                $levels[] = $this->level((float) $trade->sl_price, "SL #{$ticket}", '#ef4444', 'dashed');
            }

            foreach (['tp1_price' => 'TP1', 'tp2_price' => 'TP2', 'tp3_price' => 'TP3'] as $column => $label) {
                if ($trade->{$column} !== null) {
                    $levels[] = $this->level((float) $trade->{$column}, "{$label} #{$ticket}", '#22c55e', 'dotted');
                }
            }

            if ($trade->opened_at !== null) {
                $markers[] = [
                    'time' => $trade->opened_at->getTimestamp(),
                    'position' => $isBuy ? 'belowBar' : 'aboveBar',
                    'color' => $isBuy ? '#22c55e' : '#ef4444',
                    'shape' => $isBuy ? 'arrowUp' : 'arrowDown',
                    'text' => strtoupper($trade->direction).' '.rtrim(rtrim((string) $trade->remaining_lot_size, '0'), '.'),
                ];
            }
        }

        $this->levels = $levels;
        $this->markers = $markers;
    }

    /**
     * @return array<string, mixed>
     */
    private function level(float $price, string $title, string $colour, string $style): array
    {
        return ['price' => $price, 'title' => $title, 'color' => $colour, 'style' => $style];
    }

    public function render()
    {
        return view('livewire.dashboard.price-chart-card');
    }
}

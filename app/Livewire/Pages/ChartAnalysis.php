<?php

namespace App\Livewire\Pages;

use App\Models\BotHeartbeat;
use App\Models\Candle;
use App\Models\Strategy;
use App\Services\Ai\ChartAnalyst;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Chart Analysis
 *
 * Structure, levels and a plan, for whichever instrument is asked about.
 *
 * ## On request, and not on a timer
 *
 * The autonomous trader decides and spends; this explains and stops. Keeping them apart
 * means this one can be more speculative than anything that opens a position without being
 * asked - a proposal to argue with costs nothing to be wrong about, and taking it is a
 * separate deliberate act.
 */
#[Layout('layouts.app')]
#[Title('Chart Analysis - FXSignalPro')]
class ChartAnalysis extends Component
{
    #[Url]
    public string $symbol = '';

    #[Url]
    public string $timeframe = '';

    public bool $analysed = false;

    public function mount(): void
    {
        $available = $this->available();

        if ($this->symbol === '' || ! in_array($this->symbol, $available, true)) {
            $this->symbol = $available[0] ?? '';
        }

        if ($this->timeframe === '') {
            $this->timeframe = (string) (Strategy::where('user_id', Auth::id())->value('timeframe_entry') ?? 'M5');
        }
    }

    /**
     * Deliberately a button rather than something that happens on arrival.
     *
     * Every analysis is a model call. A page that fired one on load would spend money each
     * time somebody navigated to it, including by accident.
     */
    public function analyse(): void
    {
        $this->analysed = true;
    }

    public function updatedSymbol(): void
    {
        $this->analysed = false;
    }

    public function updatedTimeframe(): void
    {
        $this->analysed = false;
    }

    /**
     * Instruments with enough stored history to read.
     *
     * @return array<int, string>
     */
    private function available(): array
    {
        return Candle::query()
            ->select('symbol')
            ->groupBy('symbol')
            ->havingRaw('count(*) >= 100')
            ->orderBy('symbol')
            ->pluck('symbol')
            ->all();
    }

    public function render(ChartAnalyst $analyst)
    {
        $strategy = Strategy::where('user_id', Auth::id())
            ->orderByDesc('is_active')->orderBy('id')->first();

        $heartbeat = BotHeartbeat::where('user_id', Auth::id())->orderByDesc('last_seen_at')->first();

        $analysis = ($this->analysed && $strategy !== null && $this->symbol !== '')
            ? $analyst->analyse($strategy, $heartbeat?->broker_account_id, $this->symbol, $this->timeframe)
            : null;

        return view('livewire.pages.chart-analysis', [
            'symbols' => $this->available(),
            'timeframes' => ['M5', 'M15', 'M30', 'H1', 'H4', 'D1'],
            'analysis' => $analysis,
            'bars' => $this->symbol === '' ? collect() : Candle::where('symbol', $this->symbol)
                ->where('timeframe', $this->timeframe)
                ->orderByDesc('open_time')
                ->limit(120)
                ->get()
                ->reverse()
                ->values(),
        ]);
    }
}

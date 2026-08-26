<?php

namespace App\Livewire\Pages;

use App\Models\BotHeartbeat;
use App\Models\Candle;
use App\Models\Strategy;
use App\Services\Ai\ChartAnalyst;
use App\Services\Ai\ScanAnalyst;
use App\Services\Analysis\MarketScanner;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Chart Analysis
 *
 * Scans every instrument there are bars for, ranks them, and proposes the ones worth a
 * look. One of them can then be opened for structure, levels and a plan.
 *
 * ## Two halves, and only one of them costs anything
 *
 * The scan is arithmetic - confluence from the same scorer the copier uses, levels
 * measured by `Structure`, a reward ratio divided out here. It runs on every render and
 * costs nothing, so the ranking is always there even with no API key, no credit, or no
 * network.
 *
 * The model is asked one question on top of that: of this shortlist, which. That is a
 * comparative judgement, it is the shape of thing a model is good at, and it is one call
 * for the whole scan rather than one per instrument.
 *
 * ## On request, and not on a timer
 *
 * The autonomous trader decides and spends; this explains and stops. Keeping them apart
 * means this one can be more speculative than anything that opens a position without being
 * asked - a proposal to argue with costs nothing to be wrong about, and taking it is a
 * separate deliberate act. Nothing on this page places an order.
 */
#[Layout('layouts.app')]
#[Title('Chart Analysis - FXSignalPro')]
class ChartAnalysis extends Component
{
    /** 'scan' across everything, or 'focus' on one instrument. */
    #[Url]
    public string $mode = 'scan';

    #[Url]
    public string $symbol = '';

    #[Url]
    public string $timeframe = '';

    /** The scan has been asked for. Until then the page shows what it would do. */
    public bool $scanned = false;

    /** The focused instrument has been read. Each one of those is a model call. */
    public bool $analysed = false;

    /**
     * Whether to ask the model to rank the shortlist.
     *
     * Off is a real choice rather than a degraded one: the measured ranking is the half
     * that can be checked, and somebody who trusts their own reading of a confluence table
     * should not have to pay for a paragraph about it.
     */
    public bool $withModel = true;

    public function mount(): void
    {
        if ($this->timeframe === '') {
            $this->timeframe = (string) (Strategy::where('user_id', Auth::id())->value('timeframe_entry') ?? 'M5');
        }

        $available = $this->available();

        if ($this->symbol !== '' && ! in_array($this->symbol, $available, true)) {
            $this->symbol = '';
        }

        // Arriving on a link that names an instrument means the focused view was asked for.
        if ($this->mode === 'focus' && $this->symbol === '') {
            $this->mode = 'scan';
        }
    }

    /**
     * Deliberately a button rather than something that happens on arrival.
     *
     * The measured half is free, but the ranking on top of it is a model call, and a page
     * that fired one on load would spend money each time somebody navigated here,
     * including by accident.
     */
    public function scan(): void
    {
        $this->mode = 'scan';
        $this->scanned = true;
    }

    /**
     * Open one instrument from the scan.
     */
    public function focus(string $symbol): void
    {
        $this->symbol = $symbol;
        $this->mode = 'focus';
        $this->analysed = true;
    }

    public function back(): void
    {
        $this->mode = 'scan';
        $this->analysed = false;
    }

    /**
     * Read the focused instrument again, past the cache.
     */
    public function analyse(): void
    {
        $this->analysed = true;
    }

    public function updatedTimeframe(): void
    {
        // A different timeframe is a different scan and a different set of levels. Keeping
        // the old results on screen under the new label would be the worst of both.
        $this->scanned = false;
        $this->analysed = false;
    }

    public function updatedSymbol(): void
    {
        $this->analysed = false;
    }

    /**
     * Turning the model off hides its card and keeps the scan; turning it on does not
     * quietly buy one.
     *
     * The measured half was free, so discarding it because somebody unticked a box would
     * be throwing away work they did not ask to lose. The other direction is a purchase,
     * and a purchase should follow a button somebody pressed on purpose.
     */
    public function updatedWithModel(bool $value): void
    {
        if ($value) {
            $this->scanned = false;
        }
    }

    /**
     * The connected terminal's account. Candles are stored per account, so asking the
     * wrong one returns an empty series and looks like missing history.
     */
    private function brokerAccountId(): ?int
    {
        return BotHeartbeat::where('user_id', Auth::id())
            ->orderByDesc('last_seen_at')
            ->value('broker_account_id');
    }

    /**
     * @return array<int, string>
     */
    private function available(): array
    {
        return MarketScanner::symbols($this->brokerAccountId(), $this->timeframe);
    }

    public function render(MarketScanner $scanner, ScanAnalyst $ranker, ChartAnalyst $analyst)
    {
        $strategy = Strategy::where('user_id', Auth::id())
            ->orderByDesc('is_active')->orderBy('id')->first();

        $account = $this->brokerAccountId();
        $symbols = $this->available();

        $scan = null;
        $ranking = null;
        $analysis = null;
        $bars = collect();

        if ($this->mode === 'scan' && $this->scanned && $strategy !== null && $symbols !== []) {
            $scan = $scanner->scan($strategy, $account, $this->timeframe, $symbols);

            if ($this->withModel) {
                $ranking = $ranker->rank($scan['candidates'], $this->timeframe);
            }
        }

        if ($this->mode === 'focus' && $this->symbol !== '') {
            if ($this->analysed && $strategy !== null) {
                $analysis = $analyst->analyse($strategy, $account, $this->symbol, $this->timeframe);
            }

            $bars = Candle::query()
                ->series($account, $this->symbol, $this->timeframe)
                ->orderByDesc('open_time')
                ->limit(120)
                ->get()
                ->reverse()
                ->values();
        }

        return view('livewire.pages.chart-analysis', [
            'symbols' => $symbols,
            'timeframes' => ['M5', 'M15', 'M30', 'H1', 'H4', 'D1'],
            'scan' => $scan,
            'ranking' => $ranking,
            'analysis' => $analysis,
            'bars' => $bars,
            'hasStrategy' => $strategy !== null,
        ]);
    }
}

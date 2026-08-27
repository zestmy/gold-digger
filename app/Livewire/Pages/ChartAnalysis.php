<?php

namespace App\Livewire\Pages;

use App\Models\BotHeartbeat;
use App\Models\Candle;
use App\Models\ChartAnalysis as StoredReading;
use App\Models\Strategy;
use App\Services\Ai\ChartAnalyst;
use App\Services\Ai\ScanAnalyst;
use App\Services\Analysis\MarketScanner;
use Illuminate\Support\Collection;
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
    /** Past readings shown beneath. Enough to see a change of mind, few enough to scan. */
    private const HISTORY = 10;

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

    /**
     * What the chart draws on top of the candles.
     *
     * Toggles rather than everything at once, because these overlays answer different
     * questions and stacking all of them turns a chart into a diagram. Structure and the
     * proposed plan are on by default - they are what the page is for. Every measured
     * level is off, because on a busy instrument that is a dozen horizontal lines and the
     * three that matter stop being findable among them.
     *
     * @var array<string, bool>
     */
    public array $overlays = [
        'levels' => false,
        'structure' => true,
        'plan' => true,
    ];

    /**
     * Candles for the chart, in the shape Lightweight Charts wants.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $candles = [];

    /** @var array<int, array<string, mixed>> */
    public array $chartLevels = [];

    /** @var array<int, array<string, mixed>> */
    public array $chartMarkers = [];

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

            $this->paint($bars, $analysis);
        }

        return view('livewire.pages.chart-analysis', [
            'symbols' => $symbols,
            'timeframes' => ['M5', 'M15', 'M30', 'H1', 'H4', 'D1'],
            'scan' => $scan,
            'ranking' => $ranking,
            'analysis' => $analysis,
            'bars' => $bars,
            'hasStrategy' => $strategy !== null,
            'history' => $this->history(),
        ]);
    }

    /**
     * Turn the bars and the reading into something the chart can draw.
     *
     * ## Why the overlays are built here and not in JavaScript
     *
     * The same reason the levels themselves are measured in PHP: everything drawn on this
     * chart has to be a number this system computed, and a browser that derives its own
     * pivots would eventually disagree with the list the model was shown. One source, one
     * set of lines.
     *
     * @param  Collection<int, Candle>  $bars
     * @param  array<string, mixed>|null  $analysis
     */
    private function paint(Collection $bars, ?array $analysis): void
    {
        $this->candles = $bars->map(fn (Candle $c) => [
            // Lightweight Charts wants a UTC epoch in seconds for an intraday series.
            'time' => $c->open_time->getTimestamp(),
            'open' => (float) $c->open,
            'high' => (float) $c->high,
            'low' => (float) $c->low,
            'close' => (float) $c->close,
        ])->all();

        $levels = [];
        $markers = [];

        if ($analysis === null) {
            $this->chartLevels = [];
            $this->chartMarkers = [];

            return;
        }

        // Every price this instrument turned at. Off by default: on a busy chart this is a
        // dozen lines, and the three the plan actually uses stop being findable among them.
        if ($this->overlays['levels']) {
            foreach ($analysis['levels'] ?? [] as $i => $level) {
                $levels[] = [
                    'price' => (float) $level['price'],
                    'title' => sprintf('[%d] %sx', $i, $level['touches']),
                    // Weight by evidence: a level tested four times is drawn brighter than
                    // one touched once, because that difference is the entire reason the
                    // touch count is computed.
                    'color' => $level['touches'] >= 3 ? '#a78bfa' : 'rgba(167, 139, 250, 0.45)',
                    'style' => 'dotted',
                ];
            }
        }

        $reading = $analysis['reading'] ?? null;

        // The proposed trade. Only when all three are real - a half-drawn ladder reads as
        // a trade that was never proposed, which is the failure the null prices exist to
        // prevent everywhere else.
        if ($this->overlays['plan'] && $reading !== null && ($reading['plan'] ?? 'wait') !== 'wait') {
            foreach ([
                ['entry_price', 'Entry', '#e5e7eb', 'solid'],
                ['stop_price', 'Stop', '#ef4444', 'dashed'],
                ['target_price', 'Target', '#22c55e', 'dotted'],
            ] as [$field, $label, $colour, $style]) {
                if (($reading[$field] ?? null) !== null) {
                    $levels[] = ['price' => (float) $reading[$field], 'title' => $label, 'color' => $colour, 'style' => $style];
                }
            }
        }

        // Where structure broke, and which kind of break it was. BOS and CHoCH mean
        // different things and the marker says which - a chart that showed both as "break"
        // would throw away the distinction the detection exists to make.
        if ($this->overlays['structure']) {
            foreach ($analysis['events'] ?? [] as $event) {
                $bar = $bars->get($event['index']);

                if ($bar === null) {
                    continue;
                }

                $bullish = $event['direction'] === 'bullish';

                $markers[] = [
                    'time' => $bar->open_time->getTimestamp(),
                    'position' => $bullish ? 'belowBar' : 'aboveBar',
                    'color' => $event['type'] === 'CHoCH' ? '#f59e0b' : ($bullish ? '#22c55e' : '#ef4444'),
                    'shape' => $bullish ? 'arrowUp' : 'arrowDown',
                    'text' => $event['type'],
                ];
            }
        }

        $this->chartLevels = $levels;
        $this->chartMarkers = $markers;
    }

    /**
     * What this account has been told before.
     *
     * Shown whether or not anything has been analysed this visit, because the most common
     * reason to open this page is to find something read yesterday - and a reading that
     * only existed while its tab was open was the state of things before
     * `chart_analyses` existed.
     *
     * Scoped to the focused instrument when there is one: on a page about gold, a list of
     * readings about everything else is noise.
     *
     * @return Collection<int, StoredReading>
     */
    private function history(): Collection
    {
        return StoredReading::query()
            ->when($this->mode === 'focus' && $this->symbol !== '', fn ($q) => $q->forSymbol($this->symbol))
            ->orderByDesc('bar_open_time')
            ->limit(self::HISTORY)
            ->get();
    }
}

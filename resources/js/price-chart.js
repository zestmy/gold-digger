import { createChart, CandlestickSeries, LineStyle, createSeriesMarkers } from 'lightweight-charts';

/**
 * Price chart with position overlays.
 *
 * Registered as an Alpine component rather than run on DOMContentLoaded, because the
 * chart lives inside a Livewire component: it is re-rendered on poll, on timeframe
 * change, and whenever a position updates. A one-shot initialiser would bind to a node
 * Livewire later replaces, leaving a dead canvas and a leaked resize observer.
 *
 * Lightweight Charts v5 API — `chart.addSeries(CandlestickSeries, …)`, not v4's
 * `chart.addCandlestickSeries()`.
 *
 * @param {object} sources Livewire property names to read from, when a component holds
 *   its overlays under different names. The dashboard card uses the defaults; the analysis
 *   page keeps `levels` for the measured list it renders as a table, so its chart overlays
 *   live under their own names. Parameterising is cheaper than either page renaming a
 *   property it uses elsewhere, or a second copy of this file drifting from the first.
 */
export default function priceChart(sources = {}) {
    const keys = { candles: 'candles', levels: 'levels', markers: 'markers', ...sources };

    return {
        chart: null,
        series: null,
        priceLines: [],
        markersPlugin: null,

        init() {
            this.build();

            // $wire.$watch fires after Livewire round-trips, which is what repaints the
            // chart when the poll brings a new bar, a position closes, or an overlay is
            // switched on.
            this.$watch(`$wire.${keys.candles}`, () => this.repaint());
            this.$watch(`$wire.${keys.levels}`, () => this.drawLevels());
            this.$watch(`$wire.${keys.markers}`, () => this.drawMarkers());

            this.$el._resize = new ResizeObserver(() => {
                if (this.chart) {
                    this.chart.applyOptions({ width: this.$refs.canvas.clientWidth });
                }
            });
            this.$el._resize.observe(this.$refs.canvas);
        },

        destroy() {
            this.$el._resize?.disconnect();
            this.chart?.remove();
            this.chart = null;
        },

        build() {
            this.chart = createChart(this.$refs.canvas, {
                width: this.$refs.canvas.clientWidth,
                height: 420,
                layout: {
                    background: { color: 'transparent' },
                    textColor: '#9ca3af',
                    attributionLogo: false,
                },
                grid: {
                    vertLines: { color: 'rgba(75, 85, 99, 0.2)' },
                    horzLines: { color: 'rgba(75, 85, 99, 0.2)' },
                },
                rightPriceScale: { borderColor: 'rgba(75, 85, 99, 0.4)' },
                timeScale: {
                    borderColor: 'rgba(75, 85, 99, 0.4)',
                    timeVisible: true,
                    secondsVisible: false,
                },
                crosshair: { mode: 0 },
            });

            this.series = this.chart.addSeries(CandlestickSeries, {
                upColor: '#22c55e',
                downColor: '#ef4444',
                borderUpColor: '#22c55e',
                borderDownColor: '#ef4444',
                wickUpColor: '#22c55e',
                wickDownColor: '#ef4444',
                // The last-price line is drawn by default but its value label is easy to
                // lose among the scale's own gridline labels. Both on, explicitly: a
                // chart whose current price you have to estimate off the axis is not
                // answering the question you opened it to ask.
                priceLineVisible: true,
                lastValueVisible: true,
                priceLineWidth: 1,
                priceLineStyle: LineStyle.Dashed,
                priceLineColor: '#eab308',
            });

            this.repaint();
        },

        repaint() {
            if (!this.series) return;

            const candles = this.$wire[keys.candles] ?? [];
            if (!candles.length) return;

            this.series.setData(candles);
            this.drawLevels();
            this.drawMarkers();
            this.chart.timeScale().fitContent();
        },

        drawLevels() {
            if (!this.series) return;

            // Remove and redraw rather than diff: a handful of lines per position makes
            // reconciliation more code than it saves, and a stale stop line is exactly
            // the thing this chart exists to avoid showing.
            this.priceLines.forEach((line) => this.series.removePriceLine(line));
            this.priceLines = [];

            const styles = {
                solid: LineStyle.Solid,
                dashed: LineStyle.Dashed,
                dotted: LineStyle.Dotted,
            };

            (this.$wire[keys.levels] ?? []).forEach((level) => {
                this.priceLines.push(
                    this.series.createPriceLine({
                        price: level.price,
                        color: level.color,
                        lineWidth: 1,
                        lineStyle: styles[level.style] ?? LineStyle.Solid,
                        axisLabelVisible: true,
                        title: level.title,
                    }),
                );
            });
        },

        drawMarkers() {
            if (!this.series) return;

            const markers = this.$wire[keys.markers] ?? [];

            if (!this.markersPlugin) {
                this.markersPlugin = createSeriesMarkers(this.series, markers);
                return;
            }

            this.markersPlugin.setMarkers(markers);
        },
    };
}

{{--
    Equity Curve

    Cumulative net P&L over closed trades, drawn as inline SVG against a real zero baseline.
    No chart library: nothing to load, and it works under the dashboard's CSP.
--}}

<div class="rounded-lg bg-gray-800 p-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h3 class="text-lg font-semibold text-white">Equity Curve</h3>
            <p class="mt-0.5 text-xs text-gray-500">Cumulative net P&amp;L, last {{ $days }} days</p>
        </div>

        @if($geometry)
            <div class="text-right">
                <span class="block text-lg font-semibold tabular-nums {{ $geometry['positive'] ? 'text-green-400' : 'text-red-400' }}">
                    {{ $geometry['final'] >= 0 ? '+' : '' }}${{ number_format($geometry['final'], 2) }}
                </span>
                <span class="block text-xs text-gray-500">
                    peak ${{ number_format($geometry['high'], 2) }} &middot; trough ${{ number_format($geometry['low'], 2) }}
                </span>
            </div>
        @endif
    </div>

    @if($geometry)
        @php
            // Green when the curve ends above water, red below. The gradient id has to be
            // unique per state or two cards on a page would share the first one defined.
            $stroke = $geometry['positive'] ? '#4ade80' : '#f87171';
            $fillId = 'equity-fill-'.($geometry['positive'] ? 'up' : 'down');
        @endphp

        <div class="mt-5">
            <svg viewBox="0 0 100 40" preserveAspectRatio="none" class="h-40 w-full overflow-visible"
                 role="img" aria-label="Cumulative profit and loss over the last {{ $days }} days, ending at {{ number_format($geometry['final'], 2) }} dollars">
                <defs>
                    <linearGradient id="{{ $fillId }}" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="{{ $stroke }}" stop-opacity="0.28" />
                        <stop offset="100%" stop-color="{{ $stroke }}" stop-opacity="0" />
                    </linearGradient>
                </defs>

                {{-- Break-even. Without it a curve that never went negative reads as if it
                     started from the floor. --}}
                <line x1="0" y1="{{ $geometry['zero'] }}" x2="100" y2="{{ $geometry['zero'] }}"
                      stroke="#4b5563" stroke-width="0.3" stroke-dasharray="1.5 1.5" vector-effect="non-scaling-stroke" />

                <polygon points="{{ $geometry['area'] }}" fill="url(#{{ $fillId }})" />

                {{-- non-scaling-stroke, or the 100x40 viewBox stretching to the container
                     width would render the line thick horizontally and hairline vertically. --}}
                <polyline points="{{ $geometry['line'] }}" fill="none" stroke="{{ $stroke }}"
                          stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"
                          vector-effect="non-scaling-stroke" />

                <circle cx="{{ $geometry['final_x'] }}" cy="{{ $geometry['final_y'] }}" r="2.5"
                        fill="{{ $stroke }}" vector-effect="non-scaling-stroke" />
            </svg>
        </div>

        <div class="mt-3 flex justify-between text-xs text-gray-500 tabular-nums">
            <span>{{ \Illuminate\Support\Carbon::parse($points[0]['day'])->format('M j') }}</span>
            <span>{{ count($points) }} trading days</span>
            <span>{{ \Illuminate\Support\Carbon::parse(end($points)['day'])->format('M j') }}</span>
        </div>
    @elseif(count($points) === 1)
        {{-- One day is a number, not a curve. --}}
        <div class="mt-6 flex h-40 flex-col items-center justify-center">
            <span class="text-2xl font-semibold tabular-nums {{ $points[0]['cumulative'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                {{ $points[0]['cumulative'] >= 0 ? '+' : '' }}${{ number_format($points[0]['cumulative'], 2) }}
            </span>
            <span class="mt-1 text-xs text-gray-500">
                from {{ $points[0]['trades'] }} {{ Str::plural('trade', $points[0]['trades']) }} on one day
            </span>
            <span class="mt-3 text-xs text-gray-600">A curve needs a second day of closed trades.</span>
        </div>
    @else
        <div class="mt-6 flex h-40 flex-col items-center justify-center text-center">
            <svg class="h-8 w-8 text-gray-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
            </svg>
            <p class="mt-3 text-sm text-gray-400">No closed trades yet</p>
            <p class="mt-1 text-xs text-gray-600">The curve appears once trades start settling.</p>
        </div>
    @endif
</div>

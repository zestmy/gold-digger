{{-- Polls every 30s. New bars arrive on the entry timeframe's cadence (M5 by default),
     so this is already far faster than the data changes; it exists so a warm-up finishing
     or a feed stopping becomes visible without a refresh. --}}
<div class="rounded-lg bg-gray-800 p-6" wire:poll.30s="refreshTrend">
    <div class="flex items-baseline justify-between">
        <h3 class="text-sm font-medium text-gray-400">Trend</h3>
        @if($context)
            <span class="font-mono text-xs text-gray-500">{{ $context['symbol'] }}</span>
        @endif
    </div>

    @if(! $hasStrategy)
        <p class="mt-4 text-sm text-gray-500">No strategy configured.</p>
        <a href="{{ route('strategies') }}" class="text-xs text-yellow-500 hover:text-yellow-400">Create one &rarr;</a>

    @elseif(! $context || ! $context['warm'])
        {{-- Warming up is not a fault, and is the expected state for the first ~100 bars
             after an executor attaches. Say which series is short rather than showing
             dashes: "no bars at all" and "not enough yet" have different fixes. --}}
        <div class="mt-4">
            <div class="flex items-center gap-x-3">
                <div class="h-3 w-3 rounded-full bg-gray-600"></div>
                <span class="text-lg font-semibold text-gray-400">WARMING UP</span>
            </div>
            <p class="mt-2 text-sm text-gray-500">
                @if(($context['bars_entry'] ?? 0) === 0)
                    No {{ $context['entry_timeframe'] ?? '' }} bars have arrived yet.
                @else
                    {{ $context['bars_entry'] }} {{ $context['entry_timeframe'] }} /
                    {{ $context['bars_trend'] }} {{ $context['trend_timeframe'] }} bars &mdash;
                    the indicators need more before they read at all.
                @endif
            </p>
        </div>

    @else
        {{-- Alignment leads, because alignment is what the strategy requires. --}}
        <div class="mt-4 flex items-center gap-x-3">
            @if($context['aligned'] && $context['trend'] === 'buy')
                <div class="h-3 w-3 rounded-full bg-green-500"></div>
                <span class="text-lg font-semibold text-green-400">BULLISH</span>
            @elseif($context['aligned'] && $context['trend'] === 'sell')
                <div class="h-3 w-3 rounded-full bg-red-500"></div>
                <span class="text-lg font-semibold text-red-400">BEARISH</span>
            @else
                <div class="h-3 w-3 rounded-full bg-gray-500"></div>
                <span class="text-lg font-semibold text-gray-400">MIXED</span>
            @endif

            @if($context['adx_label'])
                <span class="rounded bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-300">
                    {{ $context['adx_label'] }}
                </span>
            @endif
        </div>

        @unless($context['aligned'])
            <p class="mt-2 text-xs text-gray-500">
                {{ $context['trend_timeframe'] }} and {{ $context['entry_timeframe'] }} disagree &mdash;
                the strategy only takes a cross the higher timeframe confirms.
            </p>
        @endunless

        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $context['trend_timeframe'] }} trend</dt>
                <dd class="mt-0.5 font-medium {{ $context['trend'] === 'buy' ? 'text-green-400' : ($context['trend'] === 'sell' ? 'text-red-400' : 'text-gray-400') }}">
                    {{ $context['trend'] === 'buy' ? 'Up' : ($context['trend'] === 'sell' ? 'Down' : 'Flat') }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">{{ $context['entry_timeframe'] }} bias</dt>
                <dd class="mt-0.5 font-medium {{ $context['entry_bias'] === 'buy' ? 'text-green-400' : ($context['entry_bias'] === 'sell' ? 'text-red-400' : 'text-gray-400') }}">
                    {{ $context['entry_bias'] === 'buy' ? 'Up' : ($context['entry_bias'] === 'sell' ? 'Down' : 'Flat') }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">ADX</dt>
                <dd class="mt-0.5 font-mono text-gray-200">
                    {{ $context['adx'] !== null ? number_format($context['adx'], 1) : '—' }}
                    @if($context['plus_di'] !== null && $context['minus_di'] !== null)
                        <span class="text-xs text-gray-500">
                            (+DI {{ number_format($context['plus_di'], 0) }} /
                            &minus;DI {{ number_format($context['minus_di'], 0) }})
                        </span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500">ATR</dt>
                <dd class="mt-0.5 font-mono text-gray-200">
                    {{ $context['atr'] !== null ? number_format($context['atr'], 2) : '—' }}
                    @if($context['atr_pct'] !== null)
                        <span class="text-xs text-gray-500">({{ number_format($context['atr_pct'], 2) }}%)</span>
                    @endif
                </dd>
            </div>
        </dl>

        <div class="mt-4 flex items-baseline justify-between border-t border-gray-700 pt-3">
            <span class="font-mono text-sm text-gray-200">{{ number_format($context['last_close'], 2) }}</span>
            <span class="text-xs text-gray-500">
                last {{ $context['entry_timeframe'] }} close
                @if($context['last_bar_at']) {{ $context['last_bar_at']->diffForHumans() }} @endif
            </span>
        </div>
    @endif
</div>

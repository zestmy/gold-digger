{{--
    Signal card

    One signal, read the way somebody about to place it needs to read it. Every figure on
    this card is either stored on the row or arithmetic on the row plus the last close -
    see App\Services\Strategy\SignalCard. Nothing here is an opinion dressed as a number.

    Expects $card from SignalCard::for().
--}}
@php
    $buy = $card['direction'] === 'buy';
    $tone = $card['guidance']['tone'];
    $toneBox = match ($tone) {
        'go' => 'border-green-500/60 bg-green-500/10',
        'wait' => 'border-yellow-500/60 bg-yellow-500/10',
        'stop' => 'border-red-500/60 bg-red-500/10',
        default => 'border-gray-600 bg-gray-800',
    };
    $toneText = match ($tone) {
        'go' => 'text-green-300',
        'wait' => 'text-yellow-300',
        'stop' => 'text-red-300',
        default => 'text-gray-300',
    };
    $confidence = $card['confidence'];
    $ring = match (true) {
        $confidence === null => 'stroke-gray-600',
        $confidence >= 70 => 'stroke-green-400',
        $confidence >= 55 => 'stroke-yellow-400',
        default => 'stroke-red-400',
    };
    // r=26 -> circumference 163.4
    $dash = $confidence === null ? 0 : round(163.4 * $confidence / 100, 1);
    $riskTone = match ($card['risk']) {
        'LOW' => 'bg-green-500/20 text-green-300',
        'MEDIUM' => 'bg-yellow-500/20 text-yellow-300',
        'HIGH' => 'bg-red-500/20 text-red-300',
        default => 'bg-gray-700 text-gray-300',
    };
    $agreeTone = fn (?bool $agrees) => $agrees === null ? 'text-gray-500' : ($agrees ? 'text-green-400' : 'text-red-400');
    $fmt = fn (?float $v, int $d = 2) => $v === null ? '—' : number_format($v, $d);
@endphp

<div class="rounded-xl border border-gray-700 bg-gray-900 p-5 shadow-lg" data-signal-card>
    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-white">{{ $card['symbol'] }}</h2>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full border px-3 py-1 text-sm font-semibold {{ $buy ? 'border-green-500/60 bg-green-500/15 text-green-300' : 'border-red-500/60 bg-red-500/15 text-red-300' }}">
                    {{ $buy ? '↗' : '↘' }} {{ strtoupper($card['direction']) }}
                </span>
                <span class="inline-flex items-center rounded-full border border-purple-500/60 bg-purple-500/15 px-2.5 py-1 text-xs font-semibold text-purple-300">
                    {{ $card['timeframe'] }}
                </span>
                <span class="inline-flex items-center rounded-full border border-blue-500/40 bg-blue-500/10 px-2.5 py-1 text-xs text-blue-300"
                      title="{{ $card['generated_at']?->toDayDateTimeString() }} UTC (signal bar open)">
                    {{ $card['generated_at']?->diffForHumans() ?? '—' }}
                </span>
            </div>
        </div>

        {{-- Confidence ring: SignalQuality's ratio of what agreed to what could have. --}}
        <div class="relative h-20 w-20 shrink-0" title="{{ $card['confluence'] !== null ? "Confluence {$card['confluence']} of {$card['possible']}" : 'No quality assessment stored for this signal' }}">
            <svg viewBox="0 0 64 64" class="h-20 w-20 -rotate-90">
                <circle cx="32" cy="32" r="26" fill="none" stroke-width="5" class="stroke-gray-800" />
                <circle cx="32" cy="32" r="26" fill="none" stroke-width="5" stroke-linecap="round"
                        class="{{ $ring }}" stroke-dasharray="{{ $dash }} 163.4" />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-lg font-bold leading-none text-white">{{ $confidence === null ? '—' : $confidence.'%' }}</span>
                @if($card['grade'])
                    <span class="mt-0.5 text-[10px] font-semibold text-gray-400">grade {{ $card['grade'] }}</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Generated / where the zone sits --}}
    <div class="mt-4 flex items-center justify-between rounded-lg border-l-2 border-blue-400 bg-gray-800/70 px-3 py-2 text-sm">
        <span class="text-blue-300">
            Signal bar <x-local-time :value="$card['generated_at']" format="M d, H:i" />
        </span>
        <span class="font-semibold {{ $card['position'] === 'inside' ? 'text-green-300' : ($card['position'] === null ? 'text-gray-500' : 'text-red-300') }}" data-position>
            @switch($card['position'])
                @case('above') 🎯 Entry above current @break
                @case('below') 🎯 Entry below current @break
                @case('inside') 🎯 Price inside entry zone @break
                @default No live price
            @endswitch
        </span>
    </div>

    {{-- Guidance --}}
    <div class="mt-3 rounded-lg border p-4 {{ $toneBox }}" data-guidance>
        <div class="flex items-start gap-3">
            <span class="text-2xl leading-none">
                @switch($tone)
                    @case('go') ✅ @break
                    @case('wait') ⏳ @break
                    @case('stop') ⛔ @break
                    @default ⚪
                @endswitch
            </span>
            <div class="min-w-0">
                <p class="text-base font-bold tracking-wide {{ $toneText }}">{{ $card['guidance']['headline'] }}</p>
                <p class="mt-1 text-sm text-gray-200">{{ $card['guidance']['detail'] }}</p>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-400">
                    <span>💪 Momentum: <span class="font-semibold text-gray-200">{{ $card['momentum']['label'] }}</span>
                        @if($card['momentum']['adx'] !== null)<span class="text-gray-500">(ADX {{ $fmt($card['momentum']['adx'], 1) }})</span>@endif
                    </span>
                    @if($card['guidance']['order'])
                        <span>📝 Order: <span class="font-semibold text-gray-200">{{ $card['guidance']['order'] }}</span></span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Current price --}}
    <div class="mt-3 rounded-lg border border-blue-500/30 bg-blue-500/5 px-4 py-3">
        <div class="flex items-center justify-between">
            <span class="text-sm text-blue-300">📈 Current price</span>
            <span class="text-2xl font-bold text-blue-200" data-current-price>{{ $fmt($card['current_price']) }}</span>
        </div>
        <p class="mt-1 text-xs text-gray-500">
            @if($card['price_at'])
                Close of the last stored {{ $card['timeframe'] }} bar, <x-local-time :value="$card['price_at']" format="H:i" />. The terminal has the live tick.
            @else
                No bar stored for this instrument yet.
            @endif
        </p>
    </div>

    {{-- Entry zone / stop --}}
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg bg-gray-800/70 p-4">
            <p class="text-sm text-gray-400">🎯 Entry zone</p>
            <p class="mt-1 text-xl font-bold text-white" data-entry-zone>{{ $fmt($card['zone_low']) }} – {{ $fmt($card['zone_high']) }}</p>
            <p class="mt-1 text-xs text-gray-500">Signal bar closed at {{ $fmt($card['entry']) }}</p>
        </div>
        <div class="rounded-lg bg-gray-800/70 p-4">
            <p class="text-sm text-gray-400">🛑 Stop loss</p>
            <p class="mt-1 text-xl font-bold text-red-400">{{ $fmt($card['stop']) }}</p>
            @if($card['stop_pips'])
                <p class="mt-1 text-xs text-gray-500">{{ $fmt($card['stop_pips'], 1) }} pips from the close</p>
            @endif
        </div>
    </div>

    {{-- Targets --}}
    <div class="mt-3 rounded-lg bg-gray-800/70 p-4">
        <p class="text-sm text-gray-400">🎯 Take profit levels</p>
        @if($card['targets'] === [])
            <p class="mt-2 text-sm text-gray-500">Unknown &mdash; targets are configured in pips and the terminal has not reported this symbol's pip size.</p>
        @else
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach($card['targets'] as $target)
                    <span class="inline-flex items-center gap-2 rounded-md border border-green-500/40 bg-green-500/10 px-3 py-1.5 text-sm font-semibold text-green-300">
                        {{ $target['name'] }}: {{ $fmt($target['price']) }}
                        @if($target['r'] !== null)
                            <span class="text-xs font-normal text-green-500/80">{{ $fmt($target['r'], 1) }}R</span>
                        @endif
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Indicators at the signal bar --}}
    <div class="mt-4">
        <p class="text-sm font-semibold text-gray-300">📊 Technical indicators <span class="font-normal text-gray-500">(at the signal bar)</span></p>
        <div class="mt-2 grid grid-cols-3 gap-2">
            <div class="rounded-lg bg-gray-800/70 p-3 text-center">
                <p class="text-xs text-gray-500">RSI (14)</p>
                <p class="mt-1 text-lg font-bold text-white">{{ $fmt($card['indicators']['rsi']['value'], 1) }}</p>
                <p class="text-xs {{ $agreeTone($card['indicators']['rsi']['agrees']) }}">{{ $card['indicators']['rsi']['label'] }}</p>
            </div>
            <div class="rounded-lg bg-gray-800/70 p-3 text-center">
                <p class="text-xs text-gray-500">MACD</p>
                <p class="mt-1 text-lg font-bold text-white">
                    @php $h = $card['indicators']['macd']['histogram']; @endphp
                    {{ $h === null ? '—' : ($h > 0 ? '▲' : ($h < 0 ? '▼' : '➡')) }}
                </p>
                <p class="text-xs {{ $agreeTone($card['indicators']['macd']['agrees']) }}">{{ $card['indicators']['macd']['label'] }}</p>
            </div>
            <div class="rounded-lg bg-gray-800/70 p-3 text-center">
                <p class="text-xs text-gray-500">Trend {{ $card['indicators']['trend']['timeframe'] ?? '' }}</p>
                <p class="mt-1 text-lg font-bold text-white">
                    {{ match ($card['indicators']['trend']['direction']) { 'buy' => '↗', 'sell' => '↘', default => '—' } }}
                </p>
                <p class="text-xs {{ $agreeTone($card['indicators']['trend']['agrees']) }}">{{ $card['indicators']['trend']['label'] }}</p>
            </div>
        </div>

        <div class="mt-2 flex flex-wrap gap-2 text-xs">
            <span class="rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-400">
                ADX: <span class="text-gray-200">{{ $fmt($card['indicators']['adx']['value'], 1) }}</span> {{ $card['indicators']['adx']['label'] }}
            </span>
            <span class="rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-400">
                Volatility: <span class="text-gray-200">{{ $card['indicators']['volatility']['label'] }}</span>
                @if($card['indicators']['volatility']['atr'] !== null)
                    <span class="text-gray-500">ATR {{ $fmt($card['indicators']['volatility']['atr']) }}</span>
                @endif
            </span>
            <span class="rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-400" data-reward>
                R:R <span class="text-gray-200">{{ $card['reward_ratio'] === null ? '—' : '1:'.rtrim(rtrim(number_format($card['reward_ratio'], 1), '0'), '.') }}</span>
            </span>
            <span class="rounded border border-gray-700 bg-gray-800 px-2 py-1 {{ $card['expired'] ? 'text-red-400' : 'text-gray-400' }}" data-validity>
                @if($card['valid_until'] === null)
                    Valid: —
                @elseif($card['expired'])
                    Expired <x-local-time :value="$card['valid_until']" format="M d, H:i" />
                @else
                    Valid until <span class="text-gray-200"><x-local-time :value="$card['valid_until']" format="H:i" /></span>
                @endif
            </span>
        </div>
    </div>

    {{-- Risk assessment --}}
    <div class="mt-4 rounded-lg border border-yellow-500/40 bg-yellow-500/5 p-4" data-risk>
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-yellow-200">⚠️ Risk assessment</p>
            <span class="rounded-full px-2.5 py-0.5 text-xs font-bold {{ $riskTone }}">{{ $card['risk'] ?? 'RISK' }}</span>
        </div>
        <ul class="mt-2 space-y-1.5 text-sm text-gray-300">
            @foreach($card['risk_notes'] as $note)
                <li class="flex gap-2"><span class="text-gray-500">•</span><span>{{ $note }}</span></li>
            @endforeach
        </ul>
    </div>

    {{-- Confluence --}}
    @if($card['factors'] !== [])
        <details class="mt-3 rounded-lg border border-gray-700 bg-gray-800/50">
            <summary class="cursor-pointer px-4 py-2 text-sm text-gray-300">
                Confluence {{ $card['confluence'] }} of {{ $card['possible'] }}
                <span class="text-gray-500">&mdash; what agreed, and what did not</span>
            </summary>
            <ul class="divide-y divide-gray-700/60 px-4 pb-3 text-xs">
                @foreach($card['factors'] as $factor)
                    <li class="flex items-start gap-2 py-1.5">
                        <span class="{{ $factor['met'] ? 'text-green-400' : 'text-red-400' }}">{{ $factor['met'] ? '✓' : '✗' }}</span>
                        <span class="text-gray-300">{{ $factor['name'] }}</span>
                        <span class="ml-auto shrink-0 text-gray-500">{{ $factor['weight'] }}</span>
                        <span class="hidden text-gray-500 sm:inline">&middot; {{ $factor['note'] }}</span>
                    </li>
                @endforeach
            </ul>
            @if($card['why'])
                <p class="border-t border-gray-700/60 px-4 py-2 text-xs text-gray-400">{{ $card['why'] }}</p>
            @endif
        </details>
    @endif
</div>

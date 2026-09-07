{{-- Polls every 30s: a signal fires at most once a bar, so this is as often as the list
     can change and still be worth re-reading. --}}
<div class="rounded-lg border border-gray-700 bg-gray-800 p-6" wire:poll.30s="refreshSignals">
    <div class="flex items-baseline justify-between gap-4">
        <div>
            <h3 class="text-sm font-medium text-gray-400">Today's signals</h3>
            @if($rows)
                <p class="mt-0.5 text-xs text-gray-500">
                    {{ $aiCount }} AI &middot; {{ $copiedCount }} copied
                    @if($aiCount + $copiedCount > count($rows))
                        &middot; newest {{ count($rows) }} shown
                    @endif
                </p>
            @endif
        </div>
        <a href="{{ route('signals') }}" class="text-xs font-medium text-yellow-500 hover:text-yellow-400">All signals &rarr;</a>
    </div>

    @if($rows === [])
        <div class="mt-6 flex h-40 flex-col items-center justify-center text-center">
            <svg class="h-8 w-8 text-gray-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
            </svg>
            <p class="mt-3 text-sm text-gray-400">No signals yet today.</p>
            <p class="mt-1 text-xs text-gray-600">AI signals fire on the bar close; copied ones when a provider posts.</p>
        </div>
    @else
        @php
            // Gold and the yen pairs quote to two places, the rest of the majors to four.
            // Magnitude is a good enough tell for a glance; the Signals page has the row.
            $price = fn (?float $v) => $v === null ? '—' : number_format($v, $v >= 100 ? 2 : 4);

            $chipClasses = [
                'go' => 'bg-green-400/10 text-green-400 ring-green-400/20',
                'wait' => 'bg-yellow-400/10 text-yellow-400 ring-yellow-400/20',
                'stop' => 'bg-red-400/10 text-red-400 ring-red-400/20',
                'muted' => 'bg-gray-700/60 text-gray-400 ring-gray-600/40',
            ];
        @endphp

        <ul class="mt-4 divide-y divide-gray-700/70">
            @foreach($rows as $row)
                <li>
                    <a href="{{ $row['href'] }}" class="-mx-2 flex flex-col gap-2 rounded-md px-2 py-3 transition-colors hover:bg-gray-700/40 sm:flex-row sm:items-center sm:gap-4">
                        {{-- Who and what --}}
                        <div class="flex min-w-0 items-center gap-3 sm:w-52 sm:shrink-0">
                            <span class="inline-flex w-12 shrink-0 justify-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $row['direction'] === 'buy' ? 'bg-green-400/10 text-green-400' : 'bg-red-400/10 text-red-400' }}">
                                {{ strtoupper((string) $row['direction']) }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-white">
                                    {{ $row['symbol'] }}
                                    @if($row['timeframe'])
                                        <span class="font-normal text-gray-500">{{ $row['timeframe'] }}</span>
                                    @endif
                                </p>
                                <p class="truncate text-xs text-gray-500">
                                    @if($row['source'] === 'ai')
                                        <span class="font-medium text-yellow-500">AI</span>
                                    @else
                                        <span class="font-medium text-sky-400">{{ $row['source_label'] }}</span>
                                    @endif
                                    &middot; <x-local-time :value="$row['at']" format="H:i" />
                                </p>
                            </div>
                        </div>

                        {{-- The levels: zone, stop, targets --}}
                        <div class="min-w-0 flex-1 text-xs tabular-nums text-gray-400">
                            <p class="truncate">
                                <span class="text-gray-500">zone</span>
                                <span class="text-gray-200">
                                    @if($row['zone_low'] !== null && $row['zone_high'] !== null && $row['zone_low'] !== $row['zone_high'])
                                        {{ $price($row['zone_low']) }} &ndash; {{ $price($row['zone_high']) }}
                                    @else
                                        {{ $price($row['zone_low']) }}
                                    @endif
                                </span>
                                <span class="ml-2 text-gray-500">stop</span>
                                <span class="text-red-300">{{ $price($row['stop']) }}</span>
                            </p>
                            <p class="truncate">
                                <span class="text-gray-500">TP</span>
                                @if($row['targets'] === [])
                                    <span class="text-gray-600">none</span>
                                @else
                                    <span class="text-green-300">{{ implode(' / ', array_map($price, $row['targets'])) }}</span>
                                @endif
                            </p>
                            @if($row['reward_ratio'] !== null)
                                <p class="truncate">
                                    <span class="text-gray-500">R:R</span>
                                    <span class="text-gray-200">1:{{ rtrim(rtrim(number_format($row['reward_ratio'], 1), '0'), '.') }}</span>
                                </p>
                            @endif
                        </div>

                        {{-- Confidence, for the signals that carry a computed one --}}
                        <div class="w-16 shrink-0 text-xs tabular-nums sm:text-right">
                            @if($row['confidence'] !== null)
                                @php
                                    $c = (int) $row['confidence'];
                                    $scoreTone = $c >= 70 ? 'text-green-400' : ($c >= 55 ? 'text-yellow-400' : 'text-red-400');
                                @endphp
                                <span class="font-semibold {{ $scoreTone }}">{{ $c }}%</span>
                                @if($row['grade'])
                                    <span class="text-gray-500">{{ $row['grade'] }}</span>
                                @endif
                            @else
                                <span class="text-gray-600">&mdash;</span>
                            @endif
                        </div>

                        {{-- The chip --}}
                        <div class="shrink-0 sm:w-40 sm:text-right">
                            <span class="inline-block whitespace-nowrap rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 ring-inset {{ $chipClasses[$row['tone']] ?? $chipClasses['muted'] }}">
                                {{ $row['chip'] }}
                            </span>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>

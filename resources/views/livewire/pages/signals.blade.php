{{--
    Signals Page

    Every decision the strategy layer has made, including the refusals. A skipped signal
    with its reason is what turns "the bot has not traded all morning" from a mystery into
    a setting somebody can change.

    Two columns on a wide screen: the feed on the left, the chosen signal read for entry on
    the right. Picking a row changes the card and moves nothing else.
--}}

@php
    $reasons = \App\Livewire\Pages\Signals::REASONS;
    $chip = fn (bool $on) => $on
        ? 'rounded-full px-3 py-1 text-xs font-medium bg-yellow-500 text-gray-900'
        : 'rounded-full px-3 py-1 text-xs font-medium bg-gray-800 text-gray-300 hover:bg-gray-700';
    $price = fn (?float $v) => $v === null ? '—' : number_format($v, 2);
@endphp

<div>
    <x-slot name="header">
        Signals
    </x-slot>

    <x-page-tabs group="signals" />

    <!-- Data feed health -->
    {{--
        Before anything else, deliberately. If bars have stopped arriving, no signal can be
        generated and every explanation further down this page is a red herring. While they
        are arriving it is one line, because a healthy feed is not what anybody came to read.
    --}}
    @if(empty($feed))
        <div class="mb-6 rounded-lg border border-yellow-500/40 bg-gray-800 p-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-white">Price feed</h2>
                @if($heartbeat?->resolved_symbol)
                    <span class="text-xs text-gray-400">{{ $heartbeat->resolved_symbol }}</span>
                @endif
            </div>
            <p class="mt-3 text-sm text-yellow-400">
                No bars have ever arrived. The strategy layer cannot produce a signal without them &mdash;
                check that the Expert Advisor is attached and that <code class="text-gray-300">Push Candles</code> is on.
            </p>
        </div>
    @else
        <div class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-xs text-gray-400">
            <span class="font-semibold text-white">Price feed</span>
            @if($heartbeat?->resolved_symbol)
                <span class="text-gray-300">{{ $heartbeat->resolved_symbol }}</span>
            @endif
            @foreach($feed as $series)
                <span class="flex items-center gap-1.5">
                    <span class="font-medium text-gray-200">{{ $series['timeframe'] }}</span>
                    <span>{{ number_format($series['bars']) }} bars</span>
                    @if($series['newest'])
                        <span class="text-gray-500">&middot; newest {{ $series['newest']->diffForHumans() }}</span>
                    @endif
                    @if($series['warm'])
                        <span class="rounded bg-green-500/20 px-1.5 py-0.5 text-[10px] text-green-400">READY</span>
                    @else
                        {{-- ADX needs 2 x period bars before it reads at all. --}}
                        <span class="rounded bg-yellow-500/20 px-1.5 py-0.5 text-[10px] text-yellow-400"
                              title="Indicators need roughly 100 bars before they read at all.">WARMING UP</span>
                    @endif
                </span>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-5">
        <!-- The feed -->
        <div class="lg:col-span-2">
            <div class="mb-3 flex items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-white">Feed</h2>
                    <p class="text-xs text-gray-500">{{ number_format($total) }} recorded</p>
                </div>
                <a href="{{ route('analysis') }}"
                   class="shrink-0 rounded-md bg-gray-800 px-3 py-1.5 text-xs font-medium text-yellow-400 ring-1 ring-inset ring-gray-700 hover:bg-gray-700">
                    Scan markets &rarr;
                </a>
            </div>

            <!-- Instrument -->
            <div class="mb-2 flex flex-wrap gap-2">
                @foreach(['gold' => 'Gold', 'majors' => 'Majors', 'all' => 'All'] as $key => $label)
                    <button type="button" wire:click="$set('instrument', '{{ $key }}')" class="{{ $chip($instrument === $key) }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <!-- Outcome -->
            <div class="mb-4 flex flex-wrap gap-2">
                <button type="button" wire:click="$set('filter', '')" class="{{ $chip($filter === '') }}">
                    Every outcome
                </button>

                <button type="button" wire:click="$set('filter', 'taken')" class="{{ $chip($filter === 'taken') }}">
                    Acted on ({{ $byReason[''] ?? 0 }})
                </button>

                @foreach($byReason as $reason => $count)
                    @if($reason !== '' && $reason !== null)
                        <button type="button" wire:click="$set('filter', '{{ $reason }}')"
                                title="{{ $reasons[$reason]['help'] ?? '' }}"
                                class="{{ $chip($filter === $reason) }}">
                            {{ $reasons[$reason]['label'] ?? $reason }} ({{ $count }})
                        </button>
                    @endif
                @endforeach
            </div>

            @if($signals->isEmpty())
                <div class="rounded-lg border border-gray-700 bg-gray-800 p-8 text-center">
                    <p class="text-sm text-gray-400">No signals recorded yet.</p>
                    <p class="mt-2 text-xs text-gray-500">
                        A signal is written whenever the entry rules fire &mdash; whether or not it is traded.
                        Nothing appears until bars are arriving and a strategy is active.
                    </p>
                </div>
            @else
                <ul class="space-y-2">
                    @foreach($signals as $signal)
                        @php
                            $reading = $readings[$signal->id];
                            $onCard = $featured?->id === $signal->id;
                            $buy = $signal->direction === 'buy';
                            $traded = $signal->was_executed && $signal->resulting_trade_id;
                            $held = $signal->skip_reason !== null;
                            $tone = match ($reading['guidance']['tone']) {
                                'go' => 'bg-green-500/20 text-green-300',
                                'wait' => 'bg-yellow-500/20 text-yellow-300',
                                'stop' => 'bg-red-500/20 text-red-300',
                                default => 'bg-gray-700 text-gray-300',
                            };
                            $confidence = $reading['confidence'];
                            $scoreTone = $confidence === null ? 'text-gray-500'
                                : ($confidence >= 70 ? 'text-green-400' : ($confidence >= 55 ? 'text-yellow-400' : 'text-red-400'));
                        @endphp
                        <li>
                            {{-- A div acting as a button, not a button: the traded row carries a
                                 link to its trade, and an anchor inside a button is not HTML. --}}
                            <div role="button" tabindex="0"
                                 wire:click="show({{ $signal->id }})"
                                 wire:keydown.enter="show({{ $signal->id }})"
                                 class="w-full cursor-pointer rounded-lg border p-3 text-left transition-colors {{ $onCard ? 'border-yellow-500/50 bg-yellow-500/10' : 'border-gray-700 bg-gray-800 hover:bg-gray-700/60' }}">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="text-sm font-semibold text-white">{{ $signal->symbol }}</span>
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $buy ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                                            {{ strtoupper($signal->direction) }}
                                        </span>
                                        <span class="rounded-full bg-purple-500/15 px-2 py-0.5 text-[11px] font-semibold text-purple-300">
                                            {{ $signal->timeframe }}
                                        </span>
                                    </div>
                                    <span class="shrink-0 text-xs text-gray-500">
                                        <x-local-time :value="$signal->generated_at" relative />
                                    </span>
                                </div>

                                {{-- The zone rather than the entry alone: a limit order goes at the zone. --}}
                                <p class="mt-1.5 text-xs text-gray-400">
                                    @if($reading['zone_low'] === $reading['zone_high'])
                                        entry <span class="text-gray-200">{{ $price($reading['entry']) }}</span>
                                    @else
                                        zone <span class="text-gray-200">{{ $price($reading['zone_low']) }} &ndash; {{ $price($reading['zone_high']) }}</span>
                                    @endif
                                    &middot; stop <span class="text-red-400">{{ $price($reading['stop']) }}</span>
                                </p>

                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                        @if($traded)
                                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold bg-green-500/20 text-green-400">TRADED</span>
                                            <a href="{{ route('trades.history') }}" x-on:click.stop
                                               class="text-xs text-green-400 hover:underline">
                                                #{{ $signal->resultingTrade?->mt5_ticket }}
                                            </a>
                                        @elseif($held)
                                            {{-- The reason is the point of the row. --}}
                                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold bg-gray-700 text-gray-300">HELD</span>
                                            <span class="text-xs text-gray-400" title="{{ $reasons[$signal->skip_reason]['help'] ?? $signal->skip_reason }}">
                                                {{ $reasons[$signal->skip_reason]['label'] ?? $signal->skip_reason }}
                                            </span>
                                        @else
                                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold {{ $tone }}">
                                                {{ $reading['guidance']['headline'] }}
                                            </span>
                                            {{--
                                                Accepted, command queued, no fill reported yet. A real state, not a
                                                gap: the command can still expire or be rejected.
                                            --}}
                                            <span class="text-xs text-yellow-400"
                                                  title="The entry was queued. It becomes a trade when the terminal reports a fill.">
                                                In flight
                                            </span>
                                        @endif
                                    </div>
                                    <span class="shrink-0 text-xs text-gray-500">
                                        <span class="font-semibold {{ $scoreTone }}"
                                              title="{{ $confidence === null ? 'No quality assessment was stored for this signal.' : 'Confluence '.($reading['confluence'] ?? '?').' of '.($reading['possible'] ?? '?') }}">
                                            {{ $confidence === null ? '—' : $confidence.'%' }}
                                        </span>
                                        &middot; R:R {{ $reading['reward_ratio'] === null ? '—' : '1:'.rtrim(rtrim(number_format($reading['reward_ratio'], 1), '0'), '.') }}
                                    </span>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-3">
                    {{ $signals->links() }}
                </div>
            @endif
        </div>

        <!-- The signal, read for entry -->
        <div class="lg:col-span-3 lg:sticky lg:top-4 lg:self-start">
            @if($card !== null)
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-white">
                        {{-- A link to a signal that is not this user's falls back to the newest,
                             and the heading has to say which it is showing, not which was asked. --}}
                        {{ $selected !== null && $featured?->id === $selected ? 'Selected signal' : 'Latest signal' }}
                    </h2>
                    <span class="text-xs text-gray-500">Pick any row to read it here</span>
                </div>
                @include('livewire.pages.partials.signal-card', ['card' => $card])
            @else
                <div class="rounded-xl border border-dashed border-gray-700 p-8 text-center">
                    <p class="text-sm text-gray-400">Nothing to read yet.</p>
                    <p class="mt-2 text-xs text-gray-500">
                        The first signal to fire appears here, read against the last close.
                    </p>
                </div>
            @endif
        </div>
    </div>

    <!-- Explainer -->
    <details class="mt-6 rounded-lg border border-gray-700 bg-gray-800/50 p-4">
        <summary class="cursor-pointer text-sm font-semibold text-white">Why declined signals are recorded</summary>
        <p class="mt-2 text-sm text-gray-400">
            A signal is written every time the entry rules fire, whether or not it was traded. That is what makes
            &ldquo;the bot has not traded all day&rdquo; answerable: the reason on the row names the one gate that
            would have to change. Bars where the rules did not fire at all are not recorded &mdash; there would be
            one row per bar per strategy, for ever, and these rows would drown in them.
        </p>
    </details>

    <!-- The state in prose, on request -->
    <div class="mt-6">
        <livewire:dashboard.ai-analysis-card />
    </div>
</div>

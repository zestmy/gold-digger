{{--
    Signals Page

    Every decision the strategy layer has made, including the refusals. A skipped signal
    with its reason is what turns "the bot has not traded all morning" from a mystery into
    a setting somebody can change.
--}}

<div>
    <!-- Header -->
    <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">Signals</h1>
            <p class="mt-1 text-sm text-gray-400">
                Every setup the strategy recognised &mdash; including the ones it declined to trade, and why.
            </p>
        </div>
        <div class="text-sm text-gray-400">
            {{ number_format($total) }} recorded
        </div>
    </div>

    <!-- Data feed health -->
    {{--
        First panel deliberately. If bars have stopped arriving, no signal can be generated
        and every explanation further down this page is a red herring.
    --}}
    <div class="mb-6 rounded-lg border border-gray-700 bg-gray-800 p-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-white">Price feed</h2>
            @if($heartbeat?->resolved_symbol)
                <span class="text-xs text-gray-400">
                    {{ $heartbeat->resolved_symbol }}
                    @if($heartbeat->pip_size)
                        &middot; pip {{ rtrim(rtrim(number_format($heartbeat->pip_size, 5), '0'), '.') }}
                    @endif
                </span>
            @endif
        </div>

        @if(empty($feed))
            <p class="mt-3 text-sm text-yellow-400">
                No bars have ever arrived. The strategy layer cannot produce a signal without them &mdash;
                check that the Expert Advisor is attached and that <code class="text-gray-300">Push Candles</code> is on.
            </p>
        @else
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($feed as $series)
                    <div class="rounded border border-gray-700 bg-gray-900/60 p-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-white">{{ $series['timeframe'] }}</span>
                            @if($series['warm'])
                                <span class="text-[10px] rounded px-1.5 py-0.5 bg-green-500/20 text-green-400">READY</span>
                            @else
                                {{-- ADX needs 2 x period bars before it reads at all. --}}
                                <span class="text-[10px] rounded px-1.5 py-0.5 bg-yellow-500/20 text-yellow-400"
                                      title="Indicators need roughly 100 bars before they read at all.">WARMING UP</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-gray-400">{{ number_format($series['bars']) }} bars</p>
                        @if($series['newest'])
                            <p class="text-xs text-gray-500">newest {{ $series['newest']->diffForHumans() }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Filters -->
    <div class="mb-4 flex flex-wrap gap-2">
        <button wire:click="$set('filter', '')"
                class="rounded-full px-3 py-1 text-xs font-medium {{ $filter === '' ? 'bg-yellow-500 text-gray-900' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">
            All
        </button>

        <button wire:click="$set('filter', 'taken')"
                class="rounded-full px-3 py-1 text-xs font-medium {{ $filter === 'taken' ? 'bg-yellow-500 text-gray-900' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">
            Acted on ({{ $byReason[''] ?? 0 }})
        </button>

        @foreach($byReason as $reason => $count)
            @if($reason !== '' && $reason !== null)
                <button wire:click="$set('filter', '{{ $reason }}')"
                        title="{{ \App\Livewire\Pages\Signals::REASONS[$reason]['help'] ?? '' }}"
                        class="rounded-full px-3 py-1 text-xs font-medium {{ $filter === $reason ? 'bg-yellow-500 text-gray-900' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">
                    {{ \App\Livewire\Pages\Signals::REASONS[$reason]['label'] ?? $reason }} ({{ $count }})
                </button>
            @endif
        @endforeach
    </div>

    <!-- Signals table -->
    <div class="overflow-hidden rounded-lg border border-gray-700 bg-gray-800">
        @if($signals->isEmpty())
            <div class="p-8 text-center">
                <p class="text-sm text-gray-400">No signals recorded yet.</p>
                <p class="mt-2 text-xs text-gray-500">
                    A signal is written whenever the entry rules fire &mdash; whether or not it is traded.
                    Nothing appears until bars are arriving and a strategy is active.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead class="bg-gray-900">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Bar</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Direction</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Entry</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Stop / targets</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Lots</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Readings</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">Outcome</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700 bg-gray-800">
                        @foreach($signals as $signal)
                            @php $features = $signal->features ?? []; @endphp
                            <tr class="hover:bg-gray-700/50 transition-colors">
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                                    <x-local-time :value="$signal->generated_at" format="M d, H:i" />
                                    <span class="block text-xs text-gray-500">
                                        {{ $signal->symbol }} {{ $signal->timeframe }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $signal->direction === 'buy' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                                        {{ strtoupper($signal->direction) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-white">
                                    {{ number_format($signal->entry_price, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs">
                                    <span class="text-red-400">{{ number_format($signal->sl_price, 2) }}</span>
                                    <span class="text-gray-600">&rarr;</span>
                                    @if($signal->tp1_price === null)
                                        {{-- Targets are configured in pips, so without the terminal's pip size
                                             there is no honest price for them. --}}
                                        <span class="text-gray-500">unknown</span>
                                    @else
                                        <span class="text-green-400">
                                            {{ number_format($signal->tp1_price, 2) }}
                                            @if($signal->tp2_price) / {{ number_format($signal->tp2_price, 2) }} @endif
                                            @if($signal->tp3_price) / {{ number_format($signal->tp3_price, 2) }} @endif
                                        </span>
                                    @endif
                                    @if(isset($features['sl_pips']) && $features['sl_pips'] > 0)
                                        <span class="block text-gray-500">stop {{ $features['sl_pips'] }} pips</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-300">
                                    {{ $signal->suggested_lot_size ? rtrim(rtrim(number_format($signal->suggested_lot_size, 4), '0'), '.') : '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-400">
                                    @if(isset($features['adx']))
                                        ADX {{ number_format($features['adx'], 1) }}
                                    @endif
                                    @if(isset($features['atr']))
                                        <span class="block">ATR {{ number_format($features['atr'], 2) }}</span>
                                    @endif
                                    @if(isset($features['trend_direction']))
                                        <span class="block text-gray-500">trend {{ $features['trend_direction'] }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm">
                                    @if($signal->skip_reason)
                                        {{-- The reason is the point of the row. --}}
                                        <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-gray-700 text-gray-300"
                                              title="{{ \App\Livewire\Pages\Signals::REASONS[$signal->skip_reason]['help'] ?? $signal->skip_reason }}">
                                            {{ \App\Livewire\Pages\Signals::REASONS[$signal->skip_reason]['label'] ?? $signal->skip_reason }}
                                        </span>
                                    @elseif($signal->was_executed && $signal->resulting_trade_id)
                                        <a href="{{ route('trades.history') }}"
                                           class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-green-500/20 text-green-400">
                                            Traded &middot; #{{ $signal->resultingTrade?->mt5_ticket }}
                                        </a>
                                    @else
                                        {{--
                                            Accepted, command queued, no fill reported yet. A real state, not a
                                            gap: the command can still expire or be rejected.
                                        --}}
                                        <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-yellow-500/20 text-yellow-400"
                                              title="The entry was queued. It becomes a trade when the terminal reports a fill.">
                                            In flight
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-gray-700 px-4 py-3">
                {{ $signals->links() }}
            </div>
        @endif
    </div>

    <!-- Explainer -->
    <div class="mt-6 rounded-lg border border-gray-700 bg-gray-800/50 p-4">
        <h3 class="text-sm font-semibold text-white">Why declined signals are recorded</h3>
        <p class="mt-2 text-sm text-gray-400">
            A signal is written every time the entry rules fire, whether or not it was traded. That is what makes
            &ldquo;the bot has not traded all day&rdquo; answerable: the reason column names the one gate that
            would have to change. Bars where the rules did not fire at all are not recorded &mdash; there would be
            one row per bar per strategy, for ever, and these rows would drown in them.
        </p>
    </div>
</div>

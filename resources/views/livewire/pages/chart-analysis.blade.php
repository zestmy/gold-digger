{{--
    Chart Analysis

    A scan across every instrument with stored bars, ranked on measured evidence, with one
    instrument openable for its structure and levels.

    ## Two kinds of number on this page

    The table is arithmetic: confluence from the same scorer the copier uses, prices taken
    from levels this instrument actually turned at, a reward ratio divided out in PHP. It
    is there whether or not a model was asked anything.

    The prose is judgement, it sits in its own card, and it is labelled. Keeping them
    visually apart is not decoration - a paragraph and a measurement rendered in the same
    weight read as equally reliable, and they are not.
--}}

@php
    // Decimals by magnitude. An FX pair at 1.08 needs five and gold at 2650 needs two;
    // showing five on gold is noise and two on EURUSD loses the trade. The broker's own
    // digits would be better and are not always stored, so this is the fallback rather
    // than a guess about the instrument.
    $price = fn (?float $value) => $value === null
        ? '—'
        : number_format($value, abs($value) >= 100 ? 2 : 5, '.', ',');
@endphp

<div>
    <x-slot name="header">
        Chart Analysis
    </x-slot>

    <div class="space-y-6">
        <!-- What to scan -->
        <div class="rounded-lg bg-gray-800 p-4">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label for="timeframe" class="block text-xs text-gray-500">Timeframe</label>
                    <select id="timeframe" wire:model.live="timeframe"
                            class="mt-1 block rounded-md border-gray-600 bg-gray-700 text-sm text-white focus:border-yellow-500 focus:ring-yellow-500">
                        @foreach($timeframes as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- A real choice rather than a degraded one: the measured ranking is the
                     half that can be checked, and somebody who trusts their own reading of
                     a confluence table should not have to pay for a paragraph about it. --}}
                <label class="flex items-center gap-2 pb-2 text-xs text-gray-400">
                    <input type="checkbox" wire:model.live="withModel"
                           class="rounded border-gray-600 bg-gray-700 text-yellow-500 focus:ring-yellow-500">
                    Ask the model to rank the shortlist
                </label>

                <button type="button" wire:click="scan" wire:loading.attr="disabled"
                        @disabled($symbols === [])
                        class="rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400 disabled:opacity-50">
                    <span wire:loading.remove wire:target="scan">Scan {{ count($symbols) ?: '' }} instrument{{ count($symbols) === 1 ? '' : 's' }}</span>
                    <span wire:loading wire:target="scan">Scanning…</span>
                </button>

                <p class="pb-2 text-xs text-gray-500">
                    {{ count($symbols) }} instrument{{ count($symbols) === 1 ? '' : 's' }} with at least
                    {{ \App\Services\Analysis\MarketScanner::MIN_BARS }} stored {{ $timeframe }} bars
                </p>
            </div>
        </div>

        @if(! $hasStrategy)
            <div class="rounded-lg border border-amber-500/30 bg-amber-900/20 p-4">
                <p class="text-sm text-amber-300">
                    There is no strategy to take indicator settings from, so nothing can be scored.
                </p>
            </div>
        @endif

        {{-- ============================================================== --}}
        {{-- FOCUS: one instrument, its structure and a plan                --}}
        {{-- ============================================================== --}}

        @if($mode === 'focus')
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-medium text-gray-100">{{ $symbol }} <span class="text-gray-500">on {{ $timeframe }}</span></h2>
                    <p class="text-xs text-gray-500">{{ $bars->count() }} bars stored</p>
                </div>

                <div class="flex gap-2">
                    <button type="button" wire:click="analyse" wire:loading.attr="disabled"
                            class="rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400 disabled:opacity-50">
                        <span wire:loading.remove wire:target="analyse">Read this chart</span>
                        <span wire:loading wire:target="analyse">Reading…</span>
                    </button>

                    <button type="button" wire:click="back"
                            class="rounded-md border border-gray-600 px-4 py-2 text-sm text-gray-300 hover:bg-gray-700">
                        Back to the scan
                    </button>
                </div>
            </div>

            @if($analysis)
                @if($analysis['error'])
                    <div class="rounded-lg border border-amber-500/30 bg-amber-900/20 p-4">
                        <p class="text-sm text-amber-300">{{ $analysis['error'] }}</p>
                    </div>
                @endif

                @php($reading = $analysis['reading'])

                @if($reading)
                    <!-- The reading -->
                    <div class="rounded-lg bg-gray-800 p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <h3 class="text-lg font-medium text-gray-100">{{ $reading['headline'] }}</h3>

                            <div class="flex shrink-0 gap-2">
                                <span class="rounded px-2 py-1 text-xs font-medium
                                    {{ $reading['bias'] === 'bullish' ? 'bg-green-400/10 text-green-400' : '' }}
                                    {{ $reading['bias'] === 'bearish' ? 'bg-red-400/10 text-red-400' : '' }}
                                    {{ $reading['bias'] === 'neutral' ? 'bg-gray-700 text-gray-400' : '' }}">
                                    {{ strtoupper($reading['bias']) }}
                                </span>

                                <span class="rounded px-2 py-1 text-xs font-medium
                                    {{ $reading['plan'] === 'wait' ? 'bg-gray-700 text-gray-400' : 'bg-yellow-400/10 text-yellow-500' }}">
                                    {{ strtoupper($reading['plan']) }}
                                </span>
                            </div>
                        </div>

                        <p class="mt-3 text-sm leading-relaxed text-gray-300">{{ $reading['structure'] }}</p>

                        <!-- The plan -->
                        @if($reading['plan'] !== 'wait' && $reading['entry_price'])
                            <div class="mt-5 grid grid-cols-3 gap-4 border-t border-gray-700 pt-4">
                                <div>
                                    <p class="text-xs text-gray-500">Entry</p>
                                    <p class="mt-1 font-mono text-lg text-gray-100">{{ $price($reading['entry_price']) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Stop</p>
                                    <p class="mt-1 font-mono text-lg text-red-400">{{ $price($reading['stop_price']) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Target</p>
                                    <p class="mt-1 font-mono text-lg text-green-400">{{ $price($reading['target_price']) }}</p>
                                </div>
                            </div>

                            {{-- Flat on purpose. Livewire wraps some conditionals in its own
                                 DOM-diffing markers, and where it does, a nested @endif sitting
                                 immediately before the outer @else puts a marker between the two
                                 and the compiled PHP no longer pairs - a 500 with a parse error
                                 naming a line in generated code. It does not happen everywhere,
                                 which is what makes it worth avoiding rather than reasoning
                                 about: rendering the value conditionally instead of the block
                                 sidesteps it and reads better anyway. --}}
                            <p class="mt-2 text-xs text-gray-500">
                                Reward against risk:
                                {{ $reading['reward_ratio'] === null ? '—' : number_format($reading['reward_ratio'], 2).' to 1' }}
                            </p>
                        @else
                            <p class="mt-4 rounded-md bg-gray-900 p-3 text-sm text-gray-400">
                                No plan proposed. Waiting is a legitimate reading, and a mixed chart has no
                                trade in it &mdash; a plan produced to fill the field would be worse than none.
                            </p>
                        @endif

                        <div class="mt-5 space-y-3 border-t border-gray-700 pt-4 text-sm">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Reasoning</p>
                                <p class="mt-1 text-gray-300">{{ $reading['reasoning'] }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">What would make this wrong</p>
                                <p class="mt-1 text-gray-400">{{ $reading['invalidation'] }}</p>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- The measured levels -->
                <div class="rounded-lg bg-gray-800 p-6">
                    <h3 class="text-sm font-medium text-gray-400">Measured levels</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ $analysis['structure'] }}
                        Each is a price this instrument actually turned at, found by definition rather than
                        named &mdash; pivots merged when within half an ATR, so a level tested three times
                        counts as one.
                    </p>

                    @if($analysis['levels'])
                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-700 text-left text-xs uppercase tracking-wide text-gray-500">
                                        <th class="pb-2 pr-4 font-medium">#</th>
                                        <th class="pb-2 pr-4 font-medium">Price</th>
                                        <th class="pb-2 pr-4 font-medium">Kind</th>
                                        <th class="pb-2 font-medium">Touches</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-700/50">
                                    @foreach($analysis['levels'] as $i => $level)
                                        <tr class="{{ $reading && in_array($i, [$reading['entry_level'], $reading['stop_level'], $reading['target_level']], true) ? 'bg-yellow-400/5' : '' }}">
                                            <td class="py-2 pr-4 text-gray-600">{{ $i }}</td>
                                            <td class="py-2 pr-4 font-mono text-gray-200">{{ $price((float) $level['price']) }}</td>
                                            <td class="py-2 pr-4 text-gray-400">{{ $level['kind'] }}</td>
                                            <td class="py-2 text-gray-400">{{ $level['touches'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="mt-4 text-sm text-gray-500">No completed swings in this window.</p>
                    @endif
                </div>
            @else
                <div class="rounded-lg bg-gray-800 p-8 text-center">
                    <p class="text-sm text-gray-400">Press &ldquo;Read this chart&rdquo; for structure, levels and a plan.</p>
                </div>
            @endif
        @endif

        {{-- ============================================================== --}}
        {{-- SCAN: every instrument, ranked                                 --}}
        {{-- ============================================================== --}}

        @if($mode === 'scan' && $scan)
            @php($candidates = $scan['candidates'])

            <!-- What the model made of the shortlist -->
            @if($ranking)
                @if($ranking['ok'])
                    <div class="rounded-lg border border-yellow-500/20 bg-gray-800 p-6">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-sm font-medium uppercase tracking-wide text-yellow-500">The proposal</h3>
                            <p class="text-xs text-gray-600">
                                Judgement, not measurement. The table below is the checkable half.
                            </p>
                        </div>

                        <p class="mt-3 text-sm leading-relaxed text-gray-200">{{ $ranking['verdict'] }}</p>

                        @if($ranking['picks'])
                            <div class="mt-5 space-y-4">
                                @foreach($ranking['picks'] as $pick)
                                    @php($o = $pick['opportunity'])
                                    <div class="rounded-md border border-gray-700 bg-gray-900/50 p-4">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-center gap-2">
                                                <button type="button" wire:click="focus(@js($o->symbol))"
                                                        class="font-mono text-base font-medium text-gray-100 underline decoration-gray-600 underline-offset-4 hover:text-yellow-400">
                                                    {{ $o->symbol }}
                                                </button>
                                                <span class="rounded px-2 py-0.5 text-xs font-medium {{ $o->direction === 'buy' ? 'bg-green-400/10 text-green-400' : 'bg-red-400/10 text-red-400' }}">
                                                    {{ strtoupper($o->direction) }}
                                                </span>
                                            </div>

                                            <div class="flex shrink-0 gap-2">
                                                <span class="rounded px-2 py-0.5 text-xs font-medium
                                                    {{ $pick['verdict'] === 'take' ? 'bg-yellow-400/10 text-yellow-500' : '' }}
                                                    {{ $pick['verdict'] === 'watch' ? 'bg-blue-400/10 text-blue-400' : '' }}
                                                    {{ $pick['verdict'] === 'pass' ? 'bg-gray-700 text-gray-400' : '' }}">
                                                    {{ strtoupper($pick['verdict']) }}
                                                </span>
                                                <span class="rounded bg-gray-700 px-2 py-0.5 text-xs text-gray-300">
                                                    {{ $pick['conviction'] }} conviction
                                                </span>
                                            </div>
                                        </div>

                                        @if($o->complete())
                                            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                                <div>
                                                    <p class="text-xs text-gray-500">Entry</p>
                                                    <p class="font-mono text-sm text-gray-100">{{ $price($o->entry) }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-xs text-gray-500">Stop</p>
                                                    <p class="font-mono text-sm text-red-400">{{ $price($o->stop) }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-xs text-gray-500">Target</p>
                                                    <p class="font-mono text-sm text-green-400">{{ $price($o->target) }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-xs text-gray-500">Reward : risk</p>
                                                    <p class="font-mono text-sm text-gray-300">{{ number_format($o->rewardRatio, 2) }} to 1</p>
                                                </div>
                                            </div>
                                        @endif

                                        <p class="mt-3 text-sm text-gray-300">{{ $pick['reasoning'] }}</p>

                                        @if($pick['invalidation'])
                                            <p class="mt-2 text-xs text-gray-500">
                                                <span class="uppercase tracking-wide">Wrong if:</span>
                                                {{ $pick['invalidation'] }}
                                            </p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-4 rounded-md bg-gray-900 p-3 text-sm text-gray-400">
                                Nothing on this shortlist was worth proposing. That is a legitimate result and a
                                frequent one &mdash; a scan of a quiet market has no trade in it, and one produced
                                to fill the field would be worse than none.
                            </p>
                        @endif

                        @if($ranking['passed_on'])
                            <p class="mt-4 border-t border-gray-700 pt-3 text-xs text-gray-500">{{ $ranking['passed_on'] }}</p>
                        @endif
                    </div>
                @else
                    <div class="rounded-lg border border-amber-500/30 bg-amber-900/20 p-4">
                        <p class="text-sm text-amber-300">{{ $ranking['error'] }}</p>
                        <p class="mt-1 text-xs text-amber-300/60">
                            The ranking below was measured, not generated, and is unaffected.
                        </p>
                    </div>
                @endif
            @endif

            <!-- The measured ranking -->
            <div class="rounded-lg bg-gray-800 p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-medium text-gray-400">Measured ranking</h3>
                    <p class="text-xs text-gray-600">
                        {{ count($candidates) }} of {{ $scan['scanned'] }} scanned on {{ $scan['timeframe'] }}
                    </p>
                </div>

                <p class="mt-1 text-xs text-gray-500">
                    Ordered by whether the entry would clear the confluence floor, then by how much agrees
                    with it, then by reward against risk. There is no single opportunity score, because a
                    weighted composite would need coefficients nobody measured and would hide which column a
                    row won on.
                </p>

                @if($candidates)
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-700 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th class="pb-2 pr-4 font-medium">#</th>
                                    <th class="pb-2 pr-4 font-medium">Instrument</th>
                                    <th class="pb-2 pr-4 font-medium">Side</th>
                                    <th class="pb-2 pr-4 font-medium">Confluence</th>
                                    <th class="pb-2 pr-4 font-medium">Entry</th>
                                    <th class="pb-2 pr-4 font-medium">Stop</th>
                                    <th class="pb-2 pr-4 font-medium">Target</th>
                                    <th class="pb-2 pr-4 font-medium">R : R</th>
                                    <th class="pb-2 pr-4 font-medium">Status</th>
                                    <th class="pb-2 font-medium"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-700/50">
                                @foreach($candidates as $i => $o)
                                    <tr class="{{ $o->tradeable ? 'bg-yellow-400/5' : '' }}">
                                        <td class="py-2 pr-4 text-gray-600">{{ $i + 1 }}</td>
                                        <td class="py-2 pr-4">
                                            <span class="font-mono text-gray-100">{{ $o->symbol }}</span>
                                            <span class="ml-1 text-xs text-gray-600">{{ $o->kind }}</span>
                                            @if(! $o->aligned)
                                                <span class="ml-1 rounded bg-gray-700 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-400"
                                                      title="The higher timeframe disagrees. The strategy will not enter against it.">
                                                    unaligned
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 font-medium {{ $o->direction === 'buy' ? 'text-green-400' : 'text-red-400' }}">
                                            {{ strtoupper($o->direction) }}
                                        </td>
                                        <td class="py-2 pr-4 text-gray-300">
                                            {{ rtrim(rtrim(number_format($o->confluence, 1), '0'), '.') }}
                                            <span class="text-gray-600">of {{ rtrim(rtrim(number_format($o->possible, 1), '0'), '.') }}</span>
                                        </td>
                                        <td class="py-2 pr-4 font-mono text-gray-300">{{ $price($o->entry) }}</td>
                                        <td class="py-2 pr-4 font-mono text-gray-400">{{ $price($o->stop) }}</td>
                                        <td class="py-2 pr-4 font-mono text-gray-400">{{ $price($o->target) }}</td>
                                        <td class="py-2 pr-4 font-mono {{ ($o->rewardRatio ?? 0) >= 1.5 ? 'text-green-400' : 'text-gray-500' }}">
                                            {{ $o->rewardRatio === null ? '—' : number_format($o->rewardRatio, 2) }}
                                        </td>
                                        <td class="py-2 pr-4 text-xs text-gray-400">{{ $o->entryStatus }}</td>
                                        <td class="py-2 text-right">
                                            <button type="button" wire:click="focus(@js($o->symbol))"
                                                    class="text-xs text-yellow-500 hover:text-yellow-400">
                                                Open
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="mt-3 text-xs text-gray-600">
                        A dash under stop or target means no measured level sits on that side of price. The
                        row is shown rather than dropped, and it is not a proposal &mdash; a stop invented to
                        fill the column would be a number nobody could check.
                    </p>
                @else
                    <p class="mt-4 text-sm text-gray-500">
                        Nothing scored. Every instrument scanned was skipped for one of the reasons below.
                    </p>
                @endif
            </div>

            <!-- What was not scored, and why -->
            @if($scan['skipped'])
                <details class="rounded-lg bg-gray-800 p-4">
                    <summary class="cursor-pointer text-sm text-gray-400">
                        {{ count($scan['skipped']) }} instrument{{ count($scan['skipped']) === 1 ? '' : 's' }} not scored
                    </summary>

                    <p class="mt-2 text-xs text-gray-600">
                        Named rather than dropped: an instrument missing because it has no direction reads
                        identically to one missing because nobody ever stored bars for it, and only one of
                        those is worth doing something about.
                    </p>

                    <ul class="mt-3 space-y-1 text-xs">
                        @foreach($scan['skipped'] as $skip)
                            <li class="flex gap-2">
                                <span class="w-24 shrink-0 font-mono text-gray-400">{{ $skip['symbol'] }}</span>
                                <span class="text-gray-500">{{ $skip['why'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @endif

        @if($mode === 'scan' && ! $scan)
            <div class="rounded-lg bg-gray-800 p-8 text-center">
                <p class="text-sm text-gray-400">
                    {{ $symbols === [] ? 'No instrument has enough stored history on this timeframe yet.' : 'Press Scan to rank every instrument this account has bars for.' }}
                </p>
                <p class="mx-auto mt-2 max-w-2xl text-xs text-gray-500">
                    The ranking is measured &mdash; confluence from the same scorer the copier uses, levels
                    found by definition rather than named, and a reward ratio divided out from them. The
                    model is asked one question on top of that: of this shortlist, which. Nothing here places
                    an order, and none of it is a forecast.
                </p>
            </div>
        @endif
    </div>
</div>

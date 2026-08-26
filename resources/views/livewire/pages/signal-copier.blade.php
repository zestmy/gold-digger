<div>
    <x-slot name="header">
        Signal Copier
    </x-slot>

    <div class="space-y-6">
        <!-- What this is, and what it is not -->
        <div class="rounded-lg border border-gray-700 bg-gray-800/50 p-4">
            <p class="text-sm text-gray-300">
                Signals captured from Telegram, parsed, reviewed, and executed against the AI fund.
            </p>
            <p class="mt-1 text-xs text-gray-500">
                Only enabled channels can produce a tradeable signal &mdash; anything else is recorded
                and never traded. Nothing is executed until you press Execute, and every gate is
                checked again when you do.
            </p>
            <a href="{{ route('signals.channels') }}" class="mt-2 inline-block text-xs text-yellow-500 hover:text-yellow-400">
                Channels, and what each has been worth &rarr;
            </a>
        </div>

        <!-- The numbers worth looking at -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
            @foreach([
                'Captured' => $counts['total'],
                'Unparsed' => $counts['unparsed'],
                'Awaiting review' => $counts['pending'],
                'Approved' => $counts['approved'],
                'Executed' => $counts['executed'],
            ] as $label => $value)
                <div class="rounded-lg bg-gray-800 p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</p>
                    <p class="mt-1 font-mono text-2xl text-gray-100">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        {{-- The single most useful number here. A copier approving most of what it sees is
             indistinguishable from having no reviewer, and that failure is invisible in a
             list of individual verdicts that each read perfectly sensibly. --}}
        @if($declineRate !== null)
            <div class="rounded-lg p-4 {{ $declineRate < 40 ? 'border border-amber-500/30 bg-amber-900/20' : 'bg-gray-800' }}">
                <div class="flex flex-wrap items-baseline gap-x-3">
                    <span class="text-xs uppercase tracking-wide text-gray-500">Decline rate</span>
                    <span class="font-mono text-2xl {{ $declineRate < 40 ? 'text-amber-400' : 'text-gray-100' }}">{{ $declineRate }}%</span>
                    <span class="text-xs text-gray-500">of {{ $counts['reviewed'] }} reviewed</span>
                </div>
                @if($declineRate < 40)
                    <p class="mt-2 text-xs text-amber-300/80">
                        The reviewer is approving most of what it sees. That is a finding about the
                        reviewer rather than about the signals &mdash; a copier that takes everything a
                        provider posts is the same thing as no reviewer at all.
                    </p>
                @endif
            </div>
        @endif

        <!-- Fund state, since execution depends on it -->
        <div class="rounded-lg bg-gray-800 p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div class="flex flex-wrap items-baseline gap-x-5">
                    <span class="text-xs uppercase tracking-wide text-gray-500">AI fund</span>
                    @if($fund['configured'])
                        <span class="font-mono text-lg {{ $fund['exhausted'] ? 'text-red-400' : 'text-gray-100' }}">
                            {{ number_format($fund['remaining'], 2) }}
                            <span class="text-xs text-gray-500">of {{ number_format($fund['cap'], 2) }}</span>
                        </span>
                        <span class="text-xs text-gray-500">risk/trade {{ number_format($fund['risk_per_trade'], 2) }}</span>
                        <span class="text-xs text-gray-500">open {{ $fund['open_trades'] }}/{{ $fund['max_concurrent'] }}</span>
                    @else
                        <span class="text-sm text-gray-400">Not configured &mdash; nothing can execute.</span>
                    @endif
                </div>
                <a href="{{ route('settings') }}" class="text-xs text-yellow-500 hover:text-yellow-400">Settings &rarr;</a>
            </div>

            @if($fund['blocked_reason'])
                <p class="mt-2 text-xs {{ $fund['exhausted'] ? 'text-red-400' : 'text-gray-500' }}">
                    {{ app(\App\Services\Ai\AiFund::class)->explain($fund['blocked_reason']) }}
                </p>
            @endif
        </div>

        <!-- Controls -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1">
                @foreach(['all' => 'All', 'parsed' => 'Parsed', 'unparsed' => 'Unparsed', 'approved' => 'Approved', 'declined' => 'Declined', 'executed' => 'Executed'] as $key => $label)
                    <button type="button" wire:click="$set('filter', '{{ $key }}')"
                            class="rounded px-3 py-1.5 text-xs font-medium {{ $filter === $key ? 'bg-yellow-500/20 text-yellow-400' : 'text-gray-500 hover:text-gray-300' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <button type="button" wire:click="pollNow" wire:loading.attr="disabled"
                    class="rounded-md bg-gray-700 px-3 py-1.5 text-xs font-medium text-gray-200 hover:bg-gray-600 disabled:opacity-50">
                <span wire:loading.remove wire:target="pollNow">Check for new messages</span>
                <span wire:loading wire:target="pollNow">Checking&hellip;</span>
            </button>
        </div>

        <!-- The pipeline -->
        @forelse($signals as $signal)
            <div class="rounded-lg bg-gray-800 p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="flex flex-wrap items-baseline gap-x-3">
                        @if($signal->symbol)
                            <span class="font-mono text-sm font-semibold text-gray-100">{{ $signal->symbol }}</span>
                            <span class="text-sm font-medium {{ $signal->direction === 'buy' ? 'text-green-400' : 'text-red-400' }}">
                                {{ strtoupper($signal->direction) }}
                            </span>
                        @else
                            <span class="text-sm text-gray-500">Not a signal</span>
                        @endif
                        <span class="text-xs text-gray-600">{{ $signal->chat_title ?? $signal->chat_id }}</span>
                    </div>
                    <span class="text-xs text-gray-500">{{ ($signal->posted_at ?? $signal->created_at)->diffForHumans() }}</span>
                </div>

                <!-- Stage 1: what arrived -->
                <pre class="mt-3 overflow-x-auto whitespace-pre-wrap rounded bg-gray-900 p-3 font-mono text-xs text-gray-400">{{ $signal->raw_text }}</pre>

                <!-- Stage 2: what was read out of it -->
                @if($signal->parse_status === \App\Models\TelegramSignal::PARSE_OK)
                    <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs">
                        <div><dt class="inline text-gray-500">Entry</dt>
                            <dd class="ml-1 inline font-mono text-gray-200">{{ $signal->entry_price === null ? 'market' : rtrim(rtrim(number_format($signal->entry_price, 5, '.', ''), '0'), '.') }}</dd></div>
                        <div><dt class="inline text-gray-500">Stop</dt>
                            <dd class="ml-1 inline font-mono text-red-400">{{ rtrim(rtrim(number_format($signal->sl_price, 5, '.', ''), '0'), '.') }}</dd></div>
                        <div><dt class="inline text-gray-500">Targets</dt>
                            <dd class="ml-1 inline font-mono text-green-400">
                                {{ $signal->tp_prices ? implode(', ', array_map(fn ($t) => rtrim(rtrim(number_format((float) $t, 5, '.', ''), '0'), '.'), $signal->tp_prices)) : 'none' }}
                            </dd></div>
                    </dl>
                    {{-- Stage 2b: what would actually be traded, where that is not what was posted.

                         Only rendered under `copier_levels = strategy`, where the stop and
                         ladder are this account's rather than the provider's. Without it the
                         card shows one trade and the verdict underneath describes another,
                         and the disagreement is invisible: a signal posted at 5.00 risk for
                         8.00 reward can be declined for reward:risk and look like a bug. --}}
                    @if($plan = $plans[$signal->id] ?? null)
                        <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 rounded border border-yellow-500/20 bg-yellow-500/5 px-3 py-2 text-xs">
                            <div class="w-full text-yellow-600/90">Traded as</div>
                            <div><dt class="inline text-gray-500">Entry</dt>
                                <dd class="ml-1 inline font-mono text-gray-200">{{ $plan['entry'] === null ? 'market' : rtrim(rtrim(number_format($plan['entry'], 5, '.', ''), '0'), '.') }}</dd></div>
                            <div><dt class="inline text-gray-500">Stop</dt>
                                <dd class="ml-1 inline font-mono text-red-400">{{ $plan['sl'] === null ? '-' : rtrim(rtrim(number_format($plan['sl'], 5, '.', ''), '0'), '.') }}</dd></div>
                            <div><dt class="inline text-gray-500">Targets</dt>
                                <dd class="ml-1 inline font-mono text-green-400">
                                    {{ $plan['tps'] ? implode(', ', array_map(fn ($t) => rtrim(rtrim(number_format((float) $t, 5, '.', ''), '0'), '.'), $plan['tps'])) : 'none' }}
                                </dd></div>
                            @if($plan['summary'])
                                <p class="w-full text-gray-500">{{ $plan['summary'] }}</p>
                            @endif
                        </dl>
                    @endif
                @else
                    {{-- Kept and shown, because a provider changing format is otherwise silent. --}}
                    <p class="mt-3 text-xs text-gray-500">
                        <span class="rounded bg-gray-700 px-1.5 py-0.5 text-gray-400">not parsed</span>
                        <span class="ml-2">{{ $signal->parse_error }}</span>
                    </p>
                @endif

                <!-- Stage 3: the verdict -->
                @if($signal->review_status === \App\Models\TelegramSignal::REVIEW_APPROVED)
                    <div class="mt-3 rounded-md border border-green-500/20 bg-green-900/15 p-3">
                        <p class="flex items-baseline gap-x-2 text-xs uppercase tracking-wide text-green-400">
                            Approved
                            @if($signal->review_confidence !== null)<span class="text-gray-500">{{ $signal->review_confidence }}% confident</span>@endif
                        </p>
                        <p class="mt-1 text-sm text-gray-300">{{ $signal->review_reasoning }}</p>
                    </div>
                @elseif($signal->review_status === \App\Models\TelegramSignal::REVIEW_DECLINED)
                    <div class="mt-3 rounded-md bg-gray-900 p-3">
                        <p class="text-xs uppercase tracking-wide text-gray-500">Declined</p>
                        <p class="mt-1 text-sm text-gray-400">{{ $signal->review_reasoning }}</p>
                    </div>
                @elseif($signal->review_status === \App\Models\TelegramSignal::REVIEW_PENDING)
                    <p class="mt-3 text-xs text-gray-500">Awaiting review.</p>
                @endif

                <!-- Stage 4: what happened -->
                @if($signal->execution_status !== \App\Models\TelegramSignal::EXEC_NONE)
                    <p class="mt-3 border-t border-gray-700 pt-3 text-xs">
                        <span class="rounded px-1.5 py-0.5 font-medium
                            {{ $signal->execution_status === \App\Models\TelegramSignal::EXEC_EXECUTED ? 'bg-green-400/10 text-green-400' : '' }}
                            {{ $signal->execution_status === \App\Models\TelegramSignal::EXEC_QUEUED ? 'bg-yellow-400/10 text-yellow-400' : '' }}
                            {{ in_array($signal->execution_status, [\App\Models\TelegramSignal::EXEC_BLOCKED, \App\Models\TelegramSignal::EXEC_FAILED], true) ? 'bg-gray-700 text-gray-400' : '' }}">
                            {{ str_replace('_', ' ', $signal->execution_status) }}
                        </span>
                        <span class="ml-2 text-gray-500">{{ $signal->execution_note }}</span>
                        @if($signal->trade_id)
                            <a href="{{ route('trades.live') }}" class="ml-2 text-yellow-500 hover:text-yellow-400">See position &rarr;</a>
                        @endif
                    </p>
                @endif

                <!-- Stage 5: what the provider said afterwards -->
                {{-- The instruction thread. Without this the only record of an autonomous
                     partial close is a Telegram message that scrolled away, and "what did
                     the copier do to this position" is a reconstruction. --}}
                @if($signal->followUps->isNotEmpty())
                    <div class="mt-3 border-t border-gray-700 pt-3">
                        <p class="text-xs uppercase tracking-wide text-gray-500">
                            Instructions since ({{ $signal->followUps->count() }})
                        </p>

                        <ol class="mt-2 space-y-2 border-l border-gray-700 pl-3">
                            @foreach($signal->followUps as $reply)
                                @php
                                    $acted = $reply->execution_status === \App\Models\TelegramSignal::EXEC_QUEUED;
                                    $nothing = $reply->follow_up_action === \App\Models\TelegramSignal::FOLLOW_NONE;
                                @endphp
                                <li class="text-xs">
                                    <div class="flex flex-wrap items-baseline gap-x-2">
                                        <span class="text-gray-500">{{ $reply->posted_at?->diffForHumans() }}</span>

                                        @if($reply->follow_up_action === null)
                                            <span class="rounded bg-gray-700 px-1.5 py-0.5 text-gray-400">not yet read</span>
                                        @elseif($nothing)
                                            <span class="rounded bg-gray-700 px-1.5 py-0.5 text-gray-500">no instruction</span>
                                        @else
                                            <span class="rounded px-1.5 py-0.5 font-medium {{ $acted ? 'bg-blue-400/10 text-blue-400' : 'bg-gray-700 text-gray-400' }}">
                                                {{ str_replace('_', ' ', $reply->follow_up_action) }}
                                                @if($reply->follow_up_fraction) {{ round($reply->follow_up_fraction * 100) }}% @endif
                                                @if($reply->follow_up_price) @ {{ rtrim(rtrim(number_format($reply->follow_up_price, 5, '.', ''), '0'), '.') }} @endif
                                            </span>
                                        @endif

                                        @if($reply->review_confidence !== null)
                                            <span class="text-gray-600">{{ $reply->review_confidence }}%</span>
                                        @endif
                                    </div>

                                    <p class="mt-1 whitespace-pre-line font-mono text-gray-300">{{ \Illuminate\Support\Str::limit(trim($reply->raw_text), 220) }}</p>

                                    {{-- Why it was read that way, and what came of it. A refusal is
                                         the interesting case: it is where a provider asked for
                                         something this copier will not do. --}}
                                    @if($reply->review_reasoning && ! $nothing)
                                        <p class="mt-1 text-gray-500">{{ $reply->review_reasoning }}</p>
                                    @endif

                                    @if($reply->execution_note && ! $nothing)
                                        <p class="mt-1 {{ $acted ? 'text-gray-400' : 'text-amber-400/80' }}">
                                            {{ $acted ? '' : 'Refused: ' }}{{ $reply->execution_note }}
                                        </p>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif

                <!-- Actions -->
                @if($signal->parse_status === \App\Models\TelegramSignal::PARSE_OK)
                    <div class="mt-3 flex flex-wrap items-center gap-x-3 border-t border-gray-700 pt-3">
                        <button type="button" wire:click="reviewNow({{ $signal->id }})" wire:loading.attr="disabled"
                                class="rounded-md bg-gray-700 px-3 py-1.5 text-xs font-medium text-gray-200 hover:bg-gray-600 disabled:opacity-50">
                            {{ $signal->review_status === \App\Models\TelegramSignal::REVIEW_PENDING ? 'Review' : 'Review again' }}
                        </button>

                        {{-- Only on signals that are approved and unacted-on, and it says what it
                             will risk before you press it. This places a real order. --}}
                        @if($signal->isActionable())
                            <button type="button" wire:click="executeNow({{ $signal->id }})" wire:loading.attr="disabled"
                                    wire:confirm="This queues a real order on the demo account, risking {{ number_format($fund['risk_per_trade'], 2) }} of the AI fund. Continue?"
                                    class="rounded-md bg-yellow-500 px-3 py-1.5 text-xs font-medium text-gray-900 hover:bg-yellow-400 disabled:opacity-50">
                                Execute
                            </button>
                            <span class="text-xs text-gray-500">
                                risks {{ number_format($fund['risk_per_trade'], 2) }} &mdash; every gate is re-checked first
                            </span>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-gray-700 p-10 text-center">
                <p class="text-sm text-gray-500">No signals captured yet.</p>
                <p class="mt-1 text-xs text-gray-600">
                    Send one to your bot, or add it to a channel and allow-list that chat in
                    <code>config/telegram.php</code>.
                </p>
            </div>
        @endforelse

        {{ $signals->links() }}
    </div>
</div>

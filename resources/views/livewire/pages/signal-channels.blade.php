<div>
    <x-slot name="header">
        Signal Channels
    </x-slot>

    <div class="space-y-6">
        <!-- Window -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-400">
                Every chat the copier has heard from, and what it has been worth.
            </p>

            <div class="flex gap-1 rounded-md bg-gray-800 p-1">
                @foreach(['all' => 'All time', '90d' => '90 days', '30d' => '30 days', '7d' => '7 days'] as $key => $label)
                    <button type="button" wire:click="$set('window', '{{ $key }}')"
                            class="{{ $window === $key ? 'bg-gray-700 text-yellow-400' : 'text-gray-400 hover:text-white' }} rounded px-3 py-1 text-xs font-medium">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        @forelse($rows as $row)
            @php
                $thin = $row['closed'] < $meaningful;
                $id = $row['channel']?->id;
            @endphp

            <div class="rounded-lg bg-gray-800 p-6">
                <!-- Identity and switch -->
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-medium text-gray-100">
                            {{ $row['label'] }}

                            @if($row['enabled'])
                                <span class="rounded bg-green-900/40 px-2 py-0.5 text-xs text-green-400">LIVE</span>
                            @else
                                <span class="rounded bg-gray-700 px-2 py-0.5 text-xs text-gray-400">RECORDING ONLY</span>
                            @endif
                        </h3>

                        @if($row['channel'])
                            <p class="mt-1 text-xs text-gray-500">
                                {{ $row['channel']->username ? '@'.$row['channel']->username.' · ' : '' }}
                                {{ $row['channel']->source === \App\Models\TelegramChannel::SOURCE_ACCOUNT ? 'account collector' : 'bot API' }}
                                @if($row['channel']->last_message_at)
                                    · last posted {{ $row['channel']->last_message_at->diffForHumans() }}
                                @endif
                            </p>
                        @endif
                    </div>

                    @if($id)
                        <button type="button" wire:click="toggle({{ $id }})"
                                wire:confirm="{{ $row['enabled']
                                    ? 'Stop trading this channel? Its messages will still be recorded.'
                                    : 'Trade this channel? Its signals will be parsed, reviewed and can place real orders.' }}"
                                class="{{ $row['enabled']
                                    ? 'bg-gray-700 text-gray-200 hover:bg-gray-600'
                                    : 'bg-yellow-500 text-gray-900 hover:bg-yellow-400' }} shrink-0 rounded-md px-4 py-2 text-sm font-medium">
                            {{ $row['enabled'] ? 'Stop trading' : 'Enable' }}
                        </button>
                    @endif
                </div>

                <!-- The funnel, then the money. In that order, because the second is not
                     interpretable without the first. -->
                <div class="mt-5 grid grid-cols-2 gap-4 border-t border-gray-700 pt-4 sm:grid-cols-4 lg:grid-cols-7">
                    <div>
                        <p class="text-xs text-gray-500">Messages</p>
                        <p class="mt-1 text-lg font-semibold text-gray-100">{{ $row['messages'] }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Parsed</p>
                        <p class="mt-1 text-lg font-semibold {{ ($row['parse_rate'] ?? 100) < 50 ? 'text-amber-400' : 'text-gray-100' }}">
                            {{ $row['parse_rate'] === null ? '—' : $row['parse_rate'].'%' }}
                        </p>
                        <p class="text-xs text-gray-600">
                            {{ $row['parsed'] }} of {{ $row['signals'] }}
                            @if($row['follow_ups'] > 0)
                                &middot; {{ $row['follow_ups'] }} {{ Str::plural('reply', $row['follow_ups']) }}
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Declined</p>
                        <p class="mt-1 text-lg font-semibold text-gray-100">
                            {{ $row['decline_rate'] === null ? '—' : $row['decline_rate'].'%' }}
                        </p>
                        <p class="text-xs text-gray-600">{{ $row['declined'] }} of {{ $row['approved'] + $row['declined'] }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Traded</p>
                        <p class="mt-1 text-lg font-semibold text-gray-100">{{ $row['executed'] }}</p>
                        <p class="text-xs text-gray-600">{{ $row['open'] }} open</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Win rate</p>
                        <p class="mt-1 text-lg font-semibold text-gray-100">
                            {{ $row['win_rate'] === null ? '—' : $row['win_rate'].'%' }}
                        </p>
                        <p class="text-xs text-gray-600">{{ $row['wins'] }}W / {{ $row['losses'] }}L</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Net P&amp;L</p>
                        <p class="mt-1 text-lg font-semibold {{ $row['net_money'] > 0 ? 'text-green-400' : ($row['net_money'] < 0 ? 'text-red-400' : 'text-gray-100') }}">
                            {{ $row['net_money'] > 0 ? '+' : '' }}{{ number_format($row['net_money'], 2) }}
                        </p>
                        <p class="text-xs text-gray-600">{{ $row['closed'] }} closed</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Avg R</p>
                        <p class="mt-1 text-lg font-semibold {{ ($row['avg_r'] ?? 0) > 0 ? 'text-green-400' : (($row['avg_r'] ?? 0) < 0 ? 'text-red-400' : 'text-gray-100') }}">
                            {{ $row['avg_r'] === null ? '—' : number_format($row['avg_r'], 2) }}
                        </p>
                        <p class="text-xs text-gray-600">
                            {{ $row['profit_factor'] === null ? 'no losses yet' : 'PF '.number_format($row['profit_factor'], 2) }}
                        </p>
                    </div>
                </div>

                {{-- Said in words, because a percentage of four trades looks exactly like a
                     percentage of four hundred. --}}
                @if($row['closed'] > 0 && $thin)
                    <p class="mt-4 rounded-md bg-gray-900 p-3 text-xs text-gray-400">
                        {{ $row['closed'] }} closed {{ Str::plural('trade', $row['closed']) }}. Too few to rank
                        against anything &mdash; a win rate over this many is a description of what happened,
                        not an estimate of what will.
                    </p>
                @elseif($row['closed'] === 0 && $row['messages'] > 0)
                    <p class="mt-4 rounded-md bg-gray-900 p-3 text-xs text-gray-400">
                        Nothing has closed yet.
                        @if(! $row['enabled'])
                            This channel is recorded but not enabled, so nothing here will ever trade until you turn it on.
                        @elseif($row['parsed'] === 0)
                            Nothing parsed either &mdash; the format may not be one the parser recognises.
                        @endif
                    </p>
                @endif

                <!-- Why signals get turned down -->
                @if($row['declined'] + ($row['messages'] - $row['parsed']) > 0)
                    <div class="mt-4 border-t border-gray-700 pt-3">
                        <button type="button" wire:click="expand({{ $id ?? 'null' }})"
                                class="text-xs text-yellow-500 hover:text-yellow-400">
                            {{ $expanded === $id && $id !== null ? 'Hide' : 'Why signals were turned down' }} &rarr;
                        </button>

                        @if($expanded === $id && $id !== null && count($reasons))
                            <ul class="mt-3 space-y-1">
                                @foreach($reasons as $reason => $count)
                                    <li class="flex items-start justify-between gap-4 text-xs">
                                        <span class="text-gray-300">{{ $reason }}</span>
                                        <span class="shrink-0 text-gray-500">{{ $count }}&times;</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-lg bg-gray-800 p-8 text-center">
                <p class="text-sm text-gray-400">No messages captured yet.</p>
                <p class="mt-2 text-xs text-gray-500">
                    The bot sees only chats it has been added to. To read a provider's channel, run the
                    account collector in <code class="text-gray-400">tools/telegram-collector/</code> &mdash;
                    it signs in as your own Telegram account and posts what it sees here.
                </p>
            </div>
        @endforelse

        <!-- Registered, never posted -->
        @if($idle->isNotEmpty())
            <div class="rounded-lg bg-gray-800 p-6">
                <h3 class="text-sm font-medium text-gray-400">Seen by the collector, nothing captured</h3>
                <p class="mt-1 text-xs text-gray-500">
                    Channels your Telegram account is in. They are listed so you can pick from names rather
                    than numeric ids; being here grants nothing.
                </p>

                <ul class="mt-4 divide-y divide-gray-700 border-t border-gray-700">
                    @foreach($idle as $channel)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-xs">
                            <div>
                                <span class="text-gray-300">{{ $channel->label() }}</span>
                                @if($channel->username)
                                    <span class="ml-2 text-gray-600">&#64;{{ $channel->username }}</span>
                                @endif
                            </div>
                            <button type="button" wire:click="toggle({{ $channel->id }})"
                                    wire:confirm="{{ $channel->is_enabled
                                        ? 'Stop trading this channel?'
                                        : 'Trade this channel? Its signals will be parsed, reviewed and can place real orders.' }}"
                                    class="{{ $channel->is_enabled ? 'text-green-400 hover:text-green-300' : 'text-gray-500 hover:text-gray-300' }}">
                                {{ $channel->is_enabled ? 'Enabled' : 'Enable' }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>

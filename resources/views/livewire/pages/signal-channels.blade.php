@php
    use App\Models\TelegramAccount as Acct;

    // Chip labels are built here rather than inline in the loop directive: a bracket inside
    // a directive's string argument throws Blade's parenthesis matching, and every directive
    // after it is left uncompiled.
    $chips = [
        'following' => 'Following ('.$counts['following'].')',
        'recording' => 'Recording only ('.$counts['recording'].')',
        'all' => 'All seen ('.$counts['all'].')',
    ];
@endphp

<div>
    <x-slot name="header">
        Providers
    </x-slot>

    <x-page-tabs group="providers" />

    <div class="space-y-6">
        <!-- The accounts the channels are read through -->
        {{--
            Above the channels, because a provider that has gone quiet is more often an
            account that has stopped being read than a channel that has stopped posting.
        --}}
        <div class="rounded-lg border border-gray-700 bg-gray-800 p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-white">Connected accounts</h2>
                <a href="{{ route('signals.accounts') }}" class="text-xs text-yellow-500 hover:text-yellow-400">
                    Manage accounts &rarr;
                </a>
            </div>

            @forelse($accounts as $account)
                {{-- Block form, not the one-line form: the rows loop below opens a block of
                     its own, and Blade would pair a one-liner here with that block's closer. --}}
                @php
                    $state = $account->login_state;
                @endphp
                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm {{ $loop->first ? '' : 'border-t border-gray-700 pt-3' }}">
                    <span class="font-medium text-gray-100">{{ $account->name() }}</span>

                    @if($state === Acct::ACTIVE && $account->isConnected())
                        <span class="rounded bg-green-900/40 px-2 py-0.5 text-xs text-green-400">CONNECTED</span>
                    @elseif($state === Acct::ACTIVE)
                        {{-- Signed in but nothing has read it lately. Not idle: the sign-in is
                             good, and sending somebody to redo it would be the wrong fix. --}}
                        <span class="rounded bg-amber-900/40 px-2 py-0.5 text-xs text-amber-400">SIGNED IN, NOT READING</span>
                    @elseif($account->loggingIn())
                        <span class="rounded bg-yellow-900/40 px-2 py-0.5 text-xs text-yellow-400">SIGNING IN</span>
                    @elseif($state === Acct::FAILED)
                        <span class="rounded bg-red-900/40 px-2 py-0.5 text-xs text-red-400">FAILED</span>
                    @else
                        <span class="rounded bg-gray-700 px-2 py-0.5 text-xs text-gray-400">IDLE</span>
                    @endif

                    @if($account->login_phone)
                        {{-- Enough to tell two numbers apart, not enough to dial one. --}}
                        <span class="font-mono text-xs text-gray-500">{{ Str::mask($account->login_phone, '*', 3, -3) }}</span>
                    @endif

                    <span class="text-xs text-gray-500">
                        @if($account->last_seen_at)
                            last heard <x-local-time :value="$account->last_seen_at" relative />
                        @else
                            never heard from
                        @endif
                    </span>
                </div>
            @empty
                <p class="mt-3 text-sm text-gray-400">
                    <a href="{{ route('signals.accounts') }}" class="text-yellow-500 hover:text-yellow-400">
                        Connect a Telegram account to follow providers &rarr;
                    </a>
                </p>
            @endforelse
        </div>

        <!-- Following, recording, everything -->
        <div class="flex flex-wrap gap-2">
            @foreach($chips as $key => $label)
                <button type="button" wire:click="$set('show', '{{ $key }}')"
                        class="rounded-full px-3 py-1 text-xs font-medium {{ $show === $key ? 'bg-yellow-500 text-gray-900' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <!-- Search and window -->
        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative min-w-0 flex-1">
                    <svg class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input type="search" wire:model.live.debounce.300ms="search"
                           placeholder="Search channels by name, @handle or id"
                           class="block w-full rounded-md border-gray-600 bg-gray-800 py-2 pl-9 pr-3 text-sm text-white placeholder-gray-500 focus:border-yellow-500 focus:ring-yellow-500">
                </div>

                <label class="flex shrink-0 items-center gap-2 text-xs text-gray-400">
                    <input type="checkbox" wire:model.live="onlyActive"
                           class="rounded border-gray-600 bg-gray-700 text-yellow-500 focus:ring-yellow-500">
                    Only channels that have posted
                </label>
            </div>

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
                                <span class="rounded bg-green-900/40 px-2 py-0.5 text-xs text-green-400">FOLLOWING</span>
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
                        {{-- The only control that arms a channel, so it is a switch with a
                             name rather than a verb: what it does is the same in both
                             directions, and the state is the thing to read. --}}
                        <button type="button" role="switch" aria-checked="{{ $row['enabled'] ? 'true' : 'false' }}"
                                wire:click="toggle({{ $id }})"
                                wire:confirm="{{ $row['enabled']
                                    ? 'Switch auto-trade off for this channel? Its messages will still be recorded.'
                                    : 'Switch auto-trade on for this channel? Its signals will be parsed, reviewed and can place real orders.' }}"
                                class="flex shrink-0 items-center gap-2 rounded-md bg-gray-900/60 px-3 py-2 text-sm font-medium text-gray-200 hover:bg-gray-700">
                            Auto-trade
                            <span class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors {{ $row['enabled'] ? 'bg-yellow-500' : 'bg-gray-600' }}">
                                <span class="inline-block h-4 w-4 rounded-full bg-white transition-transform {{ $row['enabled'] ? 'translate-x-4' : 'translate-x-0.5' }}"></span>
                            </span>
                            <span class="text-xs {{ $row['enabled'] ? 'text-yellow-400' : 'text-gray-500' }}">{{ $row['enabled'] ? 'on' : 'off' }}</span>
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
                            This channel is recording only, so nothing here will ever trade until auto-trade is switched on.
                        @elseif($row['parsed'] === 0)
                            Nothing parsed either &mdash; the format may not be one the parser recognises.
                        @endif
                    </p>
                @endif

                <!-- Per-channel overrides -->
                @if($id)
                    <div class="mt-4 border-t border-gray-700 pt-3">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <button type="button" wire:click="edit({{ $id }})"
                                    class="text-xs text-yellow-500 hover:text-yellow-400">
                                {{ $editing === $id ? 'Editing settings' : 'Settings for this channel' }} &rarr;
                            </button>

                            {{-- Which values are this channel's own, so "5% because I chose it"
                                 and "5% because the account says so" do not look identical. --}}
                            @php($policy = $row['channel']->policy($defaults))
                            @foreach($policy['overridden'] as $field)
                                <span class="rounded bg-yellow-400/10 px-1.5 py-0.5 text-xs text-yellow-500">
                                    {{ str_replace('_', ' ', $field) }}
                                </span>
                            @endforeach
                            @if($row['channel']->symbols_allow)
                                <span class="rounded bg-yellow-400/10 px-1.5 py-0.5 text-xs text-yellow-500">
                                    only {{ implode(', ', $row['channel']->symbols_allow) }}
                                </span>
                            @endif
                        </div>

                        @if($editing === $id)
                            <div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-3">
                                <div>
                                    <label class="block text-xs text-gray-500">Risk %</label>
                                    <input type="number" step="0.01" wire:model="form.risk_percentage"
                                           placeholder="{{ $defaults?->ai_risk_percentage ?? '—' }} (account)"
                                           class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-xs text-white focus:border-yellow-500 focus:ring-yellow-500">
                                    @error('form.risk_percentage') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="block text-xs text-gray-500">Levels</label>
                                    <select wire:model="form.copier_levels"
                                            class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-xs text-white focus:border-yellow-500 focus:ring-yellow-500">
                                        <option value="">{{ $defaults?->copier_levels ?? 'provider' }} (account)</option>
                                        <option value="provider">Provider's own</option>
                                        <option value="strategy">This account's</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs text-gray-500">Trades / day</label>
                                    <input type="number" step="1" wire:model="form.max_trades_per_day"
                                           placeholder="{{ $defaults?->ai_max_trades_per_day ?? 'no limit' }} (account)"
                                           class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-xs text-white focus:border-yellow-500 focus:ring-yellow-500">
                                </div>

                                <div>
                                    <label class="block text-xs text-gray-500">Min confluence</label>
                                    <input type="number" step="0.5" wire:model="form.min_confluence"
                                           placeholder="3 (default)"
                                           class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-xs text-white focus:border-yellow-500 focus:ring-yellow-500">
                                </div>

                                <div>
                                    <label class="block text-xs text-gray-500">Only these symbols</label>
                                    <input type="text" wire:model="form.symbols_allow" placeholder="XAUUSD, EURUSD"
                                           class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-xs text-white focus:border-yellow-500 focus:ring-yellow-500">
                                </div>

                                <div>
                                    <label class="block text-xs text-gray-500">Never these</label>
                                    <input type="text" wire:model="form.symbols_deny" placeholder="US30"
                                           class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-xs text-white focus:border-yellow-500 focus:ring-yellow-500">
                                </div>

                                <div>
                                    <label class="block text-xs text-gray-500">Read screenshots</label>
                                    <select wire:model="form.read_images"
                                            class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-xs text-white focus:border-yellow-500 focus:ring-yellow-500">
                                        <option value="">Yes (default)</option>
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </select>
                                </div>
                            </div>

                            <p class="mt-2 text-xs text-gray-500">
                                Blank means follow the account. An override is not a copy &mdash; lowering risk on the
                                Settings page still lowers it everywhere that has not deliberately said otherwise.
                            </p>

                            <div class="mt-3 flex gap-2">
                                <button type="button" wire:click="savePolicy"
                                        class="rounded-md bg-yellow-500 px-3 py-1.5 text-xs font-medium text-gray-900 hover:bg-yellow-400">
                                    Save
                                </button>
                                <button type="button" wire:click="$set('editing', null)"
                                        class="rounded-md px-3 py-1.5 text-xs text-gray-400 hover:text-gray-200">
                                    Cancel
                                </button>
                            </div>
                        @endif
                    </div>
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
                <p class="text-sm text-gray-400">
                    {{ $show === 'all' ? 'No messages captured yet.' : 'No channels here.' }}
                </p>
                <p class="mt-2 text-xs text-gray-500">
                    @if($show === 'all')
                        The bot sees only chats it has been added to. To read a provider's channel, run the
                        account collector in <code class="text-gray-400">tools/telegram-collector/</code> &mdash;
                        it signs in as your own Telegram account and posts what it sees here.
                    @else
                        Nothing that has posted is {{ $show === 'following' ? 'being followed' : 'recording only' }} right now.
                    @endif
                </p>
            </div>
        @endforelse

        {{-- What the switch means, said once under the list rather than on every row. --}}
        <p class="text-xs text-gray-500">
            A channel switched on is the only thing that arms it. Every signal it posts is still reviewed
            against your own market data and the AI fund before anything is placed. Signals from a channel
            that is recording only are kept, scored, and never traded.
        </p>

        <!-- Registered, never posted -->
        @if($idle->isNotEmpty())
            <div class="rounded-lg bg-gray-800 p-6">
                <h3 class="text-sm font-medium text-gray-400">Seen by the collector, nothing captured</h3>
                <p class="mt-1 text-xs text-gray-500">
                    Channels your Telegram account is in. They are listed so you can pick from names rather
                    than numeric ids; being here grants nothing.
                    @if($idleTotal > $idle->count())
                        <span class="text-gray-400">
                            Showing {{ $idle->count() }} of {{ $idleTotal }} &mdash; search to narrow it.
                        </span>
                    @endif
                </p>

                {{-- Channels, groups and bots announce themselves. A person does not:
                     inventorying somebody's private conversations into this database is
                     not a thing to do because it would have been convenient, so one is
                     named here, deliberately, one at a time. --}}
                <div class="mt-4 flex flex-wrap items-end gap-2 rounded-md bg-gray-900/40 p-3">
                    <div class="min-w-0 flex-1">
                        <label for="privateUsername" class="block text-xs text-gray-500">
                            Watch a private chat &mdash; a provider who sends signals by direct message
                        </label>
                        <input type="text" id="privateUsername" wire:model="privateUsername" placeholder="@username"
                               class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-sm text-white focus:border-yellow-500 focus:ring-yellow-500">
                    </div>
                    <button type="button" wire:click="watchPrivate" wire:loading.attr="disabled"
                            class="shrink-0 rounded-md bg-gray-700 px-3 py-2 text-xs font-medium text-gray-200 hover:bg-gray-600 disabled:opacity-50">
                        Look up
                    </button>
                    @error('privateUsername')
                        <p class="w-full text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <ul class="mt-4 divide-y divide-gray-700 border-t border-gray-700">
                    @foreach($idle as $channel)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-xs">
                            <div>
                                <span class="text-gray-300">{{ $channel->label() }}</span>
                                @if($channel->username)
                                    <span class="ml-2 text-gray-600">&#64;{{ $channel->username }}</span>
                                @endif
                                {{-- A bot delivering signals privately and a broadcast channel
                                     arrive by the same transport and read identically here, but
                                     they are not the same thing to trust. --}}
                                @if($channel->resolve_state === \App\Models\TelegramChannel::RESOLVE_PENDING)
                                    <span class="ml-2 rounded bg-yellow-900/40 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-yellow-500">looking up</span>
                                @elseif($channel->resolve_state === \App\Models\TelegramChannel::RESOLVE_FAILED)
                                    <span class="ml-2 rounded bg-red-900/40 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-red-400"
                                          title="{{ $channel->resolve_error }}">not found</span>
                                @endif
                                @if($channel->kind === \App\Models\TelegramChannel::KIND_BOT)
                                    <span class="ml-2 rounded bg-sky-900/40 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-sky-400">bot</span>
                                @elseif($channel->kind === \App\Models\TelegramChannel::KIND_USER)
                                    <span class="ml-2 rounded bg-purple-900/40 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-purple-400">private</span>
                                @elseif($channel->kind === \App\Models\TelegramChannel::KIND_GROUP)
                                    <span class="ml-2 rounded bg-gray-700 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-400">group</span>
                                @endif
                            </div>
                            <button type="button" role="switch" aria-checked="{{ $channel->is_enabled ? 'true' : 'false' }}"
                                    wire:click="toggle({{ $channel->id }})"
                                    wire:confirm="{{ $channel->is_enabled
                                        ? 'Switch auto-trade off for this channel?'
                                        : 'Switch auto-trade on for this channel? Its signals will be parsed, reviewed and can place real orders.' }}"
                                    class="{{ $channel->is_enabled ? 'text-green-400 hover:text-green-300' : 'text-gray-500 hover:text-gray-300' }}">
                                Auto-trade {{ $channel->is_enabled ? 'on' : 'off' }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>

@php
    use App\Models\TelegramAccount as Acct;
@endphp

<div>
    <x-slot name="header">
        Telegram Accounts
    </x-slot>

    {{-- Polled only while a sign-in is under way, so an idle page is not refetching every
         few seconds for nothing. --}}
    <div class="space-y-6" @if($awaiting) wire:poll.3s @endif>
        <div class="rounded-lg border border-gray-700 bg-gray-800/50 p-4">
            <p class="text-sm text-gray-300">
                Sign in with your phone number. Each account runs its own collector.
            </p>
            <p class="mt-1 text-xs text-gray-500">
                The code is relayed to that collector, which performs the sign-in and keeps the session on its
                own machine. This dashboard ends up with a row saying &ldquo;signed in&rdquo;, never a
                credential that can read your chats.
            </p>
        </div>

        <!-- Add -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="text-sm font-medium text-gray-400">Add an account</h3>

            <div class="mt-3 flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <label for="label" class="block text-xs text-gray-500">Name it something you will recognise</label>
                    <input type="text" id="label" wire:model="label" placeholder="Personal account"
                           class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                    @error('label') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>

                <button type="button" wire:click="add"
                        class="shrink-0 rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400">
                    Add account
                </button>
            </div>
        </div>

        <!-- Accounts -->
        @forelse($accounts as $account)
            @php($state = $account->login_state)

            <div class="rounded-lg bg-gray-800 p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-medium text-gray-100">
                            {{ $account->name() }}

                            @if($state === Acct::ACTIVE && $account->isConnected())
                                <span class="rounded bg-green-900/40 px-2 py-0.5 text-xs text-green-400">CONNECTED</span>
                            @elseif($state === Acct::ACTIVE)
                                <span class="rounded bg-amber-900/40 px-2 py-0.5 text-xs text-amber-400">SIGNED IN, NOT RUNNING</span>
                            @elseif($account->loggingIn())
                                <span class="rounded bg-yellow-900/40 px-2 py-0.5 text-xs text-yellow-400">SIGNING IN</span>
                            @else
                                <span class="rounded bg-gray-700 px-2 py-0.5 text-xs text-gray-400">NOT SIGNED IN</span>
                            @endif
                        </h3>

                        <p class="mt-1 text-xs text-gray-500">
                            {{ $account->label }}
                            @if($account->last_seen_at)
                                {{-- "Never" and "an hour ago" are both not-connected and mean
                                     entirely different things. --}}
                                &middot; last heard from <x-local-time :value="$account->last_seen_at" relative />
                            @endif
                            &middot; {{ $account->enabled_channels_count }} of {{ $account->channels_count }} channels enabled
                        </p>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <button type="button" wire:click="reissue({{ $account->id }})"
                                wire:confirm="Issue a new token? The current one stops working immediately and that collector will need restarting."
                                class="rounded-md bg-gray-700 px-3 py-1.5 text-xs font-medium text-gray-200 hover:bg-gray-600">
                            New token
                        </button>
                        <button type="button" wire:click="remove({{ $account->id }})"
                                wire:confirm="Remove this account and revoke its token? Its channels and their history are kept."
                                class="rounded-md px-3 py-1.5 text-xs text-red-400 hover:text-red-300">
                            Remove
                        </button>
                    </div>
                </div>

                <!-- Signing in -->
                @if($account->loginStalled())
                    <div class="mt-4 rounded-md border border-amber-500/30 bg-amber-900/20 p-4">
                        <p class="text-sm text-amber-300">This sign-in stalled.</p>
                        <p class="mt-1 text-xs text-amber-200/70">
                            {{ $account->login_message ?: 'Nothing has moved for ten minutes. The code may have expired, or this account\'s collector may not be running.' }}
                        </p>
                        <button type="button" wire:click="cancelLogin({{ $account->id }})"
                                class="mt-3 rounded-md bg-gray-700 px-3 py-1.5 text-xs font-medium text-gray-200 hover:bg-gray-600">
                            Start again
                        </button>
                    </div>
                @elseif($state === Acct::IDLE || $state === Acct::FAILED)
                    <div class="mt-4 rounded-md bg-gray-900/60 p-4">
                        @if($state === Acct::FAILED && $account->login_message)
                            {{-- Telegram's own words. "Failed" alone sends people to the wrong
                                 place: a wrong code and a banned number need different actions. --}}
                            <p class="mb-3 text-xs text-red-400">{{ $account->login_message }}</p>
                        @endif

                        <label for="phone-{{ $account->id }}" class="block text-xs text-gray-500">
                            Phone number, with country code
                        </label>

                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <input type="tel" id="phone-{{ $account->id }}" wire:model="phone" placeholder="+60123456789"
                                   class="min-w-0 flex-1 rounded-md border-gray-600 bg-gray-700 text-sm text-white focus:border-yellow-500 focus:ring-yellow-500">
                            <button type="button" wire:click="beginLogin({{ $account->id }})" wire:loading.attr="disabled"
                                    class="shrink-0 rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400 disabled:opacity-50">
                                Send code
                            </button>
                        </div>
                        @error('phone') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                @elseif($state === Acct::REQUESTED)
                    <div class="mt-4 flex items-center gap-3 rounded-md bg-gray-900/60 p-4">
                        <svg class="h-4 w-4 animate-spin text-yellow-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <p class="text-sm text-gray-300">Asking Telegram for a code for {{ $account->login_phone }}&hellip;</p>
                    </div>
                @elseif($state === Acct::CODE_SENT || $state === Acct::CODE_SUBMITTED)
                    <div class="mt-4 rounded-md bg-gray-900/60 p-4">
                        <p class="text-xs text-gray-400">
                            Telegram sent a code to <span class="text-gray-200">{{ $account->login_phone }}</span> &mdash;
                            check your Telegram app rather than your messages.
                        </p>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <input type="text" inputmode="numeric" wire:model="code" placeholder="12345"
                                   @disabled($state === Acct::CODE_SUBMITTED)
                                   class="w-32 rounded-md border-gray-600 bg-gray-700 text-center font-mono text-lg tracking-widest text-white focus:border-yellow-500 focus:ring-yellow-500 disabled:opacity-50">
                            <button type="button" wire:click="submitCode({{ $account->id }})"
                                    @disabled($state === Acct::CODE_SUBMITTED)
                                    class="rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400 disabled:opacity-50">
                                {{ $state === Acct::CODE_SUBMITTED ? 'Signing in…' : 'Sign in' }}
                            </button>
                            <button type="button" wire:click="cancelLogin({{ $account->id }})"
                                    class="px-2 py-2 text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                        </div>
                        @error('code') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                @elseif($state === Acct::PASSWORD_NEEDED || $state === Acct::PASSWORD_SUBMITTED)
                    <div class="mt-4 rounded-md bg-gray-900/60 p-4">
                        <p class="text-xs text-gray-400">
                            This account has two-step verification. The password is relayed to the collector and
                            never stored here.
                        </p>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <input type="password" wire:model="password" placeholder="Two-step password"
                                   @disabled($state === Acct::PASSWORD_SUBMITTED)
                                   class="min-w-0 flex-1 rounded-md border-gray-600 bg-gray-700 text-sm text-white focus:border-yellow-500 focus:ring-yellow-500 disabled:opacity-50">
                            <button type="button" wire:click="submitPassword({{ $account->id }})"
                                    @disabled($state === Acct::PASSWORD_SUBMITTED)
                                    class="shrink-0 rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400 disabled:opacity-50">
                                {{ $state === Acct::PASSWORD_SUBMITTED ? 'Checking…' : 'Continue' }}
                            </button>
                            <button type="button" wire:click="cancelLogin({{ $account->id }})"
                                    class="px-2 py-2 text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                        </div>
                        @error('password') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif

                <!-- The token, needed only by a machine with no collector yet -->
                @if($issuedFor === $account->id && $issuedToken)
                    <div class="mt-4 rounded-md border border-green-500/30 bg-green-900/20 p-4" x-data="{ copied: false, open: false }">
                        <p class="text-xs uppercase tracking-wide text-green-400">
                            Copy this now &mdash; only a hash is kept, so it cannot be shown again
                        </p>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <code class="flex-1 overflow-x-auto rounded bg-gray-900 px-3 py-2 font-mono text-sm text-gray-100">{{ $issuedToken }}</code>
                            <button type="button"
                                    x-on:click="navigator.clipboard.writeText(@js($issuedToken)); copied = true; setTimeout(() => copied = false, 2000)"
                                    class="shrink-0 rounded-md bg-gray-700 px-3 py-2 text-xs font-medium text-gray-200 hover:bg-gray-600">
                                <span x-show="!copied">Copy</span>
                                <span x-show="copied" x-cloak class="text-green-400">Copied</span>
                            </button>
                            <button type="button" wire:click="dismissToken"
                                    class="shrink-0 px-2 py-2 text-xs text-gray-500 hover:text-gray-300">Done</button>
                        </div>

                        {{-- Folded away: only the first account on a new machine needs it, and
                             leaving it open made adding an account look like a build step. --}}
                        <button type="button" x-on:click="open = !open"
                                class="mt-3 text-xs text-green-300/70 hover:text-green-200">
                            <span x-show="!open">Running this one on a new machine? &rarr;</span>
                            <span x-show="open" x-cloak>Hide &darr;</span>
                        </button>

                        <div x-show="open" x-cloak>
                            <pre class="mt-2 overflow-x-auto rounded bg-gray-900 p-3 font-mono text-xs text-gray-300">GD_BASE_URL={{ $baseUrl }}
GD_TOKEN={{ $issuedToken }}
GD_SESSION_FILE=./{{ Str::slug($account->label) }}

python collector.py run</pre>
                            <p class="mt-1 text-xs text-green-200/60">
                                Then sign in above &mdash; the collector waits for it.
                            </p>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-lg bg-gray-800 p-8 text-center">
                <p class="text-sm text-gray-400">No accounts yet.</p>
                <p class="mt-2 text-xs text-gray-500">
                    Add one above, then sign in with your phone number.
                </p>
            </div>
        @endforelse
    </div>
</div>

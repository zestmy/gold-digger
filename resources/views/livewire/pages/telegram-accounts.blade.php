<div>
    <x-slot name="header">
        Telegram Accounts
    </x-slot>

    <div class="space-y-6">
        <div class="rounded-lg border border-gray-700 bg-gray-800/50 p-4">
            <p class="text-sm text-gray-300">
                Each account runs its own collector, with its own token and its own session.
            </p>
            <p class="mt-1 text-xs text-gray-500">
                Signing in happens on the machine that will hold the session, not here &mdash; it needs the code
                Telegram sends to the phone, and that session can read every chat on the account.
            </p>
        </div>

        <!-- Add -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="text-sm font-medium text-gray-400">Add an account</h3>

            <div class="mt-3 flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <label for="label" class="block text-xs text-gray-500">Name it something you will recognise</label>
                    <input type="text" id="label" wire:model="label" placeholder="Personal account (home VPS)"
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
            <div class="rounded-lg bg-gray-800 p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-medium text-gray-100">
                            {{ $account->name() }}

                            @if($account->isConnected())
                                <span class="rounded bg-green-900/40 px-2 py-0.5 text-xs text-green-400">CONNECTED</span>
                            @elseif($account->last_seen_at)
                                <span class="rounded bg-amber-900/40 px-2 py-0.5 text-xs text-amber-400">STOPPED</span>
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

                @if($issuedFor === $account->id && $issuedToken)
                    <div class="mt-4 rounded-md border border-green-500/30 bg-green-900/20 p-4" x-data="{ copied: false }">
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

                        {{-- The command, with this deployment's own URL in it, so nothing has to be
                             assembled by hand at two in the morning. --}}
                        <p class="mt-3 text-xs text-green-200/70">On the machine that will run this collector:</p>
                        <pre class="mt-1 overflow-x-auto rounded bg-gray-900 p-3 font-mono text-xs text-gray-300">GD_BASE_URL={{ $baseUrl }}
GD_TOKEN={{ $issuedToken }}
GD_SESSION_FILE=./{{ Str::slug($account->label) }}

python collector.py login      # phone, code, 2FA - on that machine
python collector.py announce
python collector.py run</pre>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-lg bg-gray-800 p-8 text-center">
                <p class="text-sm text-gray-400">No accounts yet.</p>
                <p class="mt-2 text-xs text-gray-500">
                    Add one above to get a token, then sign its collector in on whichever machine will run it.
                </p>
            </div>
        @endforelse
    </div>
</div>

<div>
    <x-slot name="header">
        Terminal Setup
    </x-slot>

    <div class="space-y-6">
        <!-- 1. The EA -->
        <div class="rounded-lg bg-gray-800 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h3 class="text-sm font-medium text-gray-400">
                        <span class="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-gray-700 text-xs text-gray-300">1</span>
                        Expert Advisor
                    </h3>
                    <p class="mt-2 text-sm text-gray-300">
                        Already pointed at <code class="text-gray-100">{{ $whitelistUrl }}</code>, so there is
                        nothing to edit before compiling.
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        Source, not a compiled binary &mdash; MetaEditor builds it in a keystroke, and that
                        compile is what proves the terminal can. Speaks wire protocol {{ $wireVersion }};
                        an older copy refuses commands and says so rather than misreading them.
                    </p>
                </div>

                <a href="{{ route('terminal.download') }}"
                   class="shrink-0 rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400">
                    Download EA
                </a>
            </div>
        </div>

        <!-- 2. The token -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="text-sm font-medium text-gray-400">
                <span class="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-gray-700 text-xs text-gray-300">2</span>
                API token
            </h3>

            {{-- Said plainly, because someone will otherwise look for a reveal button that
                 cannot exist: only a hash is stored, on purpose. --}}
            <p class="mt-2 text-xs text-gray-500">
                Only a hash of each token is stored, so an existing one can never be shown again &mdash;
                a compromise of this dashboard leaks no working credentials. To get a token you can
                paste, issue a new one. Existing terminals keep working until you revoke theirs.
            </p>

            @if($issuedToken)
                {{-- Once. The copy button exists because retyping 51 characters by eye is
                     how a token ends up subtly wrong and the EA silently unauthorised. --}}
                <div class="mt-4 rounded-md border border-green-500/30 bg-green-900/20 p-4"
                     x-data="{ copied: false }">
                    <p class="text-xs uppercase tracking-wide text-green-400">Copy this now &mdash; it will not be shown again</p>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <code class="flex-1 overflow-x-auto rounded bg-gray-900 px-3 py-2 font-mono text-sm text-gray-100">{{ $issuedToken }}</code>

                        <button type="button"
                                x-on:click="navigator.clipboard.writeText(@js($issuedToken)); copied = true; setTimeout(() => copied = false, 2000)"
                                class="shrink-0 rounded-md bg-gray-700 px-3 py-2 text-xs font-medium text-gray-200 hover:bg-gray-600">
                            <span x-show="!copied">Copy</span>
                            <span x-show="copied" x-cloak class="text-green-400">Copied</span>
                        </button>

                        <button type="button" wire:click="dismissToken"
                                class="shrink-0 rounded-md px-2 py-2 text-xs text-gray-500 hover:text-gray-300">
                            Done
                        </button>
                    </div>

                    <p class="mt-2 text-xs text-green-200/60">
                        Paste it into the EA's <code>ApiToken</code> input. It is not in the downloaded
                        archive on purpose &mdash; a credential inside a file travels with the file.
                    </p>
                </div>
            @else
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label for="tokenName" class="block text-xs text-gray-500">Label</label>
                        <input type="text" id="tokenName" wire:model="tokenName" placeholder="Windows VPS"
                               class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                        @error('tokenName') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="brokerAccountId" class="block text-xs text-gray-500">Broker account</label>
                        <select id="brokerAccountId" wire:model="brokerAccountId"
                                class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                            <option value="">Not bound</option>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <button type="button" wire:click="issueToken"
                        class="mt-3 rounded-md bg-gray-700 px-4 py-2 text-sm font-medium text-gray-200 hover:bg-gray-600">
                    Issue token
                </button>
            @endif

            @if($tokens->isNotEmpty())
                <ul class="mt-5 divide-y divide-gray-700 border-t border-gray-700">
                    @foreach($tokens as $token)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-xs">
                            <div>
                                <span class="text-gray-300">{{ $token->name }}</span>
                                @if($token->brokerAccount)
                                    <span class="ml-2 text-gray-600">{{ $token->brokerAccount->label }}</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-x-4">
                                <span class="text-gray-500">
                                    {{ $token->last_used_at ? 'last used '.$token->last_used_at->diffForHumans() : 'never used' }}
                                </span>
                                <button type="button" wire:click="revokeToken({{ $token->id }})"
                                        wire:confirm="Revoke &quot;{{ $token->name }}&quot;? Any terminal using it stops authenticating on its next poll."
                                        class="text-red-400 hover:text-red-300">Revoke</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <!-- 3. Whitelist -->
        <div class="rounded-lg bg-gray-800 p-6" x-data="{ copied: false }">
            <h3 class="text-sm font-medium text-gray-400">
                <span class="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-gray-700 text-xs text-gray-300">3</span>
                Whitelist this dashboard
            </h3>
            <p class="mt-2 text-sm text-gray-300">
                Tools &rarr; Options &rarr; Expert Advisors &rarr; tick <em>Allow WebRequest for listed URL</em>, and add:
            </p>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                <code class="flex-1 rounded bg-gray-900 px-3 py-2 font-mono text-sm text-gray-100">{{ $whitelistUrl }}</code>
                <button type="button"
                        x-on:click="navigator.clipboard.writeText(@js($whitelistUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                        class="shrink-0 rounded-md bg-gray-700 px-3 py-2 text-xs font-medium text-gray-200 hover:bg-gray-600">
                    <span x-show="!copied">Copy</span>
                    <span x-show="copied" x-cloak class="text-green-400">Copied</span>
                </button>
            </div>

            <p class="mt-2 text-xs text-gray-500">
                Scheme and host only. A trailing path is the usual cause of <code>WebRequest</code> error 4014.
            </p>
        </div>

        <!-- 4. Attach -->
        <div class="rounded-lg bg-gray-800 p-6">
            <h3 class="text-sm font-medium text-gray-400">
                <span class="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-gray-700 text-xs text-gray-300">4</span>
                Compile and attach
            </h3>
            <ol class="mt-3 space-y-2 text-sm text-gray-300">
                <li>Extract the archive over <em>File &rarr; Open Data Folder</em>, merging the MQL5 directory.</li>
                <li>Open <code class="text-gray-400">Experts/GoldDigger/GoldDiggerBridge.mq5</code> in MetaEditor and press F7.</li>
                <li>Drag it onto any chart of a <strong>demo</strong> account, paste the token into <code class="text-gray-400">ApiToken</code>.</li>
                <li>Tick <strong>Allow Algo Trading</strong> in the Common tab, and check the toolbar button too.</li>
            </ol>

            {{-- The one that catches everybody, including during commissioning here. --}}
            <p class="mt-3 rounded-md bg-gray-900 p-3 text-xs text-gray-400">
                Those are two separate switches, and MetaTrader turns the toolbar one off by itself
                whenever the account changes. The dashboard shows BLOCKED rather than OFFLINE when the
                terminal is healthy but one of them is off &mdash; the executor is fine, and every order
                would still be refused with 10027.
            </p>

            <a href="{{ route('dashboard') }}" class="mt-3 inline-block text-xs text-yellow-500 hover:text-yellow-400">
                Watch Bot Status &rarr;
            </a>
        </div>
    </div>
</div>

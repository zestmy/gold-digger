<section class="space-y-8">
    {{-- ================================================================ --}}
    {{-- TWO-FACTOR                                                       --}}
    {{-- ================================================================ --}}
    <div>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Two-factor authentication</h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                A session here can enable autonomous trading, raise the AI capital cap and queue orders.
                A password on its own is thin protection for that.
            </p>
        </header>

        <div class="mt-6">
            @if($enabled)
                <div class="flex flex-wrap items-center gap-3">
                    <span class="rounded bg-green-500/10 px-2 py-1 text-xs font-semibold uppercase tracking-wide text-green-400">On</span>
                    <span class="text-sm text-gray-400">
                        {{ $remaining }} recovery {{ \Illuminate\Support\Str::plural('code', $remaining) }} left.
                    </span>
                </div>

                @if($remaining <= 2)
                    {{-- Said before they are down to none, because the point of noticing is
                         to act while there is still a way back in. --}}
                    <p class="mt-2 text-sm text-amber-400">
                        Running low. Generate a new set before you are locked out of your own account.
                    </p>
                @endif

                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="button" wire:click="regenerateRecoveryCodes"
                            class="rounded-md border border-gray-600 px-4 py-2 text-sm text-gray-300 hover:bg-gray-700">
                        New recovery codes
                    </button>
                </div>
            @elseif($pendingSecret)
                <p class="text-sm text-gray-400">
                    Add this to your authenticator app, then enter the code it shows. Nothing is enforced
                    until that code works &mdash; a secret nobody holds would lock you out.
                </p>

                <div class="mt-4 rounded-md bg-gray-900 p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Secret</p>
                    <p class="mt-1 break-all font-mono text-sm text-gray-100">{{ $pendingSecret }}</p>

                    <p class="mt-3 text-xs uppercase tracking-wide text-gray-500">Or paste this</p>
                    <p class="mt-1 break-all font-mono text-xs text-gray-400">{{ $uri }}</p>
                </div>

                <div class="mt-4 max-w-xs">
                    <label for="confirm-code" class="block text-sm font-medium text-gray-300">Code from the app</label>
                    <input wire:model="code" id="confirm-code" type="text" inputmode="numeric" placeholder="000000"
                           class="mt-1 block w-full rounded-md border-gray-600 bg-gray-800 font-mono tracking-widest text-white shadow-sm focus:border-yellow-500 focus:ring-yellow-500">
                    <x-input-error :messages="$errors->get('code')" class="mt-2" />
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="button" wire:click="confirm"
                            class="rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400">
                        Turn it on
                    </button>
                    <button type="button" wire:click="cancel"
                            class="rounded-md border border-gray-600 px-4 py-2 text-sm text-gray-300 hover:bg-gray-700">
                        Cancel
                    </button>
                </div>
            @else
                <button type="button" wire:click="begin"
                        class="rounded-md bg-yellow-500 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-yellow-400">
                    Set up two-factor
                </button>
            @endif
        </div>

        {{-- Shown once. They are stored hashed, so leaving this page loses them - which is
             said here rather than discovered later. --}}
        @if($freshRecoveryCodes)
            <div class="mt-6 rounded-md border border-amber-500/40 bg-amber-900/20 p-4">
                <p class="text-sm font-medium text-amber-300">Save these now. They are not shown again.</p>
                <p class="mt-1 text-xs text-amber-200/70">
                    Each works once, in place of a code, if you lose your phone. They are stored hashed,
                    so nobody &mdash; including this application &mdash; can read them back.
                </p>

                <div class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-amber-100 sm:grid-cols-4">
                    @foreach($freshRecoveryCodes as $recovery)
                        <span>{{ $recovery }}</span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- ================================================================ --}}
    {{-- SESSIONS                                                         --}}
    {{-- ================================================================ --}}
    <div class="border-t border-gray-700 pt-8">
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Where you are signed in</h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Every browser holding a session on this account. If one is not you, sign the others out
                and change your password.
            </p>
        </header>

        @if($sessions)
            <ul class="mt-4 space-y-2">
                @foreach($sessions as $session)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-gray-900 px-4 py-3 text-sm">
                        <span class="text-gray-200">{{ $session['agent'] }}</span>
                        <span class="font-mono text-xs text-gray-500">{{ $session['ip'] }}</span>

                        @if($session['current'])
                            <span class="rounded bg-green-500/10 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-green-400">This browser</span>
                        @endif

                        <span class="ml-auto text-xs text-gray-500">{{ $session['last_active'] }}</span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-4 text-sm text-gray-500">
                Sessions are not stored in the database on this deployment, so there is nothing to list.
            </p>
        @endif
    </div>

    {{-- ================================================================ --}}
    {{-- PASSWORD-GATED ACTIONS                                           --}}
    {{-- ================================================================ --}}
    <div class="border-t border-gray-700 pt-8">
        <div class="max-w-xs">
            <label for="security-password" class="block text-sm font-medium text-gray-300">Confirm your password</label>
            <input wire:model="password" id="security-password" type="password" autocomplete="current-password"
                   class="mt-1 block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm focus:border-yellow-500 focus:ring-yellow-500">
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
            {{-- Removing a second factor or keeping a session alive is exactly what somebody
                 holding a stolen cookie would do first. --}}
            <p class="mt-2 text-xs text-gray-500">
                Required for both actions below, so a stolen session is not enough on its own.
            </p>
        </div>

        <div class="mt-4 flex flex-wrap gap-3">
            <button type="button" wire:click="signOutOtherSessions"
                    class="rounded-md border border-gray-600 px-4 py-2 text-sm text-gray-300 hover:bg-gray-700">
                Sign out every other browser
            </button>

            @if($enabled)
                <button type="button" wire:click="disable"
                        class="rounded-md border border-red-500/40 px-4 py-2 text-sm text-red-400 hover:bg-red-500/10">
                    Turn off two-factor
                </button>
            @endif
        </div>
    </div>
</section>

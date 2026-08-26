{{--
    Sidebar Content Partial

    Shared between the desktop and mobile sidebars.

    ## Why the links are data rather than markup

    Fourteen hand-written blocks had drifted: each carried its own copy of the same
    active-state ternary, and adding a page meant pasting forty lines to change three
    words. One loop means a new page is one line, and the highlight rule exists once.

    ## Why they are grouped

    Flat, the list gave equal weight to the page you open every day and the one you open
    once when connecting a terminal. The groups are by how often you need them rather than
    by what the code calls them - "Copier" is a section because that is what somebody is
    doing, even though its pages live in different namespaces.
--}}

@php
    $sections = [
        'Overview' => [
            ['dashboard', 'Dashboard', 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25'],
            ['analytics', 'Analytics', 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
        ],
        'Trading' => [
            ['trades.live', 'Live Trades', 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z'],
            ['trades.history', 'History', 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['analysis', 'Chart Analysis', 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
            ['signals', 'Signals', 'M9.348 14.652a3.75 3.75 0 010-5.304m5.304 0a3.75 3.75 0 010 5.304m-7.425 2.121a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M5.106 18.894c-3.808-3.807-3.808-9.98 0-13.788m13.788 0c3.808 3.807 3.808 9.98 0 13.788M12 12h.008v.008H12V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z'],
        ],
        'Copier' => [
            ['signals.accounts', 'Telegram Accounts', 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z'],
            ['signals.copier', 'Signal Copier', 'M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5'],
            ['signals.channels', 'Channels', 'M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46'],
        ],
        'Configure' => [
            ['setup', 'Setup', 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
            ['strategies', 'Strategies', 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z'],
            ['strategies.improve', 'Improve', 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z'],
            ['terminal', 'Terminal', 'M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25'],
            ['broker-accounts', 'Broker Accounts', 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z'],
            ['settings', 'Settings', 'M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75'],
        ],
        'System' => [
            ['logs', 'Logs', 'M6.75 7.5l3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0021 18V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v12a2.25 2.25 0 002.25 2.25z'],
        ],
    ];
@endphp


<!-- Logo -->
<div class="flex h-16 shrink-0 items-center">
    <a href="{{ route('dashboard') }}" class="flex items-center gap-x-3">
        <svg class="h-8 w-8" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 20L8 14H24L28 20V26H4V20Z" fill="#FFD700" stroke="#DAA520" stroke-width="1"/>
            <path d="M8 14L12 8H20L24 14H8Z" fill="#FFD700" stroke="#DAA520" stroke-width="1"/>
            <path d="M16 6L24 14" stroke="#9CA3AF" stroke-width="2" stroke-linecap="round"/>
            <path d="M22 12L28 6M24 14L30 8" stroke="#6B7280" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <span class="whitespace-nowrap text-xl font-bold text-white" x-show="!collapsed">FX<span class="text-yellow-400">SignalPro</span></span>
    </a>
</div>

{{-- Which section is open, and whether the bar is minimised, both live in localStorage:
     they are preferences about this browser window and have to survive a full page load
     without a round trip. The section containing the current page is forced open, so
     collapsing something can never hide where you are. --}}
<nav class="flex flex-1 flex-col"
     x-data="{
         /* One section at a time. Five open at once is the wall this replaced, and a
            list that keeps growing as you explore it never settles. Stored as a single
            name rather than a set, because that is what it now is. */
         opened: localStorage.getItem('gd-nav-open') || 'Overview',
         toggle(name) {
             this.opened = this.opened === name ? '' : name;
             localStorage.setItem('gd-nav-open', this.opened);
         },
         open(name, hasCurrent) { return hasCurrent || this.opened === name; }
     }">
    <ul role="list" class="flex flex-1 flex-col gap-y-4">
        @foreach($sections as $heading => $links)
            @php($hasCurrent = collect($links)->contains(fn ($l) => request()->routeIs($l[0])))
            <li>
                <button type="button" x-on:click="toggle('{{ $heading }}')"
                        class="flex w-full items-center gap-x-1.5 rounded px-2 py-1 text-xs font-semibold uppercase tracking-wider text-gray-600 hover:text-gray-400"
                        x-bind:title="collapsed ? '{{ $heading }}' : null">
                    <svg class="h-3 w-3 shrink-0 transition-transform"
                         x-bind:class="open('{{ $heading }}', {{ $hasCurrent ? 'true' : 'false' }}) ? 'rotate-90' : ''"
                         fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                    <span x-show="!collapsed">{{ $heading }}</span>
                </button>

                <ul role="list" class="-mx-2 mt-1 space-y-0.5"
                    x-show="open('{{ $heading }}', {{ $hasCurrent ? 'true' : 'false' }})" x-cloak>
                    @foreach($links as [$route, $label, $icon])
                        @php($active = request()->routeIs($route))
                        <li>
                            <a href="{{ route($route) }}"
                               @class([
                                   'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6',
                                   'bg-gray-800 text-yellow-400' => $active,
                                   'text-gray-400 hover:bg-gray-800 hover:text-white' => ! $active,
                               ])>
                                <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" />
                                </svg>
                                <span x-show="!collapsed">{{ $label }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </li>
        @endforeach

        {{-- Admin Panel. Shown to admins only; the panel is gated server-side too, so this
             just avoids offering a link that answers 403. A Blade comment rather than an
             HTML one, or the label leaks into the markup for everybody. --}}
        @if(auth()->user()?->is_admin)
            <li>
                <div class="px-2 text-xs font-semibold uppercase tracking-wider text-gray-600" x-show="!collapsed">Admin</div>

                <ul role="list" class="-mx-2 mt-1 space-y-0.5">
                    <li>
                        <a href="/admin"
                           class="group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 text-gray-400 hover:bg-gray-800 hover:text-white">
                            <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                            </svg>
                            <span x-show="!collapsed">Admin Panel</span>
                        </a>
                    </li>
                </ul>
            </li>
        @endif

        {{-- Status at the foot on small screens, where the header's copy of it is hidden
             for want of room. --}}
        <li class="mt-auto sm:hidden">
            <div class="rounded-md bg-gray-800/50 p-3">
                <livewire:bot-status-indicator />
            </div>
        </li>
    </ul>
</nav>

{{--
    Sidebar Content Partial

    Shared between the desktop and mobile sidebars.

    ## Six destinations, no groups

    The menu is organised around what a subscriber does - read signals, choose providers,
    watch trades, connect a terminal and set risk, manage the account - rather than around
    how the system is built. Sixteen pages in five collapsible groups became six items,
    and the pages inside each destination are tabs on that destination, so the menu never
    has to explain the difference between "Setup", "Terminal" and "Broker Accounts".

    ## Why the links are data rather than markup

    One loop means a new destination is one line, and the highlight rule exists once. Each
    entry names the route it links to and the route patterns that count as "you are here",
    so a tab three levels into Auto-Trade still lights up Auto-Trade.

    ## Operator tools

    The strategy editor and the improver tune the proprietary signal system. A subscriber
    chooses instruments and risk; they do not edit EMA periods. Those pages sit under Admin
    with the support console, shown only to administrators and gated server-side too.
--}}

@php
    $destinations = [
        ['dashboard', 'Home', ['dashboard'], 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25'],
        ['signals', 'Signals', ['signals', 'signals.copier'], 'M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941'],
        ['signals.channels', 'Providers', ['signals.channels', 'signals.accounts'], 'M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5'],
        ['trades.live', 'Trades', ['trades.*', 'analytics'], 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z'],
        ['setup', 'Auto-Trade', ['setup', 'terminal', 'terminal.download', 'broker-accounts', 'settings'], 'M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z'],
        ['profile', 'Settings', ['profile', 'logs'], 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 0 1 0 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 0 1 0-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281Z'],
    ];

    $operator = [
        ['strategies', 'Strategies', ['strategies'], 'M4.5 12a7.5 7.5 0 0 0 15 0m-15 0a7.5 7.5 0 1 1 15 0m-15 0H3m16.5 0H21m-1.5 0H12m-8.457 3.077 1.41-.513m14.095-5.13 1.41-.513M5.106 17.785l1.15-.964m11.49-9.642 1.149-.964M7.501 19.795l.75-1.3m7.5-12.99.75-1.3m-6.063 16.658.26-1.477m2.605-14.772.26-1.477m0 17.726-.26-1.477M10.698 4.614l-.26-1.477M16.5 19.794l-.75-1.299M7.5 4.205 12 12m6.894 5.785-1.149-.964M6.256 7.178l-1.15-.964m15.352 8.864-1.41-.513M4.954 9.435l-1.41-.514M12.002 12l-3.4 5.889'],
        ['strategies.improve', 'Improve', ['strategies.improve'], 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z'],
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

<nav class="flex flex-1 flex-col">
    <ul role="list" class="flex flex-1 flex-col gap-y-6">
        <li>
            <ul role="list" class="-mx-2 space-y-1">
                @foreach($destinations as [$route, $label, $patterns, $icon])
                    @php($active = request()->routeIs(...$patterns))
                    <li>
                        <a href="{{ route($route) }}"
                           x-bind:title="collapsed ? '{{ $label }}' : null"
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

        {{-- Operator tools. Shown to admins only; every page here is gated server-side
             too, so this just avoids offering a link that answers 403. A Blade comment
             rather than an HTML one, or the label leaks into the markup for everybody. --}}
        @if(auth()->user()?->is_admin)
            <li>
                <div class="px-2 text-xs font-semibold uppercase tracking-wider text-gray-600" x-show="!collapsed">Admin</div>

                <ul role="list" class="-mx-2 mt-1 space-y-1">
                    @foreach($operator as [$route, $label, $patterns, $icon])
                        @php($active = request()->routeIs(...$patterns))
                        <li>
                            <a href="{{ route($route) }}"
                               x-bind:title="collapsed ? '{{ $label }}' : null"
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
                    <li>
                        <a href="/admin"
                           x-bind:title="collapsed ? 'Admin Panel' : null"
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

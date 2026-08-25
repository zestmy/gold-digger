<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'FXSignalPro') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-900">
            <!-- Mobile sidebar backdrop -->
            <div x-data="{
                 sidebarOpen: false,
                 /* Minimised is a per-browser preference and has to survive a full page
                    load without a round trip, so it lives in localStorage rather than on
                    the server. Desktop only - on a phone the sidebar is a drawer and
                    there is nothing to reclaim. */
                 collapsed: localStorage.getItem('gd-nav-collapsed') === '1',
                 toggleCollapsed() {
                     this.collapsed = ! this.collapsed;
                     localStorage.setItem('gd-nav-collapsed', this.collapsed ? '1' : '0');
                 }
             }"
             x-bind:data-nav="collapsed ? 'min' : 'full'">
                <!-- Off-canvas menu for mobile -->
                <div x-show="sidebarOpen" class="relative z-50 lg:hidden" role="dialog" aria-modal="true">
                    <div x-show="sidebarOpen"
                         x-transition:enter="transition-opacity ease-linear duration-300"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="transition-opacity ease-linear duration-300"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         class="fixed inset-0 bg-gray-900/80"
                         @click="sidebarOpen = false"></div>

                    <div class="fixed inset-0 flex">
                        <div x-show="sidebarOpen"
                             x-transition:enter="transition ease-in-out duration-300 transform"
                             x-transition:enter-start="-translate-x-full"
                             x-transition:enter-end="translate-x-0"
                             x-transition:leave="transition ease-in-out duration-300 transform"
                             x-transition:leave-start="translate-x-0"
                             x-transition:leave-end="-translate-x-full"
                             class="relative mr-16 flex w-full max-w-xs flex-1">
                            <div class="absolute left-full top-0 flex w-16 justify-center pt-5">
                                <button type="button" class="-m-2.5 p-2.5" @click="sidebarOpen = false">
                                    <span class="sr-only">Close sidebar</span>
                                    <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            {{-- The drawer shadows `collapsed` to false: minimising is a
                                 desktop preference about reclaiming width, and on a phone
                                 the drawer covers the screen anyway - inheriting it would
                                 hide every label for no gain. --}}
                            <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-gray-900 px-6 pb-4 ring-1 ring-white/10"
                                 x-data="{ collapsed: false }">
                                @include('layouts.partials.sidebar-content')
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Static sidebar for desktop -->
                {{-- Width comes from a data attribute and CSS rather than x-bind:class.
                     Binding a class here meant Alpine owned the class attribute of an
                     element whose visibility depends on a static `hidden`, and a Livewire
                     DOM morph could reapply the bound value alone - leaving the desktop
                     sidebar visible on a phone, underneath the drawer, as a second logo. --}}
                <div class="gd-sidebar hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-72 lg:flex-col">
                    <div class="relative flex grow flex-col gap-y-5 overflow-y-auto overflow-x-hidden border-r border-gray-800 bg-gray-900 px-6 pb-4">
                        @include('layouts.partials.sidebar-content')

                        {{-- At the foot rather than beside the logo: it is used rarely, and
                             beside the logo it competes with the one control on the page
                             somebody actually clicks. --}}
                        <button type="button" x-on:click="toggleCollapsed()"
                                class="mt-auto flex items-center gap-x-2 rounded-md p-2 text-xs text-gray-600 hover:bg-gray-800 hover:text-gray-300"
                                x-bind:title="collapsed ? 'Expand menu' : 'Minimise menu'">
                            <svg class="h-5 w-5 shrink-0 transition-transform" x-bind:class="collapsed ? 'rotate-180' : ''"
                                 fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5" />
                            </svg>
                            <span x-show="!collapsed">Minimise</span>
                        </button>
                    </div>
                </div>

                <!-- Main content area -->
                <div class="gd-main lg:pl-72">
                    <!-- Top bar with mobile menu button -->
                    <div class="sticky top-0 z-40 flex h-16 shrink-0 items-center gap-x-4 border-b border-gray-800 bg-gray-900 px-4 shadow-sm sm:gap-x-6 sm:px-6 lg:px-8">
                        <button type="button" class="-m-2.5 p-2.5 text-gray-400 lg:hidden" @click="sidebarOpen = true">
                            <span class="sr-only">Open sidebar</span>
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                            </svg>
                        </button>

                        <!-- Separator -->
                        <div class="h-6 w-px bg-gray-800 lg:hidden" aria-hidden="true"></div>

                        <div class="flex flex-1 gap-x-4 self-stretch lg:gap-x-6">
                            <!-- Page title area -->
                            <div class="flex flex-1 items-center">
                                @if (isset($header))
                                    <h1 class="text-lg font-semibold text-white">{{ $header }}</h1>
                                @endif
                            </div>

                            <!-- User dropdown -->
                            <div class="flex items-center gap-x-4 lg:gap-x-6">
                                <div class="hidden sm:block">
                                    <livewire:bot-status-indicator />
                                </div>

                                <div class="relative" x-data="{ open: false }">
                                    <button type="button"
                                            class="flex items-center gap-x-2 text-sm font-medium text-gray-300 hover:text-white"
                                            @click="open = !open">
                                        <span>{{ Auth::user()->name }}</span>
                                        <svg class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                        </svg>
                                    </button>

                                    <div x-show="open"
                                         @click.away="open = false"
                                         x-transition
                                         class="absolute right-0 z-10 mt-2 w-48 origin-top-right rounded-md bg-gray-800 py-1 shadow-lg ring-1 ring-black ring-opacity-5">
                                        <a href="{{ route('profile') }}" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700">Profile</a>
                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-300 hover:bg-gray-700">
                                                Log Out
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Session strip, under the title bar and above everything else.
                         Session is the one piece of context that changes the meaning of the
                         rest of the screen: a signal declined at 03:00 UTC and the same
                         signal declined at 09:00 are different events, and a page cannot
                         say which without it. --}}
                    <div class="sticky top-16 z-30 border-b border-gray-800 bg-gray-900/95 px-4 py-2 backdrop-blur sm:px-6 lg:px-8">
                        <livewire:session-bar />
                    </div>

                    <!-- Page content -->
                    <main class="py-6">
                        <div class="px-4 sm:px-6 lg:px-8">
                            {{ $slot }}
                        </div>
                    </main>
                </div>
            </div>
        </div>
    </body>
</html>

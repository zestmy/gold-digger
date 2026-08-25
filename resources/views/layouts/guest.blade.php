<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'FXSignalPro') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="h-full bg-gray-900 font-sans text-gray-100 antialiased">
        <div class="flex min-h-full">
            {{-- The form column. Narrow and centred on its own half, so on a wide screen the
                 eye lands on the fields rather than on the middle of the page. --}}
            <div class="flex w-full flex-col justify-center px-6 py-12 lg:w-1/2 lg:flex-none lg:px-20 xl:px-24">
                <div class="mx-auto w-full max-w-sm lg:w-96">
                    <a href="/" class="flex items-center gap-x-3" wire:navigate>
                        <svg class="h-9 w-9" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M4 20L8 14H24L28 20V26H4V20Z" fill="#FFD700" stroke="#DAA520" stroke-width="1"/>
                            <path d="M8 14L12 8H20L24 14H8Z" fill="#FFD700" stroke="#DAA520" stroke-width="1"/>
                            <path d="M16 6L24 14" stroke="#9CA3AF" stroke-width="2" stroke-linecap="round"/>
                            <path d="M22 12L28 6M24 14L30 8" stroke="#6B7280" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <span class="text-2xl font-bold text-white">FX<span class="text-yellow-400">SignalPro</span></span>
                    </a>

                    <div class="mt-10">
                        {{ $slot }}
                    </div>

                    {{-- Said on the way in, because it is the thing somebody signing in to a
                         trading dashboard most wants to know about it. --}}
                    <p class="mt-10 border-t border-gray-800 pt-6 text-xs text-gray-600">
                        Orders are placed by an Expert Advisor running in your own terminal. No broker
                        password is stored here.
                    </p>
                </div>
            </div>

            {{-- Decorative, and hidden below lg rather than shrunk: on a phone it would push
                 the form off the first screen to say nothing. --}}
            <div class="relative hidden w-0 flex-1 lg:block">
                <div class="absolute inset-0 bg-gradient-to-br from-gray-900 via-gray-900 to-gray-950"></div>

                <div class="absolute inset-0 flex items-center justify-center p-16">
                    <div class="max-w-md">
                        <h2 class="text-3xl font-semibold leading-tight text-white">
                            Signals in.<br>
                            <span class="text-yellow-400">Checks first.</span>
                        </h2>

                        <p class="mt-4 text-sm leading-relaxed text-gray-400">
                            Telegram signals read, reviewed against your own market data, and sized from a
                            fund you cap &mdash; with per-channel results, so you can see which providers
                            are actually worth following.
                        </p>

                        <ul class="mt-8 space-y-3 text-sm text-gray-400">
                            @foreach([
                                'Every signal re-checked at execution, not at approval',
                                'Positions sized from a capped fund, never the balance',
                                'An order too large to fit is refused, not rounded up',
                            ] as $point)
                                <li class="flex items-start gap-2">
                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                    {{ $point }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>

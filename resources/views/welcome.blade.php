<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FXSignalPro - AI trading signals for Gold and the majors</title>
    <meta name="description" content="AI trading signals for Gold, EURUSD, GBPUSD, USDJPY and GBPJPY, each with its entry zone, stop, three targets and a computed confidence score. Follow by hand or auto-trade on your own MetaTrader 5.">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    // Registration is off by default; see routes/auth.php. The route is the single source
    // of truth, so with it absent every "Start free" on the page becomes "Log in" rather
    // than pointing at a door that answers 404.
    $canRegister = Route::has('register');
    $ctaLabel = $canRegister ? 'Start free' : 'Log in';
    $ctaHref = $canRegister ? route('register') : route('login');
@endphp
<body class="font-sans antialiased bg-gray-900 text-gray-300">
    <!-- Navigation -->
    <nav class="fixed inset-x-0 top-0 z-50 border-b border-gray-800 bg-gray-900/80 backdrop-blur-lg">
        <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
            <a href="/" class="text-xl font-bold tracking-tight text-white">
                FX<span class="text-yellow-400">Signal</span>Pro
            </a>

            <div class="flex items-center gap-x-5">
                @auth
                    <a href="{{ route('dashboard') }}" class="text-sm font-medium text-gray-300 transition-colors hover:text-white">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="text-sm font-medium text-gray-300 transition-colors hover:text-white">Log in</a>
                    @if($canRegister)
                        <a href="{{ route('register') }}" class="rounded-md bg-yellow-500 px-4 py-2 text-sm font-semibold text-gray-900 transition-colors hover:bg-yellow-400">
                            Start free
                        </a>
                    @endif
                @endauth
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="relative overflow-hidden pt-16">
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-yellow-500/5 via-transparent to-transparent"></div>
        <div class="pointer-events-none absolute -top-24 right-0 h-96 w-96 rounded-full bg-yellow-500/10 blur-3xl"></div>

        <div class="relative mx-auto grid max-w-7xl gap-12 px-4 py-20 sm:px-6 lg:grid-cols-2 lg:items-center lg:px-8 lg:py-28">
            <div>
                <p class="font-mono text-xs font-medium uppercase tracking-[0.2em] text-yellow-400">
                    Gold &middot; EURUSD &middot; GBPUSD &middot; USDJPY &middot; GBPJPY
                </p>

                <h1 class="mt-5 text-4xl font-bold leading-tight tracking-tight text-white sm:text-5xl lg:text-6xl">
                    AI trading signals, with the reasoning shown and the entry told plainly
                </h1>

                <p class="mt-6 max-w-xl text-lg leading-relaxed text-gray-400">
                    Every signal carries its entry zone, stop, three targets and a confidence score
                    computed from what actually agreed. Follow it by hand, or let FXSignal Pro place
                    it on your own MetaTrader 5 with the risk you set.
                </p>

                <div class="mt-8 flex flex-col items-start gap-4 sm:flex-row sm:items-center">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-md bg-yellow-500 px-6 py-3 text-base font-semibold text-gray-900 shadow-lg shadow-yellow-500/20 transition-colors hover:bg-yellow-400">
                            Go to Dashboard
                        </a>
                    @else
                        <a href="{{ $ctaHref }}" class="rounded-md bg-yellow-500 px-6 py-3 text-base font-semibold text-gray-900 shadow-lg shadow-yellow-500/20 transition-colors hover:bg-yellow-400">
                            {{ $ctaLabel }}
                        </a>
                        <p class="text-sm text-gray-500">No card. Delayed signals on the free plan.</p>
                    @endauth
                </div>
            </div>

            {{-- A signal card, as the app draws one. Static markup: the numbers are an
                 example, not a live signal, and the page must say nothing it cannot show. --}}
            <div class="lg:justify-self-end">
                <div class="w-full max-w-md rounded-xl border border-gray-700 bg-gray-800 p-6 shadow-2xl shadow-black/40" aria-label="Example signal card">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Latest signal</p>
                            <p class="mt-1 text-2xl font-semibold text-white">
                                XAUUSD
                                <span class="ml-1 rounded bg-red-400/10 px-2 py-0.5 align-middle text-sm font-semibold text-red-400">SELL</span>
                            </p>
                            <p class="mt-1 text-xs text-gray-500">M5 &middot; AI &middot; 13:05 UTC</p>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-semibold tabular-nums text-green-400">92%</p>
                            <p class="text-xs text-gray-500">confidence &middot; A</p>
                        </div>
                    </div>

                    <div class="mt-5 rounded-md border border-yellow-500/20 bg-yellow-500/5 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-yellow-400">Set limit order</p>
                        <p class="mt-1 text-sm text-gray-300">
                            Price has moved past the entry zone. Set a SELL limit at
                            <span class="tabular-nums text-white">4,579.82 &ndash; 4,585.82</span> and let price come back to it.
                        </p>
                    </div>

                    <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-3 text-sm tabular-nums">
                        <div>
                            <dt class="text-xs text-gray-500">Entry zone</dt>
                            <dd class="mt-0.5 text-white">4,579.82 &ndash; 4,585.82</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">Stop</dt>
                            <dd class="mt-0.5 text-red-300">4,591.82</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">Targets</dt>
                            <dd class="mt-0.5 text-green-300">4,570.82 / 4,561.82 / 4,552.82</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">R:R</dt>
                            <dd class="mt-0.5 text-white">1:2.3</dd>
                        </div>
                    </dl>

                    <ul class="mt-5 space-y-1.5 border-t border-gray-700 pt-4 text-xs text-gray-400">
                        <li class="flex gap-x-2"><span class="text-green-400">&#10003;</span> H1 trend down, entry EMA cross confirmed</li>
                        <li class="flex gap-x-2"><span class="text-green-400">&#10003;</span> ADX 31.4, trend present</li>
                        <li class="flex gap-x-2"><span class="text-green-400">&#10003;</span> RSI 42.1 and MACD histogram below zero</li>
                        <li class="flex gap-x-2"><span class="text-gray-500">&ndash;</span> Session: London/New York overlap</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Proof strip -->
    <section class="border-y border-gray-800 bg-gray-900/60">
        <div class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-3 lg:px-8">
            <div class="flex gap-x-4">
                <svg class="mt-0.5 h-6 w-6 shrink-0 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div>
                    <h2 class="font-semibold text-white">Every signal timestamped, every outcome kept</h2>
                    <p class="mt-1 text-sm text-gray-400">The record includes the signals that lost and the ones that were never taken, with the reason.</p>
                </div>
            </div>
            <div class="flex gap-x-4">
                <svg class="mt-0.5 h-6 w-6 shrink-0 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div>
                    <h2 class="font-semibold text-white">Confidence is computed, not claimed</h2>
                    <p class="mt-1 text-sm text-gray-400">The score is the share of factors that agreed at the bar, and the card lists each one that did not.</p>
                </div>
            </div>
            <div class="flex gap-x-4">
                <svg class="mt-0.5 h-6 w-6 shrink-0 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                </svg>
                <div>
                    <h2 class="font-semibold text-white">Your broker, your account, your risk</h2>
                    <p class="mt-1 text-sm text-gray-400">Auto-trade runs on your own MetaTrader 5. Funds never leave your account, and you set the size of every position.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- How it works -->
    <section class="py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl">
                <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">How it works</h2>
                <p class="mt-3 text-gray-400">Three steps, and none of them asks you to trust a number you cannot check.</p>
            </div>

            <div class="mt-12 grid gap-6 md:grid-cols-3">
                <div class="rounded-xl border border-gray-700 bg-gray-800 p-6">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-yellow-500/10 font-mono text-sm font-semibold text-yellow-400">1</span>
                    <h3 class="mt-4 text-lg font-semibold text-white">Signals arrive with their reasoning</h3>
                    <p class="mt-2 text-sm leading-relaxed text-gray-400">
                        On each bar close the strategy reads the trend, momentum and volatility of Gold and the majors. When enough agrees, a signal is written with its zone, stop, targets, and the factors it was scored on.
                    </p>
                </div>
                <div class="rounded-xl border border-gray-700 bg-gray-800 p-6">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-yellow-500/10 font-mono text-sm font-semibold text-yellow-400">2</span>
                    <h3 class="mt-4 text-lg font-semibold text-white">Read the card, or let it trade</h3>
                    <p class="mt-2 text-sm leading-relaxed text-gray-400">
                        The card tells you the order to place against the price now: enter at market, set a limit, or wait. Or connect your MetaTrader 5 and let auto-trade place it, sized to the risk you set and protected as it moves.
                    </p>
                </div>
                <div class="rounded-xl border border-gray-700 bg-gray-800 p-6">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-yellow-500/10 font-mono text-sm font-semibold text-yellow-400">3</span>
                    <h3 class="mt-4 text-lg font-semibold text-white">See what each source is worth</h3>
                    <p class="mt-2 text-sm leading-relaxed text-gray-400">
                        Every outcome is kept against the signal that produced it, for the AI and for every Telegram provider you follow. Win rate, profit factor and drawdown per source, on the same rules for all of them.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section class="border-t border-gray-800 bg-gray-900/60 py-24" id="pricing">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl">
                <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">Pricing</h2>
                <p class="mt-3 text-gray-400">Start on the free plan. Pricing for Pro and Auto is announced at launch.</p>
            </div>

            <div class="mt-12 grid gap-6 lg:grid-cols-3">
                <!-- Free -->
                <div class="flex flex-col rounded-xl border border-gray-700 bg-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-white">Free</h3>
                    <p class="mt-1 text-sm text-gray-500">See what the signals look like before paying for them.</p>
                    <p class="mt-6 text-2xl font-semibold text-white">$0</p>
                    <ul class="mt-6 flex-1 space-y-3 text-sm text-gray-300">
                        <li class="flex gap-x-3"><span class="text-yellow-400">&#10003;</span> Gold signals, 30 minutes delayed</li>
                        <li class="flex gap-x-3"><span class="text-yellow-400">&#10003;</span> Signal history with every outcome</li>
                        <li class="flex gap-x-3"><span class="text-yellow-400">&#10003;</span> 1 provider, recording only</li>
                    </ul>
                    @auth
                        <a href="{{ route('dashboard') }}" class="mt-8 block rounded-md border border-gray-600 px-4 py-2.5 text-center text-sm font-semibold text-white transition-colors hover:border-gray-500 hover:bg-gray-700/60">Go to Dashboard</a>
                    @else
                        <a href="{{ $ctaHref }}" class="mt-8 block rounded-md border border-gray-600 px-4 py-2.5 text-center text-sm font-semibold text-white transition-colors hover:border-gray-500 hover:bg-gray-700/60">{{ $ctaLabel }}</a>
                    @endauth
                </div>

                <!-- Pro -->
                <div class="flex flex-col rounded-xl border border-yellow-500/40 bg-gray-800 p-6 ring-1 ring-yellow-500/20">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-white">Pro</h3>
                        <span class="rounded bg-yellow-500/10 px-2 py-0.5 text-xs font-semibold text-yellow-400">Real-time</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-500">Every signal the moment it fires, on every instrument.</p>
                    <p class="mt-6 text-sm font-medium text-gray-400">Pricing announced at launch</p>
                    <ul class="mt-6 flex-1 space-y-3 text-sm text-gray-300">
                        <li class="flex gap-x-3"><span class="text-yellow-400">&#10003;</span> Real-time AI signals, Gold and the majors</li>
                        <li class="flex gap-x-3"><span class="text-yellow-400">&#10003;</span> Telegram alerts</li>
                        <li class="flex gap-x-3"><span class="text-yellow-400">&#10003;</span> Unlimited providers, each scored</li>
                        <li class="flex gap-x-3"><span class="text-yellow-400">&#10003;</span> Market scan on demand</li>
                    </ul>
                    @auth
                        <a href="{{ route('dashboard') }}" class="mt-8 block rounded-md bg-yellow-500 px-4 py-2.5 text-center text-sm font-semibold text-gray-900 transition-colors hover:bg-yellow-400">Go to Dashboard</a>
                    @else
                        <a href="{{ $ctaHref }}" class="mt-8 block rounded-md bg-yellow-500 px-4 py-2.5 text-center text-sm font-semibold text-gray-900 transition-colors hover:bg-yellow-400">{{ $ctaLabel }}</a>
                    @endauth
                </div>

                <!-- Auto -->
                <div class="flex flex-col rounded-xl border border-gray-700 bg-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-white">Auto</h3>
                    <p class="mt-1 text-sm text-gray-500">Everything in Pro, placed on your own terminal.</p>
                    <p class="mt-6 text-sm font-medium text-gray-400">Pricing announced at launch</p>
                    <ul class="mt-6 flex-1 space-y-3 text-sm text-gray-300">
                        <li class="flex gap-x-3"><span class="text-yellow-400">&#10003;</span> Everything in Pro</li>
                        <li class="flex gap-x-3"><span class="text-yellow-400">&#10003;</span> Auto-trade on your own MT5</li>
                        <li class="flex gap-x-3"><span class="text-yellow-400">&#10003;</span> Copier with review, sizing and protection</li>
                        <li class="flex gap-x-3"><span class="text-yellow-400">&#10003;</span> Capped AI fund</li>
                    </ul>
                    @auth
                        <a href="{{ route('dashboard') }}" class="mt-8 block rounded-md border border-gray-600 px-4 py-2.5 text-center text-sm font-semibold text-white transition-colors hover:border-gray-500 hover:bg-gray-700/60">Go to Dashboard</a>
                    @else
                        <a href="{{ $ctaHref }}" class="mt-8 block rounded-md border border-gray-600 px-4 py-2.5 text-center text-sm font-semibold text-white transition-colors hover:border-gray-500 hover:bg-gray-700/60">{{ $ctaLabel }}</a>
                    @endauth
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="border-t border-gray-800 py-12">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-6 md:flex-row md:items-start md:justify-between">
                <p class="text-lg font-bold tracking-tight text-white">FX<span class="text-yellow-400">Signal</span>Pro</p>
                <p class="max-w-2xl text-sm leading-relaxed text-gray-500">
                    Signals are for trading education only and are not financial advice. Trading leveraged
                    instruments can lose more than the amount deposited.
                </p>
            </div>
            <p class="mt-8 text-xs text-gray-600">&copy; {{ date('Y') }} FXSignalPro.</p>
        </div>
    </footer>
</body>
</html>

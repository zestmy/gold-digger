<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gold Digger - Automated Gold Trading Bot</title>
    <meta name="description" content="Professional automated gold (XAUUSD) scalping bot with advanced risk management and real-time analytics.">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-gray-950">
    <!-- Navigation -->
    <nav class="fixed top-0 left-0 right-0 z-50 bg-gray-950/80 backdrop-blur-lg border-b border-gray-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <div class="flex items-center space-x-3">
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg bg-gradient-to-br from-yellow-400 to-yellow-600">
                        <svg class="w-6 h-6 text-gray-900" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                        </svg>
                    </div>
                    <span class="text-xl font-bold text-white">Gold Digger</span>
                </div>

                <!-- Auth Links -->
                <div class="flex items-center space-x-4">
                    @auth
                        <a href="{{ route('dashboard') }}" class="text-gray-300 hover:text-white transition-colors">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="text-gray-300 hover:text-white transition-colors">Login</a>
                        <a href="{{ route('register') }}" class="px-4 py-2 rounded-lg bg-yellow-500 text-gray-900 font-semibold hover:bg-yellow-400 transition-colors">
                            Get Started
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-16">
        <!-- Background Effects -->
        <div class="absolute inset-0 bg-gradient-to-b from-yellow-500/5 via-transparent to-transparent"></div>
        <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-yellow-500/10 rounded-full blur-3xl"></div>
        <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-yellow-600/10 rounded-full blur-3xl"></div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-24 text-center">
            <!-- Badge -->
            <div class="inline-flex items-center px-4 py-2 rounded-full bg-yellow-500/10 border border-yellow-500/20 text-yellow-400 text-sm font-medium mb-8">
                <span class="w-2 h-2 rounded-full bg-yellow-400 animate-pulse mr-2"></span>
                Automated Trading Bot
            </div>

            <!-- Headline -->
            <h1 class="text-4xl sm:text-6xl lg:text-7xl font-bold text-white mb-6">
                Trade Gold Like a
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-yellow-400 to-yellow-600">Pro</span>
            </h1>

            <p class="text-xl text-gray-400 max-w-2xl mx-auto mb-10">
                Professional automated gold (XAUUSD) scalping bot with advanced risk management,
                multi-timeframe analysis, and real-time performance tracking.
            </p>

            <!-- CTA Buttons -->
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                @auth
                    <a href="{{ route('dashboard') }}" class="px-8 py-4 rounded-lg bg-yellow-500 text-gray-900 font-semibold text-lg hover:bg-yellow-400 transition-colors shadow-lg shadow-yellow-500/25">
                        Go to Dashboard
                    </a>
                @else
                    <a href="{{ route('register') }}" class="px-8 py-4 rounded-lg bg-yellow-500 text-gray-900 font-semibold text-lg hover:bg-yellow-400 transition-colors shadow-lg shadow-yellow-500/25">
                        Start Trading Now
                    </a>
                    <a href="{{ route('login') }}" class="px-8 py-4 rounded-lg bg-gray-800 text-white font-semibold text-lg hover:bg-gray-700 transition-colors border border-gray-700">
                        Sign In
                    </a>
                @endauth
            </div>

            <!-- Stats -->
            <div class="mt-20 grid grid-cols-2 md:grid-cols-4 gap-8">
                <div class="text-center">
                    <p class="text-3xl font-bold text-yellow-400">24/7</p>
                    <p class="text-gray-500 mt-1">Automated Trading</p>
                </div>
                <div class="text-center">
                    <p class="text-3xl font-bold text-yellow-400">3-Level</p>
                    <p class="text-gray-500 mt-1">Take Profit System</p>
                </div>
                <div class="text-center">
                    <p class="text-3xl font-bold text-yellow-400">ATR-Based</p>
                    <p class="text-gray-500 mt-1">Risk Management</p>
                </div>
                <div class="text-center">
                    <p class="text-3xl font-bold text-yellow-400">Real-time</p>
                    <p class="text-gray-500 mt-1">Analytics</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-24 bg-gray-900/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl font-bold text-white mb-4">Powerful Trading Features</h2>
                <p class="text-gray-400 max-w-2xl mx-auto">Everything you need to trade gold professionally with automated precision.</p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="p-6 rounded-2xl bg-gray-800/50 border border-gray-700/50 hover:border-yellow-500/30 transition-colors">
                    <div class="w-12 h-12 rounded-lg bg-yellow-500/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-white mb-2">EMA Crossover Strategy</h3>
                    <p class="text-gray-400">Multi-timeframe trend following with configurable fast/slow EMA periods and ADX filter for high-probability entries.</p>
                </div>

                <!-- Feature 2 -->
                <div class="p-6 rounded-2xl bg-gray-800/50 border border-gray-700/50 hover:border-yellow-500/30 transition-colors">
                    <div class="w-12 h-12 rounded-lg bg-yellow-500/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-white mb-2">Advanced Risk Management</h3>
                    <p class="text-gray-400">ATR-based stop losses, configurable risk percentage per trade, and maximum daily loss limits to protect your capital.</p>
                </div>

                <!-- Feature 3 -->
                <div class="p-6 rounded-2xl bg-gray-800/50 border border-gray-700/50 hover:border-yellow-500/30 transition-colors">
                    <div class="w-12 h-12 rounded-lg bg-yellow-500/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-white mb-2">3-Level Take Profit</h3>
                    <p class="text-gray-400">Lock in profits progressively with TP1, TP2, and TP3 levels. Partial close percentages ensure you never miss a winning trade.</p>
                </div>

                <!-- Feature 4 -->
                <div class="p-6 rounded-2xl bg-gray-800/50 border border-gray-700/50 hover:border-yellow-500/30 transition-colors">
                    <div class="w-12 h-12 rounded-lg bg-yellow-500/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-white mb-2">Session Filters</h3>
                    <p class="text-gray-400">Trade only during optimal market sessions - Asian, London, New York, or the high-volatility overlap periods.</p>
                </div>

                <!-- Feature 5 -->
                <div class="p-6 rounded-2xl bg-gray-800/50 border border-gray-700/50 hover:border-yellow-500/30 transition-colors">
                    <div class="w-12 h-12 rounded-lg bg-yellow-500/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-white mb-2">Real-time Analytics</h3>
                    <p class="text-gray-400">Track your performance with detailed P&L breakdown, win rate statistics, and daily equity charts.</p>
                </div>

                <!-- Feature 6 -->
                <div class="p-6 rounded-2xl bg-gray-800/50 border border-gray-700/50 hover:border-yellow-500/30 transition-colors">
                    <div class="w-12 h-12 rounded-lg bg-yellow-500/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-white mb-2">Trade Screenshots</h3>
                    <p class="text-gray-400">Automatically capture chart screenshots at entry and exit for post-trade analysis and strategy refinement.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-24">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl sm:text-4xl font-bold text-white mb-6">Ready to Start Trading?</h2>
            <p class="text-xl text-gray-400 mb-10">
                Join Gold Digger today and take control of your gold trading with automated precision.
            </p>
            @guest
                <a href="{{ route('register') }}" class="inline-flex items-center px-8 py-4 rounded-lg bg-yellow-500 text-gray-900 font-semibold text-lg hover:bg-yellow-400 transition-colors shadow-lg shadow-yellow-500/25">
                    Create Free Account
                    <svg class="ml-2 w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                    </svg>
                </a>
            @endguest
        </div>
    </section>

    <!-- Footer -->
    <footer class="border-t border-gray-800 py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row items-center justify-between">
                <div class="flex items-center space-x-3 mb-4 md:mb-0">
                    <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gradient-to-br from-yellow-400 to-yellow-600">
                        <svg class="w-4 h-4 text-gray-900" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                        </svg>
                    </div>
                    <span class="text-lg font-semibold text-white">Gold Digger</span>
                </div>
                <p class="text-gray-500 text-sm">
                    &copy; {{ date('Y') }} Gold Digger. Personal trading bot dashboard.
                </p>
            </div>
        </div>
    </footer>
</body>
</html>

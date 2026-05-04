<div>
    <x-slot name="header">
        {{ $pageTitle }}
    </x-slot>

    <div class="rounded-lg bg-gray-800 p-8">
        <div class="mx-auto max-w-md text-center">
            <!-- Icon -->
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gray-700">
                <svg class="h-8 w-8 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" />
                </svg>
            </div>

            <!-- Title -->
            <h2 class="mt-6 text-xl font-semibold text-white">{{ $pageTitle }}</h2>

            <!-- Description -->
            <p class="mt-2 text-sm text-gray-400">{{ $pageDescription }}</p>

            <!-- Coming Soon Badge -->
            <div class="mt-6">
                <span class="inline-flex items-center rounded-full bg-yellow-400/10 px-4 py-2 text-sm font-medium text-yellow-400 ring-1 ring-inset ring-yellow-400/20">
                    <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Coming in {{ $comingPhase }}
                </span>
            </div>

            <!-- Additional Info -->
            <div class="mt-8 rounded-lg bg-gray-900 p-4">
                <p class="text-xs text-gray-500">
                    This feature is currently under development. Check the
                    <a href="/admin" class="text-yellow-400 hover:text-yellow-300">Admin Panel</a>
                    for raw data access.
                </p>
            </div>
        </div>
    </div>
</div>

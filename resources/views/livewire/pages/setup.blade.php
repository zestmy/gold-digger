<div>
    <x-slot name="header">
        Setup
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-8">
        <!-- Progress -->
        <div>
            <ol class="flex items-start">
                @foreach($steps as $index => $step)
                    <li class="flex flex-1 flex-col items-center {{ $loop->last ? '' : 'relative' }}">
                        {{-- The connector sits behind the markers rather than between them, so
                             the row stays aligned when a label wraps to two lines. --}}
                        @unless($loop->last)
                            <span aria-hidden="true"
                                  class="absolute left-1/2 top-4 -z-10 h-0.5 w-full {{ $step['done'] ? 'bg-yellow-500' : 'bg-gray-700' }}"></span>
                        @endunless

                        <button type="button" wire:click="$set('step', {{ $index }})"
                                class="flex h-8 w-8 items-center justify-center rounded-full border-2 text-xs font-semibold transition
                                    {{ $step['done']
                                        ? 'border-yellow-500 bg-yellow-500 text-gray-900'
                                        : ($current === $index
                                            ? 'border-yellow-500 bg-gray-900 text-yellow-500'
                                            : 'border-gray-700 bg-gray-900 text-gray-500') }}">
                            @if($step['done'])
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                            @else
                                {{ $index + 1 }}
                            @endif
                        </button>

                        <span class="mt-2 px-1 text-center text-xs {{ $current === $index ? 'text-yellow-500' : 'text-gray-500' }}">
                            {{ $step['title'] }}
                        </span>
                    </li>
                @endforeach
            </ol>
        </div>

        @if($ready)
            <div class="rounded-lg border border-green-500/30 bg-green-900/20 p-6 text-center">
                <h3 class="text-base font-medium text-green-400">Everything is connected.</h3>
                <p class="mt-2 text-sm text-gray-300">
                    Signals are captured, reviewed and executed without anyone present. Every order is
                    announced on Telegram as it happens.
                </p>
                <a href="{{ route('signals.channels') }}" class="mt-4 inline-block text-sm text-yellow-500 hover:text-yellow-400">
                    Watch what each channel is worth &rarr;
                </a>
            </div>
        @endif

        <!-- The step in hand -->
        @php($active = $steps[$current] ?? null)

        @if($active)
            <div class="rounded-lg bg-gray-800 p-8">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h2 class="text-xl font-semibold text-gray-100">{{ $active['title'] }}</h2>

                    <span class="rounded px-2 py-1 text-xs font-medium {{ $active['done'] ? 'bg-green-400/10 text-green-400' : 'bg-gray-700 text-gray-400' }}">
                        {{ $active['done'] ? 'Done' : 'Not yet' }}
                    </span>
                </div>

                <p class="mt-1 text-sm text-gray-400">{{ $active['detail'] }}</p>

                <p class="mt-4 text-sm leading-relaxed text-gray-300">{{ $active['blurb'] }}</p>

                <a href="{{ route($active['route']) }}"
                   class="mt-6 inline-block rounded-md bg-yellow-500 px-5 py-2.5 text-sm font-medium text-gray-900 hover:bg-yellow-400">
                    {{ $active['action'] }}
                </a>
            </div>
        @endif

        <!-- Everything, at a glance -->
        <div class="rounded-lg bg-gray-800/50 p-6">
            <h3 class="text-sm font-medium text-gray-400">All four</h3>

            <ul class="mt-3 divide-y divide-gray-700 border-t border-gray-700">
                @foreach($steps as $index => $step)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div class="min-w-0">
                            <button type="button" wire:click="$set('step', {{ $index }})"
                                    class="text-sm {{ $step['done'] ? 'text-gray-300' : 'text-gray-100' }} hover:text-yellow-500">
                                {{ $step['title'] }}
                            </button>
                            <p class="text-xs text-gray-500">{{ $step['detail'] }}</p>
                        </div>

                        @if($step['done'])
                            <span class="shrink-0 text-xs text-green-400">ready</span>
                        @else
                            <a href="{{ route($step['route']) }}" class="shrink-0 text-xs text-yellow-500 hover:text-yellow-400">
                                {{ $step['action'] }} &rarr;
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{-- Said here because it is the thing people compare on, and the comparison is
                 not in our favour on convenience. --}}
            <p class="mt-4 border-t border-gray-700 pt-4 text-xs text-gray-500">
                Hosted copiers skip the terminal step by asking for your broker password and logging into
                your account from their cloud. That is genuinely less work. It also means a company holds a
                credential that can trade your account, which is the trade this makes differently.
            </p>
        </div>
    </div>
</div>

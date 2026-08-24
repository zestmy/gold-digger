{{-- Deliberately no wire:poll. Every generation is a paid API call, so this card
     refreshes when asked and caches against the newest bar. --}}
<div class="rounded-lg bg-gray-800 p-6">
    <div class="flex items-baseline justify-between">
        <h3 class="text-sm font-medium text-gray-400">Analysis</h3>
        @if($generatedAt)
            <span class="text-xs text-gray-500">{{ $generatedAt }}</span>
        @endif
    </div>

    @if(! $configured)
        <p class="mt-4 text-sm text-gray-500">
            Not configured. Set <code class="text-gray-400">ANTHROPIC_API_KEY</code> to enable written analysis.
        </p>
        <p class="mt-1 text-xs text-gray-600">
            Every other card on this page works without it &mdash; this one only puts the same
            numbers into sentences.
        </p>

    @else
        @if($headline)
            <p class="mt-3 text-base font-medium text-gray-100">{{ $headline }}</p>
        @endif

        @if($reading)
            {{-- The checkable half. Everything here should be verifiable against the
                 trend, session and news cards on this same screen. --}}
            <div class="mt-4">
                <p class="text-xs uppercase tracking-wide text-gray-500">What the data says</p>
                <p class="mt-1 text-sm leading-relaxed text-gray-300">{{ $reading }}</p>
            </div>
        @endif

        @if($outlook)
            {{-- The half that is guessing. Visually separated and labelled, because a
                 fluent paragraph about gold reads as authority regardless of whether
                 anything supports it, and this card sits one column from a Start button. --}}
            <div class="mt-4 rounded-md border border-amber-500/20 bg-amber-900/10 p-3">
                <p class="flex items-center gap-x-1.5 text-xs uppercase tracking-wide text-amber-400/80">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    Speculation
                </p>
                <p class="mt-1 text-sm leading-relaxed text-amber-100/70">{{ $outlook }}</p>
                <p class="mt-2 text-xs text-amber-200/40">
                    Opinion, not a signal. Nothing here reaches the strategy or moves a position.
                </p>
            </div>
        @endif

        @if($error)
            <p class="mt-4 text-sm text-gray-400">{{ $error }}</p>
        @endif

        @unless($headline || $error)
            <p class="mt-4 text-sm text-gray-500">No analysis yet for the current bar.</p>
        @endunless

        <div class="mt-4 flex items-center gap-x-3 border-t border-gray-700 pt-3">
            <button
                type="button"
                wire:click="{{ $headline ? 'refresh' : 'analyse' }}"
                wire:loading.attr="disabled"
                class="rounded-md bg-gray-700 px-3 py-1.5 text-xs font-medium text-gray-200 hover:bg-gray-600 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="analyse,refresh">{{ $headline ? 'Regenerate' : 'Analyse' }}</span>
                <span wire:loading wire:target="analyse,refresh">Thinking&hellip;</span>
            </button>
            <span class="text-xs text-gray-600">Cached per bar &mdash; regenerating costs an API call.</span>
        </div>
    @endif
</div>

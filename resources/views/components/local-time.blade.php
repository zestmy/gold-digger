@if($moment === null)
    <span class="text-gray-600">{{ $empty }}</span>
@else
    {{-- The UTC stays reachable on hover: support conversations about a trading system
         are conducted in UTC, and a dashboard that can only speak local makes them
         harder rather than easier. --}}
    <time datetime="{{ $moment->toIso8601String() }}"
          title="{{ $moment->copy()->utc()->format('Y-m-d H:i:s') }} UTC">{{ $relative ? $moment->diffForHumans() : $moment->copy()->setTimezone($zone)->format($format) }}</time>
@endif

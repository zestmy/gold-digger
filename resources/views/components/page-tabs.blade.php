{{-- The tab strip inside a destination. See App\View\Components\PageTabs. --}}
<nav class="mb-6 -mt-1 flex w-fit max-w-full gap-1 overflow-x-auto rounded-lg bg-gray-800 p-1" aria-label="Sections">
    @foreach($tabs as [$route, $label])
        @php($active = request()->routeIs($route))
        <a href="{{ route($route) }}"
           @class([
               'whitespace-nowrap rounded-md px-3.5 py-1.5 text-sm font-semibold transition-colors',
               'bg-gray-700 text-white' => $active,
               'text-gray-400 hover:text-white' => ! $active,
           ])
           @if($active) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

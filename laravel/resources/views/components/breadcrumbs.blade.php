@props(['items' => []])

<nav {{ $attributes->merge(['class' => 'flex items-center gap-2 overflow-x-auto text-sm text-slate-500']) }} aria-label="Breadcrumb">
    @foreach ($items as $label => $url)
        @if (!$loop->first)<span aria-hidden="true">/</span>@endif
        @if ($url)<a href="{{ $url }}" class="whitespace-nowrap font-medium hover:text-teal-700">{{ $label }}</a>@else<span class="whitespace-nowrap text-slate-700">{{ $label }}</span>@endif
    @endforeach
</nav>

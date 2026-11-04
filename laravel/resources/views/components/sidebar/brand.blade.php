@props([
    'href' => null,
    'subtitle' => null,
])

@php
    $cooperative = null;
    if (\Illuminate\Support\Facades\Schema::hasTable('koperasi')) {
        try {
            $cooperative = \Illuminate\Support\Facades\DB::table('koperasi')->orderBy('id')->first(['nama', 'logo']);
        } catch (\Throwable) {
            $cooperative = null;
        }
    }
    $brandName = $cooperative?->nama ?: 'SIMKEU';
    $logoUrl = filled($cooperative?->logo)
        ? route('brand.logo', ['v' => basename((string) $cooperative->logo)])
        : null;
@endphp

<a
    href="{{ $href ?? '#' }}"
    title="{{ $brandName }}{{ filled($subtitle) ? ' · '.$subtitle : '' }}"
    {{ $attributes->class(['sidebar-brand-link']) }}
>
    <span class="sidebar-brand-mark">
        @if ($logoUrl)
            <img src="{{ $logoUrl }}" alt="{{ $brandName }}" class="sidebar-brand-logo" />
        @else
            <span class="sidebar-brand-fallback" aria-hidden="true">{{ mb_strtoupper(mb_substr($brandName, 0, 1)) }}</span>
        @endif
    </span>
    <span class="sidebar-brand-text sidebar-hide-collapsed">
        <span class="sidebar-brand-name">{{ $brandName }}</span>
        @if (filled($subtitle))
            <span class="sidebar-brand-sub">{{ $subtitle }}</span>
        @endif
    </span>
</a>

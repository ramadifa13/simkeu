@props([
    'href' => null,
    'subtitle' => null,
    'label' => null,
    'onDark' => true,
    'size' => 'md',
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
    $brandName = $label ?? ($cooperative?->nama ?: 'SIMKEU');
    $logoUrl = filled($cooperative?->logo)
        ? route('brand.logo', ['v' => basename((string) $cooperative->logo)])
        : null;
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    {!! $href ? 'href="'.e($href).'"' : '' !!}
    {{ $attributes->class([
        'ui-brand group inline-flex min-w-0 items-center gap-3 rounded-2xl',
        'ui-brand-'.$size,
        'text-white' => $onDark,
        'text-slate-900' => ! $onDark,
    ]) }}
>
    @if ($logoUrl)
        <span class="ui-brand-lockup flex min-w-0 flex-col items-start gap-1.5">
            <span class="ui-brand-shell">
                <img src="{{ $logoUrl }}" alt="{{ $brandName }}" class="ui-brand-logo" />
            </span>
            @if (filled($subtitle))
                <small class="block truncate text-[0.7rem] font-medium leading-none {{ $onDark ? 'text-teal-100/85' : 'text-slate-500' }}">{{ $subtitle }}</small>
            @endif
        </span>
    @else
        <span class="ui-brand-mark grid shrink-0 place-items-center rounded-xl font-black">S</span>
        <span class="ui-brand-copy min-w-0 font-semibold tracking-tight">
            <span class="block truncate">{{ $brandName }}</span>
            @if (filled($subtitle))
                <small class="mt-0.5 block truncate text-xs font-medium {{ $onDark ? 'text-teal-100/85' : 'text-slate-500' }}">{{ $subtitle }}</small>
            @endif
        </span>
    @endif
</{{ $tag }}>

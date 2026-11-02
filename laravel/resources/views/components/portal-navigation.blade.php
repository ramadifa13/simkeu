@props(['role', 'mobile' => false])

@php($items = config("navigation.{$role}", []))

<nav {{ $attributes->merge(['class' => 'grid gap-1 text-sm']) }} aria-label="Navigasi portal{{ $mobile ? ' mobile' : '' }}">
    @foreach ($items as $item)
        @if (isset($item['items']))
            <p class="px-3 pt-5 text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $item['label'] }}</p>
            @foreach ($item['items'] as $child)
                @php($href = isset($child['route']) ? route($child['route'], ['role' => $role]) : route('portal.modules.placeholder', ['role' => $role, 'module' => $child['module']]))
                <a href="{{ $href }}" @class(['rounded-xl px-3 py-2.5 font-medium transition hover:bg-white/10', 'text-slate-900 hover:bg-slate-100' => $mobile, 'text-slate-300 hover:text-white' => ! $mobile])>{{ $child['label'] }}</a>
            @endforeach
        @else
            @php($href = isset($item['route']) ? route($item['route'], ['role' => $role]) : route('portal.modules.placeholder', ['role' => $role, 'module' => $item['module']]))
            <a href="{{ $href }}" @class(['rounded-xl px-3 py-2.5 font-semibold transition hover:bg-white/10', 'text-slate-900 hover:bg-slate-100' => $mobile, 'text-slate-300 hover:text-white' => ! $mobile])>{{ $item['label'] }}</a>
        @endif
    @endforeach
</nav>

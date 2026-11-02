@props(['role', 'mobile' => false])
@php($items = config("navigation.{$role}", []))
<nav {{ $attributes->merge(['class' => 'grid auto-rows-min content-start gap-1 text-sm']) }} aria-label="Navigasi portal">
@foreach ($items as $item)
    @if (isset($item['items']))
        <details class="group" open><summary title="{{ $item['label'] }}" class="flex cursor-pointer list-none items-center justify-between rounded-xl px-3 py-2.5 font-semibold text-slate-200 transition hover:bg-white/10 hover:text-white"><flux:icon :name="$item['icon']" class="sidebar-icon size-5 shrink-0" /><span class="sidebar-label ml-3 flex-1">{{ $item['label'] }}</span><flux:icon.chevron-down class="sidebar-label size-4 transition group-open:rotate-180" /></summary><div class="sidebar-submenu grid gap-1 pb-2 pt-1">@foreach ($item['items'] as $child)@php($href = isset($child['route']) ? route($child['route'], ['role' => $role]) : route('portal.modules.placeholder', ['role' => $role, 'module' => $child['module']]))<a href="{{ $href }}" class="rounded-xl px-3 py-2 pl-11 text-slate-300 transition hover:bg-white/10 hover:text-white">{{ $child['label'] }}</a>@endforeach</div></details>
    @else
        @php($href = isset($item['route']) ? route($item['route'], ['role' => $role]) : route('portal.modules.placeholder', ['role' => $role, 'module' => $item['module']]))
        <a href="{{ $href }}" title="{{ $item['label'] }}" class="flex items-center rounded-xl px-3 py-2.5 font-semibold text-slate-200 transition hover:bg-white/10 hover:text-white"><flux:icon :name="$item['icon']" class="sidebar-icon size-5 shrink-0" /><span class="sidebar-label ml-3">{{ $item['label'] }}</span></a>
    @endif
@endforeach
</nav>

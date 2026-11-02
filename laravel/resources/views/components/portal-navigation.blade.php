@props(['role', 'mobile' => false])
@php($items = config("navigation.{$role}", []))
<nav {{ $attributes->merge(['class' => 'grid gap-1 text-sm']) }} aria-label="Navigasi portal">
@foreach ($items as $item)
    @if (isset($item['items']))
        <details class="group" open><summary @class(['flex cursor-pointer list-none items-center justify-between rounded-xl px-3 py-2.5 font-semibold transition', 'text-slate-900 hover:bg-slate-100' => $mobile, 'text-slate-300 hover:bg-white/8 hover:text-white' => ! $mobile])><flux:icon :name="$item['icon']" class="sidebar-icon size-5 shrink-0" /><span class="sidebar-label ml-3 flex-1">{{ $item['label'] }}</span><flux:icon.chevron-down class="sidebar-label size-4 transition group-open:rotate-180" /></summary><div class="sidebar-submenu grid gap-1 pb-2 pt-1">@foreach ($item['items'] as $child)@php($href = isset($child['route']) ? route($child['route'], ['role' => $role]) : route('portal.modules.placeholder', ['role' => $role, 'module' => $child['module']]))<a href="{{ $href }}" @class(['rounded-xl px-3 py-2 pl-11 transition', 'text-slate-600 hover:bg-slate-100 hover:text-slate-950' => $mobile, 'text-slate-400 hover:bg-white/8 hover:text-white' => ! $mobile])>{{ $child['label'] }}</a>@endforeach</div></details>
    @else
        @php($href = isset($item['route']) ? route($item['route'], ['role' => $role]) : route('portal.modules.placeholder', ['role' => $role, 'module' => $item['module']]))
        <a href="{{ $href }}" @class(['flex items-center rounded-xl px-3 py-2.5 font-semibold transition', 'text-slate-900 hover:bg-slate-100' => $mobile, 'text-slate-300 hover:bg-white/8 hover:text-white' => ! $mobile])><flux:icon :name="$item['icon']" class="sidebar-icon size-5 shrink-0" /><span class="sidebar-label ml-3">{{ $item['label'] }}</span></a>
    @endif
@endforeach
</nav>

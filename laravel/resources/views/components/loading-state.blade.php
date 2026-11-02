@props(['label' => 'Memuat data'])

<div {{ $attributes->merge(['class' => 'ui-loading-state flex items-center gap-3 px-4 py-3 text-sm text-slate-600']) }} role="status">
    <span class="size-4 animate-spin rounded-full border-2 border-slate-300 border-t-teal-600"></span>
    <span>{{ $label }}…</span>
</div>

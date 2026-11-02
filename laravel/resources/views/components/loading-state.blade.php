@props(['label' => 'Memuat data'])

<div {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-500']) }} role="status">
    <span class="size-4 animate-spin rounded-full border-2 border-slate-300 border-t-teal-600"></span>
    <span>{{ $label }}…</span>
</div>

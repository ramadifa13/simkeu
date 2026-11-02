@props(['label', 'value' => null, 'hint' => null])

<div {{ $attributes->merge(['class' => 'ui-stat-card']) }}>
    <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
    <p class="mt-4 text-2xl font-semibold tracking-tight text-slate-950">{{ $value ?? '—' }}</p>
    @if ($hint)<p class="mt-2 text-sm text-slate-500">{{ $hint }}</p>@endif
</div>

@props(['label', 'value' => null, 'hint' => null, 'tone' => 'teal', 'icon' => null])

<div {{ $attributes->merge(['class' => 'ui-stat-card is-'.$tone]) }}>
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
        @if ($icon)<span class="ui-stat-icon"><flux:icon :name="$icon" class="size-4" /></span>@endif
    </div>
    <p class="mt-4 text-2xl font-semibold tracking-tight text-slate-950">{{ $value ?? '—' }}</p>
    @if ($hint)<p class="mt-2 text-sm text-slate-500">{{ $hint }}</p>@endif
</div>

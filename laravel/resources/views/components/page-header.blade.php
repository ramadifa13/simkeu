@props(['eyebrow' => null, 'title', 'description' => null])

<div {{ $attributes->merge(['class' => 'ui-page-header flex flex-col justify-between gap-5 sm:flex-row sm:items-end']) }}>
    <div>
        @if ($eyebrow)
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-teal-700">{{ $eyebrow }}</p>
        @endif
        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ $title }}</h1>
        @if ($description)
            <p class="mt-3 max-w-2xl text-slate-600">{{ $description }}</p>
        @endif
    </div>
    @if (isset($actions))
        <div class="shrink-0">{{ $actions }}</div>
    @endif
</div>

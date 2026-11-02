@props(['eyebrow' => null, 'title', 'description' => null])

<div {{ $attributes->merge(['class' => 'ui-page-header flex flex-col justify-between gap-5 sm:flex-row sm:items-end']) }}>
    <div>
        @if ($eyebrow)
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-teal-700">{{ $eyebrow }}</p>
        @endif
        <h1 class="mt-1.5 text-xl font-semibold tracking-tight text-slate-950 sm:text-2xl">{{ $title }}</h1>
        @if ($description)
            <p class="mt-2 max-w-2xl text-sm leading-5 text-slate-600">{{ $description }}</p>
        @endif
    </div>
    @if (isset($actions))
        <div class="shrink-0">{{ $actions }}</div>
    @endif
</div>

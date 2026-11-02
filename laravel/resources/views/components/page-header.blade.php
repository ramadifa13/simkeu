@props(['eyebrow' => null, 'title', 'description' => null, 'icon' => null, 'backHref' => null, 'backLabel' => 'Kembali'])

<div {{ $attributes->merge(['class' => 'ui-page-header flex flex-col justify-between gap-4 sm:flex-row sm:items-end']) }}>
    <div class="ui-page-header-main">
        @if ($backHref)
            <flux:button :href="$backHref" variant="outline" icon="arrow-left" class="ui-page-header-back">{{ $backLabel }}</flux:button>
        @endif
        @if ($icon)<span class="ui-page-header-icon"><flux:icon :name="$icon" class="size-7" /></span>@endif
        <div>
            @if ($eyebrow)
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-teal-700">{{ $eyebrow }}</p>
            @endif
            <h1 class="mt-1.5 text-xl font-semibold tracking-tight text-slate-950 sm:text-2xl">{{ $title }}</h1>
            @if ($description)
                <p class="mt-2 max-w-2xl text-sm leading-5 text-slate-600">{{ $description }}</p>
            @endif
        </div>
    </div>
    @if (isset($actions))
        <div class="shrink-0">{{ $actions }}</div>
    @endif
</div>

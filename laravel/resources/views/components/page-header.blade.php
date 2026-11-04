@props(['eyebrow' => null, 'title', 'description' => null, 'icon' => null, 'backHref' => null, 'backLabel' => 'Kembali'])

<section {{ $attributes->merge(['class' => 'ui-page-header flex flex-col justify-between gap-4 sm:flex-row sm:items-center']) }}>
    <div class="ui-page-header-main">
        @if ($backHref)
            <a href="{{ $backHref }}" class="ui-page-header-back" data-safe-back aria-label="{{ $backLabel }}" title="{{ $backLabel }}">
                <flux:icon name="arrow-left" class="size-4" />
                <span class="sr-only">{{ $backLabel }}</span>
            </a>
        @endif
        @if ($icon)<span class="ui-page-header-icon"><flux:icon :name="$icon" class="size-7" /></span>@endif
        <div class="ui-page-header-copy">
            @if ($eyebrow)
                <p class="ui-kicker text-xs font-bold uppercase tracking-[0.18em]">{{ $eyebrow }}</p>
            @endif
            <h1 class="mt-1.5 text-xl font-semibold tracking-tight text-slate-950 sm:text-2xl">{{ $title }}</h1>
            @if ($description)
                <p class="mt-2 max-w-2xl text-sm leading-5 text-slate-600">{{ $description }}</p>
            @endif
        </div>
    </div>
    @if (isset($actions))
        <div class="ui-page-header-actions shrink-0">{{ $actions }}</div>
    @endif
</section>

@props([
    'eyebrow' => 'Langkah berikutnya',
    'title',
    'description' => null,
    'tone' => 'teal',
])

@php
    $tones = [
        'teal' => 'border-teal-100 from-teal-50 to-white text-teal-700',
        'amber' => 'border-amber-200 from-amber-50 to-white text-amber-800',
        'rose' => 'border-rose-200 from-rose-50 to-white text-rose-800',
        'slate' => 'border-slate-200 from-slate-50 to-white text-slate-700',
    ];
@endphp

<section {{ $attributes->merge(['class' => 'rounded-2xl border bg-gradient-to-r p-5 shadow-sm '.$tones[$tone]]) }}>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-widest">{{ $eyebrow }}</p>
            <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ $title }}</h2>
            @if ($description)
                <p class="mt-1 text-sm leading-5 text-slate-600">{{ $description }}</p>
            @endif
        </div>
        @if (isset($actions))
            <div class="flex flex-wrap gap-2">{{ $actions }}</div>
        @endif
    </div>
</section>

@props(['title', 'description' => null])

<section {{ $attributes->merge(['class' => 'ui-form-section']) }}>
    <div class="border-b border-slate-100 pb-4">
        <h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>
        @if ($description)<p class="mt-1 text-sm text-slate-500">{{ $description }}</p>@endif
    </div>
    <div class="mt-6">{{ $slot }}</div>
</section>

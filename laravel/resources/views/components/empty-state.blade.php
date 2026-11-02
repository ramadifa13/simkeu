@props(['title' => 'Belum ada data', 'description' => null])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center']) }}>
    <p class="font-semibold text-slate-800">{{ $title }}</p>
    @if ($description)<p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">{{ $description }}</p>@endif
    @if (isset($action))<div class="mt-5">{{ $action }}</div>@endif
</div>

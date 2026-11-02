@props(['url' => null, 'label' => 'Dokumen', 'mime' => null])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-2xl border border-slate-200 bg-white']) }}>
    @if ($url && str_starts_with((string) $mime, 'image/'))
        <img src="{{ $url }}" alt="Pratinjau {{ $label }}" class="aspect-video w-full object-cover">
    @else
        <div class="flex aspect-video items-center justify-center bg-slate-50 px-6 text-center text-sm text-slate-500">{{ $url ? 'Pratinjau file tersedia melalui tautan aman.' : 'Dokumen belum tersedia.' }}</div>
    @endif
    <div class="flex items-center justify-between gap-3 p-4">
        <p class="truncate text-sm font-semibold text-slate-800">{{ $label }}</p>
        @if ($url)<a href="{{ $url }}" target="_blank" rel="noopener" class="shrink-0 text-sm font-semibold text-teal-700 hover:text-teal-800">Buka</a>@endif
    </div>
</div>

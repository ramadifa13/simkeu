@props(['label' => 'Aksi'])

<details class="relative inline-block text-left">
    <summary class="grid size-9 cursor-pointer place-items-center rounded-lg border border-slate-200 bg-white text-lg font-bold text-slate-600 transition hover:bg-slate-50" aria-label="{{ $label }}" title="{{ $label }}">⋯</summary>
    <div class="absolute right-0 z-20 mt-2 min-w-44 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">{{ $slot }}</div>
</details>

@props(['label' => 'Tanda tangan', 'name' => 'signature'])

<div data-signature {{ $attributes->merge(['class' => 'ui-signature-field']) }}>
    <label class="mb-2 block text-sm font-semibold text-slate-800">{{ $label }}</label>
    <canvas class="ui-signature-pad" aria-label="Area menggambar tanda tangan"></canvas>
    <input type="file" name="{{ $name }}" accept="image/webp" class="hidden" tabindex="-1" />
    <div class="mt-2 flex items-center justify-between gap-3"><p class="text-xs text-slate-600" data-signature-message aria-live="polite">Belum ada tanda tangan</p><button type="button" data-signature-clear class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">Hapus tanda tangan</button></div>
</div>

@props(['rate' => null, 'editing' => false])
@php($value = fn (string $field, mixed $default = null) => old($field, $rate?->{$field} ?? $default))

<x-required-hint class="mb-1" />

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start gap-3">
        <span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">1</span>
        <div>
            <h2 class="text-base font-semibold text-slate-900">Tarif denda harian</h2>
            <p class="mt-1 text-sm text-slate-600">Batas nominal pinjaman dan denda per hari. Kalkulasi angsuran diterapkan di Fase 7.</p>
        </div>
    </div>
    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <flux:field>
            <flux:label>Batas nominal pinjaman <x-required-mark /></flux:label>
            <flux:input type="number" min="0" step="0.01" name="jumlah_pinjaman" :value="$value('jumlah_pinjaman')" required />
            <flux:error name="jumlah_pinjaman" />
        </flux:field>
        <flux:field>
            <flux:label>Denda per hari <x-required-mark /></flux:label>
            <flux:input type="number" min="0" step="0.01" name="denda_per_hari" :value="$value('denda_per_hari')" required />
            <flux:error name="denda_per_hari" />
        </flux:field>
    </div>
</section>

@props(['rate' => null, 'loans', 'editing' => false])
@php($value = fn (string $field, mixed $default = null) => old($field, $rate?->{$field} ?? $default))

<x-required-hint class="mb-1" />

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start gap-3">
        <span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">1</span>
        <div>
            <h2 class="text-base font-semibold text-slate-900">Tarif per tenor</h2>
            <p class="mt-1 text-sm text-slate-600">Bunga khusus untuk tenor tertentu pada jenis pinjaman.</p>
        </div>
    </div>
    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <flux:field class="md:col-span-2">
            <flux:label>Jenis pinjaman <x-required-mark /></flux:label>
            <flux:select name="jenis_pinjaman_id" required>
                <option value="">Pilih jenis pinjaman</option>
                @foreach ($loans as $loan)
                    <option value="{{ $loan->id }}" @selected((string) $value('jenis_pinjaman_id') === (string) $loan->id)>
                        {{ $loan->nama_jenis }} ({{ $loan->kode_jenis }})
                    </option>
                @endforeach
            </flux:select>
            <flux:error name="jenis_pinjaman_id" />
        </flux:field>
        <flux:field>
            <flux:label>Tenor (bulan) <x-required-mark /></flux:label>
            <flux:input type="number" min="1" max="360" name="tenor_bulan" :value="$value('tenor_bulan')" required />
            <flux:error name="tenor_bulan" />
        </flux:field>
        <flux:field>
            <flux:label>Bunga (%) <x-required-mark /></flux:label>
            <flux:input type="number" min="0" max="100" step="0.01" name="bunga" :value="$value('bunga')" required />
            <flux:error name="bunga" />
        </flux:field>
    </div>
</section>

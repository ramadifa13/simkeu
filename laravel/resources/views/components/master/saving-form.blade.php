@props(['saving' => null, 'editing' => false, 'locked' => false])
@php($value = fn (string $field, mixed $default = null) => old($field, $saving?->{$field} ?? $default))

<x-required-hint class="mb-1" />

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start gap-3">
        <span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">1</span>
        <div>
            <h2 class="text-base font-semibold text-slate-900">Identitas produk</h2>
            <p class="mt-1 text-sm text-slate-600">Kode unik dipakai pada rekening simpanan anggota.</p>
        </div>
    </div>
    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <flux:field>
            <flux:label>Kode jenis <x-required-mark /></flux:label>
            <flux:input name="kode_jenis" :value="$value('kode_jenis')" required :disabled="$locked" />
            @if ($locked)<input type="hidden" name="kode_jenis" value="{{ $value('kode_jenis') }}">@endif
            <flux:error name="kode_jenis" />
            <flux:description>Kode ANG, BONUS, dan SHU dilindungi sistem.</flux:description>
        </flux:field>
        <flux:field>
            <flux:label>Nama jenis <x-required-mark /></flux:label>
            <flux:input name="nama_jenis" :value="$value('nama_jenis')" required :disabled="$locked" />
            @if ($locked)<input type="hidden" name="nama_jenis" value="{{ $value('nama_jenis') }}">@endif
            <flux:error name="nama_jenis" />
        </flux:field>
        <flux:field class="md:col-span-2">
            <flux:label>Deskripsi</flux:label>
            <flux:textarea name="deskripsi" rows="3" :disabled="$locked">{{ $value('deskripsi') }}</flux:textarea>
            <flux:error name="deskripsi" />
        </flux:field>
    </div>
</section>

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start gap-3">
        <span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">2</span>
        <div>
            <h2 class="text-base font-semibold text-slate-900">Batas & bagi hasil</h2>
            <p class="mt-1 text-sm text-slate-600">Nilai berlaku untuk transaksi baru setelah disimpan.</p>
        </div>
    </div>
    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <flux:field>
            <flux:label>Minimum</flux:label>
            <flux:input type="number" min="0" step="0.01" name="minimum" :value="$value('minimum')" :disabled="$locked" />
            <flux:error name="minimum" />
        </flux:field>
        <flux:field>
            <flux:label>Maksimum</flux:label>
            <flux:input type="number" min="0" step="0.01" name="maksimum" :value="$value('maksimum')" :disabled="$locked" />
            <flux:error name="maksimum" />
        </flux:field>
        <flux:field>
            <flux:label>Bagi hasil (%)</flux:label>
            <flux:input type="number" min="0" max="100" step="0.01" name="bagi_hasil" :value="$value('bagi_hasil')" :disabled="$locked" />
            <flux:error name="bagi_hasil" />
        </flux:field>
        <flux:field>
            <flux:label>Status <x-required-mark /></flux:label>
            <flux:select name="status" required :disabled="$locked">
                <option value="aktif" @selected($value('status', 'aktif') === 'aktif')>Aktif</option>
                <option value="nonaktif" @selected($value('status') === 'nonaktif')>Nonaktif</option>
            </flux:select>
            <flux:error name="status" />
        </flux:field>
    </div>
</section>

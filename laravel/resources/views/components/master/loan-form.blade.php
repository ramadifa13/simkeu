@props(['loan' => null, 'editing' => false])
@php($value = fn (string $field, mixed $default = null) => old($field, $loan?->{$field} ?? $default))
@php($frequencies = collect(old('frekuensi_diizinkan', $loan ? explode(',', (string) $loan->frekuensi_diizinkan) : ['bulanan'])))

<x-required-hint class="mb-1" />

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start gap-3">
        <span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">1</span>
        <div>
            <h2 class="text-base font-semibold text-slate-900">Identitas produk</h2>
            <p class="mt-1 text-sm text-slate-600">Produk pinjaman yang tersedia untuk pengajuan baru.</p>
        </div>
    </div>
    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <flux:field>
            <flux:label>Kode jenis <x-required-mark /></flux:label>
            <flux:input name="kode_jenis" :value="$value('kode_jenis')" required />
            <flux:error name="kode_jenis" />
        </flux:field>
        <flux:field>
            <flux:label>Nama jenis <x-required-mark /></flux:label>
            <flux:input name="nama_jenis" :value="$value('nama_jenis')" required />
            <flux:error name="nama_jenis" />
        </flux:field>
        <flux:field class="md:col-span-2">
            <flux:label>Deskripsi</flux:label>
            <flux:textarea name="deskripsi" rows="3">{{ $value('deskripsi') }}</flux:textarea>
            <flux:error name="deskripsi" />
        </flux:field>
    </div>
</section>

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start gap-3">
        <span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">2</span>
        <div>
            <h2 class="text-base font-semibold text-slate-900">Plafond & tenor</h2>
            <p class="mt-1 text-sm text-slate-600">Bunga default dipakai bila tarif tenor spesifik belum tersedia.</p>
        </div>
    </div>
    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <flux:field>
            <flux:label>Maksimal pinjaman <x-required-mark /></flux:label>
            <flux:input type="number" min="0" step="0.01" name="maksimal_pinjaman" :value="$value('maksimal_pinjaman')" required />
            <flux:error name="maksimal_pinjaman" />
        </flux:field>
        <flux:field>
            <flux:label>Tenor maksimal (bulan) <x-required-mark /></flux:label>
            <flux:input type="number" min="1" max="360" name="tenor_max" :value="$value('tenor_max')" required />
            <flux:error name="tenor_max" />
        </flux:field>
        <flux:field>
            <flux:label>Bunga default (%) <x-required-mark /></flux:label>
            <flux:input type="number" min="0" max="100" step="0.01" name="bunga" :value="$value('bunga')" required />
            <flux:error name="bunga" />
        </flux:field>
        <flux:field>
            <flux:label>Status <x-required-mark /></flux:label>
            <flux:select name="status" required>
                <option value="aktif" @selected($value('status', 'aktif') === 'aktif')>Aktif</option>
                <option value="nonaktif" @selected($value('status') === 'nonaktif')>Nonaktif</option>
            </flux:select>
            <flux:error name="status" />
        </flux:field>
    </div>
</section>

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start gap-3">
        <span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">3</span>
        <div>
            <h2 class="text-base font-semibold text-slate-900">Frekuensi & jaminan</h2>
            <p class="mt-1 text-sm text-slate-600">Pilih minimal satu frekuensi angsuran yang diizinkan.</p>
        </div>
    </div>
    <div class="mt-5 space-y-4">
        <fieldset>
            <legend class="text-sm font-medium text-slate-700">Frekuensi diizinkan <x-required-mark /></legend>
            <div class="mt-3 flex flex-wrap gap-4 text-sm">
                @foreach (['bulanan' => 'Bulanan', 'mingguan' => 'Mingguan', 'dua_mingguan' => 'Dua mingguan'] as $freq => $label)
                    <label class="inline-flex items-center gap-2 font-medium text-slate-700">
                        <input type="checkbox" name="frekuensi_diizinkan[]" value="{{ $freq }}" @checked($frequencies->contains($freq)) class="rounded border-slate-300 text-teal-700 focus:ring-teal-600">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
            @error('frekuensi_diizinkan')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
        </fieldset>
        <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
            <input type="checkbox" name="memerlukan_jaminan" value="1" @checked(old('memerlukan_jaminan', $loan?->memerlukan_jaminan)) class="rounded border-slate-300 text-teal-700 focus:ring-teal-600">
            Memerlukan jaminan / gadai
        </label>
    </div>
</section>

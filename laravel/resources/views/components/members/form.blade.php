@props(['member' => null, 'groupLeaders', 'editing' => false])
@php($value = fn (string $field, mixed $default = null) => old($field, $member?->{$field} ?? $default))

<x-required-hint class="mb-1" />

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start gap-3"><span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">1</span><div><h2 class="text-base font-semibold text-slate-900">Identitas utama</h2><p class="mt-1 text-sm text-slate-600">Data kontak dan identitas anggota.</p></div></div>
    <div class="mt-5 grid gap-4 md:grid-cols-2">
        @if ($editing)
            <div class="md:col-span-2 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2"><div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Kode anggota</p><p class="mt-1 font-mono text-sm font-semibold text-slate-900">{{ $member->kode_anggota }}</p></div><div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Kode jaminan</p><p class="mt-1 font-mono text-sm font-semibold text-slate-900">{{ $member->kode_anggota_jaminan ?: '—' }}</p></div><p class="sm:col-span-2 text-xs text-slate-600">Kode otomatis dan tidak dapat diubah dari formulir.</p></div>
        @else
            <div class="md:col-span-2 rounded-xl border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900"><p class="font-semibold">Kode dan akun dibuat otomatis</p><p class="mt-1 text-teal-800">Kode anggota dan username dibuat saat data disimpan. Password awal digenerate acak dan ditampilkan sekali setelah penyimpanan.</p></div>
            <flux:field><flux:label>Kategori anggota <x-required-mark /></flux:label><flux:select name="kategori_anggota" required><option value="biasa" @selected($value('kategori_anggota', 'biasa') === 'biasa')>Anggota biasa (kode AGT)</option><option value="jaminan" @selected($value('kategori_anggota') === 'jaminan')>Anggota jaminan/gadai (kode AGTJ)</option></flux:select><flux:error name="kategori_anggota" /></flux:field>
        @endif
        <flux:field><flux:label>Nama lengkap <x-required-mark /></flux:label><flux:input name="nama_lengkap" :value="$value('nama_lengkap')" required /><flux:error name="nama_lengkap" /></flux:field>
        <flux:field><flux:label>Telepon</flux:label><flux:input name="telepon" :value="$value('telepon')" /><flux:error name="telepon" /></flux:field>
        @if ($editing)
            <flux:field>
                <flux:label>Email</flux:label>
                <flux:input type="email" :value="$member->email" disabled />
                <flux:description>Email tidak diubah dari formulir (sama seperti PHP native).</flux:description>
            </flux:field>
            <flux:field>
                <flux:label>Tanggal daftar</flux:label>
                <flux:input type="date" :value="$member?->tanggal_daftar?->format('Y-m-d')" disabled />
            </flux:field>
        @else
            <flux:field>
                <flux:label>Tanggal daftar <x-required-mark /></flux:label>
                <flux:input type="date" name="tanggal_daftar" :value="$value('tanggal_daftar', now()->toDateString())" required />
                <flux:error name="tanggal_daftar" />
            </flux:field>
        @endif
        <flux:field class="md:col-span-2"><flux:label>Alamat</flux:label><flux:textarea name="alamat">{{ $value('alamat') }}</flux:textarea><flux:error name="alamat" /></flux:field>
    </div>
</section>

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><div class="flex items-start gap-3"><span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">2</span><div><h2 class="text-base font-semibold text-slate-900">Informasi pribadi</h2><p class="mt-1 text-sm text-slate-600">Sesuai data master anggota PHP native.</p></div></div><div class="mt-5 grid gap-4 md:grid-cols-2"><flux:field><flux:label>Tempat lahir</flux:label><flux:input name="tempat_lahir" :value="$value('tempat_lahir')" /><flux:error name="tempat_lahir" /></flux:field><flux:field><flux:label>Tanggal lahir</flux:label><flux:input type="date" name="tanggal_lahir" :value="$value('tanggal_lahir', $member?->tanggal_lahir?->format('Y-m-d'))" /><flux:error name="tanggal_lahir" /></flux:field><flux:field><flux:label>Jenis kelamin</flux:label><flux:select name="jenis_kelamin"><option value="">Pilih</option><option value="L" @selected($value('jenis_kelamin') === 'L')>Laki-laki</option><option value="P" @selected($value('jenis_kelamin') === 'P')>Perempuan</option></flux:select><flux:error name="jenis_kelamin" /></flux:field><flux:field><flux:label>Pekerjaan</flux:label><flux:input name="pekerjaan" :value="$value('pekerjaan')" /><flux:error name="pekerjaan" /></flux:field><flux:field><flux:label>NIK / KTP <x-required-mark /></flux:label><flux:input name="ktp" :value="$value('ktp')" required /><flux:error name="ktp" /></flux:field><flux:field><flux:label>Rekening bank</flux:label><flux:input name="rekening_bank" :value="$value('rekening_bank')" /><flux:error name="rekening_bank" /></flux:field></div></section>

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><div class="flex items-start gap-3"><span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">3</span><div><h2 class="text-base font-semibold text-slate-900">Klasifikasi koperasi</h2><p class="mt-1 text-sm text-slate-600">Kategori pembagian SHU dan struktur kelompok.</p></div></div><div class="mt-5 grid gap-4 md:grid-cols-2"><flux:field><flux:label>Kategori SHU <x-required-mark /></flux:label><flux:select name="kategori_shu" required><option value="anggota" @selected($value('kategori_shu', 'anggota') === 'anggota')>Anggota</option><option value="ketua_kelompok" @selected($value('kategori_shu') === 'ketua_kelompok')>Ketua kelompok</option></flux:select><flux:error name="kategori_shu" /></flux:field><flux:field><flux:label>Ketua kelompok</flux:label><flux:select name="ketua_kelompok_id"><option value="">Tidak tergabung kelompok</option>@foreach ($groupLeaders as $leader)<option value="{{ $leader->id }}" @selected((string) $value('ketua_kelompok_id') === (string) $leader->id)>{{ $leader->kode_anggota }} — {{ $leader->nama_lengkap }}</option>@endforeach</flux:select><flux:error name="ketua_kelompok_id" /></flux:field>@if($editing)<flux:field><flux:label>Status keanggotaan <x-required-mark /></flux:label><flux:select name="status" required><option value="aktif" @selected($value('status') === 'aktif')>Aktif</option><option value="nonaktif" @selected($value('status') === 'nonaktif')>Nonaktif</option></flux:select><flux:error name="status" /></flux:field><flux:field><flux:label>Jadwal nonaktif</flux:label><flux:input type="date" name="nonaktif_jadwal" :value="$value('nonaktif_jadwal', $member?->nonaktif_jadwal?->format('Y-m-d'))" /><flux:error name="nonaktif_jadwal" /></flux:field>@endif</div></section>

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start gap-3">
        <span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">4</span>
        <div>
            <h2 class="text-base font-semibold text-slate-900">{{ $editing ? 'Dokumen anggota' : 'Dokumen wajib' }}</h2>
            <p class="mt-1 text-sm text-slate-600">JPG, PNG, atau WebP. Foto kamera sampai 40 MB boleh; yang disimpan maksimal 1 MB. Anggota biasa wajib KTP, KK, dan KTP pasangan. Anggota jaminan cukup Foto KTP. {{ $editing ? 'Kosongkan bila tidak ingin mengganti file.' : '' }}</p>
        </div>
    </div>
    <div class="mt-5 grid gap-4 md:grid-cols-3">
        @foreach (['foto_ktp' => 'Foto KTP', 'foto_kk' => 'Foto Kartu Keluarga', 'foto_ktp_pasangan' => 'Foto KTP Suami/Istri'] as $field => $label)
            <x-image-upload :label="$label" :name="$field" :required="! $editing" />
        @endforeach
    </div>
</section>

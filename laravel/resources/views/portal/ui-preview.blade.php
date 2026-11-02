<x-layouts.portal role="admin" title="UI Preview / Design System">
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header class="ui-page-header-rich" icon="sparkles" eyebrow="FONDASI INTERNAL" title="UI Preview / Design System" description="Ruang validasi visual sementara. Semua contoh di bawah ini tidak membaca atau mengubah data operasional.">
            <x-slot:actions>
                <div class="flex flex-wrap gap-2">
                    <a href="#table"><flux:button variant="primary" color="teal" icon="table-cells">Lihat tabel</flux:button></a>
                    <a href="#forms"><flux:button variant="ghost" icon="document-text">Lihat form</flux:button></a>
                </div>
            </x-slot:actions>
        </x-page-header>

        <nav class="ui-preview-nav" aria-label="Bagian UI Preview">
            <a href="#foundations">Fondasi</a><a href="#actions">Aksi</a><a href="#forms">Input form</a><a href="#table">Tabel</a><a href="#states">State</a><a href="#feedback">Feedback</a><a href="#detail">Detail</a>
        </nav>

        <section id="foundations" class="scroll-mt-24">
            <div class="ui-preview-section-heading"><span>01</span><div><h2>Warna, hierarki, dan kepadatan</h2><p>Kontras tinggi, ruang cukup, dan informasi penting tetap dominan.</p></div></div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-stat-card label="Nilai utama" value="Rp 24.500.000" hint="Aksen teal untuk informasi aktif" />
                <x-stat-card label="Menunggu proses" value="12" hint="Peringatan selalu memakai label" />
                <x-stat-card label="Perlu perhatian" value="3" hint="Bahaya tidak hanya mengandalkan warna" />
                <x-stat-card label="Terakhir diperbarui" value="Baru saja" hint="Teks sekunder tetap terbaca" />
            </div>
            <div class="ui-panel mt-4 p-4 sm:p-5">
                <p class="ui-preview-label">Status semantik</p>
                <div class="mt-3 flex flex-wrap gap-2"><x-status-badge status="Aktif" /><x-status-badge status="Menunggu" /><x-status-badge status="Jatuh tempo" /><x-status-badge status="Ditolak" /><x-status-badge status="Arsip" /></div>
            </div>
        </section>

        <section id="actions" class="scroll-mt-24">
            <div class="ui-preview-section-heading"><span>02</span><div><h2>Aksi dan keputusan</h2><p>Aksi utama jelas; tiga titik hanya hidup pada data per baris.</p></div></div>
            <div class="ui-panel grid gap-5 p-4 sm:p-5 lg:grid-cols-[1fr_auto] lg:items-center">
                <div><p class="font-semibold text-slate-900">Tombol halaman</p><p class="mt-1 text-sm text-slate-600">Gunakan satu tombol primer per tujuan utama halaman.</p></div>
                <div class="flex flex-wrap gap-2"><flux:button variant="primary" color="teal" icon="plus">Tambah data</flux:button><flux:button variant="outline" icon="arrow-down-tray">Ekspor</flux:button><flux:button variant="danger" icon="trash">Hapus</flux:button></div>
            </div>
        </section>

        <section id="forms" class="scroll-mt-24">
            <div class="ui-preview-section-heading"><span>03</span><div><h2>Katalog input form</h2><p>Seluruh kontrol yang boleh dipakai modul; label, bantuan, error, disabled, dan fokus diperiksa dari satu tempat.</p></div></div>
            <div class="grid gap-4 xl:grid-cols-2">
                <x-form-section title="Input teks, angka, tanggal" description="Gunakan tipe input yang sesuai agar keyboard mobile dan validasi browser tepat.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field><flux:label>Nama lengkap</flux:label><flux:input placeholder="Nama sesuai identitas" /><flux:description>Teks pendek yang wajib diberi label.</flux:description></flux:field>
                        <flux:field><flux:label>Alamat email</flux:label><flux:input type="email" placeholder="nama@koperasi.id" /></flux:field>
                        <flux:field><flux:label>Nominal</flux:label><flux:input type="number" min="0" placeholder="0" /></flux:field>
                        <flux:field><flux:label>Tanggal proses</flux:label><flux:input type="date" value="2026-11-02" /></flux:field>
                        <flux:field><flux:label>Waktu layanan</flux:label><flux:input type="time" value="08:00" /></flux:field>
                        <flux:field><flux:label>Kata sandi</flux:label><flux:input type="password" placeholder="Minimal 8 karakter" viewable /></flux:field>
                    </div>
                    <flux:field class="mt-4"><flux:label>Catatan lengkap</flux:label><flux:textarea placeholder="Tambahkan keterangan bila diperlukan…" /><flux:description>Untuk isian panjang yang masih bagian dari halaman.</flux:description></flux:field>
                </x-form-section>
                <x-form-section title="Pilihan, autocomplete, dan upload" description="Select untuk pilihan sedikit; autocomplete untuk data master yang panjang.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field><flux:label>Status anggota</flux:label><flux:select><option>Aktif</option><option>Nonaktif</option></flux:select><flux:description>Pilihan sedikit memakai select biasa.</flux:description></flux:field>
                        <x-autocomplete label="Pilih anggota" name="preview_member" :options="['AGT0020' => 'AGT0020 — Alisa Debi Rahmawati', 'AGT0007' => 'AGT0007 — Desinta Indrawati', 'AGT0016' => 'AGT0016 — Elis Lutfiana']" />
                    </div>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <flux:field><flux:label>Frekuensi pembayaran</flux:label><flux:radio.group name="frequency" variant="segmented"><flux:radio value="weekly" label="Mingguan" checked /><flux:radio value="biweekly" label="2 mingguan" /></flux:radio.group></flux:field>
                        <flux:field><flux:label>Pilihan layanan</flux:label><div class="grid gap-2 pt-1"><flux:checkbox label="Kirim notifikasi" checked /><flux:checkbox label="Perlu dokumen pendukung" /><flux:checkbox label="Pilihan tidak tersedia" disabled /></div></flux:field>
                    </div>
                    <x-file-upload class="mt-4" label="Dokumen pendukung" name="preview_document" description="PDF/JPG/PNG; validasi ukuran dan MIME dilakukan di server." />
                    <div class="mt-4 grid gap-4 sm:grid-cols-2"><flux:field><flux:label>Kontrol tidak aktif</flux:label><flux:input value="Data terkunci" disabled /></flux:field><flux:field><flux:label>Contoh kesalahan</flux:label><flux:input value="" invalid placeholder="Wajib diisi" /><flux:error name="preview_error" message="Nomor identitas wajib diisi." /></flux:field></div>
                </x-form-section>
            </div>
            <x-form-section class="mt-4" title="Pola form dan modal" description="Modal hanya untuk tindakan singkat. Form panjang tetap membuka halaman khusus.">
                <div class="flex flex-wrap gap-2"><flux:modal.trigger name="preview-short-form"><flux:button variant="primary" color="teal" icon="pencil-square">Buka form singkat</flux:button></flux:modal.trigger><flux:modal.trigger name="preview-confirm"><flux:button variant="outline" icon="exclamation-triangle">Buka konfirmasi</flux:button></flux:modal.trigger></div>
            </x-form-section>
        </section>

        <section id="table" class="scroll-mt-24">
            <div class="ui-preview-section-heading"><span>04</span><div><h2>Data table operasional</h2><p>Filter menyatu dengan tabel, baris padat, dan aksi kontekstual rata kanan.</p></div></div>
            <x-data-table title="Daftar anggota" :count="3" :columns="['kode' => 'Kode', 'nama' => 'Nama anggota', 'telepon' => 'Telepon', 'status' => 'Status', 'terdaftar' => 'Terdaftar', 'actions' => 'Aksi']" :mobile-hidden="['telepon', 'terdaftar']">
                <x-slot:filters><div class="ui-table-filter-bar"><flux:field><flux:label>Pencarian</flux:label><flux:input type="search" placeholder="Nama, kode, atau telepon" /></flux:field><flux:field><flux:label>Status anggota</flux:label><flux:select><option>Semua status</option><option>Aktif</option><option>Nonaktif</option></flux:select></flux:field></div></x-slot:filters>
                @foreach ([['AGT0020', 'Alisa Debi Rahmawati', '082326970453'], ['AGT0007', 'Desinta Indrawati', '—'], ['AGT0016', 'Elis Lutfiana', '—']] as [$code, $name, $phone])
                    <tr><td class="font-mono text-slate-600">{{ $code }}</td><td class="font-semibold text-slate-900">{{ $name }}</td><td class="hidden text-slate-600 md:table-cell">{{ $phone }}</td><td><x-status-badge status="Aktif" /></td><td class="hidden text-slate-600 md:table-cell">31 Okt 2026</td><td class="text-right"><x-action-menu><a href="#detail" class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-teal-50 hover:text-teal-800">Lihat detail</a><button class="block w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">Ubah</button><button class="block w-full rounded-lg px-3 py-2 text-left text-sm text-rose-700 hover:bg-rose-50">Arsipkan</button></x-action-menu></td></tr>
                @endforeach
            </x-data-table>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 px-1 text-sm text-slate-600"><span>Menampilkan 1–3 dari 3 data</span><div class="flex gap-1"><button class="ui-pagination-button" disabled aria-label="Halaman pertama">«</button><button class="ui-pagination-button" aria-current="page">1</button><button class="ui-pagination-button" disabled aria-label="Halaman terakhir">»</button></div></div>
        </section>

        <section id="states" class="scroll-mt-24">
            <div class="ui-preview-section-heading"><span>05</span><div><h2>State sistem</h2><p>Memuat, kosong, dan kegagalan selalu menjelaskan apa yang sedang terjadi serta langkah berikutnya.</p></div></div>
            <div class="grid gap-4 xl:grid-cols-3">
                <div class="ui-panel overflow-hidden"><div class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-800">Memuat daftar</div><div class="p-4 space-y-4">@for ($i = 0; $i < 3; $i++)<div class="grid grid-cols-[1fr_1.3fr_.8fr] gap-3"><span class="ui-table-skeleton w-full"></span><span class="ui-table-skeleton w-full"></span><span class="ui-table-skeleton w-full"></span></div>@endfor</div></div>
                <div class="ui-panel p-3"><x-empty-state title="Belum ada pengajuan" description="Data baru akan muncul setelah pengajuan dibuat."><x-slot:action><flux:button variant="primary" color="teal" size="sm" icon="plus">Buat pengajuan</flux:button></x-slot:action></x-empty-state></div>
                <div class="ui-panel p-5"><p class="ui-preview-label">Gagal dimuat</p><div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-4"><p class="font-semibold text-rose-900">Data belum dapat dimuat</p><p class="mt-1 text-sm leading-6 text-rose-800">Periksa koneksi lalu coba kembali. Tidak ada perubahan data yang dilakukan.</p><flux:button class="mt-3" variant="ghost" icon="arrow-path">Coba lagi</flux:button></div></div>
            </div>
        </section>

        <section id="feedback" class="scroll-mt-24">
            <div class="ui-preview-section-heading"><span>06</span><div><h2>Feedback: toast, callout, dan proses</h2><p>Setiap hasil aksi memberi pesan singkat, jelas, dan tidak hanya mengandalkan warna.</p></div></div>
            <div class="grid gap-4 xl:grid-cols-2">
                <div class="ui-panel p-5" x-data>
                    <p class="ui-preview-label">Toast interaktif</p><p class="mt-2 text-sm text-slate-600">Coba tiap variasi untuk memeriksa pesan dan kontras pada posisi nyata.</p>
                    <div class="mt-4 flex flex-wrap gap-2"><flux:button size="sm" variant="primary" color="teal" x-on:click="$flux.toast('Data anggota berhasil disimpan.', { variant: 'success' })">Sukses</flux:button><flux:button size="sm" variant="outline" x-on:click="$flux.toast('Perubahan perlu ditinjau kembali.', { variant: 'warning' })">Peringatan</flux:button><flux:button size="sm" variant="danger" x-on:click="$flux.toast('Data tidak dapat disimpan.', { variant: 'danger' })">Gagal</flux:button><flux:button size="sm" variant="ghost" x-on:click="$flux.toast('Sistem sedang memuat pembaruan.', { variant: 'info' })">Info</flux:button></div>
                </div>
                <div class="ui-panel p-5"><p class="ui-preview-label">Pesan dalam konteks</p><div class="mt-3 space-y-3"><flux:callout variant="success" icon="check-circle"><flux:callout.heading>Dokumen siap ditinjau</flux:callout.heading><flux:callout.text>Unggahan telah diterima dan menunggu validasi petugas.</flux:callout.text></flux:callout><flux:callout variant="warning" icon="exclamation-triangle"><flux:callout.heading>Perlu perhatian</flux:callout.heading><flux:callout.text>Nominal di atas batas rekomendasi membutuhkan persetujuan.</flux:callout.text></flux:callout></div></div>
            </div>
        </section>

        <section id="detail" class="scroll-mt-24 pb-6">
            <div class="ui-preview-section-heading"><span>07</span><div><h2>Detail, dokumen, dan jejak audit</h2><p>Halaman detail memakai ringkasan di atas lalu tab/konten bertahap agar tidak padat.</p></div></div>
            <div class="grid gap-4 xl:grid-cols-[1.1fr_.9fr]">
                <div class="ui-panel p-5"><div class="flex items-start justify-between gap-4"><div><p class="ui-preview-label">Anggota</p><h3 class="mt-1 text-lg font-semibold text-slate-950">Siti Rahmawati</h3><p class="mt-1 text-sm text-slate-600">AGT0024 · anggota aktif</p></div><x-status-badge status="Aktif" /></div><div class="ui-preview-tabs mt-5"><button class="is-active">Ringkasan</button><button>Dokumen</button><button>Aktivitas</button></div><dl class="mt-5 grid gap-4 sm:grid-cols-2"><div><dt>Nomor telepon</dt><dd>0812 3456 7890</dd></div><div><dt>Tanggal daftar</dt><dd>02 Nov 2026</dd></div></dl></div>
                <div class="ui-panel p-5"><p class="ui-preview-label">Aksesibilitas & responsif</p><ul class="ui-preview-checklist mt-3"><li>Fokus keyboard terlihat pada seluruh kontrol.</li><li>Mobile memprioritaskan kolom dan aksi penting.</li><li>Tablet memakai grid dua kolom bila ruang cukup.</li><li>Animasi berhenti saat reduced-motion aktif.</li><li>Warna status selalu disertai teks.</li></ul></div>
            </div>
        </section>
    </div>

    <x-short-form-modal name="preview-short-form" title="Ubah status anggota" description="Contoh form pendek yang aman dikerjakan tanpa pindah halaman.">
        <flux:field><flux:label>Status baru</flux:label><flux:select><option>Aktif</option><option>Nonaktif</option></flux:select></flux:field>
        <flux:field><flux:label>Catatan petugas</flux:label><flux:textarea placeholder="Alasan perubahan…" /></flux:field>
        <x-form-actions><flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close><flux:button variant="primary" color="teal">Simpan</flux:button></x-form-actions>
    </x-short-form-modal>
    <x-confirm-action-modal name="preview-confirm" title="Arsipkan data?" description="Aksi ini hanya contoh. Pada modul nyata, histori tidak akan dihapus." confirm-label="Ya, arsipkan" />
</x-layouts.portal>

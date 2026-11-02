# Rencana Implementasi Modernisasi SIMKEU Laravel

> **Status:** aktif. Dokumen ini adalah satu-satunya acuan pelaksanaan migrasi.
> Aplikasi PHP native tetap menjadi referensi fungsional sampai cutover disetujui.
> **Aturan utama:** tidak boleh memulai fase berikutnya sebelum seluruh checklist dan bukti kelulusan fase aktif selesai.

## Aturan pelaksanaan wajib

- [ ] Kerjakan hanya fase yang berstatus aktif.
- [ ] Jangan memulai pekerjaan implementasi dari fase berikutnya walaupun sudah mengetahui kebutuhannya.
- [ ] Setiap fase harus ditutup dengan verifikasi, pembaruan dokumen ini, dan laporan hasil.
- [ ] Bila terhambat oleh kredensial, infrastruktur, atau keputusan bisnis, catat hambatan di fase aktif dan berhenti; jangan mengganti pekerjaan dengan modul lain.
- [ ] Temuan fase berikutnya boleh dicatat, tetapi tidak boleh diimplementasikan lebih awal.
- [ ] Menu tiga titik dipakai hanya untuk aksi kontekstual pada baris tabel. Aksi utama halaman memakai tombol yang jelas.
- [ ] Seluruh kontrol input memakai Flux UI. Pilihan sedikit memakai select; pilihan panjang memakai autocomplete.
- [ ] Form singkat memakai modal; form panjang, unggah dokumen, atau transaksi finansial memakai halaman tersendiri.

## Audit kondisi saat ini

### Yang sudah tersedia

- [x] Branch khusus dan workspace Laravel terpisah.
- [x] Database pengembangan terpisah bernama simkeu_laravel.
- [x] Landing shell, login/logout, redirect per role, CSRF, dan rate limit login.
- [x] Role admin, teller, anggota; middleware role; Policy dasar anggota.
- [x] Daftar anggota admin/teller dengan pencarian, filter, urut, pagination, detail, tambah, edit, dan dokumen privat.
- [x] Komponen Flux/Tailwind dasar, tabel responsif awal, dan shell portal desktop/mobile.
- [x] Audit action/migration untuk log aktivitas anggota sudah dibuat tetapi migrasinya belum dijalankan.

### Kekurangan utama yang terverifikasi

- [ ] Landing belum memiliki profil koperasi, layanan, proses, FAQ, kontak, lokasi/jam, dan tautan legal.
- [ ] Login belum memiliki lupa/reset kata sandi, halaman akun nonaktif/terkunci, profil, dan alur akun lengkap.
- [ ] Navigasi hanya memiliki Dashboard dan Data Anggota; seluruh modul native lain belum memiliki route/screen Laravel.
- [ ] Dashboard Admin, Teller, dan Anggota masih placeholder tanpa data/KPI/antrean/tautan filter.
- [ ] Transaksi, kas, pembelian, pinjaman, layanan, laporan, SHU, master data, pengaturan, impor, ekspor, PDF, scheduler, dan portal mandiri belum dimigrasikan.
- [ ] Katalog field/rule belum mencakup seluruh tabel, halaman, form, status, relasi, dan efek finansial native.
- [ ] Cakupan test Laravel masih dasar; test bisnis/finansial/UAT belum ada.

## Checklist kelengkapan lintas semua fase

Setiap fase yang membuat atau mengubah modul wajib mengecek seluruh butir yang relevan di bawah ini. Tidak ada modul yang boleh dinyatakan selesai hanya karena tampilannya sudah tersedia.

- [ ] **Data dan aturan:** semua field, enum, relasi, default, kondisi, perhitungan, dan histori native dipetakan serta memiliki keputusan tertulis.
- [ ] **Hak akses:** route, navigation item, query, action, dokumen, export, dan audit hanya tersedia untuk role/permission yang tepat.
- [ ] **Keamanan:** CSRF, validasi server, rate limit, validasi MIME/ukuran/ekstensi upload, private storage, sanitasi output, error aman, dan logging tidak membocorkan data sensitif.
- [ ] **Uang dan konsistensi:** decimal, transaksi database, locking, idempotensi, pencegahan duplikasi, reversal yang dapat diaudit, dan tidak ada penghapusan histori finansial.
- [ ] **Pengalaman pengguna:** Flux UI, tombol utama jelas, action menu hanya pada tabel, desktop/mobile/tablet, keyboard/focus, kontras, label semantik, reduced-motion, loading/empty/error/retry/sukses.
- [ ] **Tabel dan pencarian:** pagination server-side, search, sort, filter, reset, URL state, data terfilter pada export, kolom prioritas mobile, dan aksesibilitas.
- [ ] **Dokumen dan keluaran:** izin akses, audit akses, preview aman, PDF/Excel/kwitansi sesuai native, serta hasil cetak dapat diverifikasi.
- [ ] **Pengujian:** test sukses/gagal, validasi, policy, status transition, edge case, concurrency, data lama, dan UAT sesuai role.
- [ ] **Operasional:** konfigurasi environment, monitoring/error log, backup/restore, queue/scheduler bila relevan, dokumentasi deployment, serta rollback.

## Matriks cakupan native yang wajib ditutup di Fase 2

- [x] Seluruh 57 halaman Admin sudah dicatat dan diberi fase tujuan.
- [x] Seluruh 36 halaman Teller sudah dicatat dan diberi fase tujuan.
- [x] Seluruh 11 halaman Anggota sudah dicatat dan diberi fase tujuan.
- [x] Seluruh 72 helper/shared PHP yang terverifikasi secara rekursif di `includes/` sudah dicatat sebagai rule, UI, utilitas, atau tidak lagi diperlukan dengan alasan.
- [x] Seluruh test native: tabel angsuran, SHU, notifikasi, migrasi, layanan, gadai, denda/scope/permission/jaminan sudah dipetakan menjadi test Laravel.
- [x] Semua tabel legacy termasuk layanan, migrasi, pendanaan, perjanjian, closing, WA log, dan user_permissions sudah memiliki pemilik modul serta strategi model/query.

## Fase 1 — Baseline runtime dan environment terverifikasi

**Status: SELESAI — 2026-11-02**

**Tujuan:** Laravel dapat boot, migrasi tambahan sendiri, merender view, dan menjalankan test fokus pada database pengembangan terisolasi.

- [x] Identifikasi dan perbaiki proses Artisan yang hang.
- [x] Verifikasi konfigurasi development untuk database, session, cache, queue, filesystem privat, mail/log, timezone, locale, URL, dan scheduler.
- [x] Tulis matriks environment untuk local, staging/UAT, dan production tanpa menyimpan kredensial di repository.
- [x] Tetapkan baseline logging/monitoring: correlation data, error log, failed job, audit log, dan prosedur eskalasi.
- [x] Jalankan migration status dan catat hasilnya di dokumen baseline skema.
- [x] Jalankan hanya migrasi activity_logs pada database simkeu_laravel.
- [x] Verifikasi struktur tabel activity_logs dan satu audit event aman melalui RecordActivity.
- [x] Jalankan view cache.
- [x] Jalankan test fitur fokus yang sudah ada.
- [x] Pastikan tidak ada environment Laravel yang menunjuk database produksi native (guard NATIVE_REFERENCE_DATABASE diterapkan; konfigurasi deployment tetap wajib diisi).
- [x] Perbarui audit kondisi saat ini setelah semua verifikasi selesai.

**Bukti kelulusan:** terpenuhi pada 2026-11-02 — migrasi activity_logs sukses, struktur tabel terverifikasi, audit action diuji, view cache sukses, 8 focused test lulus, dan guard database referensi native diterapkan.

## Fase 2 — Katalog legacy dan peta kesetaraan

**Status: SELESAI — 2026-11-02.**

**Tujuan:** setiap data/rule native sudah diketahui sebelum membangun fitur baru.

**Progres terdokumentasi (2026-11-02):** baseline skema, halaman/form/helper, field dan klasifikasinya, relasi, kontrak finansial, keluaran, automasi, serta baseline rekonsiliasi telah diaudit. Sinkronisasi dari `main` juga menegaskan bahwa plafond pengajuan harus mengikuti produk aktif, bukan nominal hard-coded per frekuensi. Bukti lengkap berada pada `docs/phase-2-completion-audit.md`.

- [x] Catat sumber matriks peran/aksi native dan keputusan struktur informasi awal.
- [x] Buat inventaris halaman native per peran dan fase target awal.
- [x] Catat kontrak awal unggahan, mutasi finansial, reversal, denda, closing, dan idempotensi impor.
- [x] Kelompokkan tabel baseline menurut domain untuk memandu pemilik modul.
- [x] Petakan seluruh 11 test native ke fase dan target test Laravel.
- [x] Buat matriks awal kesetaraan halaman ke route, policy, tabel, keluaran, dan target test.
- [x] Ambil baseline jumlah data clone untuk domain inti dan catat prosedur rekonsiliasi cutover.
- [x] Catat field lengkap dan klasifikasi awal seluruh tabel legacy/legacy-ekstensi pada kamus data.
- [x] Inventaris form mutasi dan filter native beserta pola halaman/modal target.
- [x] Catat foreign key, relasi implisit, dan unique index yang menjadi invariant bisnis.
- [x] Catat definisi KPI, sumber angka, fallback, dan scope dashboard native untuk Fase 4.

- [x] Inventaris 54 tabel legacy: kolom, indeks, enum/status, dan relasi implisit (clone juga memiliki ekstensi legacy `user_permissions`; 55 tabel telah disnapshot).
- [x] Inventaris seluruh halaman/helper/form native per Admin, Teller, Anggota, cron, dan shared.
- [x] Lengkapi docs/field-rule-catalogue.md untuk setiap field/rule.
- [x] Buat matriks page-to-route, page-to-policy, page-to-table, page-to-export/PDF, dan page-to-test untuk seluruh native scope.
- [x] Tandai setiap field sebagai dipertahankan, dipindah, historis baca-saja, digabung, atau dihentikan beserta alasannya.
- [x] Petakan seluruh upload, lokasi file, PDF, Excel, import/export, dan aturan akses dokumen.
- [x] Petakan seluruh efek finansial, transaksi DB, lock, reversal, otorisasi, dan audit log.
- [x] Rekonsiliasi jumlah data awal untuk users, anggota, simpanan, transaksi, kas, pinjaman, angsuran, denda, SHU, dan file.

**Bukti kelulusan:** terpenuhi pada 2026-11-02; lihat `docs/phase-2-completion-audit.md`.

## Fase 3 — Arsitektur informasi, landing, dan akun

**Mulai hanya setelah Fase 2 selesai.**

- [x] Setujui peta navigasi lengkap Admin, Teller, dan Anggota berdasarkan katalog legacy.
- [x] Buat route placeholder dan navigasi desktop/mobile per role untuk seluruh modul yang disetujui.
- [x] Tetapkan fondasi desain UI/UX: token warna/typography/spacing, ikon, grid, kartu, tombol, form Flux, tabel, status, modal, toast, empty/loading/error state, dan aturan animasi.
- [x] Tetapkan pola responsif dan aksesibilitas landing/login: mobile-first, sticky navigation, mobile drawer, skip link, focus keyboard, kontras, serta reduced-motion.
- [x] Lengkapi landing: identitas koperasi, produk/layanan, proses, FAQ, kontak, lokasi/jam, legal/privacy, dan CTA login.
- [x] Terapkan landing modern dan interaktif: hero dinamis, visual brand, micro-interaction hemat animasi, hover/focus state, dan CTA.
- [x] Tentukan strategi aset gambar/ikon/font, optimasi ukuran, fallback, dan aturan lisensi sebelum aset dipakai.
- [x] Lengkapi login: feedback throttling, akun nonaktif, pesan generik, dan aksesibilitas.
- [x] Terapkan halaman login modern, responsif, dan aksesibel dengan feedback validasi/loading yang jelas.
- [x] Buat profil, ubah kata sandi, reset kata sandi, logout, dan area notifikasi.
- [x] Terapkan aturan tombol utama dan action menu tabel secara konsisten.
- [x] Sempurnakan fondasi visual reusable: token warna berkontras, surface/panel, page header, stat card, tabel, form section, dan state kosong/memuat dengan responsif serta reduced-motion.

**Bukti kelulusan:** terpenuhi untuk fondasi Fase 3 pada 2026-11-02. Navigasi per peran dan placeholder dikendalikan oleh `config/navigation.php`; UI publik/akun menggunakan Blade, Flux, token Tailwind, dan aksesibilitas dasar. Penyempurnaan fondasi visual terbaru menyatukan token kontras dan primitive reusable untuk surface/panel, header halaman, statistik, tabel, form, serta empty/loading state. Migrations reset kata sandi telah dijalankan, cache Blade berhasil, Vite build berhasil, dan 14 feature test lulus. Data profil koperasi spesifik (alamat, jam, kontak resmi, legal/privacy final) akan dibaca dari master pengaturan pada Fase 5; landing saat ini menampilkan fallback aman tanpa mengarang data.

## Fase 4 — Dashboard berbasis data nyata

**Mulai hanya setelah Fase 3 selesai.**

- [ ] Buat action/query dashboard yang aman per role.
- [ ] Rancang pola dashboard per role: hierarki KPI, kartu prioritas, alert, antrean, aktivitas terbaru, grafik yang diperlukan, quick action, dan empty/loading/error state.
- [ ] Terapkan layout dashboard modern, interaktif, dan responsif untuk mobile, tablet, dan desktop tanpa menyembunyikan informasi prioritas.
- [ ] Admin: anggota, kas, simpanan, pinjaman aktif, sisa pokok, jatuh tempo/telat, denda, pengajuan/pencairan, SHU, audit terbaru.
- [ ] Teller: transaksi hari ini, antrean operasional, angsuran jatuh tempo, denda, pencairan siap proses, aktivitas teller.
- [ ] Anggota: simpanan, pinjaman aktif, angsuran berikutnya, status pengajuan, SHU, dan aktivitas terbaru.
- [ ] Setiap kartu/alert mengarah ke halaman tabel yang telah terfilter dan berizin.
- [ ] Lengkapi loading, empty state, error/retry, dan test dashboard.
- [ ] Pastikan refresh data, periode/tanggal dashboard, timezone, dan angka finansial memiliki sumber/label yang jelas.

**Bukti kelulusan:** nilai dashboard cocok dengan query native dan data role lain tidak dapat diakses.

## Fase 5 — Anggota, master data, dokumen, dan pengaturan

**Mulai hanya setelah Fase 4 selesai.**

- [ ] Lengkapi rule anggota: status, kelompok, penjamin, user link, foto, dokumen bersyarat, dan export.
- [ ] Lengkapi detail anggota: profil, dokumen, simpanan, transaksi, pinjaman, SHU, dan audit dari seluruh sumber relevan.
- [ ] Implementasikan jenis simpanan, jenis pinjaman, tarif/tenor/denda, dan data teller.
- [ ] Implementasikan profil koperasi, pengaturan pinjaman, perjanjian/tanda tangan, dan pengaturan aplikasi.
- [ ] Lengkapi policy, validasi server, audit event, akses file privat, dan test.
- [ ] Tambahkan retensi/arsip data dan aturan perubahan status anggota agar histori tidak rusak.

**Bukti kelulusan:** UAT anggota/master data setara native dan semua kondisi field teruji.

## Fase 6 — Simpanan, pembelian, kas, koreksi, dan laporan

**Mulai hanya setelah Fase 5 selesai.**

- [ ] Implementasi setoran, penarikan, validasi saldo, buku simpanan, riwayat, dan bukti.
- [ ] Implementasi pembelian/Waserda dan dampak kas/transaksi.
- [ ] Implementasi kas, ringkasan harian, koreksi, otorisasi, rekonsiliasi, dan audit.
- [ ] Implementasi laporan simpanan, transaksi, kas, anggota, denda, dan ekspor berfilter.
- [ ] Gunakan decimal, transaksi database, lock, dan larangan hapus histori finansial.

**Bukti kelulusan:** matriks test finansial dan rekonsiliasi dengan native lulus tanpa selisih tidak terjelaskan.

## Fase 7 — Siklus pinjaman

**Mulai hanya setelah Fase 6 selesai.**

- [ ] Master pinjaman: jenis, tenor, frekuensi, bunga/tarif, jaminan, dan validasi.
- [ ] Pengajuan, dokumen, jaminan, review, persetujuan/penolakan, dan audit.
- [ ] Pencairan dengan kondisi foto/dokumen dan perubahan status transaksional.
- [ ] Pembuatan jadwal angsuran, pembayaran, denda, receipt, dan duplicate-payment lock.
- [ ] Pembatalan, reversal, pelunasan dini/akhir, bukti lunas, status anggota, dan closing angsuran.
- [ ] Porting dan test kalkulasi pinjaman flat-rate native.

**Bukti kelulusan:** seluruh skenario end-to-end cocok dengan native dan aman terhadap concurrency.

## Fase 8 — Layanan, SHU, dokumen keluaran, dan automasi

**Mulai hanya setelah Fase 7 selesai.**

- [ ] Implementasikan macet, lelang, alokasi pendanaan, jaminan kembali, bayar denda, pelunasan dini, repeat order, prioritas, tunda cair, dan log layanan.
- [ ] Implementasikan siklus SHU: periode, hitung, validasi sumber, persetujuan, hak anggota, pembayaran, kwitansi, audit, laporan.
- [ ] Port PDF: perjanjian, slip pencairan, buku angsuran, bukti bayar/lunas, kwitansi SHU, laporan.
- [ ] Port Excel, template/download/import migrasi dengan perlindungan duplikasi.
- [ ] Implementasikan backup terbatas admin setelah security review.
- [ ] Ubah cron WhatsApp menjadi command, scheduler, queue, retry, dan log deduplikasi.

**Bukti kelulusan:** UAT tiap layanan legacy dan verifikasi artefak PDF/Excel/automasi lulus.

## Fase 9 — Desain UI/UX final, quality gate, dan cutover

**Mulai hanya setelah Fase 8 selesai.**

- [ ] Terapkan visual modern final pada layar dan state nyata, bukan placeholder.
- [ ] Lakukan visual review khusus pada landing, login, dan seluruh dashboard terhadap desain yang disetujui pada Fase 3 dan Fase 4.
- [ ] Sempurnakan animasi/micro-interaction dengan performa baik dan dukungan reduced-motion; jangan gunakan animasi yang menghalangi tugas operasional.
- [ ] Jalankan review keamanan, performa, ukuran aset, cache, query lambat, logging error, dan pemulihan kegagalan sebelum UAT final.
- [ ] QA responsive mobile/tablet/desktop; fokus keyboard; kontras; screen-reader label; loading/error/empty/retry.
- [ ] Pastikan tabel memiliki pagination, search, filter, sort, reset, URL state, loading, empty state, dan export data terfilter.
- [ ] Lengkapi test unit/fitur/Livewire/role/upload/finansial dan jalankan full suite.
- [ ] Jalankan UAT per role, rekonsiliasi data, staging parallel run, dan backup/restore drill.
- [ ] Freeze native write, final delta import, persetujuan cutover, dan fallback read-only native.

**Bukti kelulusan:** UAT tertandatangani, rekonsiliasi bersih, rollback terbukti, dan cutover disetujui.

## Referensi modul native yang wajib tercakup

- [ ] Admin: dashboard, anggota, teller, simpanan, pinjaman, persetujuan, pencairan, angsuran, denda, pelunasan, closing, kas, koreksi, laporan, SHU, layanan, master, pengaturan, import/export/backup.
- [ ] Teller: dashboard, anggota, setoran, penarikan, pembelian, pengajuan, pencairan, angsuran, denda, pelunasan, closing, layanan, laporan, kwitansi, PDF.
- [ ] Anggota: dashboard, simpanan, riwayat/buku, pinjaman/jadwal, Waserda, SHU/kwitansi, profil, kata sandi.
- [ ] Shared: dokumen, PDF/Excel, WhatsApp reminder, migrasi, dan recovery dokumen.

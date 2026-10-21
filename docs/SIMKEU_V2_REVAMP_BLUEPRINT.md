# SIMKEU v2 — Blueprint UI/UX, RBAC, dan Migrasi Laravel

> Status: **rencana teknis, belum implementasi aplikasi produksi**. Dokumen ini dibuat dari audit statis branch `main` pada 22 Oktober 2026. Tidak ada perubahan pada alur transaksi atau database dalam branch ini.

## 1. Tujuan dan batasan

Bangun pengalaman SIMKEU modern, konsisten, dan responsif untuk Admin, Teller, serta Anggota; hilangkan duplikasi *business logic* dan komponen; tegakkan otorisasi pada backend; pertahankan kesetaraan saldo, bunga, denda, angsuran, SHU, bukti cetak, dan proses persetujuan terhadap aplikasi lama.

**Bukan** migrasi sekali jalan ke production. Kode PHP lama tetap berjalan sampai rangkaian pengujian dan *reconciliation* dinyatakan lulus.

## 2. Temuan audit yang dapat ditelusuri

| Temuan | Bukti | Implikasi |
|---|---|---|
| Backend procedural | `index.php`, `admin/*.php`, `teller/*.php`, `anggota/*.php` | Routing, validasi, query, dan HTML sering satu file |
| Duplikasi halaman | `admin/angsuran.php` dan `teller/angsuran.php` memuat ratusan baris kode panjang identik | Perbaikan fitur rawan harus dilakukan dua kali |
| Pengaturan role tersebar | `includes/auth.php`, pemeriksaan role di page/helper, `includes/sidebar.php` | Izin perlu dibakukan pada Policy + Permission |
| Sudah ada dasar UI | `includes/header.php`, `includes/sidebar.php`, `assets/css/app-ui.css`, `assets/js/app-ui.js` | Gunakan sebagai inventaris fitur; buat komponen UI baru yang tunggal |
| Sudah ada responsivitas | Media query pada `assets/css/app-ui.css`, `assets/css/home.css`, `login.php` | Harus diuji ulang per layar, bukan sekadar menambah CSS |
| Password default anggota | `admin/anggota.php`, `teller/anggota.php`, README merujuk password `123456` | Ganti dengan alur aktivasi/set-password aman |
| Konfigurasi DB longgar | `config/config.php` mengubah SQL mode per koneksi menjadi `NO_ENGINE_SUBSTITUTION` | Audit dan bersihkan legacy data sebelum strict-mode rollout |
| Skema awal tidak tersedia | README menyebut `simkeusi_koperasi_db.sql`, namun file tidak ada di tree Git | Dibutuhkan snapshot **struktur saja** yang sudah disanitasi, plus migrasi baseline |
| Logika penting telah ada | `includes/pinjaman_helper.php`, `includes/denda_helper.php`, `includes/shu_helper.php`, `tests/*.php` | Port & uji kesetaraan; jangan membuat rumus baru |

Audit ini belum menyatakan sistem aman/tidak aman secara keseluruhan karena belum ada audit runtime dan database.

## 3. Pilihan teknologi

- **Laravel 13 / PHP 8.3+**, MySQL/MariaDB dengan charset `utf8mb4`.
- **Livewire 4 + Blade + Tailwind CSS 4** dengan komponen UI yang konsisten (Flux UI atau komponen Blade internal). Pilihan ini mengurangi kompleksitas frontend untuk aplikasi bisnis berbasis formulir.
- **Laravel Policies/Gates** dan permission terpusat; `spatie/laravel-permission` hanya jika memang memerlukan role/permission dinamis per pengguna. Periksa kompatibilitas paket yang dipasang.
- **Form Requests** untuk validasi, `DB::transaction` untuk transaksi uang, Jobs/Scheduler untuk WhatsApp, Storage untuk berkas, feature tests dan browser tests untuk UI.
- Versi dependency dipin serta diaudit sebelum deployment; hosting wajib mendukung PHP dan ekstensi Laravel yang dipakai.

Arsitektur contoh:

```text
app/
  Actions/                 # Use cases: setoran, bayar angsuran, persetujuan, pencairan
  Enums/                   # Status bisnis & permission identifiers
  Http/Controllers/
  Http/Requests/           # Validasi tiap formulir
  Models/
  Policies/                # Perizinan sisi server & batas akses record
  Services/Finance/        # Rumus tunggal: bunga, angsuran, denda, SHU
  Services/Reporting/
  Jobs/
resources/views/
  components/ui/           # Button, table, form, badge, modal, toast, empty state
  layouts/                 # App shell + accessible mobile navigation
  livewire/
tests/Feature/             # Auth, permissions, money flows
tests/Unit/                # Finance parity test cases
```

## 4. Matriks izin awal (harus dikonfirmasi terhadap SOP koperasi)

Legenda: **P** penuh / menyetujui, **O** operasional, **D** data sendiri, **L** lihat, **—** dilarang. Kategori ini konsep; keputusan akhir diuji terhadap kode & SOP saat tahap implementasi.

| Aktivitas | Admin | Teller | Anggota |
|---|---|---|---|
| Dashboard | L seluruh koperasi | L operasional | D |
| Data anggota | P | O daftar/perbarui sesuai izin | D profil |
| Setoran/penarikan | P | O input transaksi | D riwayat |
| Pengajuan pinjaman | P | O ajukan | D status |
| Persetujuan pinjaman | P | — | D status |
| Pencairan | L/P pembatasan menurut SOP | O setelah disetujui | D status |
| Angsuran | P termasuk koreksi dengan audit | O pembayaran; ajukan pembatalan | D jadwal |
| Keputusan pembatalan angsuran | P | — | — |
| Denda/pemutihan | P | O bayar tanpa mengubah tarif | D tagihan |
| SHU | P dan pengesahan | — kecuali SOP mengizinkan | D hasil disahkan |
| Laporan keuangan umum | P | L laporan operasional terfilter | — |
| Pengaturan dan backup | P | — | — |

**Aturan wajib:** menyembunyikan menu bukan kontrol akses. Setiap route, controller/action, pengambilan record, ekspor, PDF, dan API wajib diperiksa server-side. Anggota hanya boleh melihat resource miliknya sendiri. Tolak akses yang tidak sah dengan 403/404 dan buat test negatif.

## 5. Design system dan responsive requirements

Arah visual: **navy + teal**, latar terang, kontras tinggi, sidebar tunggal, ruang antar-komponen konsisten, penyajian IDR jelas, status berwarna **dan** berlabel teks, font sistem atau font yang dapat di-*self-host*.

Komponen wajib: `AppShell`, `RoleAwareNavigation`, `PageHeader`, `StatCard`, `DataTable`, `SearchFilter`, `FormField`, `MoneyInput`, `StatusBadge`, `ConfirmDialog`, `Toast`, `EmptyState`, `LoadingState`, `ErrorState`.

Target *manual + automated visual QA*: 360/390/430 px (HP), 768/820 px (tablet), 1024/1280/1366/1440 px (laptop/desktop). Semua kontrol sentuh minimal ±44 px, fokus keyboard terlihat, tabel mobile menjadi kartu atau scroll horizontal yang jelas, tidak ada overflow horizontal tak sengaja, dialog dan drawer ramah keyboard, serta semua form menampilkan validasi eksplisit.

## 6. Strategi migrasi tanpa kehilangan data

1. **Baseline**: inventaris semua route dan endpoint, skema database termasuk indeks & FK, backup tersanitasi, fixtures, saldo laporan akhir periode, dan akses berkas. Dokumentasikan invarian keuangan.
2. **Foundation**: buat proyek Laravel terpisah pada branch baru atau direktori `next/`; setup CI, tests, session/auth, design tokens, layout responsif. Biarkan aplikasi lama tidak terganggu.
3. **Read-only parity**: tampilkan dashboard, profil, anggota, simpanan, pinjaman dengan pembacaan database *staging*. Cocokkan perhitungan dan query.
4. **Write parity**: implementasikan setoran/penarikan, pengajuan/persetujuan/pencairan, angsuran, denda, pelunasan, SHU menggunakan transaksi atomik dan audit log.
5. **Laporan/integrasi**: ekspor Excel/PDF, tanda tangan, upload KTP/KK, notifikasi, WhatsApp scheduler dengan deduplikasi pesan.
6. **Cutover**: dry-run migrasi; rekonsiliasi jumlah record, saldo, angsuran, status, dan SHA berkas; uji semua role/ukuran layar; backup, maintenance window, rollback plan, dan monitoring.

**Jangan menjalankan migrasi destructive ke database produksi sebelum verifikasi backup + rollback.** Jangan memublikasikan dump berisi data anggota, NIK, atau kredensial.

## 7. Acceptance criteria sebelum production

- Tidak ada duplikasi file halaman Admin/Teller untuk workflow yang identik; gunakan satu service/use case dengan policy berbeda.
- 100% route sensitif diuji otorisasi untuk tiga role termasuk akses melalui URL langsung, POST, API, export, PDF, dan ID milik pengguna lain.
- Semua transaksi finansial dibandingkan pada fixture lawas vs Laravel: nominal, bunga, denda, saldo, jadwal, rounding, posting kas, koreksi, pelunasan, SHU.
- Concurrent requests dan submit ulang tidak menyebabkan transaksi dobel; pembalikan dicatat melalui audit trail, bukan delete tanpa jejak.
- Password awal tetap diganti alur aktivasi; session aman, login dibatasi percobaan, CSRF, upload guard, dan logging bebas data sensitif.
- UI diuji pada breakpoint yang disebut di atas, contrast/keyboard/basic screen reader, error/empty/loading states.
- CI sukses pada test unit, feature, lint/format, build aset, dan smoke test staging.
- Ada baseline schema yang dapat direproduksi dari repo tanpa data pribadi.

## 8. Prototype

Prototype dashboard HTML dibuat sebagai artefak terpisah dalam percakapan ChatGPT (belum terintegrasi dengan Laravel atau repo). Perubahan role di prototype **hanya simulasi antarmuka**, bukan security control.

## 9. Keputusan yang dibutuhkan saat implementasi

Definisikan SOP akhir tiap permission, lingkungan hosting PHP 8.3+, baseline schema teranonimkan, metode migrasi data dan URL production, serta fase rilis yang meminimalkan downtime.

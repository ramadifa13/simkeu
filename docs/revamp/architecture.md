# Arsitektur dan arah UI/UX

Status: rancangan, belum implementasi. Versi dependency final menunggu spesifikasi hosting dan instalasi lockfile yang dapat diuji.

## Stack dan batas deployment

Laravel + React TypeScript + Inertia.js + Tailwind CSS + shadcn/ui dalam satu modular monolith. Starter kit resmi Laravel React cocok dengan stack ini. Kandidat Laravel 13 membutuhkan PHP >=8.3; shared hosting harus diverifikasi sebelum memilih versi. Referensi resmi diperiksa 22 Oktober 2026:

- https://laravel.com/docs/13.x/starter-kits
- https://laravel.com/docs/13.x/deployment

Build frontend dilakukan lokal/CI, hasil aset statis disertakan dalam paket deploy. Inertia tanpa SSR agar tidak membutuhkan proses Node di hosting. Document root hanya `public/`; source, `.env`, vendor, log, backup, dan dokumen privat di luar area publik. Session/cache berbasis file atau database; cron menjalankan pekerjaan terjadwal dengan lock. Tidak bergantung Redis, websocket, worker permanen, atau shell pada setiap request.

## Pembagian kode

| Area | Tanggung jawab |
|---|---|
| `app/Domain/Identity`, `Members` | Akun, role, status, profil, AGT/AGTJ, hubungan ketua |
| `Savings`, `Cash` | Setoran/penarikan, posting kas, koreksi, rekonsiliasi |
| `Loans`, `Installments`, `Penalties` | Pengajuan, approval, pencairan, jadwal, pembayaran, pembatalan, denda |
| `Collateral`, `LoanServices` | Dokumen jaminan, pelunasan, reschedule, lelang, prioritas, bonus |
| `ProfitSharing`, `Retail` | SHU aktif dan transaksi Waserda; kode tahunan terpisah menunggu keputusan |
| `Documents`, `Reports`, `Notifications` | Snapshot, cetak/export, query laporan, indikator dan WhatsApp |

Controller tipis → FormRequest + Policy → Action → domain calculator/query/model. Action adalah batas transaksi database, bukan komponen React. Domain calculator tidak membaca request/session dan menerima waktu acuan eksplisit. Model menggunakan nama tabel/primary key legacy pada fase kompatibilitas, setelah DDL asli tersedia. Jangan menambahkan repository abstraction untuk setiap model jika tidak memberi manfaat.

Satu Action per operasi bisnis, satu Query per kebutuhan baca yang dipakai layar dan export, satu kalkulator otoritatif di PHP. React meminta preview dari server; tidak menggandakan rumus dalam TypeScript. DTO nilai uang berupa string decimal, format Rupiah hanya di presentasi. Engine legacy tahunan tetap mempertahankan semantik hasil dan jadwal tersimpan; perubahan representasi decimal harus lolos differential test.

Policy menangani role + scope kepemilikan + status; `capabilities` yang dikirim Inertia hanya membantu UI, server tetap memeriksa setiap mutation/download. Auth username legacy, hash yang kompatibel, status aktif, dan pemisahan pencairan Teller tetap dipertahankan. Jangan mengaktifkan public registration dari starter kit.

## Integritas

Gunakan transaction + lock order konsisten + unique constraint untuk operasi yang terbukti unik setelah audit DDL/data. Klik ulang, retry jaringan, dan approval bersamaan tidak boleh menggandakan pembayaran. Outbox/notifikasi hanya setelah commit. Penyimpanan file butuh staged upload dan cleanup saat rollback. Cetak perjanjian menggunakan snapshot saat tanda tangan, bukan identitas/template terkini.

Cash posting dipusatkan setelah D-01–D-04 diputuskan; jangan menerapkan sistem akuntansi baru secara terselubung. Bentuk jurnal pembalik maupun perubahan saldo harus menghasilkan perilaku yang disepakati dan audit trail lengkap.

## Navigasi baru berdasarkan pekerjaan

| Ruang kerja | Isi | Pengurangan langkah |
|---|---|---|
| Ringkasan | Tugas hari ini dan antrean yang relevan terhadap role | Klik indikator langsung ke daftar terfilter |
| Anggota | Daftar dan detail dengan tab profil, simpanan, pinjaman, dokumen, histori | Satu detail, konteks anggota tidak hilang |
| Simpanan | Saldo dan mutasi; aksi setoran/penarikan | Form bersama dengan jenis transaksi eksplisit |
| Pinjaman | Pengajuan, menunggu keputusan, siap cair, aktif, lunas | Tab/filter status; satu detail pinjaman dan timeline |
| Tugas & persetujuan | Pengajuan, pembatalan, denda, pelunasan, jaminan, prioritas | Antrean bersama, setiap jenis tetap punya syarat dan policy sendiri |
| Waserda | Input barang, histori, detail transaksi | Baris item yang dapat ditambah, total server |
| SHU | Hak, belum dibayar, sudah dibayar, kuitansi | Satu layar dengan filter, bukan tiga salinan halaman |
| Laporan & administrasi | Laporan, ekspor, master, akun, pengaturan, backup sesuai role | Filter laporan digunakan juga oleh export |

Portal Anggota terpisah dalam shell yang sama: Ringkasan, Simpanan Saya, Pinjaman Saya, SHU Saya, Waserda Saya, Profil. Izin menulis tidak ditambahkan hanya karena UI baru mampu menampilkan form.

Detail pinjaman menjadi pusat konteks: ringkasan kontrak, jadwal, pembayaran/denda, jaminan, perjanjian, layanan, audit. Semua aksi menawarkan hasil dan prasyarat yang jelas. Pencairan berupa langkah Data & tanggal → Bukti & saksi → TTD → Ringkasan; tidak menghapus bukti atau TTD wajib untuk mempercepat form.

## Komponen reusable dan kriteria UX

`AppShell`, `PageHeader`, `DataTable`, `FilterBar`, `MoneyInput`, `MemberCombobox`, `StatusBadge`, `ApprovalPanel`, `EvidenceUpload`, `SignaturePad`, `TransactionSummary`, `AuditTimeline`, `EmptyState`, `ErrorSummary`, `ConfirmAction` memakai primitive shadcn/ui. Variant berbasis kebutuhan, bukan komponen duplikat perrole.

- Tipografi tenang, latar netral, satu warna aksi utama, angka rata kanan/tabular; status selalu teks + warna.
- Tabel desktop memiliki search, filter URL, pagination server, sorting kolom whitelist, dan pilihan export sesuai izin. Mobile memakai ringkasan baris/kartu dengan detail; nominal/status/aksi utama tetap terlihat.
- Form memiliki label jelas, contoh format, pesan error dekat field, focus ke error, draft input terjaga ketika validasi gagal. Ringkasan sebelum transaksi menampilkan nominal kas, denda yang telah dibayar, diskon, kredit lelang, dan hasil akhir.
- Tindakan finansial tidak memakai optimistic success. Tombol pending mencegah klik ganda; server tetap idempotent. Nominal berubah sejak preview → tampilkan ringkasan baru dan minta tinjauan ulang.
- Keyboard, focus trap dialog, kontras, target sentuh minimal 44px, viewport 360/768/1280px, tanpa horizontal scroll halaman. Signature/upload diuji pada perangkat sentuh.

Setiap halaman inventaris harus dipetakan ke layar/action baru atau alias route. Penggabungan halaman bukan alasan menghapus field, filter, cetak, bukti, histori, atau hak akses.

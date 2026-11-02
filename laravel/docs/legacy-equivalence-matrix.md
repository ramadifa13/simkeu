# Matriks Kesetaraan Halaman Native

Matriks ini adalah rancangan kontrak Fase 2, bukan route yang telah dibuat. Nama route dapat disempurnakan di Fase 3, tetapi policy, data, keluaran, dan target test tidak boleh hilang saat struktur navigasi diputuskan.

| Cakupan halaman native | Route Laravel yang direncanakan | Policy minimum | Tabel sumber utama | Keluaran | Target test |
| --- | --- | --- | --- | --- | --- |
| `index.php` | `landing` | publik | `koperasi`, `jenis_simpanan`, `jenis_pinjaman` aktif | — | landing content/accessibility |
| seluruh `*/dashboard.php` | `portal.{role}.dashboard` | role sendiri | sumber KPI sesuai `dashboard_helper.php` | — | scope dan angka KPI per role |
| `admin/anggota.php`, `teller/anggota.php`, `teller/tambah_anggota.php` | `portal.{role}.anggota.*` | staff view/create/update; admin delete | `anggota`, `users`, dokumen migrasi | `export_anggota.php` | CRUD, kode unik, upload, scope |
| `admin/teller.php` | `portal.admin.teller.*` | admin | `users`, `user_permissions` | — | policy, status dan reset akun |
| detail anggota pada admin/teller | `portal.{role}.anggota.show` + tab | staff view | anggota + simpanan/transaksi/pinjaman/SHU/audit | dokumen privat | relational read model |
| `jenis_simpanan.php` | `portal.admin.master.simpanan.*` | admin master | `jenis_simpanan` | — | validasi minimum/maksimum/status |
| `jenis_pinjaman.php`, `pengaturan_pinjaman.php` | `portal.admin.master.pinjaman.*` | admin master | `jenis_pinjaman`, `jenis_pinjaman_tarif`, `denda_tarif` | — | tarif, tenor, status, denda |
| `setoran.php`, `penarikan.php`, `get_saldo.php` | `portal.staff.simpanan.{setoran,penarikan}` | staff transaction | `simpanan`, `transaksi`, `kas` | bukti transaksi | saldo, race condition, audit |
| `pembelian.php`, `anggota/waserda.php` | `portal.{role}.waserda.*` | staff mutate / anggota own view | `waserda_transaksi`, transaksi/kas terkait | riwayat/bukti | scope, kas, nominal |
| `koreksi.php` | `portal.admin.koreksi.*` | admin correction | `transaksi`, `kas`, `simpanan`, `log_koreksi` | audit koreksi | reversal, saldo berantai |
| semua laporan simpanan/transaksi/kas/harian | `portal.{role}.laporan.{domain}` | role laporan | domain tabel terkait | PDF/Excel tabel terfilter | filter, export authorization, totals |
| `pengajuan.php`, `teller/pengajuan_pinjaman.php` | `portal.staff.pinjaman.pengajuan.*` | staff create/view | `pengajuan_pinjaman`, jenis, anggota | draft/perjanjian | validation/status |
| `persetujuan.php` | `portal.admin.pinjaman.persetujuan.*` | admin approve/reject | `pengajuan_pinjaman`, `layanan_prioritas` | — | valid transitions/audit |
| `pencairan.php`, `cetak_slip_pencairan.php` | `portal.staff.pinjaman.pencairan.*` | staff disburse; admin special decision | pengajuan, pinjaman, jaminan, kas | slip PDF | required photo/document, transaction |
| `angsuran.php`, `detail_angsuran.php`, `cetak_bukti_angsuran.php`, `cetak_buku_angsuran.php` | `portal.staff.pinjaman.angsuran.*` | staff pay; admin cancel/override | pinjaman, angsuran, transaksi, kas | receipt/book PDF | schedule, payment, duplicate lock |
| `denda.php`, `laporan_denda.php` | `portal.staff.pinjaman.denda.*` | staff view; admin override | angsuran, denda_tarif, layanan denda | report | calculator + policy |
| `pelunasan.php`, `cetak_bukti_pelunasan.php`, `laporan_lunas.php` | `portal.staff.pinjaman.pelunasan.*` | staff pay; admin discount/reversal | pinjaman, angsuran, pelunasan, layanan | receipt PDF | settlement state machine |
| `closing_angsuran.php` | `portal.staff.pinjaman.closing.*` | staff view; admin lock/unlock | closing tables, angsuran | closing report | locked period enforcement |
| `cetak_draft_perjanjian.php`, `cetak_perjanjian.php` | `portal.staff.pinjaman.perjanjian.*` | staff view; admin configuration | pengaturan/perjanjian/lampiran | PDF | rendering/authorization |
| `layanan.php`, `detail_layanan.php`, `kwitansi_layanan.php` | `portal.staff.layanan.*` | action-specific policy | all `layanan_*`, jaminan, pinjaman, kas | receipt PDF | native services integration suite |
| `shu.php`, `shu_anggota.php`, `shu_laporan.php`, `anggota/shu_saya.php` | `portal.{role}.shu.*` | admin mutate; anggota own view | semua `shu_*`, periode, transaksi | PDF/receipt | SHU lifecycle/scope |
| `kwitansi_shu.php`, `anggota/kwitansi_shu.php`, `cetak_shu_saya.php` | `portal.{role}.shu.pembayaran.show` | admin or owner | `shu_pembayaran`, `shu_anggota` | receipt PDF | authorization/immutable receipt |
| `pengaturan.php`, `profil.php`, password pages | `portal.{role}.pengaturan.*`, `profile.*` | owner; admin config | `koperasi`, `settings`, `users`, perjanjian | — | password/profile/config policy |
| `import_migrasi.php`, template/download, backup | `portal.admin.migrasi.*`, `portal.admin.backup` | admin only | seluruh migrasi/cadangan | XLSX/template/backup | idempotent import, protected export |

## Halaman cetak/ekspor yang tidak menjadi menu sendiri

Seluruh `cetak_*`, `export_*`, `download_template_migrasi.php`, `kwitansi_*`, dan `get_saldo.php` diperlakukan sebagai keluaran atau endpoint terotorisasi milik domainnya. Keluaran tersebut tidak boleh membuka akses hanya berdasarkan ID URL; semua memakai policy resource yang sama dengan layar asal dan memakai filter yang disimpan/tervalidasi.

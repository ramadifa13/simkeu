# Inventaris baseline legacy

Commit `6eab4d2fcbed51d831df6a913ebb04760f18bc35`. Dibuat dengan `python3 scripts/revamp/inventory.py`.

**Pemindaian statis otomatis; tidak berarti semua halaman sudah dibaca mendalam, dijalankan, atau lulus parity.** Guard/action/table di bawah merupakan kandidat regex; guard pada include, dynamic SQL, dan field form dapat mengubah maknanya.

393 file tracked; 179 di luar TCPDF; 159 file PHP aplikasi/test; 101 kandidat entrypoint web.

## Jumlah file per area (tanpa TCPDF)

| Area | File |
|---|---:|
| admin | 51 |
| anggota | 11 |
| api | 1 |
| assets | 11 |
| config | 5 |
| cron | 1 |
| database | 2 |
| includes | 42 |
| root | 9 |
| scripts | 1 |
| storage | 2 |
| teller | 33 |
| tests | 10 |

## Kandidat halaman dan jejak source

Setiap baris perlu review runtime, policy, filter, output, dan pemetaan layar baru.

| Source | Baris | Guard lokal terdeteksi | Field POST terdeteksi | Include/require literal |
|---|---:|---|---|---|
| `admin/anggota.php` | 1340 | melalui include / review | add, alamat, delete, edit, id, jenis_kelamin, kategori_anggota, kategori_shu, ketua_kelompok_id, ktp, nama_lengkap, pekerjaan, rekening_bank, reset_password, status, tanggal_daftar, tanggal_lahir, telepon, tempat_lahir | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../includes/form_dokumen_anggota.php, /../includes/modal_dokumen.php |
| `admin/angsuran.php` | 1123 | isAdmin(, isTeller( | ajukan_pembatalan, alasan, batalkan_bayar, bayar, catatan, denda, id, keputusan, metode, pengajuan_id, putuskan_pembatalan | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../config/bootstrap.php, /../includes/denda_dashboard.php |
| `admin/backup.php` | 534 | isAdmin( | backup_database, confirmation, delete, reset_data | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/cetak_bukti_angsuran.php` | 10 | isAdmin( | — | ../config/config.php, ../includes/auth.php, /../includes/bukti_angsuran.php |
| `admin/cetak_bukti_pelunasan.php` | 10 | isAdmin( | — | ../config/config.php, ../includes/auth.php, /../includes/bukti_pelunasan.php |
| `admin/cetak_buku_angsuran.php` | 7 | isAdmin( | — | /../includes/auth.php, /../includes/buku_angsuran.php |
| `admin/cetak_laporan_angsuran.php` | 404 | isAdmin(, isTeller( | — | ../includes/auth.php, ../tcpdf/tcpdf.php, /../config/bootstrap.php |
| `admin/cetak_laporan_kas.php` | 551 | isAdmin( | — | ../config/config.php, ../includes/auth.php, ../tcpdf/tcpdf.php |
| `admin/cetak_laporan_pinjaman.php` | 340 | isAdmin( | — | ../includes/auth.php, ../tcpdf/tcpdf.php, /../config/bootstrap.php |
| `admin/cetak_laporan_simpanan.php` | 346 | isAdmin( | — | ../config/config.php, ../includes/auth.php, ../tcpdf/tcpdf.php |
| `admin/cetak_laporan_transaksi.php` | 222 | melalui include / review | — | ../config/config.php, ../includes/auth.php |
| `admin/cetak_pdf_transaksi.php` | 433 | isAdmin( | — | ../config/config.php, ../includes/auth.php, ../tcpdf/tcpdf.php |
| `admin/cetak_perjanjian.php` | 4 | isAdmin( | — | ../config/config.php, ../includes/auth.php, ../includes/cetak_perjanjian.php |
| `admin/cetak_slip_pencairan.php` | 11 | isAdmin(, isTeller( | — | ../config/config.php, ../includes/auth.php, /../includes/slip_pencairan.php |
| `admin/dashboard.php` | 1261 | isAdmin( | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../includes/layanan_dashboard.php |
| `admin/denda.php` | 7 | isAdmin(, isTeller( | — | /../config/config.php, /../includes/auth.php |
| `admin/detail_anggota.php` | 612 | melalui include / review | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../includes/kartu_dokumen_anggota.php, /../includes/modal_dokumen.php |
| `admin/export_anggota.php` | 228 | melalui include / review | — | ../config/config.php, ../includes/auth.php |
| `admin/export_excel_angsuran.php` | 337 | isAdmin(, isTeller( | — | ../includes/auth.php, /../config/bootstrap.php |
| `admin/export_excel_pinjaman.php` | 272 | isAdmin( | — | ../includes/auth.php, /../config/bootstrap.php |
| `admin/export_excel_simpanan.php` | 302 | isAdmin( | — | ../config/config.php, ../includes/auth.php |
| `admin/export_excel_transaksi.php` | 420 | isAdmin( | — | ../config/config.php, ../includes/auth.php |
| `admin/export_kas.php` | 407 | isAdmin( | — | ../config/config.php, ../includes/auth.php |
| `admin/get_saldo.php` | 114 | melalui include / review | — | ../includes/auth.php, /../config/bootstrap.php |
| `admin/jenis_pinjaman.php` | 684 | isAdmin( | add, delete, deskripsi, edit, frekuensi, id, kode_jenis, maksimal_pinjaman, memerlukan_jaminan, nama_jenis, status, tarif_bunga, tarif_tenor | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../includes/form_tarif_pinjaman.php |
| `admin/jenis_simpanan.php` | 574 | melalui include / review | add, bagi_hasil, delete, deskripsi, edit, id, kode_jenis, maksimum, minimum, nama_jenis, status | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/koreksi.php` | 324 | melalui include / review | jumlah_baru, keterangan_koreksi, koreksi, transaksi_id | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/kwitansi_layanan.php` | 1 | melalui include / review | — | /../includes/kwitansi_layanan.php |
| `admin/kwitansi_shu.php` | 2 | melalui include / review | — | /../includes/kwitansi_shu.php |
| `admin/laporan_anggota.php` | 611 | isAdmin( | — | ../config/config.php, ../includes/auth.php, ../tcpdf/tcpdf.php |
| `admin/laporan_angsuran.php` | 564 | isAdmin(, isTeller( | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/laporan_denda.php` | 3 | melalui include / review | — | /../includes/layanan_page.php |
| `admin/laporan_kas.php` | 1100 | isAdmin( | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/laporan_lunas.php` | 3 | melalui include / review | — | /../includes/layanan_page.php |
| `admin/laporan_pinjaman.php` | 444 | isAdmin( | — | ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../config/bootstrap.php |
| `admin/laporan_simpanan.php` | 477 | isAdmin( | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/laporan_transaksi.php` | 653 | isAdmin( | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/layanan.php` | 1 | melalui include / review | — | /../includes/layanan_page.php |
| `admin/pelunasan.php` | 493 | isAdmin( | catatan, id, jumlah, jumlah_pelunasan, keterangan, lunaskan, metode | ../config/config.php, ../includes/auth.php, ../includes/filter_pelunasan.php, ../includes/header.php, ../includes/sidebar.php, /../config/bootstrap.php, /../includes/layanan_actions.php |
| `admin/pembelian.php` | 713 | isAdmin( | anggota_id, harga, jumlah, metode_bayar, nama_barang, proses_waserda | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/penarikan.php` | 475 | melalui include / review | anggota_id, jenis_simpanan_id, jumlah, keterangan, proses_penarikan | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/pencairan.php` | 461 | isAdmin( | cairkan | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../config/bootstrap.php, /../includes/modal_dokumen.php |
| `admin/pengajuan.php` | 572 | isAdmin( | catatan_admin, id, setujui, tolak | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../config/bootstrap.php |
| `admin/pengaturan.php` | 502 | isAdmin( | alamat, alamat_pihak_pertama, change_password, confirm_password, current_password, email, isi_lengkap, jabatan_admin, nama, nama_pihak_pertama, new_password, nik_pihak_pertama, rekening_bank, telepon, telepon_pihak_pertama, update_koperasi, update_perjanjian, upload_logo | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/pengaturan_pinjaman.php` | 265 | isAdmin( | bagi_hasil, simpan_tarif_denda, tarif_denda, tarif_jumlah, update_bagi_hasil | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/persetujuan.php` | 740 | melalui include / review | approve, catatan_admin, delete, id, reject | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/setoran.php` | 506 | melalui include / review | anggota_id, jenis_simpanan_id, jumlah, keterangan, proses_setoran | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `admin/shu.php` | 4 | melalui include / review | — | /../includes/auth.php, /../includes/shu_page.php |
| `admin/shu_anggota.php` | 4 | melalui include / review | — | /../includes/auth.php, /../includes/shu_page.php |
| `admin/shu_laporan.php` | 4 | melalui include / review | — | /../includes/auth.php, /../includes/shu_page.php |
| `admin/teller.php` | 351 | melalui include / review | add, edit, id, nama_lengkap, password, reset_password, status, username | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `anggota/cetak_buku.php` | 631 | checkAnggota( | — | ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, ../tcpdf/tcpdf.php |
| `anggota/cetak_shu_saya.php` | 5 | melalui include / review | — | /../includes/auth.php, /../includes/shu_pinjaman_helper.php, /../tcpdf/tcpdf.php |
| `anggota/dashboard.php` | 761 | checkAnggota( | — | ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `anggota/ganti_password.php` | 351 | checkAnggota( | ganti_password, konfirmasi_password, password_baru, password_lama | ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `anggota/kwitansi_shu.php` | 2 | melalui include / review | — | /../includes/kwitansi_shu.php |
| `anggota/pinjaman.php` | 936 | checkAnggota( | — | ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `anggota/profil.php` | 113 | checkAnggota( | — | ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `anggota/riwayat_simpanan.php` | 566 | checkAnggota( | — | ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `anggota/shu_saya.php` | 6 | melalui include / review | — | /../includes/auth.php, /../includes/header.php, /../includes/shu_pinjaman_helper.php, /../includes/sidebar.php |
| `anggota/simpanan.php` | 264 | checkAnggota( | — | ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `anggota/waserda.php` | 314 | isAnggota( | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `api/notifikasi_pinjaman.php` | 21 | melalui include / review | — | ../config/config.php, ../includes/auth.php |
| `index.php` | 21 | melalui include / review | — | /config/config.php, /includes/auth.php, /includes/brand.php |
| `login.php` | 692 | melalui include / review | username | /config/bootstrap.php, /includes/brand.php, config/config.php, config/database.php, includes/auth.php |
| `login_process.php` | 101 | melalui include / review | login_csrf, password, role, username | /config/bootstrap.php, config/config.php, config/database.php |
| `logout.php` | 12 | melalui include / review | — | /config/bootstrap.php |
| `notifikasi.php` | 52 | melalui include / review | — | /includes/auth.php |
| `teller/anggota.php` | 916 | melalui include / review | aksi, alamat, delete, edit, email, id, jenis_kelamin, kategori_anggota, kategori_shu, ketua_kelompok_id, ktp, nama_lengkap, pekerjaan, rekening_bank, reset_password, status, tanggal_daftar, tanggal_lahir, telepon, tempat_lahir | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../includes/form_dokumen_anggota.php, /../includes/modal_dokumen.php |
| `teller/angsuran.php` | 1123 | isAdmin(, isTeller( | ajukan_pembatalan, alasan, batalkan_bayar, bayar, catatan, denda, id, keputusan, metode, pengajuan_id, putuskan_pembatalan | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../config/bootstrap.php, /../includes/denda_dashboard.php |
| `teller/cetak_bukti_angsuran.php` | 10 | isTeller( | — | ../config/config.php, ../includes/auth.php, /../includes/bukti_angsuran.php |
| `teller/cetak_bukti_pelunasan.php` | 10 | isTeller( | — | ../config/config.php, ../includes/auth.php, /../includes/bukti_pelunasan.php |
| `teller/cetak_buku_angsuran.php` | 8 | isTeller( | — | /../includes/auth.php, /../includes/buku_angsuran.php |
| `teller/cetak_draft_perjanjian.php` | 40 | isTeller( | — | ../config/config.php, ../includes/auth.php |
| `teller/cetak_laporan_harian.php` | 423 | isAdmin(, isTeller( | — | ../includes/auth.php, ../tcpdf/tcpdf.php, /../config/bootstrap.php |
| `teller/cetak_laporan_simpanan_teller.php` | 389 | isAdmin(, isTeller( | — | ../includes/auth.php, ../tcpdf/tcpdf.php, /../config/bootstrap.php |
| `teller/cetak_perjanjian.php` | 4 | isTeller( | — | ../config/config.php, ../includes/auth.php, ../includes/cetak_perjanjian.php |
| `teller/cetak_slip_pencairan.php` | 11 | isTeller( | — | ../config/config.php, ../includes/auth.php, /../includes/slip_pencairan.php |
| `teller/dashboard.php` | 1297 | checkTeller( | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../includes/layanan_dashboard.php |
| `teller/denda.php` | 7 | isAdmin(, isTeller( | — | /../config/config.php, /../includes/auth.php |
| `teller/detail_anggota.php` | 475 | melalui include / review | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../includes/kartu_dokumen_anggota.php, /../includes/modal_dokumen.php |
| `teller/export_excel_harian.php` | 353 | isAdmin(, isTeller( | — | ../includes/auth.php, /../config/bootstrap.php |
| `teller/export_excel_simpanan_teller.php` | 289 | isAdmin(, isTeller( | — | ../includes/auth.php, /../config/bootstrap.php |
| `teller/get_saldo.php` | 115 | melalui include / review | — | ../includes/auth.php, /../config/bootstrap.php |
| `teller/kwitansi_layanan.php` | 1 | melalui include / review | — | /../includes/kwitansi_layanan.php |
| `teller/laporan_denda.php` | 3 | melalui include / review | — | /../includes/layanan_page.php |
| `teller/laporan_harian.php` | 820 | isTeller( | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `teller/laporan_lunas.php` | 3 | melalui include / review | — | /../includes/layanan_page.php |
| `teller/laporan_simpanan.php` | 597 | isTeller( | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `teller/layanan.php` | 1 | melalui include / review | — | /../includes/layanan_page.php |
| `teller/pelunasan.php` | 524 | melalui include / review | catatan, id, jumlah, jumlah_pelunasan, keterangan, lunaskan, metode | ../config/config.php, ../includes/auth.php, ../includes/filter_pelunasan.php, ../includes/header.php, ../includes/sidebar.php, /../config/bootstrap.php, /../includes/layanan_actions.php |
| `teller/pembelian.php` | 616 | isTeller( | anggota_id, harga, jumlah, metode_bayar, nama_barang, proses_waserda | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `teller/penarikan.php` | 581 | melalui include / review | anggota_id, jenis_simpanan_id, jumlah, keterangan, proses_penarikan | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `teller/pencairan.php` | 1106 | isTeller( | id, nama_saksi_1, nama_saksi_2, proses_pencairan, sudah_dibayar, tanggal_pencairan | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../config/bootstrap.php, /../includes/modal_dokumen.php |
| `teller/pengajuan.php` | 1105 | isTeller( | ajukan_pinjaman, anggota_id, fasilitas_prioritas, frekuensi, hari_angsuran, jenis_pinjaman_id, jumlah_pinjaman, kategori_jaminan, kategori_shu, ketua_kelompok_id, tanggal_pengajuan, tenor, tujuan_pinjaman | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../config/bootstrap.php |
| `teller/pengajuan_pinjaman.php` | 4 | isTeller( | — | /../includes/auth.php |
| `teller/persetujuan.php` | 370 | isTeller( | — | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php, /../config/bootstrap.php |
| `teller/profil.php` | 602 | melalui include / review | email, konfirmasi_password, nama_lengkap, password_baru, password_lama, telepon, update_password, update_profil | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `teller/setoran.php` | 728 | melalui include / review | anggota_id, jenis_simpanan_id, jumlah, keterangan, proses_setoran | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |
| `teller/tambah_anggota.php` | 12 | checkTeller( | — | ../includes/auth.php |
| `teller/ubah_password.php` | 439 | isTeller( | confirm_password, current_password, new_password | ../config/config.php, ../includes/auth.php, ../includes/header.php, ../includes/sidebar.php |

## Referensi tabel SQL — perlu verifikasi DDL

Nama yang tidak didefinisikan CREATE pada source bukan otomatis tabel hilang di produksi. Parser ini tidak memahami dynamic SQL; hasil tidak boleh dipakai membuat schema otomatis.

| Kandidat tabel | CREATE terlihat | Source pemakai |
|---|---|---|
| `anggota` | Tidak terdeteksi | admin/anggota.php, admin/angsuran.php, admin/backup.php, admin/cetak_laporan_angsuran.php, admin/cetak_laporan_kas.php, admin/cetak_laporan_pinjaman.php, admin/cetak_laporan_simpanan.php, admin/cetak_laporan_transaksi.php, admin/cetak_pdf_transaksi.php, admin/dashboard.php, admin/detail_anggota.php, admin/export_anggota.php, admin/export_excel_angsuran.php, admin/export_excel_pinjaman.php, admin/export_excel_simpanan.php, admin/export_excel_transaksi.php, admin/export_kas.php, admin/get_saldo.php, admin/koreksi.php, admin/laporan_anggota.php, admin/laporan_angsuran.php, admin/laporan_kas.php, admin/laporan_pinjaman.php, admin/laporan_simpanan.php, admin/laporan_transaksi.php, admin/pelunasan.php, admin/pembelian.php, admin/penarikan.php, admin/pencairan.php, admin/pengajuan.php, admin/pengaturan.php, admin/persetujuan.php, admin/setoran.php, anggota/cetak_buku.php, anggota/cetak_shu_saya.php, anggota/dashboard.php, anggota/pinjaman.php, anggota/profil.php, anggota/riwayat_simpanan.php, anggota/shu_saya.php, anggota/simpanan.php, anggota/waserda.php, cron/wa_jatuh_tempo.php, database/migrations/MIGRASI_UNIVERSAL.sql, includes/anggota_helper.php, includes/bukti_angsuran.php, includes/bukti_pelunasan.php, includes/buku_angsuran.php, includes/denda_dashboard.php, includes/denda_report_panel.php, includes/dokumen_anggota.php, includes/kwitansi_layanan.php, includes/kwitansi_shu.php, includes/layanan_actions.php, includes/layanan_bonus_panel.php, includes/layanan_dashboard.php, includes/layanan_followup_panel.php, includes/layanan_helper.php, includes/layanan_page.php, includes/pinjaman_helper.php, includes/shu_helper.php, includes/shu_page.php, includes/shu_pinjaman_helper.php, includes/sidebar.php, includes/slip_pencairan.php, teller/anggota.php, teller/angsuran.php, teller/cetak_draft_perjanjian.php, teller/cetak_laporan_harian.php, teller/cetak_laporan_simpanan_teller.php, teller/dashboard.php, teller/detail_anggota.php, teller/export_excel_harian.php, teller/export_excel_simpanan_teller.php, teller/get_saldo.php, teller/laporan_harian.php, teller/laporan_simpanan.php, teller/pelunasan.php, teller/pembelian.php, teller/penarikan.php, teller/pencairan.php, teller/pengajuan.php, teller/persetujuan.php, teller/profil.php, teller/setoran.php, tests/shu_test.php |
| `angsuran` | Tidak terdeteksi | admin/angsuran.php, admin/cetak_laporan_angsuran.php, admin/cetak_laporan_pinjaman.php, admin/dashboard.php, admin/export_excel_angsuran.php, admin/export_excel_pinjaman.php, admin/laporan_anggota.php, admin/laporan_angsuran.php, admin/laporan_pinjaman.php, admin/pelunasan.php, admin/pencairan.php, anggota/dashboard.php, anggota/pinjaman.php, cron/wa_jatuh_tempo.php, database/fixtures/status_test_data.sql, database/migrations/MIGRASI_UNIVERSAL.sql, includes/angsuran_helper.php, includes/bukti_angsuran.php, includes/bukti_pelunasan.php, includes/buku_angsuran.php, includes/denda_dashboard.php, includes/denda_helper.php, includes/denda_report_panel.php, includes/kwitansi_layanan.php, includes/layanan_actions.php, includes/layanan_dashboard.php, includes/layanan_helper.php, includes/layanan_lanjutan.php, includes/layanan_page.php, includes/pinjaman_helper.php, includes/shu_helper.php, includes/shu_pinjaman_helper.php, teller/angsuran.php, teller/cetak_laporan_harian.php, teller/dashboard.php, teller/export_excel_harian.php, teller/laporan_harian.php, teller/pelunasan.php, teller/pencairan.php, tests/layanan_integration_test.php |
| `denda_tarif` | database/migrations/MIGRASI_UNIVERSAL.sql | admin/pengaturan_pinjaman.php, database/migrations/MIGRASI_UNIVERSAL.sql, includes/denda_helper.php |
| `information_schema` | Tidak terdeteksi | admin/backup.php, database/migrations/MIGRASI_UNIVERSAL.sql, includes/anggota_helper.php, includes/pinjaman_helper.php |
| `jaminan_pinjaman` | database/migrations/MIGRASI_UNIVERSAL.sql | admin/pencairan.php, admin/pengajuan.php, anggota/pinjaman.php, database/fixtures/status_test_data.sql, database/migrations/MIGRASI_UNIVERSAL.sql, includes/kwitansi_layanan.php, includes/layanan_actions.php, includes/layanan_helper.php, includes/layanan_lanjutan.php, includes/pinjaman_helper.php, teller/pelunasan.php, teller/pencairan.php, teller/pengajuan.php |
| `jenis_pinjaman` | Tidak terdeteksi | admin/angsuran.php, admin/cetak_laporan_angsuran.php, admin/cetak_laporan_kas.php, admin/cetak_laporan_pinjaman.php, admin/dashboard.php, admin/detail_anggota.php, admin/export_excel_angsuran.php, admin/export_excel_pinjaman.php, admin/export_kas.php, admin/jenis_pinjaman.php, admin/laporan_anggota.php, admin/laporan_angsuran.php, admin/laporan_kas.php, admin/laporan_pinjaman.php, admin/laporan_transaksi.php, admin/pelunasan.php, admin/pencairan.php, admin/pengajuan.php, admin/persetujuan.php, anggota/pinjaman.php, database/fixtures/status_test_data.sql, database/migrations/MIGRASI_UNIVERSAL.sql, includes/bukti_pelunasan.php, includes/buku_angsuran.php, includes/denda_helper.php, includes/denda_report_panel.php, includes/layanan_helper.php, includes/layanan_page.php, includes/shu_pinjaman_helper.php, includes/slip_pencairan.php, index.php, teller/angsuran.php, teller/cetak_draft_perjanjian.php, teller/cetak_laporan_harian.php, teller/dashboard.php, teller/detail_anggota.php, teller/export_excel_harian.php, teller/laporan_harian.php, teller/pelunasan.php, teller/pencairan.php, teller/pengajuan.php, teller/persetujuan.php |
| `jenis_pinjaman_tarif` | database/migrations/MIGRASI_UNIVERSAL.sql | admin/jenis_pinjaman.php, includes/pinjaman_helper.php |
| `jenis_simpanan` | Tidak terdeteksi | admin/anggota.php, admin/angsuran.php, admin/cetak_laporan_kas.php, admin/cetak_laporan_simpanan.php, admin/cetak_laporan_transaksi.php, admin/cetak_pdf_transaksi.php, admin/dashboard.php, admin/detail_anggota.php, admin/export_excel_simpanan.php, admin/export_excel_transaksi.php, admin/export_kas.php, admin/get_saldo.php, admin/jenis_simpanan.php, admin/koreksi.php, admin/laporan_anggota.php, admin/laporan_kas.php, admin/laporan_simpanan.php, admin/laporan_transaksi.php, admin/penarikan.php, admin/pengaturan.php, admin/pengaturan_pinjaman.php, admin/setoran.php, anggota/cetak_buku.php, anggota/dashboard.php, anggota/riwayat_simpanan.php, anggota/simpanan.php, includes/layanan_helper.php, includes/layanan_lanjutan.php, includes/shu_helper.php, index.php, teller/anggota.php, teller/angsuran.php, teller/cetak_laporan_harian.php, teller/cetak_laporan_simpanan_teller.php, teller/dashboard.php, teller/detail_anggota.php, teller/export_excel_harian.php, teller/export_excel_simpanan_teller.php, teller/get_saldo.php, teller/laporan_harian.php, teller/laporan_simpanan.php, teller/penarikan.php, teller/setoran.php, tests/shu_test.php |
| `kas` | Tidak terdeteksi | admin/angsuran.php, admin/backup.php, admin/cetak_laporan_kas.php, admin/cetak_pdf_transaksi.php, admin/dashboard.php, admin/export_excel_transaksi.php, admin/export_kas.php, admin/koreksi.php, admin/laporan_kas.php, admin/laporan_transaksi.php, admin/pembelian.php, admin/penarikan.php, admin/setoran.php, includes/angsuran_helper.php, includes/layanan_helper.php, includes/layanan_lanjutan.php, includes/shu_helper.php, teller/angsuran.php, teller/cetak_laporan_harian.php, teller/dashboard.php, teller/export_excel_harian.php, teller/laporan_harian.php, teller/pembelian.php, teller/penarikan.php, teller/setoran.php, tests/layanan_integration_test.php, tests/shu_test.php |
| `koperasi` | Tidak terdeteksi | admin/angsuran.php, admin/cetak_laporan_angsuran.php, admin/cetak_laporan_kas.php, admin/cetak_laporan_pinjaman.php, admin/cetak_laporan_simpanan.php, admin/cetak_pdf_transaksi.php, admin/export_anggota.php, admin/export_excel_angsuran.php, admin/export_excel_pinjaman.php, admin/export_excel_simpanan.php, admin/export_excel_transaksi.php, admin/export_kas.php, admin/laporan_anggota.php, admin/penarikan.php, admin/pengaturan.php, admin/setoran.php, anggota/cetak_buku.php, anggota/cetak_shu_saya.php, includes/bukti_angsuran.php, includes/bukti_pelunasan.php, includes/buku_angsuran.php, includes/header.php, includes/kwitansi_layanan.php, includes/kwitansi_shu.php, includes/layanan_helper.php, includes/layanan_lanjutan.php, includes/shu_helper.php, includes/sidebar.php, includes/slip_pencairan.php, index.php, login.php, teller/angsuran.php, teller/cetak_draft_perjanjian.php, teller/cetak_laporan_harian.php, teller/cetak_laporan_simpanan_teller.php, teller/export_excel_harian.php, teller/export_excel_simpanan_teller.php, teller/penarikan.php, teller/pencairan.php, teller/setoran.php |
| `lampiran_perjanjian` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/cetak_perjanjian.php, teller/pencairan.php |
| `layanan_alokasi_lelang` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/layanan_lanjutan.php |
| `layanan_audit` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/layanan_helper.php, includes/layanan_page.php |
| `layanan_bayar_denda` | database/migrations/MIGRASI_UNIVERSAL.sql | admin/angsuran.php, database/migrations/MIGRASI_UNIVERSAL.sql, includes/denda_dashboard.php, includes/denda_report_panel.php, includes/layanan_actions.php, includes/layanan_dashboard.php, includes/layanan_helper.php, includes/layanan_lanjutan.php, includes/layanan_page.php, teller/angsuran.php, tests/layanan_integration_test.php, tests/notifikasi_integration_test.php |
| `layanan_bonus` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/layanan_bonus_panel.php, includes/layanan_detail_lanjutan.php, includes/layanan_lanjutan.php, includes/layanan_page.php |
| `layanan_jaminan_kembali` | database/migrations/MIGRASI_UNIVERSAL.sql | anggota/pinjaman.php, database/fixtures/status_test_data.sql, database/migrations/MIGRASI_UNIVERSAL.sql, includes/angsuran_helper.php, includes/layanan_actions.php, includes/layanan_helper.php, includes/layanan_page.php |
| `layanan_laporan_macet` | database/migrations/MIGRASI_UNIVERSAL.sql | database/fixtures/status_test_data.sql, includes/layanan_actions.php, includes/layanan_page.php, tests/layanan_integration_test.php |
| `layanan_lelang` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/angsuran_helper.php, includes/layanan_actions.php, includes/layanan_detail_lanjutan.php, includes/layanan_lanjutan.php |
| `layanan_pelunasan` | database/migrations/MIGRASI_UNIVERSAL.sql | database/fixtures/status_test_data.sql, includes/bukti_pelunasan.php, includes/layanan_actions.php, includes/layanan_helper.php, includes/layanan_page.php, tests/layanan_integration_test.php |
| `layanan_pembayaran` | database/migrations/MIGRASI_UNIVERSAL.sql | admin/angsuran.php, database/migrations/MIGRASI_UNIVERSAL.sql, includes/angsuran_helper.php, includes/bukti_angsuran.php, includes/layanan_actions.php, includes/layanan_dashboard.php, includes/layanan_lanjutan.php, includes/layanan_page.php, includes/shu_helper.php, teller/angsuran.php, tests/layanan_integration_test.php |
| `layanan_pengajuan_hapus_denda` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/layanan_detail_lanjutan.php, includes/layanan_lanjutan.php |
| `layanan_pengaturan` | database/migrations/MIGRASI_UNIVERSAL.sql | admin/angsuran.php, database/migrations/MIGRASI_UNIVERSAL.sql, includes/bukti_pelunasan.php, includes/denda_helper.php, includes/kwitansi_layanan.php, includes/layanan_actions.php, includes/layanan_lanjutan.php, includes/layanan_page.php, teller/angsuran.php |
| `layanan_prioritas` | database/migrations/MIGRASI_UNIVERSAL.sql | database/fixtures/status_test_data.sql, database/migrations/MIGRASI_UNIVERSAL.sql, includes/layanan_actions.php, includes/layanan_lanjutan.php, includes/layanan_page.php |
| `layanan_repeat_order` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/layanan_helper.php, includes/layanan_lanjutan.php |
| `lengkap` | Tidak terdeteksi | admin/pencairan.php |
| `log_koreksi` | admin/koreksi.php | admin/koreksi.php, includes/angsuran_helper.php |
| `nomor` | Tidak terdeteksi | cron/wa_jatuh_tempo.php |
| `pelunasan_dini` | database/migrations/MIGRASI_UNIVERSAL.sql | database/migrations/MIGRASI_UNIVERSAL.sql, includes/layanan_actions.php |
| `pembelian_indihome` | Tidak terdeteksi | includes/anggota_helper.php |
| `pembelian_listrik` | Tidak terdeteksi | includes/anggota_helper.php |
| `pengajuan_pembatalan_angsuran` | database/migrations/MIGRASI_UNIVERSAL.sql | admin/angsuran.php, database/migrations/MIGRASI_UNIVERSAL.sql, includes/angsuran_helper.php, includes/layanan_helper.php, teller/angsuran.php |
| `pengajuan_pinjaman` | Tidak terdeteksi | admin/angsuran.php, admin/dashboard.php, admin/jenis_pinjaman.php, admin/pencairan.php, admin/pengajuan.php, admin/persetujuan.php, anggota/pinjaman.php, database/fixtures/status_test_data.sql, database/migrations/MIGRASI_UNIVERSAL.sql, includes/anggota_helper.php, includes/bukti_pelunasan.php, includes/layanan_helper.php, includes/pinjaman_helper.php, includes/slip_pencairan.php, teller/angsuran.php, teller/cetak_draft_perjanjian.php, teller/dashboard.php, teller/pencairan.php, teller/pengajuan.php, teller/persetujuan.php |
| `pengaturan_perjanjian` | database/migrations/MIGRASI_UNIVERSAL.sql | admin/pengaturan.php, database/migrations/MIGRASI_UNIVERSAL.sql, includes/perjanjian_helper.php |
| `perjanjian_pinjaman` | database/migrations/MIGRASI_UNIVERSAL.sql | admin/pencairan.php, includes/cetak_perjanjian.php, teller/pencairan.php |
| `pinjaman` | Tidak terdeteksi | admin/angsuran.php, admin/cetak_laporan_angsuran.php, admin/cetak_laporan_kas.php, admin/cetak_laporan_pinjaman.php, admin/cetak_pdf_transaksi.php, admin/dashboard.php, admin/detail_anggota.php, admin/export_anggota.php, admin/export_excel_angsuran.php, admin/export_excel_pinjaman.php, admin/export_excel_transaksi.php, admin/export_kas.php, admin/jenis_pinjaman.php, admin/laporan_anggota.php, admin/laporan_angsuran.php, admin/laporan_kas.php, admin/laporan_pinjaman.php, admin/laporan_transaksi.php, admin/pelunasan.php, admin/pencairan.php, admin/pengajuan.php, admin/pengaturan.php, admin/persetujuan.php, anggota/cetak_shu_saya.php, anggota/dashboard.php, anggota/pinjaman.php, anggota/shu_saya.php, cron/wa_jatuh_tempo.php, database/fixtures/status_test_data.sql, database/migrations/MIGRASI_UNIVERSAL.sql, includes/anggota_helper.php, includes/angsuran_helper.php, includes/bukti_angsuran.php, includes/bukti_pelunasan.php, includes/buku_angsuran.php, includes/denda_dashboard.php, includes/denda_helper.php, includes/denda_report_panel.php, includes/kwitansi_layanan.php, includes/kwitansi_shu.php, includes/layanan_actions.php, includes/layanan_bonus_panel.php, includes/layanan_dashboard.php, includes/layanan_followup_panel.php, includes/layanan_helper.php, includes/layanan_lanjutan.php, includes/layanan_page.php, includes/pinjaman_helper.php, includes/shu_helper.php, includes/shu_page.php, includes/shu_pinjaman_helper.php, includes/slip_pencairan.php, includes/upload_foto.php, teller/angsuran.php, teller/cetak_laporan_harian.php, teller/dashboard.php, teller/detail_anggota.php, teller/export_excel_harian.php, teller/laporan_harian.php, teller/pelunasan.php, teller/pencairan.php, teller/pengajuan.php, teller/profil.php, tests/layanan_integration_test.php |
| `saldo` | Tidak terdeteksi | admin/detail_anggota.php, teller/detail_anggota.php |
| `setting_value` | Tidak terdeteksi | includes/denda_helper.php |
| `settings` | admin/pengaturan_pinjaman.php | includes/denda_helper.php, tests/denda_scope_test.php |
| `shu_anggota` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/shu_helper.php, tests/shu_test.php |
| `shu_audit` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/shu_helper.php |
| `shu_hak_audit` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/shu_pinjaman_helper.php |
| `shu_hak_pinjaman` | database/migrations/MIGRASI_UNIVERSAL.sql | anggota/cetak_shu_saya.php, anggota/shu_saya.php, includes/kwitansi_shu.php, includes/layanan_dashboard.php, includes/shu_page.php, includes/shu_pinjaman_helper.php |
| `shu_pembayaran` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/shu_helper.php |
| `shu_periode` | database/migrations/MIGRASI_UNIVERSAL.sql | includes/shu_helper.php |
| `simpanan` | Tidak terdeteksi | admin/anggota.php, admin/angsuran.php, admin/detail_anggota.php, admin/export_anggota.php, admin/get_saldo.php, admin/jenis_simpanan.php, admin/koreksi.php, admin/laporan_anggota.php, admin/penarikan.php, admin/pengaturan.php, admin/setoran.php, anggota/cetak_buku.php, anggota/dashboard.php, anggota/riwayat_simpanan.php, anggota/simpanan.php, includes/anggota_helper.php, includes/angsuran_helper.php, includes/layanan_helper.php, includes/layanan_lanjutan.php, teller/anggota.php, teller/angsuran.php, teller/cetak_laporan_simpanan_teller.php, teller/detail_anggota.php, teller/export_excel_simpanan_teller.php, teller/get_saldo.php, teller/laporan_simpanan.php, teller/penarikan.php, teller/setoran.php |
| `transaksi` | Tidak terdeteksi | admin/angsuran.php, admin/cetak_laporan_kas.php, admin/cetak_laporan_simpanan.php, admin/cetak_laporan_transaksi.php, admin/cetak_pdf_transaksi.php, admin/dashboard.php, admin/detail_anggota.php, admin/export_excel_simpanan.php, admin/export_excel_transaksi.php, admin/export_kas.php, admin/get_saldo.php, admin/jenis_simpanan.php, admin/koreksi.php, admin/laporan_anggota.php, admin/laporan_kas.php, admin/laporan_simpanan.php, admin/laporan_transaksi.php, admin/penarikan.php, admin/setoran.php, anggota/cetak_buku.php, anggota/dashboard.php, anggota/riwayat_simpanan.php, includes/anggota_helper.php, includes/angsuran_helper.php, includes/bukti_angsuran.php, includes/layanan_helper.php, includes/layanan_lanjutan.php, includes/shu_helper.php, teller/angsuran.php, teller/cetak_laporan_harian.php, teller/cetak_laporan_simpanan_teller.php, teller/dashboard.php, teller/detail_anggota.php, teller/export_excel_harian.php, teller/export_excel_simpanan_teller.php, teller/get_saldo.php, teller/laporan_harian.php, teller/laporan_simpanan.php, teller/penarikan.php, teller/profil.php, teller/setoran.php, tests/shu_test.php |
| `user_id` | Tidak terdeteksi | includes/layanan_lanjutan.php |
| `users` | Tidak terdeteksi | admin/anggota.php, admin/cetak_laporan_kas.php, admin/cetak_laporan_transaksi.php, admin/dashboard.php, admin/detail_anggota.php, admin/export_kas.php, admin/koreksi.php, admin/laporan_anggota.php, admin/laporan_kas.php, admin/laporan_transaksi.php, admin/pembelian.php, admin/penarikan.php, admin/pencairan.php, admin/pengajuan.php, admin/pengaturan.php, admin/persetujuan.php, admin/setoran.php, admin/teller.php, anggota/dashboard.php, anggota/ganti_password.php, anggota/profil.php, anggota/waserda.php, database/fixtures/status_test_data.sql, database/migrations/MIGRASI_UNIVERSAL.sql, includes/akun_helper.php, includes/anggota_helper.php, includes/angsuran_helper.php, includes/auth.php, includes/bukti_angsuran.php, includes/bukti_pelunasan.php, includes/denda_report_panel.php, includes/kwitansi_layanan.php, includes/kwitansi_shu.php, includes/layanan_detail_lanjutan.php, includes/layanan_page.php, includes/pinjaman_helper.php, includes/shu_page.php, includes/sidebar.php, login_process.php, teller/anggota.php, teller/cetak_laporan_harian.php, teller/cetak_laporan_simpanan_teller.php, teller/dashboard.php, teller/detail_anggota.php, teller/export_excel_harian.php, teller/export_excel_simpanan_teller.php, teller/laporan_harian.php, teller/laporan_simpanan.php, teller/pembelian.php, teller/penarikan.php, teller/pencairan.php, teller/pengajuan.php, teller/profil.php, teller/setoran.php, tests/layanan_integration_test.php, tests/shu_test.php |
| `wa_pengingat_log` | cron/wa_jatuh_tempo.php, database/migrations/MIGRASI_UNIVERSAL.sql | cron/wa_jatuh_tempo.php |
| `waserda_transaksi` | admin/pembelian.php, teller/pembelian.php | admin/backup.php, admin/dashboard.php, admin/detail_anggota.php, admin/laporan_transaksi.php, admin/pembelian.php, anggota/dashboard.php, anggota/waserda.php, includes/anggota_helper.php, includes/shu_helper.php, teller/dashboard.php, teller/pembelian.php |

## File identik byte-for-byte (tanpa TCPDF)

Alias kecil memang dapat berbagi implementasi; jangan menghapus alias sebelum semua link/cetak/redirect dipetakan.

- `admin/denda.php`, `teller/denda.php`
- `admin/kwitansi_layanan.php`, `teller/kwitansi_layanan.php`
- `admin/kwitansi_shu.php`, `anggota/kwitansi_shu.php`
- `admin/laporan_denda.php`, `teller/laporan_denda.php`
- `admin/laporan_lunas.php`, `teller/laporan_lunas.php`
- `admin/layanan.php`, `teller/layanan.php`

## Semua file aplikasi di luar TCPDF

- `.env.example`
- `.gitignore`
- `MIGRASI_HOSTING.md`
- `README.md`
- `admin/anggota.php`
- `admin/angsuran.php`
- `admin/backup.php`
- `admin/cetak_bukti_angsuran.php`
- `admin/cetak_bukti_pelunasan.php`
- `admin/cetak_buku_angsuran.php`
- `admin/cetak_laporan_angsuran.php`
- `admin/cetak_laporan_kas.php`
- `admin/cetak_laporan_pinjaman.php`
- `admin/cetak_laporan_simpanan.php`
- `admin/cetak_laporan_transaksi.php`
- `admin/cetak_pdf_transaksi.php`
- `admin/cetak_perjanjian.php`
- `admin/cetak_slip_pencairan.php`
- `admin/dashboard.php`
- `admin/denda.php`
- `admin/detail_anggota.php`
- `admin/export_anggota.php`
- `admin/export_excel_angsuran.php`
- `admin/export_excel_pinjaman.php`
- `admin/export_excel_simpanan.php`
- `admin/export_excel_transaksi.php`
- `admin/export_kas.php`
- `admin/get_saldo.php`
- `admin/jenis_pinjaman.php`
- `admin/jenis_simpanan.php`
- `admin/koreksi.php`
- `admin/kwitansi_layanan.php`
- `admin/kwitansi_shu.php`
- `admin/laporan_anggota.php`
- `admin/laporan_angsuran.php`
- `admin/laporan_denda.php`
- `admin/laporan_kas.php`
- `admin/laporan_lunas.php`
- `admin/laporan_pinjaman.php`
- `admin/laporan_simpanan.php`
- `admin/laporan_transaksi.php`
- `admin/layanan.php`
- `admin/pelunasan.php`
- `admin/pembelian.php`
- `admin/penarikan.php`
- `admin/pencairan.php`
- `admin/pengajuan.php`
- `admin/pengaturan.php`
- `admin/pengaturan_pinjaman.php`
- `admin/persetujuan.php`
- `admin/setoran.php`
- `admin/shu.php`
- `admin/shu_anggota.php`
- `admin/shu_laporan.php`
- `admin/teller.php`
- `anggota/cetak_buku.php`
- `anggota/cetak_shu_saya.php`
- `anggota/dashboard.php`
- `anggota/ganti_password.php`
- `anggota/kwitansi_shu.php`
- `anggota/pinjaman.php`
- `anggota/profil.php`
- `anggota/riwayat_simpanan.php`
- `anggota/shu_saya.php`
- `anggota/simpanan.php`
- `anggota/waserda.php`
- `api/notifikasi_pinjaman.php`
- `assets/css/app-ui.css`
- `assets/css/home.css`
- `assets/css/public-ui.css`
- `assets/css/tanda-tangan.css`
- `assets/css/upload-foto.css`
- `assets/js/app-ui.js`
- `assets/js/brand.js`
- `assets/js/denda-form.js`
- `assets/js/pilihan-foto.js`
- `assets/js/pinjaman.js`
- `assets/js/tanda-tangan-layanan.js`
- `config/bootstrap.php`
- `config/config.php`
- `config/database.php`
- `config/env.php`
- `config/functions.php`
- `cron/wa_jatuh_tempo.php`
- `database/fixtures/status_test_data.sql`
- `database/migrations/MIGRASI_UNIVERSAL.sql`
- `includes/.htaccess`
- `includes/akun_helper.php`
- `includes/anggota_helper.php`
- `includes/angsuran_helper.php`
- `includes/auth.php`
- `includes/brand.php`
- `includes/bukti_angsuran.php`
- `includes/bukti_pelunasan.php`
- `includes/buku_angsuran.php`
- `includes/cetak_perjanjian.php`
- `includes/denda_dashboard.php`
- `includes/denda_helper.php`
- `includes/denda_report_panel.php`
- `includes/dokumen_anggota.php`
- `includes/filter_denda.php`
- `includes/filter_pelunasan.php`
- `includes/form_dokumen_anggota.php`
- `includes/form_tarif_pinjaman.php`
- `includes/header.php`
- `includes/kartu_dokumen_anggota.php`
- `includes/kwitansi_layanan.php`
- `includes/kwitansi_shu.php`
- `includes/layanan_actions.php`
- `includes/layanan_bonus_panel.php`
- `includes/layanan_dashboard.php`
- `includes/layanan_detail_lanjutan.php`
- `includes/layanan_followup_panel.php`
- `includes/layanan_helper.php`
- `includes/layanan_lanjutan.php`
- `includes/layanan_page.php`
- `includes/layanan_settings_panel.php`
- `includes/logout.php`
- `includes/modal_dokumen.php`
- `includes/perjanjian_helper.php`
- `includes/pinjaman_helper.php`
- `includes/shu_empty_state.php`
- `includes/shu_helper.php`
- `includes/shu_page.php`
- `includes/shu_pinjaman_helper.php`
- `includes/sidebar.php`
- `includes/slip_pencairan.php`
- `includes/upload_foto.php`
- `index.php`
- `login.php`
- `login_process.php`
- `logout.php`
- `notifikasi.php`
- `scripts/hitung_denda.php`
- `storage/sessions/.gitignore`
- `storage/sessions/.htaccess`
- `teller/anggota.php`
- `teller/angsuran.php`
- `teller/cetak_bukti_angsuran.php`
- `teller/cetak_bukti_pelunasan.php`
- `teller/cetak_buku_angsuran.php`
- `teller/cetak_draft_perjanjian.php`
- `teller/cetak_laporan_harian.php`
- `teller/cetak_laporan_simpanan_teller.php`
- `teller/cetak_perjanjian.php`
- `teller/cetak_slip_pencairan.php`
- `teller/dashboard.php`
- `teller/denda.php`
- `teller/detail_anggota.php`
- `teller/export_excel_harian.php`
- `teller/export_excel_simpanan_teller.php`
- `teller/get_saldo.php`
- `teller/kwitansi_layanan.php`
- `teller/laporan_denda.php`
- `teller/laporan_harian.php`
- `teller/laporan_lunas.php`
- `teller/laporan_simpanan.php`
- `teller/layanan.php`
- `teller/pelunasan.php`
- `teller/pembelian.php`
- `teller/penarikan.php`
- `teller/pencairan.php`
- `teller/pengajuan.php`
- `teller/pengajuan_pinjaman.php`
- `teller/persetujuan.php`
- `teller/profil.php`
- `teller/setoran.php`
- `teller/tambah_anggota.php`
- `teller/ubah_password.php`
- `tests/denda_jaminan_test.php`
- `tests/denda_permissions_test.php`
- `tests/denda_scope_test.php`
- `tests/denda_test.php`
- `tests/gadai_tenor_6_bulan_test.php`
- `tests/layanan_integration_test.php`
- `tests/notifikasi_integration_test.php`
- `tests/notifikasi_shu_test.php`
- `tests/shu_test.php`
- `tests/tabel_angsuran_test.php`

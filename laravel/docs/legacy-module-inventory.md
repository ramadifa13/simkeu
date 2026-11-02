# Inventaris Modul Native dan Target Laravel

Dokumen ini adalah daftar kerja Fase 2. Nama halaman native bukan rancangan URL akhir; targetnya adalah pengalaman terstruktur menurut peran, dengan halaman, tab, filter, atau ekspor yang tepat. Tidak ada modul yang dianggap selesai hanya karena tabelnya sudah tampil.

## Hak akses sumber yang telah diverifikasi

| Aksi / area | Peran native | Kontrak migrasi |
| --- | --- | --- |
| Dashboard | admin, teller, anggota | Dashboard per peran; angka dan daftar sumber harus direkonsiliasi sebelum dipakai operasional. |
| Data anggota, setoran, penarikan, pembelian, pengajuan, persetujuan, pencairan, angsuran, pelunasan, layanan, denda, closing | admin, teller | Policy per resource dan per aksi, bukan hanya pembatasan menu. |
| Koreksi, master, mutasi SHU, lock/unlock closing, keputusan pembatalan angsuran | admin | Semua mutasi harus ber-audit, bertransaksi database, dan dilindungi policy. |
| Simpanan pribadi, pinjaman pribadi, waserda, SHU pribadi, profil | anggota | Scoping selalu ke identitas anggota yang sedang login. |

## Halaman admin

| Kelompok target | Halaman native | Fase target | Status katalog |
| --- | --- | --- | --- |
| Dashboard | `dashboard.php` | 4 | Documented |
| Anggota & staf | `anggota.php`, `detail_anggota.php`, `teller.php`, `laporan_anggota.php`, `export_anggota.php` | 5 | Documented |
| Master | `jenis_simpanan.php`, `jenis_pinjaman.php`, `pengaturan.php`, `pengaturan_pinjaman.php` | 5 | Documented |
| Simpanan, kas & koreksi | `setoran.php`, `penarikan.php`, `pembelian.php`, `koreksi.php`, `get_saldo.php`, `laporan_simpanan.php`, `laporan_transaksi.php`, `laporan_kas.php`, `cetak_laporan_simpanan.php`, `cetak_laporan_transaksi.php`, `cetak_laporan_kas.php`, `cetak_pdf_transaksi.php`, `export_excel_simpanan.php`, `export_excel_transaksi.php`, `export_kas.php` | 6 | Documented |
| Siklus pinjaman | `pengajuan.php`, `persetujuan.php`, `pencairan.php`, `angsuran.php`, `detail_angsuran.php`, `pelunasan.php`, `denda.php`, `closing_angsuran.php`, `laporan_pinjaman.php`, `laporan_angsuran.php`, `laporan_lunas.php`, `laporan_denda.php`, `cetak_bukti_angsuran.php`, `cetak_bukti_pelunasan.php`, `cetak_buku_angsuran.php`, `cetak_draft_perjanjian.php`, `cetak_perjanjian.php`, `cetak_slip_pencairan.php`, `cetak_laporan_angsuran.php`, `cetak_laporan_pinjaman.php`, `export_excel_angsuran.php`, `export_excel_pinjaman.php` | 7 | Documented |
| Layanan & SHU | `layanan.php`, `detail_layanan.php`, `kwitansi_layanan.php`, `shu.php`, `shu_anggota.php`, `shu_laporan.php`, `kwitansi_shu.php` | 8 | Documented |
| Migrasi & administrasi | `import_migrasi.php`, `download_template_migrasi.php`, `backup.php` | 8 | Documented |

## Halaman teller

| Kelompok target | Halaman native | Fase target | Status katalog |
| --- | --- | --- | --- |
| Dashboard & profil | `dashboard.php`, `profil.php`, `ubah_password.php` | 4 / 5 | Documented |
| Anggota | `anggota.php`, `tambah_anggota.php`, `detail_anggota.php` | 5 | Documented |
| Simpanan & laporan harian | `setoran.php`, `penarikan.php`, `pembelian.php`, `get_saldo.php`, `laporan_harian.php`, `laporan_simpanan.php`, `cetak_laporan_harian.php`, `cetak_laporan_simpanan_teller.php`, `export_excel_harian.php`, `export_excel_simpanan_teller.php` | 6 | Documented |
| Siklus pinjaman | `pengajuan.php`, `pengajuan_pinjaman.php`, `persetujuan.php`, `pencairan.php`, `angsuran.php`, `detail_angsuran.php`, `pelunasan.php`, `denda.php`, `closing_angsuran.php`, `laporan_lunas.php`, `laporan_denda.php`, `cetak_bukti_angsuran.php`, `cetak_bukti_pelunasan.php`, `cetak_buku_angsuran.php`, `cetak_draft_perjanjian.php`, `cetak_perjanjian.php`, `cetak_slip_pencairan.php` | 7 | Documented |
| Layanan | `layanan.php`, `detail_layanan.php`, `kwitansi_layanan.php` | 8 | Documented |

## Halaman anggota

| Kelompok target | Halaman native | Fase target | Status katalog |
| --- | --- | --- | --- |
| Dashboard | `dashboard.php` | 4 | Documented |
| Simpanan | `simpanan.php`, `riwayat_simpanan.php`, `cetak_buku.php` | 6 | Documented |
| Pinjaman & layanan | `pinjaman.php`, `waserda.php` | 7 / 8 | Documented |
| SHU | `shu_saya.php`, `kwitansi_shu.php`, `cetak_shu_saya.php` | 8 | Documented |
| Akun | `profil.php`, `ganti_password.php` | 5 | Documented |

## Helper native yang merupakan sumber aturan

| Helper | Aturan yang harus dipindahkan ke Action/Service Laravel | Fase |
| --- | --- | --- |
| `permissions.php`, `auth.php` | matriks peran dan fail-closed untuk aksi tak dikenal | 3, 5–8 |
| `anggota_helper.php`, `dokumen_anggota.php`, `upload_foto.php` | kode anggota terkunci, syarat hapus, validasi/path upload, pembersihan file setelah commit | 5 |
| `setoran_page.php`, `penarikan_page.php`, `pembelian_page.php`, `get_saldo.php` | saldo anggota, transaksi, kas, koreksi | 6 |
| `pinjaman_helper.php`, `pengajuan_page.php`, `persetujuan_page.php`, `pencairan_page.php`, `angsuran_helper.php`, `denda_helper.php`, `closing_helper.php`, `pelunasan_page.php` | status mesin pinjaman, jadwal, pembukuan, denda, pembatalan, lock periode | 7 |
| `layanan_*.php` | alur layanan lanjutan, lelang/jaminan, pembayaran, audit | 8 |
| `shu_helper.php`, `shu_pinjaman_helper.php`, `shu_page.php` | snapshot periode, hak, pembayaran dan pembatalannya | 8 |
| `migrasi_*.php` | idempotensi impor, cadangan dokumen, template/ekspor | 8 |
| `dashboard_helper.php`, `dashboard_page.php` | definisi KPI dan daftar prioritas per peran | 4 |

## Klasifikasi seluruh helper `includes/` yang terverifikasi (72 berkas)

| Klasifikasi | Berkas | Keputusan migrasi |
| --- | --- | --- |
| Aturan akun, izin, navigasi | `auth`, `permissions`, `akun_helper`, `brand`, `logout`, `menu`, `sidebar`, `header` | Policy/middleware/layout Laravel; tidak dipindahkan sebagai PHP prosedural. |
| Aturan dan UI anggota/dokumen | `anggota_helper`, `anggota_page`, `detail_anggota_page`, `dokumen_anggota`, `form_dokumen_anggota`, `kartu_dokumen_anggota`, `modal_dokumen`, `upload_foto` | Action, Form Request, policy, private storage, Blade/Flux component (Fase 5). |
| Dashboard | `dashboard_helper`, `dashboard_page` | Query/DTO dashboard + Blade/Livewire (Fase 4). |
| Simpanan/kas | `get_saldo`, `setoran_page`, `penarikan_page`, `pembelian_page` | Action transaksi/ledger dan halaman domain (Fase 6). |
| Pinjaman inti | `pinjaman_helper`, `pengajuan_page`, `persetujuan_page`, `pencairan_page`, `angsuran_helper`, `angsuran_page`, `pelunasan_page`, `closing_helper`, `closing_page`, `denda_helper`, `denda_report_panel`, `filter_denda`, `filter_pelunasan`, `form_tarif_pinjaman`, `lengkapi_foto_pencairan` | Action/state machine, calculator teruji, query filter dan komponen formulir (Fase 7). |
| Dokumen pinjaman dan bukti | `bukti_angsuran`, `bukti_pelunasan`, `buku_angsuran`, `cetak_draft_perjanjian`, `cetak_perjanjian`, `perjanjian_helper`, `slip_pencairan` | Generator dokumen/PDF dan policy keluaran (Fase 7/8). |
| Layanan | `layanan_actions`, `layanan_bonus_panel`, `layanan_dashboard`, `layanan_detail_lanjutan`, `layanan_followup_panel`, `layanan_helper`, `layanan_lanjutan`, `layanan_page`, `layanan_settings_panel`, `kwitansi_layanan` | State machine per layanan, Action, policy dan komponen panel (Fase 8). |
| SHU | `shu_empty_state`, `shu_helper`, `shu_page`, `shu_pinjaman_helper`, `kwitansi_shu` | Calculator/snapshot lifecycle, Action, policy, PDF dan komponen (Fase 8). |
| Migrasi | `migrasi_dokumen_cadangan`, `migrasi_excel_helper`, `migrasi_export_service`, `migrasi_import_service`, `migrasi_xlsx_writer` | Command/service import-export idempoten; tidak mengekspos berkas langsung (Fase 8). |
| Utilitas kompatibilitas skema | `schema_helper` | Diganti pemeriksaan migration/schema Laravel; hanya dipertahankan sementara jika diperlukan command import (Fase 8). |
| Layout dan UI bersama | `layout/assets`, `layout/shell_start`, `layout/shell_end`, `ui/helpers`, `ui/partial_riwayat_transaksi`, `ui/partial_riwayat_waserda`, `ui/riwayat_list` | Dipecah menjadi layout Blade dan component reusable Flux/DataTable; tidak dipindahkan sebagai HTML PHP prosedural (Fase 3, 5, 6). |

Tidak ada helper yang boleh dipindahkan apa adanya. Klasifikasi ini hanya menetapkan pemilik serta bentuk pengganti; aturan rinci tiap Action masih harus dipindahkan ke Field & Rule Catalogue sebelum Fase target dimulai.

## Cron dan endpoint API native

| Sumber | Kontrak | Target Laravel |
| --- | --- | --- |
| `cron/wa_jatuh_tempo.php` | H-1 jatuh tempo untuk pinjaman aktif/angsuran belum lunas, normalisasi nomor Indonesia, template WhatsApp, deduplikasi `angsuran_id`+tanggal, pencatatan berhasil/gagal dan percobaan | Scheduled command + queued job/retry, konfigurasi environment rahasia, log idempoten dan observability (Fase 8). Endpoint HTTP ber-token tidak diteruskan kecuali ada kebutuhan operasional tertulis. |
| `api/notifikasi_pinjaman.php` | Memerlukan sesi valid semua peran; mengembalikan jumlah notifikasi menurut role/user tanpa cache | Controller terautentikasi atau Livewire polling terbatasi, Policy per role, response tanpa data lintas anggota (Fase 3/4). |

## Keputusan struktur informasi awal

- Daftar anggota tetap satu halaman dengan tab/detail anggota; dokumen, simpanan, pinjaman, SHU, dan audit berada dalam detail, bukan menu daftar terpisah.
- Daftar pinjaman menggunakan filter status/tahap; pengajuan, persetujuan, dan pencairan dapat menjadi tab atau tampilan yang difilter, tanpa menghilangkan izin aksi masing-masing.
- Laporan menggunakan satu pusat laporan per domain dengan filter periode dan ekspor; cetak bukti individual tetap dari halaman detail transaksi/angsuran.
- Tiga titik hanya untuk aksi per baris tabel. Aksi primer, tindakan dashboard, dan tindakan detail tetap tombol eksplisit.

## Peta test native ke test Laravel

| Test native | Kontrak yang diuji | Target Fase / bentuk test Laravel |
| --- | --- | --- |
| `tabel_angsuran_test.php` | tabel resmi cicilan flat mingguan, pembulatan pokok/bunga, total dan sisa nol | 7 — unit test `LoanScheduleCalculatorTest` berbasis data provider |
| `denda_test.php` | tarif harian, hari telat, batas sampai jatuh tempo berikutnya, cicilan terakhir | 7 — unit test `PenaltyCalculatorTest` |
| `denda_scope_test.php` | ruang lingkup denda per cicilan dan batas waktu | 7 — unit/feature test pembayaran |
| `denda_permissions_test.php` | Teller tak boleh mengubah denda; Admin berwenang | 7 — feature/policy test |
| `denda_jaminan_test.php` | tarif denda jaminan menurut kelipatan juta | 7 — unit test `PenaltyCalculatorTest` |
| `gadai_tenor_6_bulan_test.php` | tenor dan jaminan gadai | 7 — feature test pengajuan/pencairan jaminan |
| `shu_test.php` | alokasi rupiah deterministik, kontribusi, snapshot, pengesahan, pembayaran/balik dan audit | 8 — unit `ShuAllocationTest` + feature lifecycle test |
| `notifikasi_shu_test.php` | notifikasi terkait SHU | 8 — notification test dengan fake queue/channel |
| `notifikasi_integration_test.php` | integrasi deduplikasi/pengiriman notifikasi | 8 — feature/queue integration test |
| `migrasi_import_integration_test.php` | import ulang tidak menggandakan pembayaran, pinjaman dan kas terbentuk | 8 — feature test import dengan fixture XLSX dan database transaction |
| `layanan_integration_test.php` | role layanan, kolektibilitas, pelunasan, denda, reschedule, kas dan audit | 8 — feature suite per state machine layanan |

Test native berupa script prosedural bukan bukti test Laravel telah ada. Semua target di atas tetap berstatus **belum diimplementasikan** sampai fase terkait selesai.

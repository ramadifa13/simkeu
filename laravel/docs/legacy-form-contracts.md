# Kontrak Form Native

Inventaris ini mencatat semua sumber form yang ditemukan pada halaman native dan helper yang merender form. Form Laravel nantinya memakai Flux UI, Form Request/action server-side, policy, CSRF bawaan, validasi error yang aksesibel, serta modal hanya untuk form pendek.

| Sumber form | Aksi / field penting | Bentuk Laravel dan fase |
| --- | --- | --- |
| `includes/anggota_page.php` | tambah/edit: kategori anggota, identitas/profil, KTP, rekening, tanggal daftar, kelompok/SHU, unggahan dokumen; reset password; hapus | halaman panjang anggota + modal pendek reset/konfirmasi; Fase 5 |
| `teller/profil.php`, `teller/ubah_password.php`, `anggota/ganti_password.php`, `admin/pengaturan.php` | profil akun, foto, kata sandi lama/baru/konfirmasi | halaman akun; Fase 3/5 |
| `admin/teller.php` | tambah/edit teller, status, reset password, hapus | tabel + modal pendek; Fase 5 |
| `admin/jenis_simpanan.php` | kode, nama, deskripsi, minimum, maksimum, bagi hasil, status, hapus | tabel + modal pendek; Fase 5 |
| `admin/jenis_pinjaman.php`, `includes/form_tarif_pinjaman.php` | kode/nama produk, plafon, deskripsi, frekuensi, jaminan, tenor/tarif, status, hapus | tabel + halaman/master form; Fase 5 |
| `admin/pengaturan_pinjaman.php` | bagi hasil per jenis, tingkat plafon/denda | halaman pengaturan; Fase 5 |
| `includes/setoran_page.php`, `includes/penarikan_page.php` | anggota, jenis simpanan, jumlah, keterangan | form transaksi singkat; modal atau halaman operasi; Fase 6 |
| `includes/pembelian_page.php` | anggota, metode, baris nama barang/harga/jumlah | halaman transaksi dinamis; Fase 6 |
| `admin/koreksi.php` | transaksi, jumlah baru, alasan koreksi | halaman khusus dengan konfirmasi kuat; Fase 6 |
| seluruh form filter laporan (`filter_denda`, `laporan_*`, `teller/laporan_*`, `anggota/cetak_buku.php`) | periode/tanggal/status/anggota/jenis | filter tabel URL state, bukan mutasi; Fase 6/7/8 |
| `includes/pengajuan_page.php` | anggota, produk, nominal, tenor, frekuensi, tujuan, kategori/ketua SHU, prioritas, hari/tanggal angsuran, detail jaminan dan foto | halaman panjang bertahap; Fase 7 |
| `includes/persetujuan_page.php` | approve/reject/hapus dan catatan Admin | tabel + modal keputusan pendek; Fase 7 |
| `includes/pencairan_page.php`, `includes/lengkapi_foto_pencairan.php` | cairkan, foto pencairan, saksi, tanggal, cicilan di muka, tanda tangan; pengajuan/keputusan tunda cair; lengkapi foto | halaman panjang + modal keputusan; Fase 7 |
| `includes/angsuran_page.php` | keranjang/bayar langsung, metode, tanggal proses, denda; batal langsung/ajukan/putuskan batal dengan alasan/catatan | tabel/detail + modal pembayaran/keputusan; Fase 7 |
| `includes/pelunasan_page.php` | nominal, metode, catatan, cek fisik/jaminan, tanda tangan, pilihan cetak akad | halaman transaksi berisiko tinggi; Fase 7 |
| `includes/closing_page.php` | bulan, catatan, lock/unlock | modal konfirmasi pendek; Fase 7 |
| `includes/layanan_page.php`, `layanan_actions.php`, `layanan_lanjutan.php`, `layanan_settings_panel.php`, `layanan_bonus_panel.php` | aksi layanan, pinjaman, nominal/metode/bukti, denda/diskon, cek fisik, tanda tangan, keputusan, catatan, konfigurasi dan filter tahun | halaman layanan berbasis tab/detail, modal hanya keputusan sederhana; Fase 8 |
| `includes/shu_page.php` | periode, kebijakan persentase, kalkulasi/pengesahan, pembayaran/pembalikan, metode/referensi/bukti | halaman lifecycle SHU + modal bayar; Fase 8 |
| `admin/import_migrasi.php` | unggah/pratinjau/final import XLSX | halaman proses import dengan dry-run; Fase 8 |
| `admin/backup.php` | backup database/Excel/ZIP, reset total import, hapus berkas | halaman Admin berbahaya dengan konfirmasi eksplisit; Fase 8 |

## Aturan pemindahan yang telah disepakati

- Form panjang: anggota lengkap, pengajuan/pencairan/pelunasan, pengaturan perjanjian, import, dan lifecycle SHU menggunakan halaman tersendiri.
- Form pendek: master, keputusan, reset kata sandi, lock/unlock, dan konfirmasi memakai modal Flux dengan validasi server-side.
- Form filter tidak memakai modal; nilai filter/search/sort/pagination disimpan dalam URL dan selalu diotorisasi di server.
- Tombol aksi tabel masuk menu tiga titik. Tombol aksi utama halaman, dashboard, dan detail tetap eksplisit.
- Semua media/form tanda tangan dan unggahan menjalankan policy dan validasi MIME/ukuran/path di server; validasi JavaScript hanya bantuan UX.

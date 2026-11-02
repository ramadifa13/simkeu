# Baseline Rekonsiliasi Data

Diambil dari clone pengembangan `simkeu_laravel` pada 2026-11-02. Ini adalah baseline kerja, bukan persetujuan cutover. Nilai pada environment UAT/produksi harus diambil ulang tepat sebelum parallel run dan sebelum final delta import.

| Domain | Tabel | Jumlah baris awal |
| --- | --- | ---: |
| Akun | `users` | 21 |
| Anggota | `anggota` | 19 |
| Simpanan | `simpanan` | 0 |
| Transaksi | `transaksi` | 28 |
| Kas | `kas` | 29 |
| Produk denda | `denda_tarif` | 3 |
| Pinjaman | `pinjaman` | 20 |
| Angsuran | `angsuran` | 215 |
| Periode SHU | `shu_periode` | 0 |
| Alokasi SHU anggota | `shu_anggota` | 0 |
| Hak SHU pinjaman | `shu_hak_pinjaman` | 0 |
| Pembayaran SHU | `shu_pembayaran` | 0 |

## Perbandingan dengan sumber native

Query read-only pada 2026-11-02 mengonfirmasi jumlah domain inti pada `simkeusi_koperasi_db` dan `simkeu_laravel` sama: users 21, anggota 19, simpanan 0, transaksi 28, kas 29, pinjaman 20, angsuran 215, denda 3, seluruh tabel SHU 0, pengajuan 20, produk pinjaman 16, dan jenis simpanan 9.

## Aturan rekonsiliasi sebelum cutover

1. Bandingkan count tabel dan key unik untuk seluruh tabel domain, bukan hanya tabel pada tabel ringkas di atas.
2. Bandingkan saldo agregat dan per anggota untuk `simpanan`, serta rantai `saldo_sebelum`/`saldo_sesudah` transaksi dan kas.
3. Bandingkan jumlah pokok, sisa pokok, status pinjaman, jumlah/jumlah status angsuran, dan total denda.
4. Bandingkan snapshot, hak, pembayaran, dan pembatalan SHU bila sudah ada data.
5. Catat checksum/daftar berkas untuk dokumen anggota, pencairan, jaminan, bukti pembayaran, dan tanda tangan tanpa memasukkan isi dokumen ke log.
6. Selisih hanya dapat diterima bila memiliki alasan bisnis, pemilik keputusan, dan jejak audit tertulis.

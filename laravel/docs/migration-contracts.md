# Kontrak Migrasi Data dan Aturan Keuangan

Status semua butir di bawah adalah **Documented** sampai modul dan pengujian Fase implementasinya selesai. Kontrak ini menjadi acuan acceptance test, bukan pengganti kebutuhan konfirmasi bisnis apabila aturan native saling bertentangan.

## Dokumen dan unggahan

- Dokumen anggota: `foto_ktp`, `foto_kk`, dan `foto_ktp_pasangan`; foto pencairan dan jaminan merupakan dokumen berbeda.
- Sumber native menerima hanya JPEG, PNG, atau WEBP berdasarkan MIME; file asal maksimum 10 MB, kemudian gambar dikompres/dibatasi sampai maksimum 1 MB.
- Nama berkas harus acak, path relatif harus ditolak bila memuat `..`, dan penghapusan hanya boleh terjadi di root penyimpanan yang diizinkan.
- Penggantian dokumen harus menyimpan record lebih dahulu; berkas lama dihapus hanya setelah transaksi database berhasil. Semua dokumen Laravel tetap private dan diunduh lewat otorisasi server.
- Tanda tangan (`ttd`) dan beberapa lampiran berada pada tabel migrasi/perjanjian; bentuk, retensi, dan aksesnya harus diverifikasi pada Fase 5/7/8 sebelum dipindahkan.

## Konsistensi anggota

- Kode anggota reguler memakai pola `AGT` + nomor urut; kode gadai memakai `AGTJ` + nomor urut. Pembuatan kode harus mengunci proses dan memeriksa benturan dengan `anggota.kode_anggota`, `anggota.kode_anggota_jaminan`, dan `users.username`.
- Penghapusan permanen hanya untuk anggota `nonaktif`, tanpa pinjaman yang belum lunas dan tanpa pengajuan `menunggu`/`disetujui`; seluruh relasi transaksi, pinjaman, kas, dokumen, dan akun harus direkonsiliasi dalam satu transaksi.
- Kode jaminan dibersihkan hanya apabila tidak ada pinjaman jaminan maupun pengajuan jaminan yang berjalan.

## Pembukuan dan pembatalan angsuran

- Setiap pembayaran harus memperbarui angsuran, sisa pokok/status pinjaman, transaksi simpanan, dan kas secara atomik; pembatalan adalah pembalikan terkontrol, bukan penghapusan histori.
- Pembatalan pembayaran mengosongkan nilai transaksi/kas terkait, menyesuaikan saldo setelahnya, mencatat `log_koreksi`, serta mengembalikan angsuran ke `belum_bayar` atau `telat` dan pinjaman ke `aktif`.
- Teller hanya dapat mengajukan pembatalan pembayaran pada hari pembayaran; keputusan berada di Admin. Teller tidak dapat mengajukan pembatalan riwayat pinjaman lunas. Admin dapat membatalkan langsung dengan alasan dan audit.
- Periode closing harus mengunci mutasi pembayaran pada bulan terkait; Admin saja dapat lock/unlock. Detail closing menjadi bukti snapshot pembayaran.

## Denda

- Denda jaminan: nominal per hari = kelipatan juta penuh (minimal satu) × tarif jaminan per juta; tarif default native Rp5.000 dan dapat berasal dari pengaturan layanan.
- Denda tanpa jaminan menggunakan tabel `denda_tarif`; nominal pinjaman memakai tingkat tarif terdekat di bawah, atau tarif terkecil bila di bawah seluruh tingkat.
- Hari terlambat dimulai setelah jatuh tempo dan dibatasi sampai jatuh tempo cicilan berikutnya; cicilan terakhir terus berjalan.
- Perhitungan otomatis tidak boleh menimpa `denda_manual`. Teller tidak boleh mengubah nominal denda; diskon/pemutihan adalah keputusan Admin dan harus diaudit.

## Import, ekspor, dan rekonsiliasi

- Import memakai `migrasi_import_item` (`jenis`, `source_key`, `target_id`) untuk idempotensi. Pembayaran lama memiliki jejak `migrasi_pembayaran`; dokumen cadangan tersimpan terpisah.
- Setiap impor harus menyediakan dry-run, laporan baris gagal, pemetaan field, validasi referensi, dan rollback/kompensasi yang terdokumentasi.
- Sebelum cutover: hitung per tabel, cek key unik/FK, jumlah saldo simpanan, total kas, pokok pinjaman, angsuran lunas/belum, dan total hak/pembayaran SHU antara sumber dan clone.
- PDF/Excel adalah keluaran resmi: angka, filter/periode, pembulatannya, dan otorisasi ekspor harus berasal dari query yang sama dengan layar laporan.

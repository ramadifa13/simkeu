# Kontrak Relasi, Indeks, dan Integritas Legacy

Dokumen ini memisahkan foreign key database dari relasi implisit yang tetap wajib dijaga oleh Action Laravel.

## Foreign key terverifikasi

| Anak → induk | Kontrak |
| --- | --- |
| `anggota.user_id` → `users`, `anggota.ketua_kelompok_id` → `anggota` | akun anggota dan ketua kelompok; hapus ketua mengosongkan relasi. |
| `simpanan.anggota_id`/`jenis_simpanan_id`, `transaksi.anggota_id`/`jenis_simpanan_id`/`user_id`, `kas.transaksi_id` | ledger anggota dan kas. |
| `pengajuan_pinjaman.anggota_id`/`jenis_pinjaman_id`/`user_id`/`admin_id`/`ketua_kelompok_id` | pengajuan dan pelaku keputusan. |
| `pinjaman.pengajuan_id`/`anggota_id`/`jenis_pinjaman_id`/`ketua_kelompok_id`, `angsuran.pinjaman_id` | rantai kontrak pinjaman dan jadwal. |
| `jaminan_pinjaman.pengajuan_id`/`pinjaman_id`, `pelunasan_dini.pinjaman_id`/`user_id` | jaminan dan pelunasan. |
| `pengajuan_pembatalan_angsuran.angsuran_id`/`teller_id`/`admin_id` | approval pembatalan pembayaran. |
| closing detail → closing/angsuran; tarif tenor → jenis pinjaman | snapshot closing dan tarif produk. |
| `perjanjian_pinjaman.pinjaman_id`, `lampiran_perjanjian.perjanjian_id` | kontrak legal dan lampiran. |
| `pendanaan_dapin`/`alokasi_dapin` → pinjaman | sumber pendanaan per pinjaman. |
| seluruh `shu_*` FK yang tersedia | periode, anggota, hak pinjaman, dan pembayaran SHU. |
| migrasi pembayaran/dokumen dan `user_permissions` | traceability import dan izin akun. |

## Relasi implisit yang wajib menjadi invariant aplikasi

| Sumber | Relasi implisit / pengamanan |
| --- | --- |
| `layanan_audit`, `layanan_pembayaran`, `layanan_bayar_denda`, `layanan_pelunasan`, `layanan_pengajuan_hapus_denda`, `layanan_laporan_macet`, `layanan_jaminan_kembali`, `layanan_lelang`, `layanan_bonus`, `layanan_prioritas`, `layanan_repeat_order` | seluruh ID pinjaman/angsuran/user/transaksi harus dicek eksistensi dan scope dalam Action sebelum tulis; jangan mengandalkan tidak adanya FK. |
| `layanan_alokasi_lelang` | `lelang_id` dan `angsuran_id` membentuk alokasi hasil lelang; jumlah tidak boleh melampaui hasil/alokasi tersisa. |
| `layanan_tunda_cair` | satu workflow tunda aktif per pengajuan; keputusan harus sesuai pengajuan yang masih dapat dicairkan. |
| `migrasi_dokumen_cadangan`, `migrasi_import_item` | relasi berdasarkan `jenis` + `source_key`; hanya command import/recovery yang boleh menulis. |
| `wa_pengingat_log` | `angsuran_id` dan `tanggal_jadwal` adalah kunci deduplikasi pesan; job harus idempoten. |
| `pembelian_listrik`, `pembelian_indihome`, `waserda_transaksi` | pembelian anggota harus terkait pelaku dan dampak kas yang terverifikasi pada Fase 6/8. |

## Indeks/unique yang merupakan aturan bisnis

- Kode unik: anggota, anggota jaminan, jenis simpanan/pinjaman, pengajuan, pinjaman, transaksi, DAPIN, pembelian listrik/Indihome/Waserda.
- Satu saldo per anggota+jenis simpanan; satu tarif per jenis pinjaman+tenor; satu alokasi SHU per periode+anggota.
- Satu jaminan per pengajuan, satu closing per bulan dan satu detail closing per angsuran, satu perjanjian per pinjaman.
- Satu proses aktif menurut index legacy: pembatalan angsuran, hapus denda, tunda cair, prioritas, pelunasan, lelang, pengembalian jaminan, bonus pinjaman.
- Satu hak SHU per pinjaman+jenis; nonce pembayaran SHU unik; source key untuk import/alokasi/cadangan unik sesuai domain; log WA unik per angsuran+tanggal.

Laravel harus memelihara unique index yang relevan di database dan menangani pelanggaran unique sebagai konflik bisnis yang aman bagi pengguna, bukan sebagai error mentah.

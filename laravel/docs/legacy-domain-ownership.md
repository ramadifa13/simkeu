# Pemilik Domain dan Strategi Akses Data

Semua tabel pada snapshot memiliki pemilik berikut. "Model" berarti kandidat Eloquent; "query read model" berarti data ditampilkan melalui query/action teroptimasi dan tidak selalu membutuhkan model publik terpisah.

| Tabel | Pemilik / fase | Strategi Laravel |
| --- | --- | --- |
| `users`, `user_permissions` | Identity & authorization / 3, 5 | `User` + Gate/Policy; permission query read model. |
| `anggota`, `migrasi_anggota_dokumen`, `migrasi_dokumen_cadangan` | Member & dokumen / 5, 8 | `Anggota` + private document service; cadangan hanya command recovery. |
| `koperasi`, `settings`, `periode`, `pengaturan_perjanjian` | Konfigurasi / 3, 5 | Model konfigurasi singleton/key-value dan Form Request Admin. |
| `jenis_simpanan`, `simpanan`, `transaksi`, `kas`, `log_koreksi` | Ledger simpanan/kas / 6 | Model ledger + Action transaksional/lock; read model laporan. |
| `waserda_transaksi`, `pembelian_listrik`, `pembelian_indihome` | Produk transaksi / 6, 8 | Model transaksi produk + Action/kas; detail item JSON read model. |
| `jenis_pinjaman`, `jenis_pinjaman_tarif`, `denda_tarif` | Master kredit / 5, 7 | Model master, policy Admin, calculator value object. |
| `pengajuan_pinjaman`, `pinjaman`, `angsuran`, `pengajuan_pembatalan_angsuran`, `pelunasan_dini` | Lifecycle pinjaman / 7 | Model state + Action/transaction/row lock; query read model detail. |
| `jaminan_pinjaman`, `perjanjian_pinjaman`, `lampiran_perjanjian` | Jaminan/legal / 7, 8 | Model aggregate + private storage/document generator. |
| `closing_angsuran_bulanan`, `closing_angsuran_detail` | Closing / 7 | Model snapshot + policy lock/unlock Admin. |
| `layanan_pengaturan`, `layanan_audit`, `layanan_pembayaran`, `layanan_bayar_denda`, `layanan_pelunasan`, `layanan_pengajuan_hapus_denda`, `layanan_laporan_macet`, `layanan_jaminan_kembali`, `layanan_lelang`, `layanan_alokasi_lelang`, `layanan_bonus`, `layanan_prioritas`, `layanan_repeat_order`, `layanan_tunda_cair` | Layanan / 8 | Model per workflow + service/state machine; audit read-only. |
| `pendanaan_dapin`, `alokasi_dapin` | Pendanaan / 8 | Model pendanaan + allocation Action dan laporan. |
| `shu_periode`, `shu_anggota`, `shu_hak_pinjaman`, `shu_pembayaran`, `shu_audit`, `shu_hak_audit` | SHU / 8 | Model snapshot + calculator/action; audit/pembayaran immutable/reversal. |
| `migrasi_import_item`, `migrasi_pembayaran` | Import traceability / 8 | Model internal/command only; unique source key untuk idempotensi. |
| `wa_pengingat_log` | Notifikasi / 8 | Model internal queued job; unique schedule untuk deduplikasi. |

Tidak ada tabel legacy yang otomatis dihapus. Tabel yang hanya dipakai migrasi/audit/cadangan tetap dipertahankan sebagai historis baca-saja dan tidak menjadi menu reguler.

# Field & Rule Catalogue

This catalogue is the traceability record for the native-to-Laravel migration. A module cannot be marked complete in `IMPLEMENTATION_PLAN.md` until every source field and business rule has an entry here.

## Dokumen pendukung katalog

- `legacy-data-dictionary.md`: seluruh field dan klasifikasi retensi/pemindahan.
- `legacy-schema-snapshot.md`: tipe, default, enum/set, dan indeks database aktual.
- `legacy-relationship-contracts.md`: foreign key, relasi implisit, dan invariant unique.
- `legacy-form-contracts.md`: field input dan bentuk form target.
- `legacy-business-rules.md`: kalkulasi, status transition, lock, reversal, SHU, dan notifikasi.
- `legacy-output-contracts.md`: PDF, Excel, bukti, ekspor, import, dan backup.

## Status key

- `Mapped`: implemented and covered by tests/UAT.
- `Documented`: confirmed in native code/schema but not yet implemented.
- `Needs review`: a business decision is required before implementation.

## Authentication — `users`

| Source field | Laravel destination | Rule | Status |
| --- | --- | --- | --- |
| `id` | `User::$primaryKey` | Preserve legacy identifier. | Documented |
| `username` | `User::username` | Unique login identifier; required, maximum 50 characters. | Mapped |
| `password` | `User::password` | Existing `password_hash` / bcrypt hashes remain valid through Laravel authentication. Never expose in views/API. | Mapped |
| `nama_lengkap` | `User::nama_lengkap` | Display name for the authenticated account. | Documented |
| `email` | `User::email` | Optional legacy contact value. | Documented |
| `telepon` | `User::telepon` | Optional legacy contact value. | Documented |
| `foto_profil` | `User::foto_profil` | Legacy storage mapping is pending upload migration. | Documented |
| `role` | `User::role` | Allowed values: `admin`, `teller`, `anggota`; controls portal access. | Mapped |
| `status` | `User::status` | Only `aktif` accounts can authenticate. | Mapped |
| `last_login` | `User::last_login` | Set after a successful login. | Mapped |
| `created_at` | `User::created_at` | Legacy creation timestamp is read-only historical data. | Documented |

## Catalogue work queue

- [ ] `anggota`: all identity, status, account link, profile photo, and conditional document fields (schema inventory in progress; list fields mapped).
- [ ] `jenis_simpanan`, `simpanan`, `transaksi`, and `kas`: fields, balance effects, correction rules, and reports.
- [ ] `jenis_pinjaman`, tariffs, `pengajuan_pinjaman`, `pinjaman`, collateral, disbursement, installments, penalties, cancellation, settlement, and closing.
- [ ] All `layanan_*` tables and status transitions.
- [ ] SHU periods, calculations, entitlements, audits, and payments.
- [ ] Cooperative settings, agreement/signature fields, imports, exports, backup, and WhatsApp logs.

## Members — `anggota`

| Source fields | Laravel destination | Rule | Status |
| --- | --- | --- | --- |
| `id`, `kode_anggota`, `nama_lengkap` | `Anggota` model and MemberTable | Preserve legacy identity; code is unique. | Mapped |
| `telepon`, `status`, `tanggal_daftar` | MemberTable | Search/filter/list display fields. | Mapped |
| `kode_anggota_jaminan`, `kategori_shu`, `ketua_kelompok_id` | `Anggota` model | Guarantee code and SHU category are collected in the full form; group-leader link remains pending. | Documented |
| `alamat`, `email`, `tempat_lahir`, `tanggal_lahir`, `jenis_kelamin`, `pekerjaan`, `ktp`, `rekening_bank` | `Anggota` model | Full member form validates and stores these fields. | Mapped |
| `foto_ktp`, `foto_kk`, `foto_ktp_pasangan` | Private Laravel `local` disk | All three are required for create; JPG/PNG/WEBP with a 10 MB maximum. During edit, an omitted upload preserves the old document; a replacement removes the former Laravel-managed file only after commit. | Mapped |
| `user_id`, `nonaktif_jadwal`, `created_at` | `Anggota` model | Account/status and historical fields; detailed workflow pending. | Documented |

## Member loan read model

| Source tables | Laravel destination | Rule | Status |
| --- | --- | --- |
| `pinjaman`, `jenis_pinjaman` | Member detail / Pinjaman tab | List the member's loans with type, status, principal, remaining principal, frequency, tenor, and final due date. | Mapped |
| `angsuran` | Member detail / Pinjaman tab | List each loan's installment number, amount, penalty, due/payment dates, and status. No payment mutation is exposed from this tab. | Mapped |
| `shu_periode`, `shu_anggota`, `shu_pembayaran` | Member detail / SHU tab | List period entitlement, component values, total, and payment state; payment records remain read-only until the SHU module is delivered. | Mapped |
| `shu_hak_pinjaman` | Member detail / SHU tab | List member loan-related rights with base amount, rate, amount, and payment state. | Mapped |
| `log_koreksi`, `layanan_audit`, `shu_hak_audit` | Member detail / Audit tab | Only show trails directly attributable to this member's transactions, loans, or loan-SHU rights. No audit mutation is exposed. | Mapped |

## Simpanan, transaksi, dan kas

| Source fields / tables | Laravel destination | Rule | Status |
| --- | --- | --- | --- |
| `jenis_simpanan.kode_jenis`, `nama_jenis`, `deskripsi`, `minimum`, `maksimum`, `bagi_hasil`, `status` | Master jenis simpanan | Kode unik; status aktif/nonaktif menentukan ketersediaan untuk transaksi. Batas nominal harus divalidasi pada server. | Documented |
| `simpanan.anggota_id`, `jenis_simpanan_id`, `saldo` | Saldo simpanan anggota | Satu saldo per anggota/jenis menurut relasi legacy; mutasi hanya melalui Action transaksional. | Documented |
| `transaksi.kode_transaksi`, `jenis_transaksi`, `jumlah`, `saldo_sebelum`, `saldo_sesudah`, `keterangan`, `user_id`, `tanggal_transaksi` | Buku transaksi simpanan | Kode unik; jenis hanya `setoran`/`penarikan`; saldo sebelum/sesudah adalah jejak pembukuan, bukan nilai yang boleh diedit dari tabel. | Documented |
| `kas.transaksi_id`, `jenis`, `jumlah`, `saldo_sebelum`, `saldo_sesudah`, `tanggal` | Buku kas | Kas mengikuti transaksi sumber dan harus diproses atomik. Pembatalan/koreksi mempertahankan baris histori serta menyesuaikan saldo berantai. | Documented |
| `log_koreksi` | Audit koreksi | Menyimpan nilai lama/baru/selisih, alasan, dan pelaku; hanya Admin dapat memutasi. | Documented |

## Pinjaman, angsuran, jaminan, dan closing

| Source fields / tables | Laravel destination | Rule | Status |
| --- | --- | --- | --- |
| `jenis_pinjaman` dan `jenis_pinjaman_tarif` | Master produk pinjaman | Kode unik; maksimum, tenor, bunga, frekuensi (`bulanan`, `mingguan`, `dua_mingguan`), jaminan, dan status harus terjaga. Tarif tenor mengesampingkan/menentukan bunga menurut helper native saat diverifikasi di Fase 7. | Documented |
| `pengajuan_pinjaman` | Workflow pengajuan | Status: `menunggu`, `disetujui`, `ditolak`, `dicairkan`, `batal_cair`; simpan nilai pinjaman, tenor, bunga/skema, frekuensi, tujuan, jadwal, fasilitas prioritas, kategori/ketua kelompok, serta pelaku dan waktu keputusan. | Documented |
| `pinjaman` | Kontrak pinjaman aktif | Kode unik; simpan pokok, sisa pokok, total, cicilan, tanggal cair/jatuh tempo/lunas, frekuensi, foto cair, status `aktif`/`lunas`/`macet`, input mundur dan cicilan di muka. | Documented |
| `angsuran` | Jadwal dan pembayaran angsuran | Nomor cicilan unik per pinjaman secara bisnis; simpan pokok, bunga, denda, jatuh tempo/bayar, status `belum_bayar`/`lunas`/`telat`, cara bayar, hari terlambat, dan flag denda manual. | Documented |
| `jaminan_pinjaman` | Jaminan | Satu jaminan untuk tiap pengajuan native; status `dititipkan`/`dikembalikan`/`dilelang`, nilai taksiran dan foto harus dilindungi sebagai data sensitif. | Documented |
| `pelunasan_dini`, `pengajuan_pembatalan_angsuran` | Pelunasan dan pembatalan | Pelunasan mencatat nominal, pelaku, bukti cek/tanda tangan jaminan; pembatalan teller berstatus `menunggu`/`disetujui`/`ditolak` dan butuh keputusan Admin. | Documented |
| `closing_angsuran_bulanan`, `closing_angsuran_detail` | Closing periode | Bulan unik; status `terkunci`/`dibuka`, pelaku/waktu/catatan serta snapshot detail pembayaran wajib dipertahankan. | Documented |

## SHU, pengaturan, migrasi, dan layanan

| Source fields / tables | Laravel destination | Rule | Status |
| --- | --- | --- | --- |
| `shu_periode`, `shu_anggota` | Periode dan alokasi SHU | Periode per tahun unik; kebijakan/alokasi JSON, snapshot hash, persetujuan, komponen jasa dan status bayar adalah histori yang tidak boleh dihitung ulang sembarangan setelah disahkan. | Documented |
| `shu_hak_pinjaman`, `shu_hak_audit`, `shu_pembayaran`, `shu_audit` | Hak/pembayaran/audit SHU | Hak berstatus `belum`/`sudah`; pembayaran membutuhkan metode, bukti, referensi, nonce unik, transaksi, pembatalan dan pembalikan yang dapat diaudit. | Documented |
| `koperasi`, `settings`, `pengaturan_perjanjian`, `perjanjian_pinjaman`, `lampiran_perjanjian` | Pengaturan koperasi & dokumen perjanjian | Profil koperasi dan template/tanda tangan perjanjian bersifat konfigurasi sensitif; perubahan khusus Admin dan ber-audit. | Documented |
| `migrasi_import_item`, `migrasi_pembayaran`, `migrasi_anggota_dokumen`, `migrasi_dokumen_cadangan` | Import/cadangan | `source_key` menjadi kunci idempotensi dan dokumen cadangan tidak boleh dipublikasikan langsung. | Documented |
| `layanan_*`, `alokasi_dapin`, `pendanaan_dapin`, `pembelian_listrik`, `pembelian_indihome`, `waserda_transaksi`, `wa_pengingat_log` | Layanan lanjutan | Seluruh status, saldo, dokumen, dan notifikasi dipetakan per sub-alur pada Fase 8; tidak ada tabel yang dihapus dari cakupan. | Documented |

# Legacy schema baseline

`simkeu_laravel` is a cloned, legacy-compatible database. Its application tables are the schema baseline for this rewrite.

## Rules

1. Never run Laravel's starter `create_users`, `create_cache`, or `create_jobs` migrations against this database; they conflict with the legacy `users` table and are intentionally removed from this codebase.
2. All schema changes after the baseline must use new, reversible Laravel migrations.
3. Do not apply a migration to the native database. Laravel development and staging use only a clone such as `simkeu_laravel`.
4. Session, cache, and queue use file/sync drivers until their dedicated schema is introduced deliberately.
5. Before any new migration is executed, compare the target table structure and record the change in the Field & Rule Catalogue.

## Current verification

- Legacy source schema: 54 tables.
- Dump sumber yang diperiksa: 54 tabel. Clone juga memiliki `user_permissions` legacy yang tidak ada pada dump tersebut, ditambah tabel pelacakan `migrations` dan `activity_logs` Laravel (total saat verifikasi: 57 tabel).
- Legacy `users` table is retained; its IDs, roles, statuses, and bcrypt password hashes are not transformed.

## Kelompok skema terverifikasi

| Domain | Tabel sumber |
| --- | --- |
| Identitas | `users`, `anggota`, `koperasi`, `settings`, `user_permissions` |
| Simpanan & kas | `jenis_simpanan`, `simpanan`, `transaksi`, `kas`, `log_koreksi` |
| Pinjaman | `jenis_pinjaman`, `jenis_pinjaman_tarif`, `pengajuan_pinjaman`, `pinjaman`, `angsuran`, `jaminan_pinjaman`, `pelunasan_dini`, `pengajuan_pembatalan_angsuran`, `closing_angsuran_bulanan`, `closing_angsuran_detail`, `denda_tarif` |
| Layanan | seluruh tabel `layanan_*`, `alokasi_dapin`, `pendanaan_dapin`, `lampiran_perjanjian`, `perjanjian_pinjaman`, `pengaturan_perjanjian` |
| SHU | `periode`, `shu_periode`, `shu_anggota`, `shu_hak_pinjaman`, `shu_hak_audit`, `shu_audit`, `shu_pembayaran` |
| Migrasi & notifikasi | `migrasi_*`, `wa_pengingat_log` |
| Produk lain | `pembelian_listrik`, `pembelian_indihome`, `waserda_transaksi` |

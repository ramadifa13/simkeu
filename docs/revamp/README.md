# SIMKEU revamp — audit baseline

Tanggal: 22 Oktober 2026 (Asia/Jakarta). Source: `6eab4d2fcbed51d831df6a913ebb04760f18bc35`.

**Status: audit statis tahap pertama, bukan aplikasi revamp selesai dan bukan sertifikasi kesetaraan 100%.** Kode operasional lama tidak diubah. Implementasi transaksi baru belum dimulai karena schema awal tidak tersedia dan beberapa perilaku pembukuan saling berbeda.

## Dokumen

- [Audit, aturan, role, risiko, dan pertanyaan](audit.md)
- [Arsitektur dan rancangan UI/UX](architecture.md)
- [Migrasi dan acceptance gates](migration.md)
- [Inventaris source dan halaman](inventory.md): hasil pemindaian otomatis, bukan bukti seluruh halaman sudah diuji.
- [Status pengujian](verification.md)

## Input yang diperlukan untuk menutup audit

1. `simkeusi_koperasi_db.sql` **struktur saja**, atau export schema database aktual (tables, indexes, foreign keys, views, triggers, routines). Jangan kirim password/token maupun data pribadi nasabah. File yang disebut README tidak ada pada commit baseline; `MIGRASI_UNIVERSAL.sql` hanya upgrade, bukan bootstrap database.
2. Versi PHP dan MySQL/MariaDB hosting; dukungan cron, CLI/SSH/Composer, kemampuan document root menuju `public/`, batas upload/memory. Pilihan versi Laravel bergantung pada ini.
3. Sampel transaksi anonim beserta keluaran yang dianggap benar untuk isu D-01–D-07 di audit. Terutama saldo simpanan, pencairan, koreksi, Waserda, serta SHU setelah pembatalan pelunasan.

Audit lanjutan harus memeriksa setiap route/action dengan database uji, setiap role, dan output laporan. Inventaris menjadi daftar cakupan; tidak ada halaman yang dianggap lolos hanya karena nama file telah tercatat.

## Reproduksi

Jalankan dari root repository:

```sh
python3 scripts/revamp/inventory.py
node tests/revamp/legacy-preview.test.cjs
bash scripts/revamp/test-legacy.sh
```

Perintah terakhir membutuhkan PHP CLI. Test database lama membutuhkan database terisolasi bernama mengandung `test`, schema asli, dan fixture yang sesuai. Tidak menjalankan cron WhatsApp, restore backup, atau transaksi pada produksi.

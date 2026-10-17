-- =====================================================================
-- Migrasi Hari Angsuran (setelah migrasi sebelumnya). Jalankan SEKALI.
-- pengajuan_pinjaman.hari_angsuran : 1 = Senin ... 7 = Minggu (NULL = pengajuan lama, belum dikategorikan)
-- Dipakai untuk memfilter menu Angsuran per hari. Aman untuk data lama.
-- =====================================================================
ALTER TABLE `pengajuan_pinjaman`
  ADD COLUMN `hari_angsuran` TINYINT(1) NULL DEFAULT NULL;

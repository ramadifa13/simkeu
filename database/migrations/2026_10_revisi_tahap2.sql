-- =====================================================================
-- Migrasi revisi tahap 2 (jalankan SEKALI, setelah migrasi tahap 1 & 2026_10b)
--   B/C  pencairan oleh Teller + foto nasabah   -> pinjaman.foto_pencairan, dicairkan_oleh
--   A    bukti pelunasan                          -> pinjaman.tanggal_lunas
--   F    profil teller                            -> users.email, telepon, foto_profil
-- Aman untuk data lama: hanya ADD COLUMN (NULL) + backfill tanggal_lunas.
-- =====================================================================
ALTER TABLE `pinjaman`
  ADD COLUMN `foto_pencairan` VARCHAR(255) DEFAULT NULL AFTER `jatuh_tempo`,
  ADD COLUMN `dicairkan_oleh` INT(11) DEFAULT NULL AFTER `foto_pencairan`,
  ADD COLUMN `tanggal_lunas` DATE DEFAULT NULL AFTER `dicairkan_oleh`;

-- Pinjaman lama yang sudah lunas: tanggal lunas = tanggal bayar angsuran terakhir
UPDATE `pinjaman` p
   SET p.`tanggal_lunas` = (SELECT MAX(DATE(a.`tanggal_bayar`)) FROM `angsuran` a WHERE a.`pinjaman_id` = p.`id`)
 WHERE p.`status` = 'lunas' AND p.`tanggal_lunas` IS NULL;

ALTER TABLE `users`
  ADD COLUMN `email` VARCHAR(100) DEFAULT NULL AFTER `nama_lengkap`,
  ADD COLUMN `telepon` VARCHAR(20) DEFAULT NULL AFTER `email`,
  ADD COLUMN `foto_profil` VARCHAR(255) DEFAULT NULL AFTER `telepon`;

-- Jalankan satu kali setelah 2026_10f_gadai_tenor_6_bulan.sql.
-- Kode utama AGT tetap dipakai untuk akun/login. Kode AGTJ membedakan nasabah gadai.
ALTER TABLE `anggota`
  ADD COLUMN IF NOT EXISTS `kode_anggota_jaminan` VARCHAR(20) DEFAULT NULL AFTER `kode_anggota`;

-- Pembuatan index dibuat aman bila migrasi dijalankan ulang.
SET @index_agtj_ada = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'anggota'
    AND index_name = 'uniq_kode_anggota_jaminan'
);
SET @sql_index_agtj = IF(
  @index_agtj_ada = 0,
  'ALTER TABLE `anggota` ADD UNIQUE KEY `uniq_kode_anggota_jaminan` (`kode_anggota_jaminan`)',
  'SELECT 1'
);
PREPARE stmt_index_agtj FROM @sql_index_agtj;
EXECUTE stmt_index_agtj;
DEALLOCATE PREPARE stmt_index_agtj;

-- MIGRASI GABUNGAN 10g + 10h
-- Gunakan file ini bila 10g dan 10h BELUM PERNAH dijalankan.
-- Setelah file ini berhasil, jangan jalankan 10g dan 10h secara terpisah.

-- 10g: kode khusus nasabah gadai AGTJ001, AGTJ002, dst.
ALTER TABLE `anggota`
  ADD COLUMN IF NOT EXISTS `kode_anggota_jaminan` VARCHAR(20) DEFAULT NULL AFTER `kode_anggota`;

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

-- 10h: dua foto tambahan untuk pencairan gadai.
-- Foto nasabah menerima uang tetap memakai pinjaman.foto_pencairan.
ALTER TABLE `jaminan_pinjaman`
  ADD COLUMN IF NOT EXISTS `foto_barang` VARCHAR(255) DEFAULT NULL AFTER `nilai_taksiran`,
  ADD COLUMN IF NOT EXISTS `foto_nasabah_barang` VARCHAR(255) DEFAULT NULL AFTER `foto_barang`;

-- Jalankan satu kali setelah 2026_10g_batas_pinjaman_kode_gadai.sql.
-- Foto penerimaan uang tetap disimpan di pinjaman.foto_pencairan.
ALTER TABLE `jaminan_pinjaman`
  ADD COLUMN IF NOT EXISTS `foto_barang` VARCHAR(255) DEFAULT NULL AFTER `nilai_taksiran`,
  ADD COLUMN IF NOT EXISTS `foto_nasabah_barang` VARCHAR(255) DEFAULT NULL AFTER `foto_barang`;

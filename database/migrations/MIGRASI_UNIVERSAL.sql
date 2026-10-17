-- ============================================================================
-- MIGRASI UNIVERSAL SIMKEU
-- Prasyarat: database awal simkeusi_koperasi_db.sql sudah diimport.
-- Aman untuk database yang belum, sebagian, atau sudah pernah dimigrasi.
-- ============================================================================

ALTER TABLE `jenis_pinjaman`
  MODIFY `bunga` DECIMAL(6,2) NOT NULL DEFAULT '0.00',
  ADD COLUMN IF NOT EXISTS `frekuensi_diizinkan` SET('bulanan','mingguan','dua_mingguan') NOT NULL DEFAULT 'bulanan' AFTER `bunga`,
  ADD COLUMN IF NOT EXISTS `memerlukan_jaminan` TINYINT(1) NOT NULL DEFAULT 0 AFTER `frekuensi_diizinkan`;
ALTER TABLE `jenis_pinjaman` MODIFY `frekuensi_diizinkan` SET('bulanan','mingguan','dua_mingguan') NOT NULL DEFAULT 'bulanan';

ALTER TABLE `pengajuan_pinjaman`
  MODIFY `bunga` DECIMAL(6,2) NOT NULL,
  ADD COLUMN IF NOT EXISTS `skema_bunga` ENUM('tahunan','flat') NOT NULL DEFAULT 'tahunan' AFTER `bunga`,
  ADD COLUMN IF NOT EXISTS `frekuensi` ENUM('bulanan','mingguan','dua_mingguan') NOT NULL DEFAULT 'bulanan' AFTER `skema_bunga`,
  ADD COLUMN IF NOT EXISTS `hari_angsuran` TINYINT(1) NULL DEFAULT NULL;
ALTER TABLE `pengajuan_pinjaman`
  MODIFY `skema_bunga` ENUM('tahunan','flat') NOT NULL DEFAULT 'flat',
  MODIFY `frekuensi` ENUM('bulanan','mingguan','dua_mingguan') NOT NULL DEFAULT 'bulanan';

ALTER TABLE `pinjaman`
  MODIFY `bunga` DECIMAL(6,2) NOT NULL,
  ADD COLUMN IF NOT EXISTS `skema_bunga` ENUM('tahunan','flat') NOT NULL DEFAULT 'tahunan' AFTER `bunga`,
  ADD COLUMN IF NOT EXISTS `frekuensi` ENUM('bulanan','mingguan','dua_mingguan') NOT NULL DEFAULT 'bulanan' AFTER `skema_bunga`,
  ADD COLUMN IF NOT EXISTS `jumlah_cicilan` INT(11) DEFAULT NULL AFTER `frekuensi`,
  ADD COLUMN IF NOT EXISTS `foto_pencairan` VARCHAR(255) DEFAULT NULL AFTER `jatuh_tempo`,
  ADD COLUMN IF NOT EXISTS `dicairkan_oleh` INT(11) DEFAULT NULL AFTER `foto_pencairan`,
  ADD COLUMN IF NOT EXISTS `tanggal_lunas` DATE DEFAULT NULL AFTER `dicairkan_oleh`,
  ADD COLUMN IF NOT EXISTS `input_mundur` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `cicilan_dimuka` INT(11) NOT NULL DEFAULT 0;
ALTER TABLE `pinjaman`
  MODIFY `skema_bunga` ENUM('tahunan','flat') NOT NULL DEFAULT 'flat',
  MODIFY `frekuensi` ENUM('bulanan','mingguan','dua_mingguan') NOT NULL DEFAULT 'bulanan';
UPDATE `pinjaman` SET `jumlah_cicilan`=`tenor` WHERE `jumlah_cicilan` IS NULL;
UPDATE `pinjaman` p SET p.`tanggal_lunas`=(SELECT MAX(DATE(a.`tanggal_bayar`)) FROM `angsuran` a WHERE a.`pinjaman_id`=p.`id`)
 WHERE p.`status`='lunas' AND p.`tanggal_lunas` IS NULL;

SET @status_type=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pengajuan_pinjaman' AND COLUMN_NAME='status');
SET @sql_status=IF(@status_type LIKE '%''dicairkan''%','SELECT 1',CONCAT('ALTER TABLE `pengajuan_pinjaman` MODIFY `status` ',LEFT(@status_type,CHAR_LENGTH(@status_type)-1),',''dicairkan'') DEFAULT ''menunggu'''));
PREPARE stmt_status FROM @sql_status; EXECUTE stmt_status; DEALLOCATE PREPARE stmt_status;

CREATE TABLE IF NOT EXISTS `jenis_pinjaman_tarif` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `jenis_pinjaman_id` INT(11) NOT NULL, `tenor_bulan` INT(11) NOT NULL,
  `bunga` DECIMAL(6,2) NOT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uniq_jenis_tenor` (`jenis_pinjaman_id`,`tenor_bulan`),
  CONSTRAINT `jenis_pinjaman_tarif_ibfk_1` FOREIGN KEY (`jenis_pinjaman_id`) REFERENCES `jenis_pinjaman` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

ALTER TABLE `anggota`
  ADD COLUMN IF NOT EXISTS `kode_anggota_jaminan` VARCHAR(20) DEFAULT NULL AFTER `kode_anggota`,
  ADD COLUMN IF NOT EXISTS `foto_kk` VARCHAR(255) DEFAULT NULL AFTER `foto_ktp`,
  ADD COLUMN IF NOT EXISTS `foto_ktp_pasangan` VARCHAR(255) DEFAULT NULL AFTER `foto_kk`;
SET @idx_agtj=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='anggota' AND index_name='uniq_kode_anggota_jaminan');
SET @sql_agtj=IF(@idx_agtj=0,'ALTER TABLE `anggota` ADD UNIQUE KEY `uniq_kode_anggota_jaminan` (`kode_anggota_jaminan`)','SELECT 1');
PREPARE stmt_agtj FROM @sql_agtj; EXECUTE stmt_agtj; DEALLOCATE PREPARE stmt_agtj;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `email` VARCHAR(100) DEFAULT NULL AFTER `nama_lengkap`,
  ADD COLUMN IF NOT EXISTS `telepon` VARCHAR(20) DEFAULT NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `foto_profil` VARCHAR(255) DEFAULT NULL AFTER `telepon`;

ALTER TABLE `angsuran`
  ADD COLUMN IF NOT EXISTS `cara_bayar` ENUM('cicilan','pelunasan') NOT NULL DEFAULT 'cicilan' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `denda_manual` TINYINT(1) NOT NULL DEFAULT 0 AFTER `denda`;
UPDATE `angsuran` SET `denda_manual`=1 WHERE `denda`>0 AND `status`<>'lunas';

CREATE TABLE IF NOT EXISTS `denda_tarif` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `jumlah_pinjaman` DECIMAL(15,2) NOT NULL, `denda_per_hari` DECIMAL(15,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`), UNIQUE KEY `uniq_jumlah` (`jumlah_pinjaman`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
INSERT IGNORE INTO `denda_tarif` (`jumlah_pinjaman`,`denda_per_hari`) VALUES (1000000,1500),(2000000,2500),(3000000,3500);

CREATE TABLE IF NOT EXISTS `pengajuan_pembatalan_angsuran` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `angsuran_id` INT(11) NOT NULL, `teller_id` INT(11) NOT NULL,
  `alasan` TEXT NOT NULL, `tanggal_pengajuan` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('menunggu','disetujui','ditolak') NOT NULL DEFAULT 'menunggu', `aktif` TINYINT(1) DEFAULT 1,
  `admin_id` INT(11) DEFAULT NULL, `catatan_admin` TEXT, `tanggal_keputusan` DATETIME DEFAULT NULL,
  `dilihat_teller` TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_angsuran_aktif` (`angsuran_id`,`aktif`), KEY `idx_status` (`status`),
  CONSTRAINT `ppa_angsuran_fk` FOREIGN KEY (`angsuran_id`) REFERENCES `angsuran` (`id`),
  CONSTRAINT `ppa_teller_fk` FOREIGN KEY (`teller_id`) REFERENCES `users` (`id`),
  CONSTRAINT `ppa_admin_fk` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
ALTER TABLE `pengajuan_pembatalan_angsuran` ADD COLUMN IF NOT EXISTS `dilihat_teller` TINYINT(1) NOT NULL DEFAULT 0;
UPDATE `pengajuan_pembatalan_angsuran` SET `dilihat_teller`=1 WHERE `status`<>'menunggu';

CREATE TABLE IF NOT EXISTS `jaminan_pinjaman` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `pengajuan_id` INT(11) NOT NULL, `pinjaman_id` INT(11) DEFAULT NULL,
  `jenis_barang` VARCHAR(100) NOT NULL, `identitas_barang` VARCHAR(100) NOT NULL, `deskripsi_barang` TEXT NOT NULL,
  `nilai_taksiran` DECIMAL(15,2) NOT NULL, `foto_barang` VARCHAR(255) DEFAULT NULL, `foto_nasabah_barang` VARCHAR(255) DEFAULT NULL,
  `status_jaminan` ENUM('dititipkan','dikembalikan','dilelang') NOT NULL DEFAULT 'dititipkan',
  `tanggal_dikembalikan` DATE DEFAULT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uniq_jaminan_pengajuan` (`pengajuan_id`), KEY `idx_jaminan_pinjaman` (`pinjaman_id`),
  CONSTRAINT `fk_jaminan_pengajuan` FOREIGN KEY (`pengajuan_id`) REFERENCES `pengajuan_pinjaman` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jaminan_pinjaman` FOREIGN KEY (`pinjaman_id`) REFERENCES `pinjaman` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
ALTER TABLE `jaminan_pinjaman`
  ADD COLUMN IF NOT EXISTS `foto_barang` VARCHAR(255) DEFAULT NULL AFTER `nilai_taksiran`,
  ADD COLUMN IF NOT EXISTS `foto_nasabah_barang` VARCHAR(255) DEFAULT NULL AFTER `foto_barang`;

CREATE TABLE IF NOT EXISTS `pelunasan_dini` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `pinjaman_id` INT(11) NOT NULL, `jumlah_pelunasan` DECIMAL(15,2) NOT NULL,
  `keterangan` TEXT DEFAULT NULL, `user_id` INT(11) NOT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_pelunasan_pinjaman` (`pinjaman_id`), KEY `idx_pelunasan_user` (`user_id`),
  CONSTRAINT `fk_pelunasan_pinjaman` FOREIGN KEY (`pinjaman_id`) REFERENCES `pinjaman` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pelunasan_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `pengaturan_perjanjian` (
  `id` INT NOT NULL DEFAULT 1, `nama_pihak_pertama` VARCHAR(120) DEFAULT NULL, `nik_pihak_pertama` VARCHAR(30) DEFAULT NULL,
  `alamat_pihak_pertama` TEXT DEFAULT NULL, `telepon_pihak_pertama` VARCHAR(30) DEFAULT NULL,
  `jabatan_admin` VARCHAR(100) NOT NULL DEFAULT 'Pengurus Koperasi', `template_default` ENUM('ringkas','lengkap') NOT NULL DEFAULT 'ringkas',
  `isi_ringkas` TEXT NOT NULL, `isi_lengkap` MEDIUMTEXT NOT NULL, `ttd_admin` MEDIUMTEXT DEFAULT NULL,
  `updated_by` INT DEFAULT NULL, `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `pengaturan_perjanjian`
  ADD COLUMN IF NOT EXISTS `nama_pihak_pertama` VARCHAR(120) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `nik_pihak_pertama` VARCHAR(30) DEFAULT NULL AFTER `nama_pihak_pertama`,
  ADD COLUMN IF NOT EXISTS `alamat_pihak_pertama` TEXT DEFAULT NULL AFTER `nik_pihak_pertama`,
  ADD COLUMN IF NOT EXISTS `telepon_pihak_pertama` VARCHAR(30) DEFAULT NULL AFTER `alamat_pihak_pertama`;

CREATE TABLE IF NOT EXISTS `perjanjian_pinjaman` (
  `id` INT NOT NULL AUTO_INCREMENT, `pinjaman_id` INT NOT NULL, `nomor_perjanjian` VARCHAR(60) NOT NULL,
  `versi` ENUM('ringkas','lengkap') NOT NULL DEFAULT 'ringkas', `snapshot_json` MEDIUMTEXT NOT NULL,
  `nama_saksi_1` VARCHAR(100) NOT NULL, `nama_saksi_2` VARCHAR(100) NOT NULL,
  `ttd_nasabah` MEDIUMTEXT NOT NULL, `ttd_teller` MEDIUMTEXT NOT NULL, `ttd_admin` MEDIUMTEXT NOT NULL,
  `ttd_saksi_1` MEDIUMTEXT NOT NULL, `ttd_saksi_2` MEDIUMTEXT NOT NULL, `hash_dokumen` CHAR(64) NOT NULL,
  `ditandatangani_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uniq_perjanjian_pinjaman` (`pinjaman_id`),
  CONSTRAINT `fk_perjanjian_pinjaman` FOREIGN KEY (`pinjaman_id`) REFERENCES `pinjaman` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lampiran_perjanjian` (
  `id` INT NOT NULL AUTO_INCREMENT, `perjanjian_id` INT NOT NULL, `file_path` VARCHAR(255) NOT NULL,
  `urutan` INT NOT NULL DEFAULT 1, PRIMARY KEY (`id`), KEY `idx_lampiran_perjanjian` (`perjanjian_id`),
  CONSTRAINT `fk_lampiran_perjanjian` FOREIGN KEY (`perjanjian_id`) REFERENCES `perjanjian_pinjaman` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Migrasi universal selesai dan aman dijalankan kembali.' AS hasil;

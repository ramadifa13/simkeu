-- Pengaturan template dapat diedit Admin; arsip transaksi menyimpan snapshot sendiri.
CREATE TABLE IF NOT EXISTS `pengaturan_perjanjian` (
  `id` INT NOT NULL DEFAULT 1,
  `nama_pihak_pertama` VARCHAR(120) DEFAULT NULL,
  `nik_pihak_pertama` VARCHAR(30) DEFAULT NULL,
  `alamat_pihak_pertama` TEXT DEFAULT NULL,
  `telepon_pihak_pertama` VARCHAR(30) DEFAULT NULL,
  `jabatan_admin` VARCHAR(100) NOT NULL DEFAULT 'Pengurus Koperasi',
  `template_default` ENUM('ringkas','lengkap') NOT NULL DEFAULT 'ringkas',
  `isi_ringkas` TEXT NOT NULL,
  `isi_lengkap` MEDIUMTEXT NOT NULL,
  `ttd_admin` MEDIUMTEXT DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Baris ini menjaga migrasi tetap dapat dijalankan ulang jika tabel 10i sudah sempat dibuat.
ALTER TABLE `pengaturan_perjanjian`
  ADD COLUMN IF NOT EXISTS `nama_pihak_pertama` VARCHAR(120) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `nik_pihak_pertama` VARCHAR(30) DEFAULT NULL AFTER `nama_pihak_pertama`,
  ADD COLUMN IF NOT EXISTS `alamat_pihak_pertama` TEXT DEFAULT NULL AFTER `nik_pihak_pertama`,
  ADD COLUMN IF NOT EXISTS `telepon_pihak_pertama` VARCHAR(30) DEFAULT NULL AFTER `alamat_pihak_pertama`;

CREATE TABLE IF NOT EXISTS `perjanjian_pinjaman` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `pinjaman_id` INT NOT NULL,
  `nomor_perjanjian` VARCHAR(60) NOT NULL,
  `versi` ENUM('ringkas','lengkap') NOT NULL DEFAULT 'ringkas',
  `snapshot_json` MEDIUMTEXT NOT NULL,
  `nama_saksi_1` VARCHAR(100) NOT NULL,
  `nama_saksi_2` VARCHAR(100) NOT NULL,
  `ttd_nasabah` MEDIUMTEXT NOT NULL,
  `ttd_teller` MEDIUMTEXT NOT NULL,
  `ttd_admin` MEDIUMTEXT NOT NULL,
  `ttd_saksi_1` MEDIUMTEXT NOT NULL,
  `ttd_saksi_2` MEDIUMTEXT NOT NULL,
  `hash_dokumen` CHAR(64) NOT NULL,
  `ditandatangani_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_perjanjian_pinjaman` (`pinjaman_id`),
  CONSTRAINT `fk_perjanjian_pinjaman` FOREIGN KEY (`pinjaman_id`) REFERENCES `pinjaman` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lampiran_perjanjian` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `perjanjian_id` INT NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `urutan` INT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_lampiran_perjanjian` (`perjanjian_id`),
  CONSTRAINT `fk_lampiran_perjanjian` FOREIGN KEY (`perjanjian_id`) REFERENCES `perjanjian_pinjaman` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

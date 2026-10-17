-- =====================================================================
-- Migrasi revisi tahap 3 (jalankan SEKALI, setelah migrasi sebelumnya):
-- Pengajuan pembatalan pembayaran angsuran (Teller -> Admin).
-- Aman untuk data lama: hanya membuat tabel baru.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `pengajuan_pembatalan_angsuran` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `angsuran_id` INT(11) NOT NULL,
  `teller_id` INT(11) NOT NULL,
  `alasan` TEXT NOT NULL,
  `tanggal_pengajuan` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('menunggu','disetujui','ditolak') NOT NULL DEFAULT 'menunggu',
  -- 1 selama menunggu, NULL setelah diputuskan: UNIQUE(angsuran_id, aktif) menjamin satu angsuran
  -- hanya punya satu pengajuan menunggu (NULL boleh berulang di UNIQUE).
  `aktif` TINYINT(1) DEFAULT 1,
  `admin_id` INT(11) DEFAULT NULL,
  `catatan_admin` TEXT,
  `tanggal_keputusan` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_angsuran_aktif` (`angsuran_id`, `aktif`),
  KEY `idx_status` (`status`),
  CONSTRAINT `ppa_angsuran_fk` FOREIGN KEY (`angsuran_id`) REFERENCES `angsuran` (`id`),
  CONSTRAINT `ppa_teller_fk` FOREIGN KEY (`teller_id`) REFERENCES `users` (`id`),
  CONSTRAINT `ppa_admin_fk` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

ALTER TABLE `jenis_pinjaman`
  MODIFY `frekuensi_diizinkan` SET('bulanan','mingguan','dua_mingguan') NOT NULL DEFAULT 'bulanan',
  ADD COLUMN IF NOT EXISTS `memerlukan_jaminan` TINYINT(1) NOT NULL DEFAULT 0 AFTER `frekuensi_diizinkan`;

ALTER TABLE `pengajuan_pinjaman`
  MODIFY `frekuensi` ENUM('bulanan','mingguan','dua_mingguan') NOT NULL DEFAULT 'bulanan';

ALTER TABLE `pinjaman`
  MODIFY `frekuensi` ENUM('bulanan','mingguan','dua_mingguan') NOT NULL DEFAULT 'bulanan';

CREATE TABLE IF NOT EXISTS `jaminan_pinjaman` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pengajuan_id` INT(11) NOT NULL,
  `pinjaman_id` INT(11) DEFAULT NULL,
  `jenis_barang` VARCHAR(100) NOT NULL,
  `identitas_barang` VARCHAR(100) NOT NULL COMMENT 'Nopol, IMEI, nomor seri, atau identitas lain',
  `deskripsi_barang` TEXT NOT NULL,
  `nilai_taksiran` DECIMAL(15,2) NOT NULL,
  `status_jaminan` ENUM('dititipkan','dikembalikan','dilelang') NOT NULL DEFAULT 'dititipkan',
  `tanggal_dikembalikan` DATE DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jaminan_pengajuan` (`pengajuan_id`),
  KEY `idx_jaminan_pinjaman` (`pinjaman_id`),
  CONSTRAINT `fk_jaminan_pengajuan` FOREIGN KEY (`pengajuan_id`) REFERENCES `pengajuan_pinjaman` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jaminan_pinjaman` FOREIGN KEY (`pinjaman_id`) REFERENCES `pinjaman` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Memperbaiki bug lama: proses pelunasan dini menulis ke tabel yang sebelumnya belum dibuat.
CREATE TABLE IF NOT EXISTS `pelunasan_dini` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pinjaman_id` INT(11) NOT NULL,
  `jumlah_pelunasan` DECIMAL(15,2) NOT NULL,
  `keterangan` TEXT DEFAULT NULL,
  `user_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pelunasan_pinjaman` (`pinjaman_id`),
  KEY `idx_pelunasan_user` (`user_id`),
  CONSTRAINT `fk_pelunasan_pinjaman` FOREIGN KEY (`pinjaman_id`) REFERENCES `pinjaman` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pelunasan_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

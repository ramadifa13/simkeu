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
SET @status_type=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pengajuan_pinjaman' AND COLUMN_NAME='status');
SET @sql_status=IF(@status_type LIKE '%''batal_cair''%','SELECT 1',CONCAT('ALTER TABLE `pengajuan_pinjaman` MODIFY `status` ',LEFT(@status_type,CHAR_LENGTH(@status_type)-1),',''batal_cair'') DEFAULT ''menunggu'''));
PREPARE stmt_status_batal FROM @sql_status; EXECUTE stmt_status_batal; DEALLOCATE PREPARE stmt_status_batal;

CREATE TABLE IF NOT EXISTS `jenis_pinjaman_tarif` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `jenis_pinjaman_id` INT(11) NOT NULL, `tenor_bulan` INT(11) NOT NULL,
  `bunga` DECIMAL(6,2) NOT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uniq_jenis_tenor` (`jenis_pinjaman_id`,`tenor_bulan`),
  CONSTRAINT `jenis_pinjaman_tarif_ibfk_1` FOREIGN KEY (`jenis_pinjaman_id`) REFERENCES `jenis_pinjaman` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

ALTER TABLE `anggota`
  ADD COLUMN IF NOT EXISTS `kode_anggota_jaminan` VARCHAR(20) DEFAULT NULL AFTER `kode_anggota`,
  ADD COLUMN IF NOT EXISTS `foto_kk` VARCHAR(255) DEFAULT NULL AFTER `foto_ktp`,
  ADD COLUMN IF NOT EXISTS `foto_ktp_pasangan` VARCHAR(255) DEFAULT NULL AFTER `foto_kk`,
  ADD COLUMN IF NOT EXISTS `nonaktif_jadwal` DATE NULL DEFAULT NULL AFTER `status`;

-- Perbaiki tanggal_bayar hasil migrasi catch-up: cicilan lama tidak ikut menyatu
-- ke bulan pembayaran terakhir (menggelembungkan closing).
UPDATE angsuran a
JOIN migrasi_pembayaran mp
  ON mp.pinjaman_id = a.pinjaman_id
 AND mp.tanggal_bayar = a.tanggal_bayar
 AND mp.jenis_pembayaran = 'cicilan'
SET a.tanggal_bayar = a.tanggal_jatuh_tempo
WHERE a.status = 'lunas'
  AND a.angsuran_ke < mp.angsuran_ke
  AND a.tanggal_jatuh_tempo IS NOT NULL
  AND a.tanggal_jatuh_tempo < a.tanggal_bayar;

UPDATE angsuran a
JOIN migrasi_pembayaran mp
  ON mp.pinjaman_id = a.pinjaman_id
 AND mp.tanggal_bayar = a.tanggal_bayar
 AND mp.jenis_pembayaran = 'pelunasan'
SET a.tanggal_bayar = a.tanggal_jatuh_tempo
WHERE a.status = 'lunas'
  AND a.cara_bayar = 'pelunasan'
  AND a.angsuran_ke <> mp.angsuran_ke
  AND a.tanggal_jatuh_tempo IS NOT NULL
  AND a.tanggal_jatuh_tempo <> a.tanggal_bayar;
SET @idx_agtj=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='anggota' AND index_name='uniq_kode_anggota_jaminan');
SET @sql_agtj=IF(@idx_agtj=0,'ALTER TABLE `anggota` ADD UNIQUE KEY `uniq_kode_anggota_jaminan` (`kode_anggota_jaminan`)','SELECT 1');
PREPARE stmt_agtj FROM @sql_agtj; EXECUTE stmt_agtj; DEALLOCATE PREPARE stmt_agtj;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `email` VARCHAR(100) DEFAULT NULL AFTER `nama_lengkap`,
  ADD COLUMN IF NOT EXISTS `telepon` VARCHAR(20) DEFAULT NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `foto_profil` VARCHAR(255) DEFAULT NULL AFTER `telepon`;

SET @denda_manual_sudah_ada=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='angsuran' AND COLUMN_NAME='denda_manual');
ALTER TABLE `angsuran`
  ADD COLUMN IF NOT EXISTS `cara_bayar` ENUM('cicilan','pelunasan') NOT NULL DEFAULT 'cicilan' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `denda_manual` TINYINT(1) NOT NULL DEFAULT 0 AFTER `denda`;
UPDATE `angsuran` SET `denda_manual`=1 WHERE @denda_manual_sudah_ada=0 AND `denda`>0 AND `status`<>'lunas';

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
ALTER TABLE `pelunasan_dini`
  ADD COLUMN IF NOT EXISTS `cek_jaminan_nasabah` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `ttd_jaminan_nasabah` MEDIUMTEXT DEFAULT NULL AFTER `cek_jaminan_nasabah`;

CREATE TABLE IF NOT EXISTS `pengaturan_perjanjian` (
  `id` INT NOT NULL DEFAULT 1, `nama_pihak_pertama` VARCHAR(120) DEFAULT NULL, `nik_pihak_pertama` VARCHAR(30) DEFAULT NULL,
  `alamat_pihak_pertama` TEXT DEFAULT NULL, `telepon_pihak_pertama` VARCHAR(30) DEFAULT NULL,
  `jabatan_admin` VARCHAR(100) NOT NULL DEFAULT 'Pengurus Koperasi', `template_default` ENUM('ringkas','lengkap') NOT NULL DEFAULT 'lengkap',
  `isi_ringkas` TEXT NOT NULL, `isi_lengkap` MEDIUMTEXT NOT NULL, `ttd_admin` MEDIUMTEXT DEFAULT NULL,
  `updated_by` INT DEFAULT NULL, `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `pengaturan_perjanjian`
  ADD COLUMN IF NOT EXISTS `nama_pihak_pertama` VARCHAR(120) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `nik_pihak_pertama` VARCHAR(30) DEFAULT NULL AFTER `nama_pihak_pertama`,
  ADD COLUMN IF NOT EXISTS `alamat_pihak_pertama` TEXT DEFAULT NULL AFTER `nik_pihak_pertama`,
  ADD COLUMN IF NOT EXISTS `telepon_pihak_pertama` VARCHAR(30) DEFAULT NULL AFTER `alamat_pihak_pertama`;
ALTER TABLE `pengaturan_perjanjian`
  MODIFY `template_default` ENUM('ringkas','lengkap') NOT NULL DEFAULT 'lengkap';
-- Pertahankan pilihan template yang sudah disimpan Admin saat migrasi diulang.

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

-- Layanan pinjaman: seluruh perubahan hanya ada pada migrasi universal ini.
CREATE TABLE IF NOT EXISTS layanan_audit (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, pinjaman_id INT NOT NULL, user_id INT NOT NULL,
 aksi VARCHAR(60) NOT NULL, detail TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_audit_pinjaman(pinjaman_id), KEY idx_audit_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS layanan_laporan_macet (
 id INT AUTO_INCREMENT PRIMARY KEY, pinjaman_id INT NOT NULL, user_id INT NOT NULL,
 hari_telat INT NOT NULL, sisa_hutang DECIMAL(15,2) NOT NULL, catatan TEXT NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'menunggu', admin_id INT NULL, keputusan TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, diputuskan_at DATETIME NULL,
 KEY idx_laporan_pinjaman(pinjaman_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS layanan_bayar_denda (
 id INT AUTO_INCREMENT PRIMARY KEY, angsuran_id INT NOT NULL, pinjaman_id INT NOT NULL,
 user_id INT NOT NULL, jumlah DECIMAL(15,2) NOT NULL, metode VARCHAR(10) NOT NULL,
 bukti VARCHAR(255) NOT NULL, transaksi_id INT NOT NULL, approved_by INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_denda_angsuran(angsuran_id), KEY idx_denda_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS layanan_pelunasan (
 id INT AUTO_INCREMENT PRIMARY KEY, pinjaman_id INT NOT NULL, user_id INT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'menunggu', diskon_persen DECIMAL(5,2) NOT NULL DEFAULT 0,
 admin_id INT NULL, diputuskan_at DATETIME NULL, catatan TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_lunas_pinjaman(pinjaman_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS layanan_jaminan_kembali (
 id INT AUTO_INCREMENT PRIMARY KEY, pinjaman_id INT NOT NULL, user_id INT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'menunggu', admin_id INT NULL,
 foto VARCHAR(255) NULL, ttd MEDIUMTEXT NULL, diserahkan_at DATETIME NULL,
 UNIQUE KEY uniq_kembali_pinjaman(pinjaman_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE layanan_jaminan_kembali
 ADD COLUMN IF NOT EXISTS alasan TEXT NULL,
 ADD COLUMN IF NOT EXISTS bukti_jaminan VARCHAR(255) NULL,
 ADD COLUMN IF NOT EXISTS bukti_lunas VARCHAR(255) NULL,
 ADD COLUMN IF NOT EXISTS catatan_admin TEXT NULL,
 ADD COLUMN IF NOT EXISTS diperiksa_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS ttd_teller MEDIUMTEXT NULL;
CREATE TABLE IF NOT EXISTS layanan_tunda_cair (
 id INT AUTO_INCREMENT PRIMARY KEY, pengajuan_id INT NOT NULL, user_id INT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'menunggu', alasan TEXT NOT NULL,
 admin_id INT NULL, catatan_admin TEXT NULL, diputuskan_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_tunda_cair_pengajuan(pengajuan_id), KEY idx_tunda_cair_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- TC yang sudah disetujui sebelum revisi ini dianggap pembatalan pencairan.
UPDATE pengajuan_pinjaman pp JOIN layanan_tunda_cair tc ON tc.pengajuan_id=pp.id
LEFT JOIN pinjaman p ON p.pengajuan_id=pp.id
SET pp.status='batal_cair'
WHERE tc.status='disetujui' AND p.id IS NULL AND pp.status='disetujui';
CREATE TABLE IF NOT EXISTS layanan_prioritas (
 id INT AUTO_INCREMENT PRIMARY KEY, pinjaman_id INT NOT NULL, anggota_id INT NOT NULL, user_id INT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'menunggu', admin_id INT NULL,
 UNIQUE KEY uniq_prioritas_pinjaman(pinjaman_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE layanan_prioritas
 ADD COLUMN IF NOT EXISTS catatan TEXT NULL,
 ADD COLUMN IF NOT EXISTS koreksi TEXT NULL,
 ADD COLUMN IF NOT EXISTS koreksi_admin_id INT NULL,
 ADD COLUMN IF NOT EXISTS koreksi_at DATETIME NULL;
CREATE TABLE IF NOT EXISTS layanan_pembayaran (
 id INT AUTO_INCREMENT PRIMARY KEY, angsuran_id INT NULL, pinjaman_id INT NOT NULL, user_id INT NOT NULL,
 jenis VARCHAR(20) NOT NULL, jumlah DECIMAL(15,2) NOT NULL, metode VARCHAR(10) NOT NULL,
 bukti VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_bayar_pinjaman(pinjaman_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS layanan_pengaturan (
 id TINYINT PRIMARY KEY, diskon_pelunasan DECIMAL(5,2) NOT NULL DEFAULT 10,
 pemutihan TINYINT NOT NULL DEFAULT 1,
 wa_denda TEXT NOT NULL, wa_lunas TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wa_pengingat_log (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, angsuran_id INT NOT NULL, tanggal_jadwal DATE NOT NULL,
 nomor VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, message_id VARCHAR(150) NULL,
 error_message VARCHAR(500) NULL, percobaan INT NOT NULL DEFAULT 1,
 sent_at DATETIME NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_wa_angsuran_tanggal (angsuran_id,tanggal_jadwal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO layanan_pengaturan (id,wa_denda,wa_lunas) VALUES
 (1,'Yth {nama}, Anda telat {hari} hari, denda Rp {denda}. Mohon segera dibayar.',
 'Yth {nama}, Selamat pinjaman {norek} LUNAS. Silakan hubungi kantor untuk pengambilan jaminan.');
ALTER TABLE layanan_pembayaran ADD COLUMN IF NOT EXISTS dibatalkan_at DATETIME NULL;
ALTER TABLE layanan_bayar_denda
 ADD COLUMN IF NOT EXISTS hari_telat INT NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS tarif DECIMAL(15,2) NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS denda_awal DECIMAL(15,2) NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS denda_akhir DECIMAL(15,2) NOT NULL DEFAULT 0;
ALTER TABLE layanan_pengaturan ADD COLUMN IF NOT EXISTS denda_jaminan_per_juta DECIMAL(15,2) NOT NULL DEFAULT 5000;
ALTER TABLE layanan_pengaturan
 ADD COLUMN IF NOT EXISTS surat_lunas TEXT NULL,
 ADD COLUMN IF NOT EXISTS bast_jaminan TEXT NULL;
UPDATE layanan_pengaturan SET surat_lunas='Dengan ini dinyatakan bahwa pinjaman {norek} atas nama {nama} telah LUNAS pada {tanggal}.' WHERE surat_lunas IS NULL;
UPDATE layanan_pengaturan SET bast_jaminan='Jaminan pinjaman {norek} telah diperiksa fisiknya dan diserahkan kepada {nama} pada {tanggal}.' WHERE bast_jaminan IS NULL;

-- Kelengkapan layanan: penjualan jaminan, prioritas, bonus, dan tindak lanjut.
ALTER TABLE pengajuan_pinjaman ADD COLUMN IF NOT EXISTS fasilitas_prioritas TINYINT NOT NULL DEFAULT 0;
ALTER TABLE layanan_pengaturan ADD COLUMN IF NOT EXISTS bonus_persen DECIMAL(5,2) NOT NULL DEFAULT 0;
ALTER TABLE layanan_pembayaran ADD COLUMN IF NOT EXISTS bunga_diskon DECIMAL(15,2) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS layanan_lelang (
 id INT AUTO_INCREMENT PRIMARY KEY, pinjaman_id INT NOT NULL, user_id INT NOT NULL,
 harga_jual DECIMAL(15,2) NOT NULL, terpakai DECIMAL(15,2) NOT NULL DEFAULT 0,
 kredit DECIMAL(15,2) NOT NULL DEFAULT 0, kelebihan DECIMAL(15,2) NOT NULL DEFAULT 0,
 dikembalikan DECIMAL(15,2) NOT NULL DEFAULT 0, metode VARCHAR(10) NOT NULL,
 bukti VARCHAR(255) NOT NULL, transaksi_id INT NOT NULL, catatan TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_lelang_pinjaman(pinjaman_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS layanan_alokasi_lelang (
 id INT AUTO_INCREMENT PRIMARY KEY, lelang_id INT NOT NULL, angsuran_id INT NOT NULL,
 jumlah DECIMAL(15,2) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_alokasi_angsuran(angsuran_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS layanan_bonus (
 id INT AUTO_INCREMENT PRIMARY KEY, pinjaman_id INT NOT NULL, anggota_id INT NOT NULL,
 tahun INT NOT NULL, bunga_dasar DECIMAL(15,2) NOT NULL, persen DECIMAL(5,2) NOT NULL,
 jumlah DECIMAL(15,2) NOT NULL, metode VARCHAR(10) NOT NULL, bukti VARCHAR(255) NOT NULL,
 user_id INT NOT NULL, transaksi_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_bonus_pinjaman(pinjaman_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS layanan_repeat_order (
 pinjaman_id INT PRIMARY KEY, user_id INT NOT NULL, catatan TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS layanan_pengajuan_hapus_denda (
 id INT AUTO_INCREMENT PRIMARY KEY, pinjaman_id INT NOT NULL, angsuran_id INT NOT NULL,
 user_id INT NOT NULL, alasan TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'menunggu',
 admin_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, diputuskan_at DATETIME NULL,
 aktif TINYINT NULL DEFAULT 1, UNIQUE KEY uniq_hapus_aktif(angsuran_id,aktif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SHU: periode, snapshot kontribusi, pengesahan RAT, pembayaran dan jurnal audit.
-- Tidak membuat tarif/pembagian asumsi dan tidak memindahkan kas saat migrasi.
CREATE TABLE IF NOT EXISTS shu_periode (
 id INT AUTO_INCREMENT PRIMARY KEY, tahun INT NOT NULL UNIQUE, nama_periode VARCHAR(100) NOT NULL,
 tanggal_mulai DATE NOT NULL, tanggal_selesai DATE NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft',
 total_shu DECIMAL(15,0) NOT NULL DEFAULT 0, kebijakan_json TEXT NOT NULL, alokasi_json TEXT NULL,
 snapshot_hash CHAR(64) NULL, referensi_rat VARCHAR(200) NULL, dibuat_oleh INT NOT NULL,
 dihitung_at DATETIME NULL, disahkan_oleh INT NULL, disahkan_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shu_anggota (
 id INT AUTO_INCREMENT PRIMARY KEY, shu_periode_id INT NOT NULL, anggota_id INT NOT NULL,
 nama_snapshot VARCHAR(150) NOT NULL, kode_snapshot VARCHAR(50) NOT NULL,
 total_simpanan DECIMAL(18,2) NOT NULL DEFAULT 0, total_pinjaman DECIMAL(18,2) NOT NULL DEFAULT 0,
 total_angsuran DECIMAL(18,2) NOT NULL DEFAULT 0, total_transaksi DECIMAL(18,2) NOT NULL DEFAULT 0,
 jasa_simpanan DECIMAL(15,0) NOT NULL DEFAULT 0, jasa_pinjaman DECIMAL(15,0) NOT NULL DEFAULT 0,
 jasa_transaksi DECIMAL(15,0) NOT NULL DEFAULT 0, total_shu DECIMAL(15,0) NOT NULL DEFAULT 0,
 status_bayar VARCHAR(10) NOT NULL DEFAULT 'belum', tanggal_bayar DATETIME NULL, pembayaran_id BIGINT NULL,
 UNIQUE KEY uniq_shu_anggota(shu_periode_id,anggota_id), KEY idx_shu_member(anggota_id),
 CONSTRAINT fk_shu_periode FOREIGN KEY(shu_periode_id) REFERENCES shu_periode(id) ON DELETE RESTRICT,
 CONSTRAINT fk_shu_member FOREIGN KEY(anggota_id) REFERENCES anggota(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shu_pembayaran (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, shu_anggota_id INT NOT NULL, jumlah DECIMAL(15,0) NOT NULL,
 metode VARCHAR(10) NOT NULL, bukti VARCHAR(255) NOT NULL, referensi VARCHAR(100) NOT NULL,
 nonce CHAR(32) NOT NULL UNIQUE, user_id INT NOT NULL, transaksi_id INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, dibatalkan_at DATETIME NULL,
 dibatalkan_oleh INT NULL, alasan_batal VARCHAR(500) NULL, transaksi_balik_id INT NULL,
 KEY idx_shu_payment(shu_anggota_id),
 CONSTRAINT fk_shu_payment FOREIGN KEY(shu_anggota_id) REFERENCES shu_anggota(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shu_audit (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, periode_id INT NOT NULL, user_id INT NOT NULL,
 aksi VARCHAR(40) NOT NULL, detail TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_shu_audit(periode_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SHU pinjaman: hak otomatis saat pinjaman tanpa jaminan lunas.
-- Anggota 1% dari pokok; ketua kelompok 2% dari pokok.
-- Ketua pereferensi mendapat 0,25% (2% x 12,5%) bila seluruh angsuran tepat waktu.
SET @shu_snapshot_baru=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pengajuan_pinjaman' AND COLUMN_NAME='kategori_shu');
ALTER TABLE anggota
 ADD COLUMN IF NOT EXISTS kategori_shu ENUM('anggota','ketua_kelompok') NOT NULL DEFAULT 'anggota' AFTER kode_anggota_jaminan,
 ADD COLUMN IF NOT EXISTS ketua_kelompok_id INT NULL AFTER kategori_shu;
ALTER TABLE pengajuan_pinjaman
 ADD COLUMN IF NOT EXISTS kategori_shu ENUM('anggota','ketua_kelompok') NOT NULL DEFAULT 'anggota',
 ADD COLUMN IF NOT EXISTS ketua_kelompok_id INT NULL;
ALTER TABLE pinjaman
 ADD COLUMN IF NOT EXISTS kategori_shu ENUM('anggota','ketua_kelompok') NOT NULL DEFAULT 'anggota',
 ADD COLUMN IF NOT EXISTS ketua_kelompok_id INT NULL;

SET @idx_anggota_ketua=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='anggota' AND index_name='idx_anggota_ketua');
SET @sql_anggota_ketua=IF(@idx_anggota_ketua=0,'ALTER TABLE anggota ADD KEY idx_anggota_ketua (ketua_kelompok_id)','SELECT 1');
PREPARE stmt_anggota_ketua FROM @sql_anggota_ketua; EXECUTE stmt_anggota_ketua; DEALLOCATE PREPARE stmt_anggota_ketua;
SET @fk_anggota_ketua=(SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='anggota' AND constraint_name='fk_anggota_ketua');
SET @sql_fk_anggota_ketua=IF(@fk_anggota_ketua=0,'ALTER TABLE anggota ADD CONSTRAINT fk_anggota_ketua FOREIGN KEY (ketua_kelompok_id) REFERENCES anggota(id) ON DELETE SET NULL','SELECT 1');
PREPARE stmt_fk_anggota_ketua FROM @sql_fk_anggota_ketua; EXECUTE stmt_fk_anggota_ketua; DEALLOCATE PREPARE stmt_fk_anggota_ketua;

SET @idx_pengajuan_ketua=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='pengajuan_pinjaman' AND index_name='idx_pengajuan_ketua');
SET @sql_pengajuan_ketua=IF(@idx_pengajuan_ketua=0,'ALTER TABLE pengajuan_pinjaman ADD KEY idx_pengajuan_ketua (ketua_kelompok_id)','SELECT 1');
PREPARE stmt_pengajuan_ketua FROM @sql_pengajuan_ketua; EXECUTE stmt_pengajuan_ketua; DEALLOCATE PREPARE stmt_pengajuan_ketua;
SET @fk_pengajuan_ketua=(SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='pengajuan_pinjaman' AND constraint_name='fk_pengajuan_ketua');
SET @sql_fk_pengajuan_ketua=IF(@fk_pengajuan_ketua=0,'ALTER TABLE pengajuan_pinjaman ADD CONSTRAINT fk_pengajuan_ketua FOREIGN KEY (ketua_kelompok_id) REFERENCES anggota(id) ON DELETE RESTRICT','SELECT 1');
PREPARE stmt_fk_pengajuan_ketua FROM @sql_fk_pengajuan_ketua; EXECUTE stmt_fk_pengajuan_ketua; DEALLOCATE PREPARE stmt_fk_pengajuan_ketua;

SET @idx_pinjaman_ketua=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='pinjaman' AND index_name='idx_pinjaman_ketua');
SET @sql_pinjaman_ketua=IF(@idx_pinjaman_ketua=0,'ALTER TABLE pinjaman ADD KEY idx_pinjaman_ketua (ketua_kelompok_id)','SELECT 1');
PREPARE stmt_pinjaman_ketua FROM @sql_pinjaman_ketua; EXECUTE stmt_pinjaman_ketua; DEALLOCATE PREPARE stmt_pinjaman_ketua;
SET @fk_pinjaman_ketua=(SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='pinjaman' AND constraint_name='fk_pinjaman_ketua');
SET @sql_fk_pinjaman_ketua=IF(@fk_pinjaman_ketua=0,'ALTER TABLE pinjaman ADD CONSTRAINT fk_pinjaman_ketua FOREIGN KEY (ketua_kelompok_id) REFERENCES anggota(id) ON DELETE RESTRICT','SELECT 1');
PREPARE stmt_fk_pinjaman_ketua FROM @sql_fk_pinjaman_ketua; EXECUTE stmt_fk_pinjaman_ketua; DEALLOCATE PREPARE stmt_fk_pinjaman_ketua;

UPDATE anggota SET ketua_kelompok_id=NULL WHERE kategori_shu='ketua_kelompok';
UPDATE pengajuan_pinjaman pp JOIN anggota a ON a.id=pp.anggota_id
 SET pp.kategori_shu=a.kategori_shu,pp.ketua_kelompok_id=a.ketua_kelompok_id
 WHERE @shu_snapshot_baru=0 AND pp.kategori_shu='anggota' AND pp.ketua_kelompok_id IS NULL;
UPDATE pinjaman p JOIN pengajuan_pinjaman pp ON pp.id=p.pengajuan_id
 SET p.kategori_shu=pp.kategori_shu,p.ketua_kelompok_id=pp.ketua_kelompok_id
 WHERE @shu_snapshot_baru=0 AND p.kategori_shu='anggota' AND p.ketua_kelompok_id IS NULL;

CREATE TABLE IF NOT EXISTS shu_hak_pinjaman (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 pinjaman_id INT NOT NULL, anggota_id INT NOT NULL, sumber_anggota_id INT NOT NULL,
 jenis ENUM('pelunasan','referensi') NOT NULL,
 kategori_snapshot ENUM('anggota','ketua_kelompok') NOT NULL,
 dasar_pinjaman DECIMAL(15,2) NOT NULL, persen DECIMAL(7,4) NOT NULL,
 jumlah DECIMAL(15,0) NOT NULL, pembayaran_lancar TINYINT(1) NOT NULL DEFAULT 0,
 status_bayar ENUM('belum','sudah') NOT NULL DEFAULT 'belum',
 metode VARCHAR(10) NULL, bukti VARCHAR(255) NULL, referensi VARCHAR(100) NULL,
 dibayar_oleh INT NULL, dibayar_at DATETIME NULL, transaksi_id INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_shu_hak_pinjaman (pinjaman_id,jenis),
 KEY idx_shu_hak_anggota (anggota_id), KEY idx_shu_hak_status (status_bayar),
 CONSTRAINT fk_shu_hak_pinjaman FOREIGN KEY (pinjaman_id) REFERENCES pinjaman(id) ON DELETE RESTRICT,
 CONSTRAINT fk_shu_hak_penerima FOREIGN KEY (anggota_id) REFERENCES anggota(id) ON DELETE RESTRICT,
 CONSTRAINT fk_shu_hak_sumber FOREIGN KEY (sumber_anggota_id) REFERENCES anggota(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shu_hak_audit (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, hak_id BIGINT NOT NULL, user_id INT NULL,
 aksi VARCHAR(40) NOT NULL, detail TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_shu_hak_audit (hak_id),
 CONSTRAINT fk_shu_hak_audit FOREIGN KEY (hak_id) REFERENCES shu_hak_pinjaman(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Import migrasi historis: identitas sumber menjamin import dapat diulang.
CREATE TABLE IF NOT EXISTS migrasi_import_item (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, jenis VARCHAR(32) NOT NULL, source_key VARCHAR(100) NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uniq_migrasi_source (jenis,source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pendanaan_dapin (
 id INT NOT NULL AUTO_INCREMENT, kode_dapin VARCHAR(50) NOT NULL, nama_pendana VARCHAR(150) NOT NULL, tanggal_diterima DATE NOT NULL,
 modal_diterima DECIMAL(15,2) NOT NULL, tenor INT NOT NULL, total_wajib_kembali DECIMAL(15,2) NOT NULL, tanggal_jatuh_tempo DATE NULL,
 status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif', catatan TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id), UNIQUE KEY uniq_kode_dapin (kode_dapin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alokasi_dapin (
 id INT NOT NULL AUTO_INCREMENT, source_key VARCHAR(100) NOT NULL, dapin_id INT NOT NULL, pinjaman_id INT NOT NULL,
 modal_dialokasikan DECIMAL(15,2) NOT NULL, kewajiban_dapin DECIMAL(15,2) NOT NULL, tanggal_alokasi DATE NULL, catatan TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uniq_alokasi_source (source_key),
 KEY idx_alokasi_dapin (dapin_id), KEY idx_alokasi_pinjaman (pinjaman_id),
 CONSTRAINT fk_alokasi_dapin FOREIGN KEY (dapin_id) REFERENCES pendanaan_dapin(id),
 CONSTRAINT fk_alokasi_pinjaman FOREIGN KEY (pinjaman_id) REFERENCES pinjaman(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS migrasi_pembayaran (
 id INT NOT NULL AUTO_INCREMENT, source_key VARCHAR(100) NOT NULL, pinjaman_id INT NOT NULL, transaksi_id INT NOT NULL, tanggal_bayar DATE NOT NULL,
 angsuran_ke INT NOT NULL, jenis_pembayaran ENUM('cicilan','pelunasan') NOT NULL DEFAULT 'cicilan', pokok DECIMAL(15,2) NOT NULL DEFAULT 0,
 bunga DECIMAL(15,2) NOT NULL DEFAULT 0, denda DECIMAL(15,2) NOT NULL DEFAULT 0, total_diterima DECIMAL(15,2) NOT NULL, status VARCHAR(30) NULL,
 keterangan TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uniq_pembayaran_source (source_key),
 KEY idx_migrasi_bayar_pinjaman (pinjaman_id), KEY idx_migrasi_bayar_transaksi (transaksi_id),
 CONSTRAINT fk_migrasi_bayar_pinjaman FOREIGN KEY (pinjaman_id) REFERENCES pinjaman(id),
 CONSTRAINT fk_migrasi_bayar_transaksi FOREIGN KEY (transaksi_id) REFERENCES transaksi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS migrasi_anggota_dokumen (
 anggota_id INT NOT NULL, foto VARCHAR(255) NULL, ttd MEDIUMTEXT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (anggota_id),
 CONSTRAINT fk_migrasi_dokumen_anggota FOREIGN KEY (anggota_id) REFERENCES anggota(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cadangan foto/TTD agar Reset Total + Import tidak menghilangkan dokumen.
CREATE TABLE IF NOT EXISTS migrasi_dokumen_cadangan (
 id INT NOT NULL AUTO_INCREMENT,
 jenis ENUM('anggota','pinjaman') NOT NULL,
 source_key VARCHAR(100) NOT NULL,
 kode_anggota VARCHAR(50) NULL,
 foto_ktp VARCHAR(255) NULL,
 foto_kk VARCHAR(255) NULL,
 foto_ktp_pasangan VARCHAR(255) NULL,
 ttd_anggota MEDIUMTEXT NULL,
 foto_pencairan VARCHAR(255) NULL,
 foto_barang VARCHAR(255) NULL,
 foto_nasabah_barang VARCHAR(255) NULL,
 perjanjian_json MEDIUMTEXT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uniq_migrasi_cadangan (jenis, source_key),
 KEY idx_migrasi_cadangan_anggota (kode_anggota)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Waserda & log koreksi (sebelumnya dibuat ad-hoc di request path)
CREATE TABLE IF NOT EXISTS `waserda_transaksi` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `kode_transaksi` VARCHAR(20) UNIQUE NOT NULL,
  `anggota_id` INT NOT NULL,
  `items` TEXT NOT NULL,
  `total_bayar` DECIMAL(15,2) NOT NULL,
  `metode_bayar` ENUM('tunai','transfer') NOT NULL,
  `user_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_waserda_anggota` FOREIGN KEY (`anggota_id`) REFERENCES `anggota` (`id`),
  CONSTRAINT `fk_waserda_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `log_koreksi` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `transaksi_id` INT NOT NULL,
  `jumlah_lama` DECIMAL(15,2) NOT NULL,
  `jumlah_baru` DECIMAL(15,2) NOT NULL,
  `selisih` DECIMAL(15,2) NOT NULL,
  `keterangan` TEXT,
  `user_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_log_koreksi_transaksi` FOREIGN KEY (`transaksi_id`) REFERENCES `transaksi` (`id`),
  CONSTRAINT `fk_log_koreksi_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- =============================================================================
-- Koreksi lapangan final (idempotent) — gabungan semua hotfix
-- Target Closing Oktober 2026: 58 pembayaran / Rp 9.714.500
-- + saldo kas tidak minus (hapus pencairan migrasi dari buku kas)
-- + Warsingah JT1 = 7 Nov (acuan pengajuan 24 Okt)
-- =============================================================================

-- 1) Hapus pencairan migrasi dari kas (cegah saldo minus)
DELETE FROM `kas` WHERE `keterangan` LIKE 'Pencairan migrasi%';
DELETE FROM `migrasi_import_item` WHERE `entity_type` = 'kas' AND `source_key` LIKE 'cair-%';

-- 2) Batalkan prematur (melebihi jatah / loncat / Warsingah & Alisa)
UPDATE pinjaman p
INNER JOIN (
  SELECT a.pinjaman_id, COALESCE(SUM(a.pokok), 0) AS pokok_balik
  FROM angsuran a
  INNER JOIN pinjaman px ON px.id = a.pinjaman_id
  WHERE a.status = 'lunas'
    AND (
      (px.kode_pinjaman = 'PJN-20261013-0005' AND a.angsuran_ke > 4)
   OR (px.kode_pinjaman = 'PJN-20261013-0004' AND a.angsuran_ke > 4)
   OR (px.kode_pinjaman = 'PJN-20261013-0003' AND a.angsuran_ke > 3)
   OR (px.kode_pinjaman IN ('PJN-20261028-0025', 'PJN-20261024-0020')
       AND a.tanggal_bayar IS NOT NULL AND a.tanggal_bayar < a.tanggal_jatuh_tempo)
    )
  GROUP BY a.pinjaman_id
) x ON x.pinjaman_id = p.id
SET p.sisa_pokok = p.sisa_pokok + x.pokok_balik,
    p.status = 'aktif',
    p.tanggal_lunas = NULL;

UPDATE angsuran a
INNER JOIN pinjaman p ON p.id = a.pinjaman_id
SET a.status = IF(a.tanggal_jatuh_tempo < CURDATE(), 'telat', 'belum_bayar'),
    a.tanggal_bayar = NULL,
    a.denda = 0,
    a.keterlambatan_hari = IF(a.tanggal_jatuh_tempo < CURDATE(), DATEDIFF(CURDATE(), a.tanggal_jatuh_tempo), 0),
    a.cara_bayar = 'cicilan'
WHERE a.status = 'lunas'
  AND (
    (p.kode_pinjaman = 'PJN-20261013-0005' AND a.angsuran_ke > 4)
 OR (p.kode_pinjaman = 'PJN-20261013-0004' AND a.angsuran_ke > 4)
 OR (p.kode_pinjaman = 'PJN-20261013-0003' AND a.angsuran_ke > 3)
 OR (p.kode_pinjaman IN ('PJN-20261028-0025', 'PJN-20261024-0020')
     AND a.tanggal_bayar IS NOT NULL AND a.tanggal_bayar < a.tanggal_jatuh_tempo)
  );

UPDATE layanan_pembayaran lp
INNER JOIN angsuran a ON a.id = lp.angsuran_id
INNER JOIN pinjaman p ON p.id = a.pinjaman_id
SET lp.dibatalkan_at = COALESCE(lp.dibatalkan_at, NOW())
WHERE lp.dibatalkan_at IS NULL
  AND (
    (p.kode_pinjaman = 'PJN-20261013-0005' AND a.angsuran_ke > 4)
 OR (p.kode_pinjaman = 'PJN-20261013-0004' AND a.angsuran_ke > 4)
 OR (p.kode_pinjaman = 'PJN-20261013-0003' AND a.angsuran_ke > 3)
 OR (p.kode_pinjaman IN ('PJN-20261028-0025', 'PJN-20261024-0020') AND a.status <> 'lunas')
  );

-- 3) Novitasari Senin → pastikan ke-4 lunas
UPDATE pinjaman p
INNER JOIN angsuran a ON a.pinjaman_id = p.id AND a.angsuran_ke = 4 AND a.status <> 'lunas'
SET p.sisa_pokok = GREATEST(0, p.sisa_pokok - a.pokok)
WHERE p.kode_pinjaman = 'PJN-20261013-0005';

UPDATE angsuran a
INNER JOIN pinjaman p ON p.id = a.pinjaman_id
SET a.status = 'lunas', a.tanggal_bayar = '2026-10-26', a.denda = 0,
    a.keterlambatan_hari = 0, a.cara_bayar = 'cicilan'
WHERE p.kode_pinjaman = 'PJN-20261013-0005' AND a.angsuran_ke = 4 AND a.status <> 'lunas';

-- 4) Solikah Jumat → pastikan ke-6 lunas (baseline closing tetap 58)
UPDATE pinjaman p
INNER JOIN angsuran a ON a.pinjaman_id = p.id AND a.angsuran_ke = 6 AND a.status <> 'lunas'
SET p.sisa_pokok = GREATEST(0, p.sisa_pokok - a.pokok)
WHERE p.kode_pinjaman = 'PJN-20261013-0007';

UPDATE angsuran a
INNER JOIN pinjaman p ON p.id = a.pinjaman_id
SET a.status = 'lunas', a.tanggal_bayar = '2026-10-30', a.denda = 0,
    a.keterlambatan_hari = 0, a.cara_bayar = 'cicilan'
WHERE p.kode_pinjaman = 'PJN-20261013-0007' AND a.angsuran_ke = 6 AND a.status <> 'lunas';

-- 5) Baseline closing 58: JANGAN ikutkan Sarinah ke-7 / Khafiyah ke-7 / Masrifah ke-5
UPDATE pinjaman p
INNER JOIN (
  SELECT a.pinjaman_id, COALESCE(SUM(a.pokok), 0) AS pokok_balik
  FROM angsuran a
  INNER JOIN pinjaman px ON px.id = a.pinjaman_id
  WHERE a.status = 'lunas'
    AND (
      (px.kode_pinjaman = 'PJN-20261013-0014' AND a.angsuran_ke = 7)
   OR (px.kode_pinjaman = 'PJN-20261013-0012' AND a.angsuran_ke = 7)
   OR (px.kode_pinjaman = 'PJN-20261013-0006' AND a.angsuran_ke = 5)
    )
  GROUP BY a.pinjaman_id
) x ON x.pinjaman_id = p.id
SET p.sisa_pokok = p.sisa_pokok + x.pokok_balik,
    p.status = 'aktif',
    p.tanggal_lunas = NULL;

UPDATE angsuran a
INNER JOIN pinjaman p ON p.id = a.pinjaman_id
SET a.status = IF(a.tanggal_jatuh_tempo < CURDATE(), 'telat', 'belum_bayar'),
    a.tanggal_bayar = NULL,
    a.denda = 0,
    a.keterlambatan_hari = IF(a.tanggal_jatuh_tempo < CURDATE(), DATEDIFF(CURDATE(), a.tanggal_jatuh_tempo), 0),
    a.cara_bayar = 'cicilan'
WHERE a.status = 'lunas'
  AND (
    (p.kode_pinjaman = 'PJN-20261013-0014' AND a.angsuran_ke = 7)
 OR (p.kode_pinjaman = 'PJN-20261013-0012' AND a.angsuran_ke = 7)
 OR (p.kode_pinjaman = 'PJN-20261013-0006' AND a.angsuran_ke = 5)
  );

-- 6) Kas batch → estimasi closing Rp 9.714.500
UPDATE kas SET jumlah = 450000
WHERE jenis = 'masuk' AND keterangan LIKE '%BYR-20260915-005%' AND keterangan LIKE '%PJN-20261013-0005%';
UPDATE kas SET jumlah = 0,
    keterangan = IF(keterangan LIKE '%[DIBATALKAN:%', keterangan, CONCAT(COALESCE(keterangan,''), ' [DIBATALKAN: koreksi prematur]'))
WHERE jenis = 'masuk' AND jumlah > 0
  AND keterangan LIKE '%BYR-20261027-NOV%' AND keterangan LIKE '%PJN-20261013-0005%';

UPDATE kas SET jumlah = 450000
WHERE jenis = 'masuk' AND keterangan LIKE '%BYR-20260915-004%' AND keterangan LIKE '%PJN-20261013-0004%';
UPDATE kas SET jumlah = 0,
    keterangan = IF(keterangan LIKE '%[DIBATALKAN:%', keterangan, CONCAT(COALESCE(keterangan,''), ' [DIBATALKAN: koreksi prematur]'))
WHERE jenis = 'masuk' AND jumlah > 0 AND keterangan LIKE '%BYR-20261028-PUJI-6%';

UPDATE kas SET jumlah = 675000
WHERE jenis = 'masuk' AND keterangan LIKE '%BYR-20260915-003%' AND keterangan LIKE '%PJN-20261013-0003%';
UPDATE kas SET jumlah = 0,
    keterangan = IF(keterangan LIKE '%[DIBATALKAN:%', keterangan, CONCAT(COALESCE(keterangan,''), ' [DIBATALKAN: koreksi prematur]'))
WHERE jenis = 'masuk' AND jumlah > 0 AND keterangan LIKE '%BYR-20261029-NOVIANI-6%';

UPDATE kas SET jumlah = 675000
WHERE jenis = 'masuk' AND keterangan LIKE '%BYR-20260915-014%' AND keterangan LIKE '%PJN-20261013-0014%';
UPDATE kas SET jumlah = 675000
WHERE jenis = 'masuk' AND keterangan LIKE '%BYR-20260915-012%' AND keterangan LIKE '%PJN-20261013-0012%';
UPDATE kas SET jumlah = 450000
WHERE jenis = 'masuk' AND keterangan LIKE '%BYR-20260915-006%' AND keterangan LIKE '%PJN-20261013-0006%';

UPDATE kas SET jumlah = 445000
WHERE jenis = 'masuk' AND keterangan LIKE '%BYR-20260915-015%' AND keterangan LIKE '%PJN-20261013-0010%';
UPDATE migrasi_pembayaran SET total_diterima = 445000
WHERE source_key = 'BYR-20260915-015' AND total_diterima <> 445000;

-- 7) Warsingah: jadwal dari tgl pengajuan 24 Okt (cicilan 1 = 7 Nov)
UPDATE pengajuan_pinjaman pp
INNER JOIN pinjaman p ON p.pengajuan_id = pp.id
SET pp.tanggal_pengajuan = '2026-10-24 12:00:00'
WHERE p.kode_pinjaman = 'PJN-20261028-0025';

UPDATE angsuran a
INNER JOIN pinjaman p ON p.id = a.pinjaman_id
SET a.tanggal_jatuh_tempo = DATE_ADD('2026-10-24', INTERVAL (14 * a.angsuran_ke) DAY),
    a.status = IF(a.status = 'lunas', a.status,
      IF(DATE_ADD('2026-10-24', INTERVAL (14 * a.angsuran_ke) DAY) < CURDATE(), 'telat', 'belum_bayar')),
    a.keterlambatan_hari = IF(a.status = 'lunas', a.keterlambatan_hari,
      IF(DATE_ADD('2026-10-24', INTERVAL (14 * a.angsuran_ke) DAY) < CURDATE(),
         DATEDIFF(CURDATE(), DATE_ADD('2026-10-24', INTERVAL (14 * a.angsuran_ke) DAY)), 0))
WHERE p.kode_pinjaman = 'PJN-20261028-0025';

UPDATE pinjaman p
SET p.jatuh_tempo = (SELECT MAX(a.tanggal_jatuh_tempo) FROM angsuran a WHERE a.pinjaman_id = p.id)
WHERE p.kode_pinjaman = 'PJN-20261028-0025';

-- 8) Rekalkulasi ledger kas
SET @simkeu_kas_saldo := 0;
UPDATE `kas`
SET
  `saldo_sebelum` = (@simkeu_kas_prev := @simkeu_kas_saldo),
  `saldo_sesudah` = (@simkeu_kas_saldo := @simkeu_kas_saldo + IF(`jenis` = 'masuk', `jumlah`, -`jumlah`))
ORDER BY `id`;

SELECT 'Migrasi universal selesai (closing Okt target 58 / Rp 9.714.500).' AS hasil;

SELECT
  (SELECT COUNT(*) FROM angsuran
    WHERE status='lunas' AND tanggal_bayar BETWEEN '2026-10-01' AND '2026-10-31'
      AND tanggal_jatuh_tempo BETWEEN '2026-10-01' AND '2026-10-31') AS closing_jumlah,
  (SELECT COALESCE(SUM(jumlah),0) FROM kas
    WHERE jenis='masuk' AND jumlah>0 AND DATE(tanggal) BETWEEN '2026-10-01' AND '2026-10-31'
      AND (keterangan LIKE 'Pembayaran angsuran%' OR keterangan LIKE 'Pembayaran migrasi%')
      AND keterangan NOT LIKE '%PELUNASAN%' AND keterangan NOT LIKE '%-PEL%'
      AND keterangan NOT LIKE '%(denda)%' AND keterangan NOT LIKE '%[DIBATALKAN:%'
  ) AS closing_kas,
  (SELECT COALESCE(saldo_sesudah,0) FROM kas ORDER BY id DESC LIMIT 1) AS saldo_kas;

-- Closing angsuran bulanan: pembayaran bulan yang sudah ditutup tidak boleh diubah.
CREATE TABLE IF NOT EXISTS `closing_angsuran_bulanan` (
  `id` INT NOT NULL AUTO_INCREMENT, `bulan` CHAR(7) NOT NULL, `status` ENUM('terkunci','dibuka') NOT NULL DEFAULT 'terkunci',
  `ditutup_at` DATETIME NOT NULL, `ditutup_oleh` INT NULL, `dibuka_at` DATETIME NULL, `dibuka_oleh` INT NULL,
  `catatan` TEXT NULL, `total_pembayaran` DECIMAL(15,2) NOT NULL DEFAULT 0, `jumlah_pembayaran` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uniq_closing_bulan` (`bulan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `closing_angsuran_detail` (
  `id` BIGINT NOT NULL AUTO_INCREMENT, `closing_id` INT NOT NULL, `angsuran_id` INT NOT NULL, `nominal` DECIMAL(15,2) NOT NULL, `tanggal_bayar` DATE NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uniq_closing_angsuran` (`angsuran_id`), KEY `idx_closing_detail` (`closing_id`),
  CONSTRAINT `fk_closing_detail_header` FOREIGN KEY (`closing_id`) REFERENCES `closing_angsuran_bulanan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_closing_detail_angsuran` FOREIGN KEY (`angsuran_id`) REFERENCES `angsuran` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

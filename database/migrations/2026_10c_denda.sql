-- =====================================================================
-- Migrasi denda otomatis (setelah migrasi sebelumnya). Jalankan SEKALI.
--  * denda_tarif   : denda nominal per hari menurut besar pinjaman (bisa diatur Admin)
--  * angsuran.denda_manual : 1 = denda diinput manual, tidak ditimpa hitungan otomatis
-- Aman untuk data lama.
-- =====================================================================
CREATE TABLE IF NOT EXISTS `denda_tarif` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `jumlah_pinjaman` DECIMAL(15,2) NOT NULL,
  `denda_per_hari` DECIMAL(15,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jumlah` (`jumlah_pinjaman`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- Tarif dari klien (Okt 2026)
INSERT IGNORE INTO `denda_tarif` (`jumlah_pinjaman`, `denda_per_hari`) VALUES
  (1000000, 1500),
  (2000000, 2500),
  (3000000, 3500);

ALTER TABLE `angsuran`
  ADD COLUMN `denda_manual` TINYINT(1) NOT NULL DEFAULT 0 AFTER `denda`;

-- Denda lama yang sudah terisi dianggap sudah ditetapkan petugas (tidak ditimpa otomatis)
UPDATE `angsuran` SET `denda_manual` = 1 WHERE `denda` > 0 AND `status` <> 'lunas';

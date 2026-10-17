-- =====================================================================
-- Migrasi: Revisi Koperasi Oktober 2026
--   R2  Bunga flat per tenor (tarif per jenis pinjaman per tenor)
--   R3  Frekuensi angsuran bulanan / mingguan
--   R4  Dokumen anggota (KTP, KK, KTP suami/istri)
--
-- Aman untuk data lama:
--   * Hanya ADD COLUMN / CREATE TABLE / memperlebar tipe kolom.
--   * Kolom penanda skema diisi 'tahunan' / 'bulanan' untuk SEMUA baris lama,
--     lalu default-nya diubah ke 'flat' sehingga data baru ber-skema flat.
--   * Nilai pinjaman/angsuran yang sudah ada tidak diubah sama sekali.
--
-- Jalankan SEKALI (backup database dulu):
--   mysql -u root koperasi_db < database/migrations/2026_10_revisi_koperasi.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- R2: bunga boleh > 100 (maks. 1000) -> DECIMAL(5,2) diperlebar ke (6,2)
-- ---------------------------------------------------------------------
ALTER TABLE `jenis_pinjaman`
  MODIFY `bunga` DECIMAL(6,2) NOT NULL DEFAULT '0.00'
    COMMENT 'Legacy: bunga per tahun (%) untuk skema tahunan',
  ADD COLUMN `frekuensi_diizinkan` SET('bulanan','mingguan') NOT NULL DEFAULT 'bulanan'
    AFTER `bunga`;

ALTER TABLE `pengajuan_pinjaman`
  MODIFY `bunga` DECIMAL(6,2) NOT NULL;

ALTER TABLE `pinjaman`
  MODIFY `bunga` DECIMAL(6,2) NOT NULL;

-- Tarif bunga flat per jenis pinjaman per tenor (bulan)
CREATE TABLE IF NOT EXISTS `jenis_pinjaman_tarif` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `jenis_pinjaman_id` INT(11) NOT NULL,
  `tenor_bulan` INT(11) NOT NULL,
  `bunga` DECIMAL(6,2) NOT NULL COMMENT 'Bunga flat (%) untuk keseluruhan tenor',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jenis_tenor` (`jenis_pinjaman_id`, `tenor_bulan`),
  CONSTRAINT `jenis_pinjaman_tarif_ibfk_1` FOREIGN KEY (`jenis_pinjaman_id`)
    REFERENCES `jenis_pinjaman` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- ---------------------------------------------------------------------
-- R2 + R3: penanda skema bunga & frekuensi pada pengajuan dan pinjaman
--   Langkah 1: tambah kolom dengan default lama -> semua baris lama terisi
--              'tahunan' / 'bulanan' / jumlah_cicilan = tenor.
--   Langkah 2: ubah default menjadi 'flat' untuk data baru.
-- ---------------------------------------------------------------------
ALTER TABLE `pengajuan_pinjaman`
  ADD COLUMN `skema_bunga` ENUM('tahunan','flat') NOT NULL DEFAULT 'tahunan' AFTER `bunga`,
  ADD COLUMN `frekuensi` ENUM('bulanan','mingguan') NOT NULL DEFAULT 'bulanan' AFTER `skema_bunga`;

ALTER TABLE `pinjaman`
  ADD COLUMN `skema_bunga` ENUM('tahunan','flat') NOT NULL DEFAULT 'tahunan' AFTER `bunga`,
  ADD COLUMN `frekuensi` ENUM('bulanan','mingguan') NOT NULL DEFAULT 'bulanan' AFTER `skema_bunga`,
  ADD COLUMN `jumlah_cicilan` INT(11) DEFAULT NULL AFTER `frekuensi`;

UPDATE `pinjaman` SET `jumlah_cicilan` = `tenor` WHERE `jumlah_cicilan` IS NULL;

ALTER TABLE `pengajuan_pinjaman` ALTER COLUMN `skema_bunga` SET DEFAULT 'flat';
ALTER TABLE `pinjaman`           ALTER COLUMN `skema_bunga` SET DEFAULT 'flat';

-- Catatan: kolom pinjaman.angsuran_per_bulan dipertahankan namanya, tetapi
-- untuk pinjaman mingguan berisi nominal per cicilan (per minggu).

-- ---------------------------------------------------------------------
-- Status 'dicairkan' sudah dipakai kode (admin/pencairan.php) tetapi belum
-- ada di enum pada dump. Ditambahkan ke enum yang SUDAH ADA (nilai lain di
-- database produksi tidak dibuang); dilewati bila sudah ada.
-- ---------------------------------------------------------------------
SET @status_type := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengajuan_pinjaman'
                       AND COLUMN_NAME = 'status');
SET @sql := IF(@status_type LIKE '%''dicairkan''%',
               'SELECT ''status dicairkan sudah ada''',
               CONCAT('ALTER TABLE `pengajuan_pinjaman` MODIFY `status` ',
                      LEFT(@status_type, CHAR_LENGTH(@status_type) - 1), ',''dicairkan'') DEFAULT ''menunggu'''));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- R4: dokumen anggota. foto_ktp sudah ada; file lama tetap dibaca dari
-- assets/uploads/ktp/, file baru disimpan di uploads/anggota/{id}/.
-- ---------------------------------------------------------------------
ALTER TABLE `anggota`
  ADD COLUMN `foto_kk` VARCHAR(255) DEFAULT NULL AFTER `foto_ktp`,
  ADD COLUMN `foto_ktp_pasangan` VARCHAR(255) DEFAULT NULL AFTER `foto_kk`;

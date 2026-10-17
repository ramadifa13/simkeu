-- =====================================================================
-- Migrasi input data lama / tanggal mundur (setelah migrasi sebelumnya). Jalankan SEKALI.
--  * pinjaman.input_mundur : 1 = pinjaman diinput dengan tanggal pencairan di masa lalu
--  * pinjaman.cicilan_dimuka : jumlah cicilan yang ditandai "sudah dibayar sebelum pakai aplikasi"
-- Aman untuk data lama.
-- =====================================================================
ALTER TABLE `pinjaman`
  ADD COLUMN `input_mundur` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `cicilan_dimuka` INT(11) NOT NULL DEFAULT 0;

-- Indikator keputusan pembatalan untuk Teller: 0 = keputusan Admin belum dilihat Teller pengaju
ALTER TABLE `pengajuan_pembatalan_angsuran`
  ADD COLUMN `dilihat_teller` TINYINT(1) NOT NULL DEFAULT 0;
UPDATE `pengajuan_pembatalan_angsuran` SET `dilihat_teller` = 1 WHERE `status` <> 'menunggu';

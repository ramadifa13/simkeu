-- =====================================================================
-- Migrasi tambahan (setelah 2026_10_revisi_koperasi.sql):
-- penanda cara pembayaran cicilan, agar Bukti Pembayaran Angsuran bisa
-- menulis "Lunas" (dicicil sampai selesai) atau "Pelunasan" (pelunasan dini).
-- Aman untuk data lama: semua cicilan lama otomatis 'cicilan'.
-- Jalankan SEKALI.
-- =====================================================================
ALTER TABLE `angsuran`
  ADD COLUMN `cara_bayar` ENUM('cicilan','pelunasan') NOT NULL DEFAULT 'cicilan' AFTER `status`;

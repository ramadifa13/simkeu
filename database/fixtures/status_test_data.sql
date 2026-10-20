-- Fixture manual untuk menguji seluruh status layanan.
-- Target: simkeusi_koperasi_db. Jalankan setelah MIGRASI_UNIVERSAL.sql.
-- Data memakai pinjaman/pengajuan existing agar foreign key tetap valid.
START TRANSACTION;

SET @admin := (SELECT id FROM users WHERE role='admin' AND status='aktif' ORDER BY id LIMIT 1);
SET @teller := (SELECT id FROM users WHERE role='teller' AND status='aktif' ORDER BY id LIMIT 1);

-- Skenario cetak buku angsuran jaminan mingguan:
-- jenis id=10 dipakai oleh pinjaman testing id=1 dan diwajibkan memiliki jaminan.
UPDATE jenis_pinjaman SET memerlukan_jaminan=1 WHERE id=10;
UPDATE pinjaman SET jenis_pinjaman_id=10, frekuensi='mingguan' WHERE id=1;

-- Pengajuan pinjaman: dua per status utama.
UPDATE pengajuan_pinjaman SET status='menunggu',admin_id=NULL,catatan_admin='TEST STATUS - menunggu' WHERE id IN (1,2);
UPDATE pengajuan_pinjaman SET status='disetujui',admin_id=@admin,tanggal_persetujuan=NOW(),catatan_admin='TEST STATUS - disetujui' WHERE id IN (3,4);
UPDATE pengajuan_pinjaman SET status='ditolak',admin_id=@admin,tanggal_persetujuan=NOW(),catatan_admin='TEST STATUS - ditolak' WHERE id IN (5,6);
UPDATE pengajuan_pinjaman SET status='dicairkan',admin_id=@admin,tanggal_persetujuan=NOW(),catatan_admin='TEST STATUS - dicairkan' WHERE id IN (7,8);

-- Pinjaman: dua aktif, dua lunas, dua macet.
UPDATE pinjaman SET status='aktif',tanggal_lunas=NULL WHERE id IN (1,2);
UPDATE pinjaman SET status='lunas',tanggal_lunas=CURDATE(),sisa_pokok=0 WHERE id IN (3,4);
UPDATE pinjaman SET status='macet',tanggal_lunas=NULL WHERE id IN (5,6);

-- Jadwal angsuran agar status dan keterlambatan terlihat di layar.
UPDATE angsuran SET status='lunas',tanggal_bayar=tanggal_jatuh_tempo,keterlambatan_hari=0 WHERE pinjaman_id IN (3,4);
UPDATE angsuran SET status='telat',tanggal_bayar=NULL,tanggal_jatuh_tempo=DATE_SUB(CURDATE(),INTERVAL 14 DAY),keterlambatan_hari=14,denda=25000 WHERE pinjaman_id IN (5,6) AND angsuran_ke=1;
UPDATE angsuran SET status='belum_bayar',tanggal_bayar=NULL,tanggal_jatuh_tempo=DATE_ADD(CURDATE(),INTERVAL 14 DAY),keterlambatan_hari=0 WHERE pinjaman_id IN (1,2) AND angsuran_ke=1;

-- Jaminan dummy untuk detail pengembalian.
INSERT IGNORE INTO jaminan_pinjaman (pengajuan_id,pinjaman_id,jenis_barang,identitas_barang,deskripsi_barang,nilai_taksiran,foto_barang,status_jaminan)
SELECT p.pengajuan_id,p.id,'HP TEST',CONCAT('TEST-IMEI-',p.id),'Jaminan data uji',1200000,'jaminan/test-placeholder.jpg','dititipkan'
FROM pinjaman p WHERE p.id BETWEEN 1 AND 10;

-- Pengembalian jaminan: dua per status.
INSERT IGNORE INTO layanan_jaminan_kembali (pinjaman_id,user_id,status,admin_id,alasan,bukti_jaminan,bukti_lunas,catatan_admin,diperiksa_at)
SELECT p.id,@teller,'menunggu',NULL,'TEST STATUS - menunggu','jaminan/test-placeholder.jpg','pembayaran/test-placeholder.jpg',NULL,NULL FROM pinjaman p WHERE p.id IN (1,2);
INSERT IGNORE INTO layanan_jaminan_kembali (pinjaman_id,user_id,status,admin_id,alasan,bukti_jaminan,bukti_lunas,catatan_admin,diperiksa_at)
SELECT p.id,@teller,'pengecekan',@admin,'TEST STATUS - pengecekan','jaminan/test-placeholder.jpg','pembayaran/test-placeholder.jpg','TEST STATUS - sedang dicek',NOW() FROM pinjaman p WHERE p.id IN (3,4);
INSERT IGNORE INTO layanan_jaminan_kembali (pinjaman_id,user_id,status,admin_id,alasan,bukti_jaminan,bukti_lunas,catatan_admin,diperiksa_at)
SELECT p.id,@teller,'disetujui',@admin,'TEST STATUS - disetujui','jaminan/test-placeholder.jpg','pembayaran/test-placeholder.jpg','TEST STATUS - disetujui',NOW() FROM pinjaman p WHERE p.id IN (5,6);
INSERT IGNORE INTO layanan_jaminan_kembali (pinjaman_id,user_id,status,admin_id,alasan,bukti_jaminan,bukti_lunas,catatan_admin,diperiksa_at)
SELECT p.id,@teller,'ditolak',@admin,'TEST STATUS - ditolak','jaminan/test-placeholder.jpg','pembayaran/test-placeholder.jpg','TEST STATUS - bukti tidak sesuai',NOW() FROM pinjaman p WHERE p.id IN (7,8);
INSERT IGNORE INTO layanan_jaminan_kembali (pinjaman_id,user_id,status,admin_id,alasan,bukti_jaminan,bukti_lunas,catatan_admin,diperiksa_at)
SELECT p.id,@teller,'diserahkan',@admin,'TEST STATUS - diserahkan','jaminan/test-placeholder.jpg','pembayaran/test-placeholder.jpg','TEST STATUS - sudah diserahkan',NOW() FROM pinjaman p WHERE p.id IN (9,10);

-- Prioritas: dua per status.
INSERT IGNORE INTO layanan_prioritas (pinjaman_id,anggota_id,user_id,status,admin_id,catatan,koreksi,koreksi_admin_id,koreksi_at)
SELECT p.id,p.anggota_id,@teller,'menunggu',NULL,'TEST STATUS - menunggu',NULL,NULL,NULL FROM pinjaman p WHERE p.id IN (1,2);
INSERT IGNORE INTO layanan_prioritas (pinjaman_id,anggota_id,user_id,status,admin_id,catatan,koreksi,koreksi_admin_id,koreksi_at)
SELECT p.id,p.anggota_id,@teller,'pengecekan',@admin,'TEST STATUS - diajukan','TEST STATUS - perlu koreksi',@admin,NOW() FROM pinjaman p WHERE p.id IN (3,4);
INSERT IGNORE INTO layanan_prioritas (pinjaman_id,anggota_id,user_id,status,admin_id,catatan,koreksi,koreksi_admin_id,koreksi_at)
SELECT p.id,p.anggota_id,@teller,'disetujui',@admin,'TEST STATUS - diajukan','TEST STATUS - disetujui',@admin,NOW() FROM pinjaman p WHERE p.id IN (5,6);
INSERT IGNORE INTO layanan_prioritas (pinjaman_id,anggota_id,user_id,status,admin_id,catatan,koreksi,koreksi_admin_id,koreksi_at)
SELECT p.id,p.anggota_id,@teller,'ditolak',@admin,'TEST STATUS - diajukan','TEST STATUS - ditolak',@admin,NOW() FROM pinjaman p WHERE p.id IN (7,8);

-- Pelunasan dipercepat: dua per status.
INSERT IGNORE INTO layanan_pelunasan (pinjaman_id,user_id,status,diskon_persen,admin_id,catatan)
SELECT p.id,@teller,'menunggu',0,NULL,'TEST STATUS - menunggu' FROM pinjaman p WHERE p.id IN (1,2);
INSERT IGNORE INTO layanan_pelunasan (pinjaman_id,user_id,status,diskon_persen,admin_id,catatan)
SELECT p.id,@teller,'disetujui',10,@admin,'TEST STATUS - disetujui' FROM pinjaman p WHERE p.id IN (3,4);
INSERT IGNORE INTO layanan_pelunasan (pinjaman_id,user_id,status,diskon_persen,admin_id,catatan)
SELECT p.id,@teller,'ditolak',0,@admin,'TEST STATUS - ditolak' FROM pinjaman p WHERE p.id IN (5,6);
INSERT IGNORE INTO layanan_pelunasan (pinjaman_id,user_id,status,diskon_persen,admin_id,catatan)
SELECT p.id,@teller,'selesai',0,@admin,'TEST STATUS - selesai' FROM pinjaman p WHERE p.id IN (7,8);

-- Laporan macet: dua per status.
INSERT IGNORE INTO layanan_laporan_macet (pinjaman_id,user_id,hari_telat,sisa_hutang,catatan,status,admin_id,keputusan)
SELECT p.id,@teller,14,750000,'TEST STATUS - laporan','menunggu',NULL,NULL FROM pinjaman p WHERE p.id IN (5,6);
INSERT IGNORE INTO layanan_laporan_macet (pinjaman_id,user_id,hari_telat,sisa_hutang,catatan,status,admin_id,keputusan)
SELECT p.id,@teller,14,750000,'TEST STATUS - laporan','ditindaklanjuti',@admin,'TEST STATUS - ditindaklanjuti' FROM pinjaman p WHERE p.id IN (7,8);
INSERT IGNORE INTO layanan_laporan_macet (pinjaman_id,user_id,hari_telat,sisa_hutang,catatan,status,admin_id,keputusan)
SELECT p.id,@teller,14,750000,'TEST STATUS - laporan','selesai',@admin,'TEST STATUS - selesai' FROM pinjaman p WHERE p.id IN (9,10);
INSERT IGNORE INTO layanan_laporan_macet (pinjaman_id,user_id,hari_telat,sisa_hutang,catatan,status,admin_id,keputusan)
SELECT p.id,@teller,14,750000,'TEST STATUS - laporan','ditolak',@admin,'TEST STATUS - ditolak' FROM pinjaman p WHERE p.id IN (11,12);

COMMIT;

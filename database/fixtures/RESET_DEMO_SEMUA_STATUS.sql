-- RESET DEMO SIMKEU
-- Hanya untuk database lokal/demo. Data transaksi dan anggota dihapus;
-- akun Admin/Teller, koperasi, pengaturan, dan master jenis pinjaman dipertahankan.
START TRANSACTION;
SET FOREIGN_KEY_CHECKS=0;
DELETE FROM wa_pengingat_log; DELETE FROM shu_pembayaran; DELETE FROM shu_hak_audit; DELETE FROM shu_audit; DELETE FROM shu_hak_pinjaman; DELETE FROM shu_anggota;
DELETE FROM waserda_transaksi; DELETE FROM pembelian_listrik; DELETE FROM pembelian_indihome; DELETE FROM transaksi; DELETE FROM simpanan;
DELETE FROM layanan_alokasi_lelang; DELETE FROM layanan_lelang; DELETE FROM layanan_bayar_denda; DELETE FROM layanan_pembayaran; DELETE FROM layanan_pengajuan_hapus_denda;
DELETE FROM layanan_jaminan_kembali; DELETE FROM layanan_pelunasan; DELETE FROM layanan_prioritas; DELETE FROM layanan_repeat_order; DELETE FROM layanan_laporan_macet; DELETE FROM layanan_tunda_cair; DELETE FROM layanan_audit;
DELETE FROM pelunasan_dini; DELETE FROM pengajuan_pembatalan_angsuran; DELETE FROM log_koreksi; DELETE FROM lampiran_perjanjian; DELETE FROM perjanjian_pinjaman;
DELETE FROM angsuran; DELETE FROM jaminan_pinjaman; DELETE FROM kas; DELETE FROM pinjaman; DELETE FROM pengajuan_pinjaman;
DELETE FROM anggota; DELETE FROM users WHERE role='anggota';
SET FOREIGN_KEY_CHECKS=1;

SET @admin=(SELECT id FROM users WHERE role='admin' AND status='aktif' ORDER BY id LIMIT 1);
SET @teller=(SELECT id FROM users WHERE role='teller' AND status='aktif' ORDER BY id LIMIT 1);
SET @gadai=(SELECT id FROM jenis_pinjaman WHERE memerlukan_jaminan=1 ORDER BY id LIMIT 1);
SET @reguler=(SELECT id FROM jenis_pinjaman WHERE memerlukan_jaminan=0 ORDER BY id LIMIT 1);

INSERT INTO anggota (kode_anggota,kode_anggota_jaminan,nama_lengkap,alamat,telepon,ktp,status,tanggal_daftar) VALUES
('DMO001','DMJG001','Demo Menunggu','Mojokerto','081200000001','3510010000000001','aktif',CURDATE()),
('DMO002','DMJG002','Demo Disetujui','Mojokerto','081200000002','3510010000000002','aktif',CURDATE()),
('DMO003','DMJG003','Demo Ditolak','Mojokerto','081200000003','3510010000000003','aktif',CURDATE()),
('DMO004','DMJG004','Demo Aktif','Mojokerto','081200000004','3510010000000004','aktif',CURDATE()),
('DMO005','DMJG005','Demo Lunas Pengecekan','Mojokerto','081200000005','3510010000000005','aktif',CURDATE()),
('DMO006','DMJG006','Demo Macet','Mojokerto','081200000006','3510010000000006','aktif',CURDATE()),
('DMO007','DMJG007','Demo TC Menunggu','Mojokerto','081200000007','3510010000000007','aktif',CURDATE()),
('DMO008','DMJG008','Demo TC Disetujui','Mojokerto','081200000008','3510010000000008','aktif',CURDATE()),
('DMO009','DMJG009','Demo TC Ditolak','Mojokerto','081200000009','3510010000000009','aktif',CURDATE()),
('DMO010','DMJG010','Demo Jaminan Diserahkan','Mojokerto','081200000010','3510010000000010','aktif',CURDATE()),
('DMO011','DMJG011','Demo Jaminan Ditolak','Mojokerto','081200000011','3510010000000011','aktif',CURDATE()),
('DMO012','DMJG012','Demo Jaminan Disetujui','Mojokerto','081200000012','3510010000000012','aktif',CURDATE());

INSERT INTO pengajuan_pinjaman (kode_pengajuan,anggota_id,jenis_pinjaman_id,jumlah_pinjaman,tenor,bunga,skema_bunga,frekuensi,tujuan_pinjaman,status,catatan_admin,admin_id,user_id,tanggal_pengajuan,tanggal_persetujuan) 
SELECT CONCAT('DEMO-PJG-',LPAD(n,3,'0')),a.id,IF(n IN (4,5,6,10,11,12),@gadai,@reguler),2000000,3,10,'flat','bulanan','Data demonstrasi semua status',
CASE n WHEN 1 THEN 'menunggu' WHEN 2 THEN 'disetujui' WHEN 3 THEN 'ditolak' ELSE 'dicairkan' END,
CONCAT('Data demo status ',n),IF(n IN (1),NULL,@admin),@teller,NOW(),IF(n=1,NULL,NOW())
FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12) x
JOIN anggota a ON a.kode_anggota=CONCAT('DMO',LPAD(n,3,'0'));

-- Tiga pengajuan TC tetap berstatus disetujui/belum cair.
UPDATE pengajuan_pinjaman SET status='disetujui',admin_id=@admin,tanggal_persetujuan=NOW() WHERE kode_pengajuan IN ('DEMO-PJG-007','DEMO-PJG-008','DEMO-PJG-009');

INSERT INTO pinjaman (pengajuan_id,kode_pinjaman,anggota_id,jenis_pinjaman_id,jumlah_pinjaman,sisa_pokok,tenor,bunga,skema_bunga,frekuensi,jumlah_cicilan,total_pinjaman,angsuran_per_bulan,tanggal_pencairan,jatuh_tempo,foto_pencairan,dicairkan_oleh,tanggal_lunas,status)
SELECT pp.id,REPLACE(pp.kode_pengajuan,'PJG','PJN'),pp.anggota_id,pp.jenis_pinjaman_id,pp.jumlah_pinjaman,
CASE WHEN pp.kode_pengajuan IN ('DEMO-PJG-005','DEMO-PJG-010') THEN 0 ELSE pp.jumlah_pinjaman END,3,pp.bunga,'flat','bulanan',3,2300000,766667,DATE_SUB(CURDATE(),INTERVAL 60 DAY),DATE_ADD(CURDATE(),INTERVAL 30 DAY),'pencairan/demo-nasabah.jpeg',@teller,
CASE WHEN pp.kode_pengajuan IN ('DEMO-PJG-005','DEMO-PJG-010') THEN CURDATE() ELSE NULL END,
CASE WHEN pp.kode_pengajuan IN ('DEMO-PJG-005','DEMO-PJG-010') THEN 'lunas' WHEN pp.kode_pengajuan='DEMO-PJG-006' THEN 'macet' ELSE 'aktif' END
FROM pengajuan_pinjaman pp WHERE pp.status='dicairkan';

INSERT INTO angsuran (pinjaman_id,angsuran_ke,jumlah_angsuran,pokok,bunga,denda,tanggal_jatuh_tempo,tanggal_bayar,status,keterlambatan_hari)
SELECT p.id,x.n,766667,666667,100000,CASE WHEN p.status='macet' THEN 25000 ELSE 0 END,
DATE_ADD(p.tanggal_pencairan,INTERVAL x.n MONTH),CASE WHEN p.status='lunas' THEN DATE_ADD(p.tanggal_pencairan,INTERVAL x.n MONTH) ELSE NULL END,
CASE WHEN p.status='lunas' THEN 'lunas' WHEN p.status='macet' AND x.n=1 THEN 'telat' ELSE 'belum_bayar' END,CASE WHEN p.status='macet' AND x.n=1 THEN 20 ELSE 0 END
FROM pinjaman p JOIN (SELECT 1 n UNION SELECT 2 UNION SELECT 3) x;

INSERT INTO jaminan_pinjaman (pengajuan_id,pinjaman_id,jenis_barang,identitas_barang,deskripsi_barang,nilai_taksiran,foto_barang,foto_nasabah_barang,status_jaminan,tanggal_dikembalikan)
SELECT pp.id,p.id,'Sepeda Motor Demo',CONCAT('Nopol DEMO-',p.id),'Merk: Honda\nKondisi: Baik\nBerat/spesifikasi: sesuai dokumen awal',3000000,'jaminan/demo-barang.jpeg','jaminan/demo-nasabah-barang.jpeg',
CASE WHEN p.kode_pinjaman LIKE '%010' THEN 'dikembalikan' ELSE 'dititipkan' END,CASE WHEN p.kode_pinjaman LIKE '%010' THEN CURDATE() ELSE NULL END
FROM pinjaman p JOIN pengajuan_pinjaman pp ON pp.id=p.pengajuan_id WHERE pp.jenis_pinjaman_id=@gadai;

INSERT INTO layanan_tunda_cair (pengajuan_id,user_id,status,alasan,admin_id,catatan_admin,diputuskan_at) SELECT id,@teller,'menunggu','Nasabah meminta waktu melengkapi berkas',NULL,NULL,NULL FROM pengajuan_pinjaman WHERE kode_pengajuan='DEMO-PJG-007';
INSERT INTO layanan_tunda_cair (pengajuan_id,user_id,status,alasan,admin_id,catatan_admin,diputuskan_at) SELECT id,@teller,'disetujui','Nasabah belum siap menerima dana',@admin,'Penundaan disetujui',NOW() FROM pengajuan_pinjaman WHERE kode_pengajuan='DEMO-PJG-008';
INSERT INTO layanan_tunda_cair (pengajuan_id,user_id,status,alasan,admin_id,catatan_admin,diputuskan_at) SELECT id,@teller,'ditolak','Permintaan tunda tanpa dokumen',@admin,'TC ditolak, pencairan dapat dilanjutkan',NOW() FROM pengajuan_pinjaman WHERE kode_pengajuan='DEMO-PJG-009';

INSERT INTO layanan_jaminan_kembali (pinjaman_id,user_id,status,admin_id,alasan,bukti_jaminan,bukti_lunas,catatan_admin,diperiksa_at) SELECT id,@teller,'menunggu',NULL,'Pengembalian demo menunggu','jaminan_kembali/demo-bukti.jpeg','pembayaran/demo-bukti.jpeg',NULL,NULL FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-004';
INSERT INTO layanan_jaminan_kembali (pinjaman_id,user_id,status,admin_id,alasan,bukti_jaminan,bukti_lunas,catatan_admin,diperiksa_at) SELECT id,@teller,'pengecekan',@admin,'Pengembalian demo pengecekan','jaminan_kembali/demo-bukti.jpeg','pembayaran/demo-bukti.jpeg','Sedang diperiksa',NOW() FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-005';
INSERT INTO layanan_jaminan_kembali (pinjaman_id,user_id,status,admin_id,alasan,bukti_jaminan,bukti_lunas,catatan_admin,diperiksa_at) SELECT id,@teller,'diserahkan',@admin,'Pengembalian demo selesai','jaminan_kembali/demo-bukti.jpeg','pembayaran/demo-bukti.jpeg','Diserahkan kepada nasabah',NOW() FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-010';
INSERT INTO layanan_jaminan_kembali (pinjaman_id,user_id,status,admin_id,alasan,bukti_jaminan,bukti_lunas,catatan_admin,diperiksa_at) SELECT id,@teller,'ditolak',@admin,'Pengembalian demo ditolak','jaminan_kembali/demo-bukti.jpeg','pembayaran/demo-bukti.jpeg','Dokumen belum sesuai',NOW() FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-011';
INSERT INTO layanan_jaminan_kembali (pinjaman_id,user_id,status,admin_id,alasan,bukti_jaminan,bukti_lunas,catatan_admin,diperiksa_at) SELECT id,@teller,'disetujui',@admin,'Pengembalian demo disetujui','jaminan_kembali/demo-bukti.jpeg','pembayaran/demo-bukti.jpeg','Jaminan siap diserahkan',NOW() FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-012';

INSERT INTO layanan_pelunasan (pinjaman_id,user_id,status,diskon_persen,admin_id,catatan) SELECT id,@teller,'menunggu',0,NULL,'Demo pelunasan menunggu' FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-004';
INSERT INTO layanan_pelunasan (pinjaman_id,user_id,status,diskon_persen,admin_id,catatan) SELECT id,@teller,'disetujui',10,@admin,'Demo pelunasan disetujui' FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-006';
INSERT INTO layanan_pelunasan (pinjaman_id,user_id,status,diskon_persen,admin_id,catatan) SELECT id,@teller,'ditolak',0,@admin,'Demo pelunasan ditolak' FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-005';
INSERT INTO layanan_pelunasan (pinjaman_id,user_id,status,diskon_persen,admin_id,catatan) SELECT id,@teller,'selesai',0,@admin,'Demo pelunasan selesai' FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-010';
INSERT INTO layanan_prioritas (pinjaman_id,anggota_id,user_id,status,admin_id,catatan,koreksi,koreksi_admin_id,koreksi_at) SELECT p.id,p.anggota_id,@teller,'menunggu',NULL,'Demo prioritas menunggu',NULL,NULL,NULL FROM pinjaman p WHERE p.kode_pinjaman='DEMO-PJN-004';
INSERT INTO layanan_prioritas (pinjaman_id,anggota_id,user_id,status,admin_id,catatan,koreksi,koreksi_admin_id,koreksi_at) SELECT p.id,p.anggota_id,@teller,'pengecekan',@admin,'Demo prioritas pengecekan','Perlu kelengkapan data',@admin,NOW() FROM pinjaman p WHERE p.kode_pinjaman='DEMO-PJN-005';
INSERT INTO layanan_prioritas (pinjaman_id,anggota_id,user_id,status,admin_id,catatan,koreksi,koreksi_admin_id,koreksi_at) SELECT p.id,p.anggota_id,@teller,'disetujui',@admin,'Demo prioritas disetujui','Data lengkap',@admin,NOW() FROM pinjaman p WHERE p.kode_pinjaman='DEMO-PJN-006';
INSERT INTO layanan_prioritas (pinjaman_id,anggota_id,user_id,status,admin_id,catatan,koreksi,koreksi_admin_id,koreksi_at) SELECT p.id,p.anggota_id,@teller,'ditolak',@admin,'Demo prioritas ditolak','Belum memenuhi syarat',@admin,NOW() FROM pinjaman p WHERE p.kode_pinjaman='DEMO-PJN-010';
INSERT INTO layanan_laporan_macet (pinjaman_id,user_id,hari_telat,sisa_hutang,catatan,status,admin_id,keputusan) SELECT id,@teller,20,2000000,'Demo laporan macet menunggu','menunggu',NULL,NULL FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-004';
INSERT INTO layanan_laporan_macet (pinjaman_id,user_id,hari_telat,sisa_hutang,catatan,status,admin_id,keputusan) SELECT id,@teller,20,2000000,'Demo laporan ditindaklanjuti','ditindaklanjuti',@admin,'Sedang ditindaklanjuti' FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-005';
INSERT INTO layanan_laporan_macet (pinjaman_id,user_id,hari_telat,sisa_hutang,catatan,status,admin_id,keputusan) SELECT id,@teller,20,2000000,'Demo laporan selesai','selesai',@admin,'Penanganan selesai' FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-006';
INSERT INTO layanan_laporan_macet (pinjaman_id,user_id,hari_telat,sisa_hutang,catatan,status,admin_id,keputusan) SELECT id,@teller,20,2000000,'Demo laporan ditolak','ditolak',@admin,'Laporan ditolak' FROM pinjaman WHERE kode_pinjaman='DEMO-PJN-010';
COMMIT;

<?php
/** Data khusus untuk mencoba alur pengajuan penghapusan denda di database uji. */
if (PHP_SAPI !== 'cli') {
    exit("Jalankan melalui CLI.\n");
}

require __DIR__ . '/../../config/config.php';
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$kode = 'UJI-HAPUS-DENDA-01';
$teller = $db->query("SELECT id, username FROM users WHERE role='teller' AND status='aktif' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin = $db->query("SELECT id, username FROM users WHERE role='admin' AND status='aktif' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$anggota = $db->query("SELECT id, nama_lengkap FROM anggota WHERE status='aktif' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$jenis = $db->query("SELECT id FROM jenis_pinjaman WHERE status='aktif' AND memerlukan_jaminan=0 ORDER BY id LIMIT 1")->fetchColumn();
if (!$teller || !$admin || !$anggota || !$jenis) {
    exit("Perlu teller, admin, anggota, dan jenis pinjaman tanpa jaminan yang aktif.\n");
}

$db->beginTransaction();
try {
    $cek = $db->prepare('SELECT id FROM pinjaman WHERE kode_pinjaman=?');
    $cek->execute([$kode]);
    $pinjamanId = (int) $cek->fetchColumn();
    if (!$pinjamanId) {
        $db->prepare("INSERT INTO pengajuan_pinjaman
            (kode_pengajuan,anggota_id,jenis_pinjaman_id,jumlah_pinjaman,tenor,bunga,skema_bunga,frekuensi,
             tujuan_pinjaman,status,admin_id,user_id,tanggal_pengajuan,tanggal_persetujuan)
            VALUES (?,?,?,?,1,10,'flat','bulanan','Uji penghapusan denda','dicairkan',?,?,DATE_SUB(NOW(),INTERVAL 40 DAY),DATE_SUB(NOW(),INTERVAL 39 DAY))")
            ->execute([$kode,(int)$anggota['id'],(int)$jenis,1000000,(int)$admin['id'],(int)$teller['id']]);
        $pengajuanId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO pinjaman
            (pengajuan_id,kode_pinjaman,anggota_id,jenis_pinjaman_id,jumlah_pinjaman,sisa_pokok,tenor,bunga,
             skema_bunga,frekuensi,jumlah_cicilan,total_pinjaman,angsuran_per_bulan,tanggal_pencairan,
             jatuh_tempo,dicairkan_oleh,status)
            VALUES (?,?,?,?,1000000,1000000,1,10,'flat','bulanan',1,1100000,1100000,
                    DATE_SUB(CURDATE(),INTERVAL 38 DAY),DATE_SUB(CURDATE(),INTERVAL 10 DAY),?,'aktif')")
            ->execute([$pengajuanId,$kode,(int)$anggota['id'],(int)$jenis,(int)$teller['id']]);
        $pinjamanId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO angsuran
            (pinjaman_id,angsuran_ke,jumlah_angsuran,pokok,bunga,denda,denda_manual,tanggal_jatuh_tempo,status,keterlambatan_hari)
            VALUES (?,1,1100000,1000000,100000,25000,1,DATE_SUB(CURDATE(),INTERVAL 10 DAY),'telat',10)")
            ->execute([$pinjamanId]);
    }
    $db->commit();
    echo "Pinjaman uji: $kode (ID $pinjamanId), nasabah: {$anggota['nama_lengkap']}\n";
    echo "Teller: {$teller['username']} | Admin: {$admin['username']}\n";
    echo "Teller: /teller/detail_layanan.php?tab=denda&id=$pinjamanId\n";
    echo "Admin: /admin/detail_layanan.php?tab=denda&id=$pinjamanId\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

<?php
/**
 * Bukti Pelunasan Pinjaman (A5 portrait, siap cetak).
 * Dipakai admin/cetak_bukti_pelunasan.php & teller/cetak_bukti_pelunasan.php setelah cek akses.
 * Hanya untuk pinjaman yang SEMUA cicilannya sudah lunas; selain itu ditolak di server.
 */

$pinjaman_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stmt = $db->prepare("SELECT p.*, a.nama_lengkap, a.kode_anggota, a.kode_anggota_jaminan,
                             jp.nama_jenis, jp.memerlukan_jaminan,
                             (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id) AS total_cicilan,
                             (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status <> 'lunas') AS cicilan_belum,
                             (SELECT COALESCE(SUM(jumlah_angsuran), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') AS total_angsuran_dibayar,
                             (SELECT COALESCE(SUM(denda), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') AS total_denda,
                             (SELECT MAX(DATE(tanggal_bayar)) FROM angsuran WHERE pinjaman_id = p.id) AS bayar_terakhir
                      FROM pinjaman p
                      JOIN anggota a ON p.anggota_id = a.id
                      JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                      WHERE p.id = ?");
$stmt->execute([$pinjaman_id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$b) {
    http_response_code(404);
    die('Data pinjaman tidak ditemukan.');
}
if ((int) $b['total_cicilan'] === 0 || (int) $b['cicilan_belum'] > 0) {
    http_response_code(403);
    die('Pinjaman ini belum lunas, bukti pelunasan belum bisa dicetak.');
}

$koperasi = $db->query("SELECT * FROM koperasi WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$koperasi) {
    $koperasi = ['nama' => 'Koperasi Simpan Pinjam Sejahtera', 'alamat' => '-', 'telepon' => '-', 'email' => '-'];
}

$hitung = hitungDariPinjaman($b);
// Tanggal lunas: kolom tanggal_lunas bila ada, selain itu tanggal bayar cicilan terakhir
$tanggal_lunas = !empty($b['tanggal_lunas']) ? $b['tanggal_lunas'] : $b['bayar_terakhir'];
$total_dibayar = (float) $b['total_angsuran_dibayar'] + (float) $b['total_denda'];
if (layananSiap($db)) {
    $stmt_diskon=$db->prepare("SELECT l.diskon_persen, (SELECT COALESCE(SUM(bunga),0) FROM angsuran WHERE pinjaman_id=l.pinjaman_id AND cara_bayar='pelunasan') sisa_bunga FROM layanan_pelunasan l WHERE l.pinjaman_id=? AND l.status='selesai'");
    $stmt_diskon->execute([$b['id']]);$diskon_lunas=$stmt_diskon->fetch(PDO::FETCH_ASSOC);
    if ($diskon_lunas) $total_dibayar-=round($diskon_lunas['sisa_bunga']*$diskon_lunas['diskon_persen']/100,2);
}
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$khusus_agtj = strpos((string) $b['kode_anggota'], 'AGTJ') === 0
    || strpos((string) ($b['kode_anggota_jaminan'] ?? ''), 'AGTJ') === 0;
// AGTJ ditandatangani Nasabah dan Teller; pinjaman lain tetap Admin dan Teller.
// Nama petugas diambil dari pihak yang menyetujui/mencairkan, dengan pengguna pencetak sebagai cadangan.
$stmt_ttd = $db->prepare("SELECT ua.nama_lengkap AS admin_nama, ut.nama_lengkap AS teller_nama
                          FROM pinjaman p
                          LEFT JOIN pengajuan_pinjaman pp ON p.pengajuan_id = pp.id
                          LEFT JOIN users ua ON pp.admin_id = ua.id
                          LEFT JOIN users ut ON p.dicairkan_oleh = ut.id
                          WHERE p.id = ?");
$stmt_ttd->execute(array($b['id']));
$ttd = $stmt_ttd->fetch(PDO::FETCH_ASSOC);
$nama_admin = ($ttd && $ttd['admin_nama']) ? $ttd['admin_nama'] : (isAdmin() ? $_SESSION['nama_lengkap'] : '');
$nama_teller = ($ttd && $ttd['teller_nama']) ? $ttd['teller_nama'] : (isTeller() ? $_SESSION['nama_lengkap'] : '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bukti Pelunasan <?php echo $e($b['kode_pinjaman']); ?></title>
    <style>
        @page { size: A5 portrait; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e9ecef; font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 11pt; }
        .toolbar { text-align: center; padding: 12px; }
        .toolbar button { font-size: 14px; padding: 8px 18px; margin: 0 4px; cursor: pointer; }
        .slip { width: 148mm; min-height: 210mm; margin: 0 auto 24px; padding: 10mm; background: #fff;
                border: 1px solid #000; box-shadow: 0 2px 8px rgba(0,0,0,.15); }
        .kop { text-align: center; border-bottom: 3px double #000; padding-bottom: 6px; margin-bottom: 14px; }
        .kop h1 { font-size: 15pt; margin: 0 0 4px; letter-spacing: 1px; }
        .kop h2 { font-size: 12.5pt; margin: 0 0 3px; }
        .kop p { font-size: 8.5pt; margin: 0; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data td { padding: 4px 2px; vertical-align: top; }
        table.data td.label { width: 40%; }
        table.data td.sep { width: 3%; }
        table.data tr.total td { border-top: 1px solid #000; font-weight: bold; padding-top: 6px; }
        .section { margin-top: 10px; }
        .lunas { margin: 16px 0 4px; text-align: center; border: 2px solid #1a7f37; color: #1a7f37;
                 padding: 8px; font-weight: bold; font-size: 11.5pt; letter-spacing: .5px; }
        .ttd-section { margin-top: 28px; display: flex; justify-content: space-between; }
        .ttd-box { text-align: center; width: 45%; }
        .ttd-line { border-top: 1px solid #000; margin-top: 55px; padding-top: 4px; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .slip { width: auto; min-height: 0; margin: 0; padding: 0; border: none; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">&#128424; Cetak</button>
        <button type="button" onclick="window.close()">Tutup</button>
    </div>

    <div class="slip">
        <div class="kop">
            <h1>BUKTI PELUNASAN PINJAMAN</h1>
            <h2><?php echo $e($koperasi['nama']); ?></h2>
            <p><?php echo $e($koperasi['alamat']); ?>, Telp: <?php echo $e($koperasi['telepon']); ?>, Email: <?php echo $e($koperasi['email']); ?></p>
        </div>

        <table class="data">
            <tr><td class="label">Kode Pinjaman</td><td class="sep">:</td><td><?php echo $e($b['kode_pinjaman']); ?></td></tr>
            <tr><td class="label">Kode Anggota</td><td class="sep">:</td><td><?php echo $e($b['kode_anggota']); ?></td></tr>
            <tr><td class="label">Nama Anggota</td><td class="sep">:</td><td><?php echo $e($b['nama_lengkap']); ?></td></tr>
            <tr><td class="label">Jenis Pinjaman</td><td class="sep">:</td><td><?php echo $e($b['nama_jenis']); ?></td></tr>
        </table>

        <table class="data section">
            <tr><td class="label">Jumlah Pinjaman</td><td class="sep">:</td><td><?php echo formatRupiah($b['jumlah_pinjaman']); ?></td></tr>
            <tr><td class="label">Tenor</td><td class="sep">:</td><td><?php echo (int) $b['tenor']; ?> bulan &mdash; <?php echo $e(labelFrekuensi($hitung['frekuensi'])); ?> <?php echo (int) $hitung['jumlah_cicilan']; ?>&times;</td></tr>
            <tr><td class="label">Total Angsuran Dibayar</td><td class="sep">:</td><td><?php echo formatRupiah($b['total_angsuran_dibayar']); ?></td></tr>
            <tr><td class="label">Total Denda</td><td class="sep">:</td><td><?php echo ((float) $b['total_denda'] > 0) ? formatRupiah($b['total_denda']) : 'Tidak ada'; ?></td></tr>
            <tr class="total"><td class="label">Total Dibayar</td><td class="sep">:</td><td><?php echo formatRupiah($total_dibayar); ?></td></tr>
        </table>

        <table class="data section">
            <tr><td class="label">Tanggal Pencairan</td><td class="sep">:</td><td><?php echo $e(formatDateIndonesian($b['tanggal_pencairan'])); ?></td></tr>
            <tr><td class="label">Tanggal Lunas</td><td class="sep">:</td><td><?php echo $tanggal_lunas ? $e(formatDateIndonesian($tanggal_lunas)) : '-'; ?></td></tr>
        </table>

        <div class="lunas">Pinjaman telah dinyatakan LUNAS</div>
        <?php if (layananSiap($db)) {
            $template=$db->query('SELECT surat_lunas FROM layanan_pengaturan WHERE id=1')->fetchColumn();
            echo '<p>'.nl2br($e(strtr((string)$template,['{nama}'=>$b['nama_lengkap'],'{norek}'=>$b['kode_pinjaman'],'{tanggal}'=>$tanggal_lunas]))).'</p>';
        } ?>

        <div class="ttd-section">
            <div class="ttd-box">
                <?php if ($khusus_agtj): ?>
                    <div class="ttd-line"><strong>NASABAH</strong><br><?php echo $e($b['nama_lengkap']); ?></div>
                <?php else: ?>
                    <div class="ttd-line"><strong>ADMIN</strong><br><?php echo $nama_admin !== '' ? $e($nama_admin) : '&nbsp;'; ?></div>
                <?php endif; ?>
            </div>
            <div class="ttd-box">
                <div class="ttd-line"><strong>TELLER</strong><br><?php echo $nama_teller !== '' ? $e($nama_teller) : '&nbsp;'; ?></div>
            </div>
        </div>
    </div>
</body>
</html>

<?php
/**
 * Bukti Pembayaran Angsuran (A5 landscape, siap cetak).
 * Dipakai admin/cetak_bukti_angsuran.php & teller/cetak_bukti_angsuran.php setelah cek akses.
 * Butuh $db. Untuk satu cicilan yang sudah dibayar (angsuran.id).
 */

$angsuran_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stmt = $db->prepare("SELECT a.*, p.kode_pinjaman, p.frekuensi, p.status AS status_pinjaman,
                             ag.nama_lengkap, ag.kode_anggota, ag.alamat,
                             (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = a.pinjaman_id) AS total_cicilan,
                             (SELECT COALESCE(SUM(jumlah_angsuran), 0) FROM angsuran
                               WHERE pinjaman_id = a.pinjaman_id AND status <> 'lunas') AS sisa_tagihan
                      FROM angsuran a
                      JOIN pinjaman p ON a.pinjaman_id = p.id
                      JOIN anggota ag ON p.anggota_id = ag.id
                      WHERE a.id = ?");
$stmt->execute([$angsuran_id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$b) {
    http_response_code(404);
    die('Data angsuran tidak ditemukan.');
}
if ($b['status'] !== 'lunas') {
    die('Angsuran ini belum dibayar, bukti pembayaran belum bisa dicetak.');
}

$koperasi = $db->query("SELECT * FROM koperasi WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$koperasi) {
    $koperasi = ['nama' => 'Koperasi Simpan Pinjam Sejahtera', 'alamat' => '-', 'telepon' => '-', 'email' => '-'];
}

$frekuensi = isset($b['frekuensi']) ? $b['frekuensi'] : 'bulanan';
$denda = (float) $b['denda'];
$jumlah_angsuran = (float) $b['jumlah_angsuran'];
$pelunasan = isset($b['cara_bayar']) && $b['cara_bayar'] === 'pelunasan';
if ($pelunasan) {
    // Pelunasan dini membayar semua sisa cicilan sekaligus: tampilkan totalnya
    $stmt_pl = $db->prepare("SELECT MIN(angsuran_ke) AS dari, MAX(angsuran_ke) AS sampai,
                                    SUM(jumlah_angsuran) AS jumlah, SUM(denda) AS denda
                             FROM angsuran WHERE pinjaman_id = ? AND cara_bayar = 'pelunasan'");
    $stmt_pl->execute([$b['pinjaman_id']]);
    $pl = $stmt_pl->fetch(PDO::FETCH_ASSOC);
    $jumlah_angsuran = (float) $pl['jumlah'];
    $denda = (float) $pl['denda'];
    $ket_angsuran = 'Pelunasan (cicilan ke-' . $pl['dari'] . ' s.d. ' . $pl['sampai'] . ' dari ' . $b['total_cicilan'] . ')';
}
$total_bayar = $jumlah_angsuran + $denda;
if (!$pelunasan) {
    if ($b['status_pinjaman'] === 'lunas' && (int) $b['angsuran_ke'] === (int) $b['total_cicilan']) {
        $ket_angsuran = 'Lunas (cicilan ke-' . $b['angsuran_ke'] . ' dari ' . $b['total_cicilan'] . ')';
    } else {
        $ket_angsuran = 'Ke-' . $b['angsuran_ke'] . ' dari ' . $b['total_cicilan'];
    }
}
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
// Pinjaman sudah lunas seluruhnya -> bukti menampilkan tulisan LUNAS (versi mini) menggantikan nominal
$pinjaman_lunas = ((float) $b['sisa_tagihan'] <= 0.005) && (int) $b['total_cicilan'] > 0;

// Hanya kolom Teller: nama teller yang mencatat pembayaran (bila tercatat), selain itu teller yang mencetak
$teller_nama = '';
$stmt_t = $db->prepare("SELECT u.nama_lengkap FROM transaksi t JOIN users u ON t.user_id = u.id
                        WHERE t.keterangan = ? ORDER BY t.id DESC LIMIT 1");
$stmt_t->execute(array('Pembayaran angsuran ke-' . $b['angsuran_ke'] . ' - ' . $b['kode_pinjaman']));
$nama_pencatat = $stmt_t->fetchColumn();
if ($nama_pencatat) {
    $teller_nama = $nama_pencatat;
} elseif (isTeller()) {
    $teller_nama = $_SESSION['nama_lengkap'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bukti Angsuran <?php echo $e($b['kode_pinjaman']); ?> ke-<?php echo (int) $b['angsuran_ke']; ?></title>
    <style>
        @page { size: A5 landscape; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e9ecef; font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 10.5pt; }
        .toolbar { text-align: center; padding: 12px; }
        .toolbar button { font-size: 14px; padding: 8px 18px; margin: 0 4px; cursor: pointer; }
        .slip { width: 210mm; min-height: 130mm; margin: 0 auto 24px; padding: 8mm 10mm; background: #fff;
                border: 1px solid #000; box-shadow: 0 2px 8px rgba(0,0,0,.15); position: relative; }
        .kop { text-align: center; border-bottom: 3px double #000; padding-bottom: 5px; margin-bottom: 10px; }
        .kop h1 { font-size: 14pt; margin: 0 0 3px; letter-spacing: 1px; }
        .kop h2 { font-size: 12pt; margin: 0 0 2px; }
        .kop p { font-size: 8.5pt; margin: 0; }
        .cols { display: flex; gap: 8mm; }
        .cols > div { flex: 1; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data td { padding: 3px 2px; vertical-align: top; }
        table.data td.label { width: 36%; }
        table.data td.sep { width: 3%; }
        .jumlah { margin-top: 8px; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 6px 2px; }
        .jumlah .nominal { font-size: 14pt; font-weight: bold; }
        .jumlah .kata { font-style: italic; margin-top: 2px; }
        .lunas-mini { margin: 8px 0; text-align: center; border: 2px solid #1a7f37; color: #1a7f37; padding: 6px;
                      font-weight: bold; font-size: 12pt; letter-spacing: 1px; }
        .stempel { position: absolute; right: 16mm; bottom: 14mm; border: 3px solid #1a7f37; color: #1a7f37; padding: 2px 10px;
                   font-size: 14pt; font-weight: bold; transform: rotate(-12deg); opacity: .75; letter-spacing: 2px; }
        .ttd-section { margin-top: 10px; display: flex; justify-content: space-around; }
        .ttd-box { text-align: center; width: 40%; }
        .ttd-line { border-top: 1px solid #000; margin-top: 42px; padding-top: 3px; }
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
            <h1>BUKTI PEMBAYARAN ANGSURAN</h1>
            <h2><?php echo $e($koperasi['nama']); ?></h2>
            <p><?php echo $e($koperasi['alamat']); ?>, Telp: <?php echo $e($koperasi['telepon']); ?></p>
        </div>
        <?php if (!$pinjaman_lunas): ?>
        <div class="stempel">LUNAS</div>
        <?php endif; ?>

        <div class="cols">
            <div>
                <table class="data">
                    <tr><td class="label">Nama Peminjam</td><td class="sep">:</td><td><?php echo $e($b['nama_lengkap']); ?> (<?php echo $e($b['kode_anggota']); ?>)</td></tr>
                    <tr><td class="label">Alamat</td><td class="sep">:</td><td><?php echo $e($b['alamat'] ?: '-'); ?></td></tr>
                    <tr><td class="label">Kode Pinjaman</td><td class="sep">:</td><td><?php echo $e($b['kode_pinjaman']); ?></td></tr>
                </table>
            </div>
            <div>
                <table class="data">
                    <tr><td class="label">Jenis Angsuran</td><td class="sep">:</td><td><?php echo labelFrekuensi($frekuensi); ?></td></tr>
                    <tr><td class="label">Angsuran</td><td class="sep">:</td><td><?php echo $e($ket_angsuran); ?></td></tr>
                    <tr><td class="label">Tanggal Bayar</td><td class="sep">:</td><td><?php echo $e(formatDateIndonesian(date('Y-m-d', strtotime($b['tanggal_bayar'])))); ?></td></tr>
                    <tr><td class="label">Jatuh Tempo</td><td class="sep">:</td><td><?php echo $e(formatDateIndonesian($b['tanggal_jatuh_tempo'])); ?></td></tr>
                </table>
            </div>
        </div>

        <?php if ($pinjaman_lunas): ?>
        <div class="lunas-mini">LUNAS</div>
        <?php else: ?>
        <div class="jumlah">
            <table class="data">
                <tr><td class="label">Jumlah Angsuran</td><td class="sep">:</td><td><?php echo formatRupiah($jumlah_angsuran); ?></td></tr>
                <?php if ($denda > 0): ?>
                <tr><td class="label">Denda</td><td class="sep">:</td><td><?php echo formatRupiah($denda); ?></td></tr>
                <?php endif; ?>
                <tr><td class="label"><strong>Total Dibayar</strong></td><td class="sep">:</td><td class="nominal"><?php echo formatRupiah($total_bayar); ?></td></tr>
                <tr><td class="label">Terbilang</td><td class="sep">:</td><td class="kata"><?php echo $e(terbilangRupiah($total_bayar)); ?></td></tr>
                <tr><td class="label">Sisa Tagihan</td><td class="sep">:</td><td><?php echo formatRupiah($b['sisa_tagihan']); ?></td></tr>
            </table>
        </div>

        <?php endif; ?>

        <div class="ttd-section">
            <div class="ttd-box"><div class="ttd-line"><strong>TELLER</strong><br><?php echo $teller_nama !== '' ? $e($teller_nama) : '&nbsp;'; ?></div></div>
        </div>
    </div>
</body>
</html>

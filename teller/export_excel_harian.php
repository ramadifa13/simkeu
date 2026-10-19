<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

// Check if user has access to this page
if (!isTeller() && !isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Get tanggal parameter
$selected_date = $_GET['tanggal'] ?? date('Y-m-d');

// Get koperasi info
$query_koperasi = "SELECT * FROM koperasi WHERE id = 1";
$stmt_koperasi = $db->prepare($query_koperasi);
$stmt_koperasi->execute();
$koperasi = $stmt_koperasi->fetch(PDO::FETCH_ASSOC);

// ============================================
// AMBIL DATA (SAMA DENGAN LAPORAN HARIAN)
// ============================================

// Ringkasan transaksi simpanan
$query_summary = "SELECT 
    COUNT(*) as total_transaksi,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE 0 END), 0) as total_setoran,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'penarikan' THEN t.jumlah ELSE 0 END), 0) as total_penarikan,
    COUNT(CASE WHEN t.jenis_transaksi = 'setoran' THEN 1 END) as jumlah_setoran,
    COUNT(CASE WHEN t.jenis_transaksi = 'penarikan' THEN 1 END) as jumlah_penarikan
    FROM transaksi t
    JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
    WHERE DATE(t.tanggal_transaksi) = :tanggal AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')";
$stmt_summary = $db->prepare($query_summary);
$stmt_summary->bindParam(':tanggal', $selected_date);
$stmt_summary->execute();
$summary = $stmt_summary->fetch(PDO::FETCH_ASSOC);

if (!$summary) {
    $summary = [
        'total_transaksi' => 0,
        'total_setoran' => 0,
        'total_penarikan' => 0,
        'jumlah_setoran' => 0,
        'jumlah_penarikan' => 0
    ];
}

// Ringkasan pembayaran angsuran
$query_angsuran_summary = "SELECT 
    COUNT(*) as total_angsuran,
    COALESCE(SUM(jumlah_angsuran + COALESCE(denda, 0)), 0) as total_pembayaran_angsuran
    FROM angsuran 
    WHERE tanggal_bayar = :tanggal AND status = 'lunas'";
$stmt_angsuran_summary = $db->prepare($query_angsuran_summary);
$stmt_angsuran_summary->bindParam(':tanggal', $selected_date);
$stmt_angsuran_summary->execute();
$angsuran_summary = $stmt_angsuran_summary->fetch(PDO::FETCH_ASSOC);

if (!$angsuran_summary) {
    $angsuran_summary = [
        'total_angsuran' => 0,
        'total_pembayaran_angsuran' => 0
    ];
}

// Ringkasan kas
$query_kas_masuk = "SELECT COALESCE(SUM(jumlah), 0) as total_penerimaan
    FROM kas
    WHERE jenis = 'masuk' AND DATE(tanggal) = :tanggal";
$stmt_kas_masuk = $db->prepare($query_kas_masuk);
$stmt_kas_masuk->bindParam(':tanggal', $selected_date);
$stmt_kas_masuk->execute();
$penerimaan = $stmt_kas_masuk->fetch(PDO::FETCH_ASSOC)['total_penerimaan'];

$query_kas_keluar = "SELECT COALESCE(SUM(jumlah), 0) as total_pengeluaran
    FROM kas
    WHERE jenis = 'keluar' AND DATE(tanggal) = :tanggal";
$stmt_kas_keluar = $db->prepare($query_kas_keluar);
$stmt_kas_keluar->bindParam(':tanggal', $selected_date);
$stmt_kas_keluar->execute();
$pengeluaran = $stmt_kas_keluar->fetch(PDO::FETCH_ASSOC)['total_pengeluaran'];

// Detail transaksi simpanan
$query_transactions = "SELECT t.*, 
                              a.kode_anggota,
                              a.nama_lengkap, 
                              j.kode_jenis,
                              j.nama_jenis, 
                              u.nama_lengkap as teller
                      FROM transaksi t 
                      JOIN anggota a ON t.anggota_id = a.id 
                      JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                      JOIN users u ON t.user_id = u.id
                      WHERE DATE(t.tanggal_transaksi) = :tanggal AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
                      ORDER BY t.tanggal_transaksi DESC";
$stmt_transactions = $db->prepare($query_transactions);
$stmt_transactions->bindParam(':tanggal', $selected_date);
$stmt_transactions->execute();
$transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

// Detail pembayaran angsuran
$query_angsuran = "SELECT a.*, 
                          ang.kode_anggota,
                          ang.nama_lengkap, 
                          p.kode_pinjaman,
                          (SELECT MAX(t.tanggal_transaksi) FROM transaksi t
                            WHERE t.keterangan = CONCAT('Pembayaran angsuran ke-', a.angsuran_ke, ' - ', p.kode_pinjaman)
                              AND t.jumlah > 0) AS waktu_bayar,
                          jp.nama_jenis as jenis_pinjaman
                  FROM angsuran a
                  JOIN pinjaman p ON a.pinjaman_id = p.id
                  JOIN anggota ang ON p.anggota_id = ang.id
                  JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                  WHERE a.tanggal_bayar = :tanggal AND a.status = 'lunas'
                  ORDER BY a.tanggal_bayar DESC, a.created_at DESC";
$stmt_angsuran = $db->prepare($query_angsuran);
$stmt_angsuran->bindParam(':tanggal', $selected_date);
$stmt_angsuran->execute();
$angsuran = $stmt_angsuran->fetchAll(PDO::FETCH_ASSOC);

// Detail transaksi kas
$query_kas = "SELECT k.*, t.kode_transaksi, a.nama_lengkap as anggota_nama
              FROM kas k
              LEFT JOIN transaksi t ON k.transaksi_id = t.id
              LEFT JOIN anggota a ON t.anggota_id = a.id
              WHERE DATE(k.tanggal) = :tanggal
              ORDER BY k.tanggal ASC";
$stmt_kas = $db->prepare($query_kas);
$stmt_kas->bindParam(':tanggal', $selected_date);
$stmt_kas->execute();
$kas_transactions = $stmt_kas->fetchAll(PDO::FETCH_ASSOC);

// Fungsi format rupiah untuk Excel
function formatRupiahExcel($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Set header untuk download file Excel
header("Content-type: application/vnd-ms-excel");
header("Content-Disposition: attachment; filename=Laporan_Harian_" . date('Ymd', strtotime($selected_date)) . ".xls");

?>
<html>
<head>
    <title>Laporan Harian</title>
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th {
            background-color: #4CAF50;
            color: white;
            font-weight: bold;
            text-align: center;
            padding: 8px;
            border: 1px solid #ddd;
        }
        td {
            padding: 5px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        .text-end {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .header {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .subheader {
            font-size: 14px;
            margin-bottom: 15px;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 10px;
            background-color: #e0e0e0;
            padding: 5px;
        }
    </style>
</head>
<body>
    <!-- Header Laporan -->
    <div class="header">LAPORAN HARIAN TRANSAKSI</div>
    <div class="subheader">
        <?php echo $koperasi['nama'] ?? 'Koperasi Simpan Pinjam Sejahtera'; ?><br>
        <?php if (!empty($koperasi['alamat'])) echo $koperasi['alamat'] . '<br>'; ?>
        Tanggal: <?php echo date('d F Y', strtotime($selected_date)); ?><br>
        Tanggal Cetak: <?php echo date('d/m/Y H:i:s'); ?>
    </div>
    <br>

    <!-- Ringkasan Statistik -->
    <div class="section-title">RINGKASAN STATISTIK</div>
    <table>
        <tr>
            <td width="50%"><strong>Transaksi Simpanan:</strong> <?php echo $summary['total_transaksi']; ?></td>
            <td width="50%"><strong>Pembayaran Angsuran:</strong> <?php echo $angsuran_summary['total_angsuran']; ?></td>
        </tr>
        <tr>
            <td><strong>Total Setoran:</strong> <?php echo formatRupiahExcel($summary['total_setoran']); ?></td>
            <td><strong>Total Angsuran:</strong> <?php echo formatRupiahExcel($angsuran_summary['total_pembayaran_angsuran']); ?></td>
        </tr>
        <tr>
            <td><strong>Total Penarikan:</strong> <?php echo formatRupiahExcel($summary['total_penarikan']); ?></td>
            <td><strong>Jumlah Setoran:</strong> <?php echo $summary['jumlah_setoran']; ?> transaksi</td>
        </tr>
        <tr>
            <td><strong>Penerimaan Kas:</strong> <?php echo formatRupiahExcel($penerimaan); ?></td>
            <td><strong>Pengeluaran Kas:</strong> <?php echo formatRupiahExcel($pengeluaran); ?></td>
        </tr>
        <tr style="background-color: #f0f0f0;">
            <td><strong>Selisih Kas:</strong> <?php echo formatRupiahExcel($penerimaan - $pengeluaran); ?></td>
            <td></td>
        </tr>
    </table>
    <br>

    <!-- Transaksi Simpanan -->
    <div class="section-title">A. TRANSAKSI SIMPANAN</div>
    <?php if (empty($transactions)): ?>
        <p>Tidak ada transaksi simpanan</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Waktu</th>
                <th>Kode Anggota</th>
                <th>Nama Anggota</th>
                <th>Jenis</th>
                <th>Transaksi</th>
                <th>Jumlah</th>
                <th>Teller</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $t): ?>
            <tr>
                <td><?php echo date('H:i', strtotime($t['tanggal_transaksi'])); ?></td>
                <td><?php echo $t['kode_anggota']; ?></td>
                <td><?php echo $t['nama_lengkap']; ?></td>
                <td class="text-center"><?php echo $t['kode_jenis']; ?></td>
                <td class="text-center"><?php echo ucfirst($t['jenis_transaksi']); ?></td>
                <td class="text-end"><?php echo ($t['jenis_transaksi'] == 'setoran' ? '+' : '-') . ' ' . formatRupiahExcel($t['jumlah']); ?></td>
                <td><?php echo $t['teller']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <br>

    <!-- Pembayaran Angsuran -->
    <div class="section-title">B. PEMBAYARAN ANGSURAN</div>
    <?php if (empty($angsuran)): ?>
        <p>Tidak ada pembayaran angsuran</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Waktu</th>
                <th>Tanggal Bayar</th>
                <th>Jatuh Tempo</th>
                <th>Kode Anggota</th>
                <th>Nama Anggota</th>
                <th>Kode Pinjaman</th>
                <th>Angsuran Ke</th>
                <th>Jumlah</th>
                <th>Denda</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($angsuran as $a): ?>
            <tr>
                <td><?php echo $a['waktu_bayar'] ? date('H:i', strtotime($a['waktu_bayar'])) : '-'; ?></td>
                <td><?php echo $a['tanggal_bayar'] ? date('d/m/Y', strtotime($a['tanggal_bayar'])) : '-'; ?></td>
                <td><?php echo date('d/m/Y', strtotime($a['tanggal_jatuh_tempo'])); ?></td>
                <td><?php echo $a['kode_anggota']; ?></td>
                <td><?php echo $a['nama_lengkap']; ?></td>
                <td><?php echo $a['kode_pinjaman']; ?></td>
                <td class="text-center"><?php echo $a['angsuran_ke']; ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($a['jumlah_angsuran']); ?></td>
                <td class="text-end"><?php echo $a['denda'] > 0 ? formatRupiahExcel($a['denda']) : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <br>

    <!-- Transaksi Kas -->
    <div class="section-title">C. TRANSAKSI KAS</div>
    <?php if (empty($kas_transactions)): ?>
        <p>Tidak ada transaksi kas</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Waktu</th>
                <th>Jenis</th>
                <th>Keterangan</th>
                <th>Jumlah</th>
                <th>Saldo Sesudah</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($kas_transactions as $k): ?>
            <tr>
                <td><?php echo date('H:i', strtotime($k['tanggal'])); ?></td>
                <td class="text-center"><?php echo ucfirst($k['jenis']); ?></td>
                <td>
                    <?php echo $k['keterangan'] ?: '-'; ?>
                    <?php if ($k['anggota_nama']): ?>
                    <br><small><?php echo $k['anggota_nama']; ?></small>
                    <?php endif; ?>
                </td>
                <td class="text-end"><?php echo ($k['jenis'] == 'masuk' ? '+' : '-') . ' ' . formatRupiahExcel($k['jumlah']); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($k['saldo_sesudah']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    
    <br><br>
    
    <!-- Tanda tangan -->
    <table width="100%">
        <tr>
            <td width="60%"></td>
            <td width="40%" style="text-align: center;">
                <p>Jakarta, <?php echo date('d F Y', strtotime($selected_date)); ?></p>
                <p>Teller,</p>
                <br><br><br>
                <p>( <?php echo $_SESSION['nama_lengkap']; ?> )</p>
            </td>
        </tr>
    </table>
</body>
</html>

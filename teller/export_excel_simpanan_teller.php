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

// Get date range
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Get koperasi info
$query_koperasi = "SELECT * FROM koperasi WHERE id = 1";
$stmt_koperasi = $db->prepare($query_koperasi);
$stmt_koperasi->execute();
$koperasi = $stmt_koperasi->fetch(PDO::FETCH_ASSOC);

// ============================================
// AMBIL DATA JENIS SIMPANAN
// ============================================
$query_jenis = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' AND kode_jenis NOT IN ('ANG','BONUS','SHU') ORDER BY id";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// STATISTIK TOTAL
// ============================================
$query_total = "SELECT 
    COUNT(*) as total_transaksi,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE 0 END), 0) as total_setoran,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'penarikan' THEN t.jumlah ELSE 0 END), 0) as total_penarikan
    FROM transaksi t
    JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
    WHERE DATE(t.tanggal_transaksi) BETWEEN :start_date AND :end_date AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')";
$stmt_total = $db->prepare($query_total);
$stmt_total->bindParam(':start_date', $start_date);
$stmt_total->bindParam(':end_date', $end_date);
$stmt_total->execute();
$total_stats = $stmt_total->fetch(PDO::FETCH_ASSOC);

if (!$total_stats) {
    $total_stats = [
        'total_transaksi' => 0,
        'total_setoran' => 0,
        'total_penarikan' => 0
    ];
}

// ============================================
// SALDO AKHIR PER JENIS SIMPANAN
// ============================================
$query_saldo = "SELECT 
    j.kode_jenis,
    COALESCE(SUM(s.saldo), 0) as saldo_akhir
    FROM jenis_simpanan j
    LEFT JOIN simpanan s ON j.id = s.jenis_simpanan_id
    WHERE j.status = 'aktif' AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
    GROUP BY j.id, j.kode_jenis
    ORDER BY j.id";
$stmt_saldo = $db->prepare($query_saldo);
$stmt_saldo->execute();
$saldo_list = $stmt_saldo->fetchAll(PDO::FETCH_ASSOC);

$saldo_map = [];
foreach ($saldo_list as $s) {
    $saldo_map[$s['kode_jenis']] = $s['saldo_akhir'];
}

// ============================================
// DETAIL TRANSAKSI
// ============================================
$query_transactions = "SELECT t.*, 
                              a.nama_lengkap,
                              a.kode_anggota,
                              j.kode_jenis,
                              j.nama_jenis,
                              u.nama_lengkap as teller
                      FROM transaksi t 
                      JOIN anggota a ON t.anggota_id = a.id 
                      JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                      JOIN users u ON t.user_id = u.id
                      WHERE DATE(t.tanggal_transaksi) BETWEEN :start_date AND :end_date 
                          AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
                      ORDER BY t.tanggal_transaksi DESC";
$stmt_transactions = $db->prepare($query_transactions);
$stmt_transactions->bindParam(':start_date', $start_date);
$stmt_transactions->bindParam(':end_date', $end_date);
$stmt_transactions->execute();
$transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

// Fungsi format rupiah untuk Excel
function formatRupiahExcel($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Set header untuk download file Excel
header("Content-type: application/vnd-ms-excel");
header("Content-Disposition: attachment; filename=Laporan_Simpanan_" . date('Ymd') . ".xls");

?>
<html>
<head>
    <title>Laporan Simpanan</title>
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
    <div class="header">LAPORAN SIMPANAN ANGGOTA</div>
    <div class="subheader">
        <?php echo $koperasi['nama'] ?? 'Koperasi Simpan Pinjam Sejahtera'; ?><br>
        <?php if (!empty($koperasi['alamat'])) echo $koperasi['alamat'] . '<br>'; ?>
        Periode: <?php echo date('d/m/Y', strtotime($start_date)); ?> s/d <?php echo date('d/m/Y', strtotime($end_date)); ?><br>
        Tanggal Cetak: <?php echo date('d/m/Y H:i:s'); ?>
    </div>
    <br>

    <!-- Ringkasan Statistik -->
    <div class="section-title">RINGKASAN STATISTIK</div>
    <table>
        <tr>
            <td><strong>Total Transaksi:</strong> <?php echo $total_stats['total_transaksi']; ?></td>
            <td><strong>Total Setoran:</strong> <?php echo formatRupiahExcel($total_stats['total_setoran']); ?></td>
        </tr>
        <tr>
            <td><strong>Total Penarikan:</strong> <?php echo formatRupiahExcel($total_stats['total_penarikan']); ?></td>
            <td><strong>Selisih:</strong> <?php echo formatRupiahExcel($total_stats['total_setoran'] - $total_stats['total_penarikan']); ?></td>
        </tr>
    </table>
    <br>

    <!-- Ringkasan per Jenis Simpanan -->
    <div class="section-title">RINGKASAN PER JENIS SIMPANAN</div>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Kode</th>
                <th>Jenis Simpanan</th>
                <th>Setoran</th>
                <th>Penarikan</th>
                <th>Saldo Akhir</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($jenis_simpanan as $jenis): 
                // Hitung total setoran
                $query_setoran = "SELECT COALESCE(SUM(jumlah), 0) as total 
                                  FROM transaksi 
                                  WHERE jenis_simpanan_id = :id 
                                  AND jenis_transaksi = 'setoran'
                                  AND DATE(tanggal_transaksi) BETWEEN :start AND :end";
                $stmt_setoran = $db->prepare($query_setoran);
                $stmt_setoran->bindParam(':id', $jenis['id']);
                $stmt_setoran->bindParam(':start', $start_date);
                $stmt_setoran->bindParam(':end', $end_date);
                $stmt_setoran->execute();
                $total_setoran = $stmt_setoran->fetch(PDO::FETCH_ASSOC)['total'];
                
                // Hitung total penarikan
                $query_penarikan = "SELECT COALESCE(SUM(jumlah), 0) as total 
                                    FROM transaksi 
                                    WHERE jenis_simpanan_id = :id 
                                    AND jenis_transaksi = 'penarikan'
                                    AND DATE(tanggal_transaksi) BETWEEN :start AND :end";
                $stmt_penarikan = $db->prepare($query_penarikan);
                $stmt_penarikan->bindParam(':id', $jenis['id']);
                $stmt_penarikan->bindParam(':start', $start_date);
                $stmt_penarikan->bindParam(':end', $end_date);
                $stmt_penarikan->execute();
                $total_penarikan = $stmt_penarikan->fetch(PDO::FETCH_ASSOC)['total'];
                
                $saldo_akhir = $saldo_map[$jenis['kode_jenis']] ?? 0;
            ?>
            <tr>
                <td class="text-center"><?php echo $no++; ?></td>
                <td class="text-center"><?php echo $jenis['kode_jenis']; ?></td>
                <td><?php echo $jenis['nama_jenis']; ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($total_setoran); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($total_penarikan); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($saldo_akhir); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <br>

    <!-- Detail Transaksi -->
    <div class="section-title">DETAIL TRANSAKSI SIMPANAN</div>
    <?php if (empty($transactions)): ?>
        <p>Tidak ada transaksi pada periode ini</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Kode Transaksi</th>
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
                <td class="text-center"><?php echo date('d/m/Y', strtotime($t['tanggal_transaksi'])); ?></td>
                <td class="text-center"><?php echo $t['kode_transaksi']; ?></td>
                <td class="text-center"><?php echo $t['kode_anggota']; ?></td>
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
    
    <br><br>
    
    <!-- Tanda tangan -->
    <table width="100%">
        <tr>
            <td width="60%"></td>
            <td width="40%" style="text-align: center;">
                <p>Jakarta, <?php echo date('d F Y'); ?></p>
                <p>Teller,</p>
                <br><br><br>
                <p>( <?php echo $_SESSION['nama_lengkap']; ?> )</p>
            </td>
        </tr>
    </table>
</body>
</html>

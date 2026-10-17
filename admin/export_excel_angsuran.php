<?php
// Start session jika belum
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include config dan auth dengan path yang benar
require_once '../config/config.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

// Check if user has access to this page
if (!isAdmin() && !isTeller()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-1 month'));
$end_date = $_GET['end_date'] ?? date('Y-m-d', strtotime('+6 months'));
$filter_status = $_GET['status'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_pinjaman = $_GET['pinjaman_id'] ?? '';

// Get data for filters
$anggota_info = '';
if (!empty($filter_anggota)) {
    $query_anggota = "SELECT nama_lengkap FROM anggota WHERE id = :id";
    $stmt = $db->prepare($query_anggota);
    $stmt->bindParam(':id', $filter_anggota);
    $stmt->execute();
    $anggota = $stmt->fetch(PDO::FETCH_ASSOC);
    $anggota_info = $anggota ? $anggota['nama_lengkap'] : '';
}

$status_info = '';
if (!empty($filter_status)) {
    $status_list = [
        'belum_bayar' => 'Belum Bayar',
        'lunas' => 'Lunas',
        'telat' => 'Telat'
    ];
    $status_info = $status_list[$filter_status] ?? '';
}

$pinjaman_info = '';
if (!empty($filter_pinjaman)) {
    $query_pinjaman = "SELECT kode_pinjaman FROM pinjaman WHERE id = :id";
    $stmt = $db->prepare($query_pinjaman);
    $stmt->bindParam(':id', $filter_pinjaman);
    $stmt->execute();
    $pinjaman = $stmt->fetch(PDO::FETCH_ASSOC);
    $pinjaman_info = $pinjaman ? $pinjaman['kode_pinjaman'] : '';
}

// Get koperasi info
$query_koperasi = "SELECT * FROM koperasi WHERE id = 1";
$stmt_koperasi = $db->prepare($query_koperasi);
$stmt_koperasi->execute();
$koperasi = $stmt_koperasi->fetch(PDO::FETCH_ASSOC);

// Build query untuk ambil data angsuran
$query = "SELECT a.*, p.kode_pinjaman, p.anggota_id, p.tanggal_pencairan,
                 ag.kode_anggota, ag.nama_lengkap,
                 jp.nama_jenis as jenis_pinjaman
          FROM angsuran a 
          JOIN pinjaman p ON a.pinjaman_id = p.id 
          JOIN anggota ag ON p.anggota_id = ag.id 
          JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id 
          WHERE 1=1";

$params = [];

// Add date filter
if (!empty($start_date) && !empty($end_date)) {
    $query .= " AND (a.tanggal_jatuh_tempo BETWEEN :start_date AND :end_date 
                OR a.tanggal_bayar BETWEEN :start_date AND :end_date)";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
}

// Add status filter
if (!empty($filter_status)) {
    $query .= " AND a.status = :status";
    $params[':status'] = $filter_status;
}

// Add anggota filter
if (!empty($filter_anggota)) {
    $query .= " AND p.anggota_id = :anggota_id";
    $params[':anggota_id'] = $filter_anggota;
}

// Add pinjaman filter
if (!empty($filter_pinjaman)) {
    $query .= " AND p.id = :pinjaman_id";
    $params[':pinjaman_id'] = $filter_pinjaman;
}

$query .= " ORDER BY a.tanggal_jatuh_tempo ASC, p.kode_pinjaman ASC, a.angsuran_ke ASC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$angsuran = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung total
$total_angsuran = 0;
$total_pokok = 0;
$total_bunga = 0;
$total_denda = 0;
$total_lunas = 0;
$total_belum_bayar = 0;
$total_telat = 0;

foreach ($angsuran as $a) {
    $total_angsuran += $a['jumlah_angsuran'];
    $total_pokok += $a['pokok'];
    $total_bunga += $a['bunga'];
    $total_denda += $a['denda'];
    
    if ($a['status'] == 'lunas') $total_lunas++;
    if ($a['status'] == 'belum_bayar') $total_belum_bayar++;
    if ($a['status'] == 'telat') $total_telat++;
}

// Fungsi format rupiah untuk Excel
function formatRupiahExcel($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Set header untuk download file Excel
header("Content-type: application/vnd-ms-excel");
header("Content-Disposition: attachment; filename=Laporan_Angsuran_" . date('Ymd') . ".xls");

?>
<html>
<head>
    <title>Laporan Angsuran</title>
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
        .filter-info {
            background-color: #f0f0f0;
            padding: 8px;
            margin-bottom: 15px;
        }
        .pinjaman-header {
            background-color: #e0e0e0;
            font-weight: bold;
            padding: 5px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <!-- Header Laporan -->
    <div class="header">LAPORAN ANGSURAN PINJAMAN</div>
    <div class="subheader">
        <?php echo $koperasi['nama'] ?? 'Koperasi Simpan Pinjam Sejahtera'; ?><br>
        <?php if (!empty($koperasi['alamat'])) echo $koperasi['alamat'] . '<br>'; ?>
        <?php if (!empty($koperasi['telepon'])) echo 'Telp: ' . $koperasi['telepon']; ?>
        <?php if (!empty($koperasi['email'])) echo ' | Email: ' . $koperasi['email']; ?>
    </div>
    
    <!-- Filter Info -->
    <div class="filter-info">
        <strong>Periode:</strong> <?php echo date('d/m/Y', strtotime($start_date)); ?> s/d <?php echo date('d/m/Y', strtotime($end_date)); ?><br>
        <strong>Filter:</strong> 
        <?php 
        $filter_text = [];
        if (!empty($anggota_info)) $filter_text[] = 'Anggota: ' . $anggota_info;
        if (!empty($status_info)) $filter_text[] = 'Status: ' . $status_info;
        if (!empty($pinjaman_info)) $filter_text[] = 'Pinjaman: ' . $pinjaman_info;
        
        if (empty($filter_text)) {
            echo 'Semua Data';
        } else {
            echo implode(' | ', $filter_text);
        }
        ?>
        <br>
        <strong>Tanggal Cetak:</strong> <?php echo date('d/m/Y H:i:s'); ?>
    </div>
    <br>

    <!-- Ringkasan Statistik -->
    <table>
        <tr>
            <th colspan="4">RINGKASAN STATISTIK</th>
        </tr>
        <tr>
            <td><strong>Total Angsuran:</strong> <?php echo count($angsuran); ?></td>
            <td><strong>Lunas:</strong> <?php echo $total_lunas; ?></td>
            <td><strong>Belum Bayar:</strong> <?php echo $total_belum_bayar; ?></td>
            <td><strong>Telat:</strong> <?php echo $total_telat; ?></td>
        </tr>
        <tr>
            <td><strong>Total Nilai:</strong> <?php echo formatRupiahExcel($total_angsuran); ?></td>
            <td><strong>Total Pokok:</strong> <?php echo formatRupiahExcel($total_pokok); ?></td>
            <td><strong>Total Bunga:</strong> <?php echo formatRupiahExcel($total_bunga); ?></td>
            <td><strong>Total Denda:</strong> <?php echo formatRupiahExcel($total_denda); ?></td>
        </tr>
    </table>
    <br>

    <!-- Group by Pinjaman -->
    <?php
    $grouped_angsuran = [];
    foreach ($angsuran as $a) {
        $pinjaman_key = $a['kode_pinjaman'];
        if (!isset($grouped_angsuran[$pinjaman_key])) {
            $grouped_angsuran[$pinjaman_key] = [
                'anggota' => $a['nama_lengkap'],
                'kode_anggota' => $a['kode_anggota'],
                'jenis_pinjaman' => $a['jenis_pinjaman'],
                'angsuran' => []
            ];
        }
        $grouped_angsuran[$pinjaman_key]['angsuran'][] = $a;
    }
    ?>

    <?php foreach ($grouped_angsuran as $kode_pinjaman => $data): ?>
    <!-- Info Pinjaman -->
    <div class="pinjaman-header">
        Pinjaman: <?php echo $kode_pinjaman; ?> | Anggota: <?php echo $data['kode_anggota']; ?> - <?php echo $data['anggota']; ?> | Jenis: <?php echo $data['jenis_pinjaman']; ?>
    </div>
    
    <!-- Tabel Angsuran -->
    <table>
        <thead>
            <tr>
                <th>Angsuran Ke</th>
                <th>Jatuh Tempo</th>
                <th>Pokok</th>
                <th>Bunga</th>
                <th>Denda</th>
                <th>Total</th>
                <th>Tanggal Bayar</th>
                <th>Status</th>
                <th>Keterlambatan</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data['angsuran'] as $a): ?>
            <tr>
                <td class="text-center"><?php echo $a['angsuran_ke']; ?></td>
                <td class="text-center"><?php echo date('d/m/Y', strtotime($a['tanggal_jatuh_tempo'])); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($a['pokok']); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($a['bunga']); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($a['denda']); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($a['jumlah_angsuran']); ?></td>
                <td class="text-center"><?php echo $a['tanggal_bayar'] ? date('d/m/Y', strtotime($a['tanggal_bayar'])) : '-'; ?></td>
                <td class="text-center"><?php echo ucfirst(str_replace('_', ' ', $a['status'])); ?></td>
                <td class="text-center"><?php echo $a['keterlambatan_hari'] > 0 ? $a['keterlambatan_hari'] . ' hari' : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Subtotal per Pinjaman -->
            <?php
            $total_pinjaman_pokok = array_sum(array_column($data['angsuran'], 'pokok'));
            $total_pinjaman_bunga = array_sum(array_column($data['angsuran'], 'bunga'));
            $total_pinjaman_denda = array_sum(array_column($data['angsuran'], 'denda'));
            $total_pinjaman_angsuran = array_sum(array_column($data['angsuran'], 'jumlah_angsuran'));
            ?>
            <tr style="font-weight: bold; background-color: #f0f0f0;">
                <td colspan="2" class="text-end">SUBTOTAL</td>
                <td class="text-end"><?php echo formatRupiahExcel($total_pinjaman_pokok); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($total_pinjaman_bunga); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($total_pinjaman_denda); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($total_pinjaman_angsuran); ?></td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>
    <br>
    <?php endforeach; ?>

    <!-- Grand Total -->
    <table>
        <tr style="font-weight: bold; background-color: #d0d0d0;">
            <td colspan="2">GRAND TOTAL</td>
            <td class="text-end"><?php echo formatRupiahExcel($total_pokok); ?></td>
            <td class="text-end"><?php echo formatRupiahExcel($total_bunga); ?></td>
            <td class="text-end"><?php echo formatRupiahExcel($total_denda); ?></td>
            <td class="text-end"><?php echo formatRupiahExcel($total_angsuran); ?></td>
            <td colspan="3"></td>
        </tr>
    </table>
    
    <br><br>
    
    <!-- Tanda tangan -->
    <table width="100%">
        <tr>
            <td width="60%"></td>
            <td width="40%" style="text-align: center;">
                <p>Jakarta, <?php echo date('d F Y'); ?></p>
                <p>Administrator,</p>
                <br><br><br>
                <p>( <?php echo $_SESSION['nama_lengkap']; ?> )</p>
            </td>
        </tr>
    </table>
</body>
</html>
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
if (!isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Filter parameters
$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_jenis = $_GET['jenis_pinjaman_id'] ?? '';
$filter_status = $_GET['status'] ?? '';

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

$jenis_info = '';
if (!empty($filter_jenis)) {
    $query_jenis = "SELECT nama_jenis FROM jenis_pinjaman WHERE id = :id";
    $stmt = $db->prepare($query_jenis);
    $stmt->bindParam(':id', $filter_jenis);
    $stmt->execute();
    $jenis = $stmt->fetch(PDO::FETCH_ASSOC);
    $jenis_info = $jenis ? $jenis['nama_jenis'] : '';
}

$status_info = '';
if (!empty($filter_status)) {
    $status_list = [
        'aktif' => 'Aktif',
        'lunas' => 'Lunas',
        'macet' => 'Macet'
    ];
    $status_info = $status_list[$filter_status] ?? '';
}

// Get koperasi info
$query_koperasi = "SELECT * FROM koperasi WHERE id = 1";
$stmt_koperasi = $db->prepare($query_koperasi);
$stmt_koperasi->execute();
$koperasi = $stmt_koperasi->fetch(PDO::FETCH_ASSOC);

// Build query untuk ambil data pinjaman
$query = "SELECT 
            p.*, 
            a.kode_anggota, 
            a.nama_lengkap, 
            j.nama_jenis,
            (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as angsuran_terbayar,
            (SELECT COALESCE(SUM(pokok), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_pokok_dibayar,
            (SELECT COALESCE(SUM(bunga), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_bunga_dibayar,
            (SELECT COALESCE(SUM(denda), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_denda_dibayar
          FROM pinjaman p 
          JOIN anggota a ON p.anggota_id = a.id 
          JOIN jenis_pinjaman j ON p.jenis_pinjaman_id = j.id 
          WHERE 1=1";

$params = [];

if (!empty($filter_anggota)) {
    $query .= " AND p.anggota_id = :anggota_id";
    $params[':anggota_id'] = $filter_anggota;
}

if (!empty($filter_jenis)) {
    $query .= " AND p.jenis_pinjaman_id = :jenis_pinjaman_id";
    $params[':jenis_pinjaman_id'] = $filter_jenis;
}

if (!empty($filter_status)) {
    $query .= " AND p.status = :status";
    $params[':status'] = $filter_status;
}

$query .= " ORDER BY p.tanggal_pencairan DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$pinjaman = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung total untuk footer
$total_pinjaman = 0;
$total_sisa = 0;
$total_dibayar = 0;
$total_bunga_dibayar = 0;
$total_denda_dibayar = 0;

foreach ($pinjaman as $p) {
    $total_pinjaman += $p['jumlah_pinjaman'];
    $total_sisa += $p['sisa_pokok'];
    $total_dibayar += $p['total_pokok_dibayar'];
    $total_bunga_dibayar += $p['total_bunga_dibayar'];
    $total_denda_dibayar += $p['total_denda_dibayar'];
}

// Fungsi format rupiah untuk Excel
function formatRupiahExcel($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Set header untuk download file Excel
header("Content-type: application/vnd-ms-excel");
header("Content-Disposition: attachment; filename=Laporan_Pinjaman_" . date('Ymd') . ".xls");

?>
<html>
<head>
    <title>Laporan Pinjaman</title>
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
    </style>
</head>
<body>
    <!-- Header Laporan -->
    <div class="header">LAPORAN PINJAMAN ANGGOTA</div>
    <div class="subheader">
        <?php echo $koperasi['nama'] ?? 'Koperasi Simpan Pinjam Sejahtera'; ?><br>
        <?php if (!empty($koperasi['alamat'])) echo $koperasi['alamat'] . '<br>'; ?>
        <?php if (!empty($koperasi['telepon'])) echo 'Telp: ' . $koperasi['telepon']; ?>
        <?php if (!empty($koperasi['email'])) echo ' | Email: ' . $koperasi['email']; ?>
    </div>
    
    <!-- Filter Info -->
    <div class="filter-info">
        <strong>Filter:</strong> 
        <?php 
        $filter_text = [];
        if (!empty($anggota_info)) $filter_text[] = 'Anggota: ' . $anggota_info;
        if (!empty($jenis_info)) $filter_text[] = 'Jenis Pinjaman: ' . $jenis_info;
        if (!empty($status_info)) $filter_text[] = 'Status: ' . $status_info;
        
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

    <!-- Tabel Pinjaman -->
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Kode Pinjaman</th>
                <th>Kode Anggota</th>
                <th>Nama Anggota</th>
                <th>Jenis Pinjaman</th>
                <th>Jumlah Pinjaman</th>
                <th>Sisa Pokok</th>
                <th>Pokok Dibayar</th>
                <th>Bunga Dibayar</th>
                <th>Denda Dibayar</th>
                <th>Tenor</th>
                <th>Bunga (%)</th>
                <th>Tanggal Cair</th>
                <th>Status</th>
                <th>Progress</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($pinjaman as $p): 
                $progress = $p['jumlah_pinjaman'] > 0 ? ($p['total_pokok_dibayar'] / $p['jumlah_pinjaman']) * 100 : 0;
                $progress = min(100, max(0, $progress));
            ?>
            <tr>
                <td class="text-center"><?php echo $no++; ?></td>
                <td><?php echo $p['kode_pinjaman']; ?></td>
                <td><?php echo $p['kode_anggota']; ?></td>
                <td><?php echo $p['nama_lengkap']; ?></td>
                <td><?php echo $p['nama_jenis']; ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($p['jumlah_pinjaman']); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($p['sisa_pokok']); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($p['total_pokok_dibayar']); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($p['total_bunga_dibayar']); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($p['total_denda_dibayar']); ?></td>
                <td class="text-center"><?php echo labelTenorRow($p); ?></td>
                <td class="text-center"><?php echo labelBungaRow($p); ?></td>
                <td class="text-center"><?php echo date('d/m/Y', strtotime($p['tanggal_pencairan'])); ?></td>
                <td class="text-center"><?php echo ucfirst($p['status']); ?></td>
                <td class="text-center"><?php echo round($progress, 1); ?>% (<?php echo $p['angsuran_terbayar']; ?>/<?php echo $p['tenor']; ?>)</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight: bold; background-color: #f0f0f0;">
                <td colspan="5" class="text-end">TOTAL</td>
                <td class="text-end"><?php echo formatRupiahExcel($total_pinjaman); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($total_sisa); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($total_dibayar); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($total_bunga_dibayar); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($total_denda_dibayar); ?></td>
                <td colspan="5"></td>
            </tr>
        </tfoot>
    </table>
    
    <br><br>
    
    <!-- Ringkasan -->
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
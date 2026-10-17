<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Filter parameters
$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_jenis = $_GET['jenis_simpanan_id'] ?? '';
$filter_tanggal_awal = $_GET['tanggal_awal'] ?? '2026-02-01';
$filter_tanggal_akhir = $_GET['tanggal_akhir'] ?? '2026-02-28';

// Get data for filters (untuk informasi di header)
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
    $query_jenis = "SELECT nama_jenis FROM jenis_simpanan WHERE id = :id";
    $stmt = $db->prepare($query_jenis);
    $stmt->bindParam(':id', $filter_jenis);
    $stmt->execute();
    $jenis = $stmt->fetch(PDO::FETCH_ASSOC);
    $jenis_info = $jenis ? $jenis['nama_jenis'] : '';
}

// Get koperasi info
$query_koperasi = "SELECT * FROM koperasi WHERE id = 1";
$stmt_koperasi = $db->prepare($query_koperasi);
$stmt_koperasi->execute();
$koperasi = $stmt_koperasi->fetch(PDO::FETCH_ASSOC);

// Jika tidak ada data koperasi, gunakan default
if (!$koperasi) {
    $koperasi = [
        'nama' => 'Koperasi Simpan Pinjam Sejahtera',
        'alamat' => 'Jl. Koperasi No. 123, Jakarta',
        'telepon' => '(021) 123-4567',
        'email' => 'info@koperasi.com'
    ];
}

// Ambil data simpanan - HANYA JENIS SIMPANAN REAL (EXCLUDE ANG)
$query = "SELECT 
            a.id as anggota_id,
            a.kode_anggota, 
            a.nama_lengkap,
            j.id as jenis_id,
            j.kode_jenis,
            j.nama_jenis,
            j.minimum,
            j.maksimum,
            j.bagi_hasil,
            COALESCE((
                SELECT SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END)
                FROM transaksi t
                WHERE t.anggota_id = a.id 
                AND t.jenis_simpanan_id = j.id
                AND DATE(t.tanggal_transaksi) BETWEEN :tanggal_awal AND :tanggal_akhir
            ), 0) as saldo
          FROM anggota a
          CROSS JOIN jenis_simpanan j
          WHERE a.status = 'aktif'
          AND j.status = 'aktif'
          AND j.kode_jenis != 'ANG'
          HAVING saldo != 0";

$params = [
    ':tanggal_awal' => $filter_tanggal_awal,
    ':tanggal_akhir' => $filter_tanggal_akhir
];

if (!empty($filter_anggota)) {
    $query = str_replace("WHERE a.status = 'aktif'", "WHERE a.status = 'aktif' AND a.id = :anggota_id", $query);
    $params[':anggota_id'] = $filter_anggota;
}

if (!empty($filter_jenis)) {
    $query = str_replace("HAVING saldo != 0", "AND j.id = :jenis_id HAVING saldo != 0", $query);
    $params[':jenis_id'] = $filter_jenis;
}

$query .= " ORDER BY a.nama_lengkap, j.id";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$simpanan = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung summary per jenis - HANYA JENIS SIMPANAN REAL
$summary = [];
foreach ($simpanan as $s) {
    $jenis = $s['kode_jenis'];
    if (!isset($summary[$jenis])) {
        $summary[$jenis] = [
            'nama' => $s['nama_jenis'],
            'total' => 0,
            'anggota' => []
        ];
    }
    $summary[$jenis]['total'] += $s['saldo'];
    $summary[$jenis]['anggota'][] = $s['anggota_id'];
}

foreach ($summary as &$s) {
    $s['jumlah_anggota'] = count(array_unique($s['anggota']));
    unset($s['anggota']);
}

// Hitung total keseluruhan
$total_semua = array_sum(array_column($simpanan, 'saldo'));

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
        body {
            font-family: Arial, sans-serif;
        }
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
            text-align: center;
        }
        .subheader {
            font-size: 14px;
            margin-bottom: 15px;
            text-align: center;
        }
        .kop {
            font-size: 20px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 5px;
        }
        .alamat {
            font-size: 12px;
            text-align: center;
            margin-bottom: 2px;
        }
        .garis {
            border-top: 2px solid #000;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <!-- Kop Surat -->
    <div class="kop"><?php echo $koperasi['nama']; ?></div>
    <div class="alamat"><?php echo $koperasi['alamat']; ?></div>
    <div class="alamat">Telp: <?php echo $koperasi['telepon']; ?> | Email: <?php echo $koperasi['email']; ?></div>
    <div class="garis"></div>
    
    <!-- Header Laporan -->
    <div class="header">LAPORAN SIMPANAN ANGGOTA</div>
    <div class="subheader">
        <strong>Periode:</strong> <?php echo date('d/m/Y', strtotime($filter_tanggal_awal)); ?> s/d <?php echo date('d/m/Y', strtotime($filter_tanggal_akhir)); ?><br>
        <?php if (!empty($anggota_info)): ?>
        <strong>Anggota:</strong> <?php echo $anggota_info; ?><br>
        <?php endif; ?>
        <?php if (!empty($jenis_info)): ?>
        <strong>Jenis Simpanan:</strong> <?php echo $jenis_info; ?><br>
        <?php endif; ?>
        <strong>Tanggal Cetak:</strong> <?php echo date('d/m/Y H:i:s'); ?>
    </div>
    <br>

    <!-- Ringkasan per Jenis -->
    <div style="font-weight: bold; margin-bottom: 10px;">A. RINGKASAN PER JENIS SIMPANAN (NON-ANGSURAN)</div>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Jenis Simpanan</th>
                <th>Jumlah Anggota</th>
                <th>Total Saldo</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($summary as $s): 
            ?>
            <tr>
                <td class="text-center"><?php echo $no++; ?></td>
                <td><?php echo $s['nama']; ?></td>
                <td class="text-center"><?php echo $s['jumlah_anggota']; ?> orang</td>
                <td class="text-end"><?php echo formatRupiahExcel($s['total']); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="font-weight: bold; background-color: #f0f0f0;">
                <td colspan="3" class="text-end">TOTAL</td>
                <td class="text-end"><?php echo formatRupiahExcel($total_semua); ?></td>
            </tr>
        </tbody>
    </table>
    <br><br>

    <!-- Detail Simpanan -->
    <div style="font-weight: bold; margin-bottom: 10px;">B. DETAIL SIMPANAN ANGGOTA (NON-ANGSURAN)</div>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Kode</th>
                <th>Nama Anggota</th>
                <th>Jenis</th>
                <th>Saldo</th>
                <th>Minimal</th>
                <th>Maksimal</th>
                <th>Bagi Hasil</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            $total_saldo = 0;
            foreach ($simpanan as $s): 
                $total_saldo += $s['saldo'];
            ?>
            <tr>
                <td class="text-center"><?php echo $no++; ?></td>
                <td><?php echo $s['kode_anggota']; ?></td>
                <td><?php echo $s['nama_lengkap']; ?></td>
                <td><?php echo $s['kode_jenis']; ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($s['saldo']); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($s['minimum']); ?></td>
                <td class="text-end"><?php echo formatRupiahExcel($s['maksimum']); ?></td>
                <td class="text-center"><?php echo $s['bagi_hasil']; ?>%</td>
            </tr>
            <?php endforeach; ?>
            <tr style="font-weight: bold; background-color: #f0f0f0;">
                <td colspan="4" class="text-end">TOTAL</td>
                <td class="text-end"><?php echo formatRupiahExcel($total_saldo); ?></td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>
    <br><br>
    
    <!-- Footer -->
    <div style="margin-top: 20px;">
        <table width="100%">
            <tr>
                <td width="60%"></td>
                <td width="40%" style="text-align: center;">
                    <p>Jakarta, <?php echo date('d F Y'); ?></p>
                    <p>Administrator,</p>
                    <br><br><br>
                    <p>( ____________________ )</p>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
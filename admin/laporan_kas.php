<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

// Filter parameters - DEFAULT KOSONG (TANPA FILTER)
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$filter_jenis = $_GET['jenis'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';

// Validasi tanggal jika diisi
if (!empty($start_date) && !empty($end_date) && strtotime($start_date) > strtotime($end_date)) {
    $_SESSION['error'] = "Tanggal awal tidak boleh lebih besar dari tanggal akhir";
    $start_date = '';
    $end_date = '';
}

// Get data anggota untuk filter
$query_anggota = "SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// AMBIL DATA DARI TABEL KAS (TRANSAKSI SIMPANAN & ANGSURAN)
// ============================================
$query_kas = "SELECT 
    'kas' as sumber,
    k.id as sumber_id,
    k.tanggal,
    k.jenis,
    k.jumlah,
    k.saldo_sebelum,
    k.saldo_sesudah,
    k.keterangan as keterangan_asli,
    t.id as transaksi_id,
    t.kode_transaksi,
    t.jenis_transaksi,
    t.anggota_id,
    a.nama_lengkap as nama_anggota,
    a.kode_anggota,
    js.nama_jenis as nama_simpanan,
    js.kode_jenis,
    u.nama_lengkap as nama_user,
    NULL as pinjaman_id,
    NULL as kode_pinjaman,
    NULL as jenis_pinjaman,
    NULL as pengajuan_id,
    CASE 
        WHEN k.keterangan LIKE '%Waserda%' THEN CONCAT('Waserda - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(k.keterangan, ''), ')')
        WHEN t.keterangan LIKE '%angsuran%' THEN CONCAT('Pembayaran Angsuran - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(t.keterangan, ''), ')')
        WHEN t.jenis_transaksi = 'setoran' THEN CONCAT('Setoran - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(js.nama_jenis, 'Simpanan'), ')')
        WHEN t.jenis_transaksi = 'penarikan' THEN CONCAT('Penarikan - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(js.nama_jenis, 'Simpanan'), ')')
        ELSE COALESCE(k.keterangan, 'Transaksi Kas')
    END as keterangan_lengkap,
    CASE 
        WHEN k.keterangan LIKE '%Waserda%' THEN 'waserda'  -- WASERDA DULUAN
        WHEN t.keterangan LIKE '%angsuran%' THEN 'angsuran'
        WHEN t.jenis_transaksi = 'setoran' THEN 'setoran'
        WHEN t.jenis_transaksi = 'penarikan' THEN 'penarikan'
        ELSE 'lainnya'
    END as tipe_transaksi
FROM kas k 
LEFT JOIN transaksi t ON k.transaksi_id = t.id 
LEFT JOIN anggota a ON t.anggota_id = a.id
LEFT JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id
LEFT JOIN users u ON t.user_id = u.id
WHERE 1=1";

// ============================================
// AMBIL DATA DARI TABEL PINJAMAN (PENCAIRAN)
// ============================================
$query_pinjaman = "SELECT 
    'pinjaman' as sumber,
    p.id as sumber_id,
    CONCAT(p.tanggal_pencairan, ' ', '23:59:59') as tanggal,
    'keluar' as jenis,
    p.jumlah_pinjaman as jumlah,
    0 as saldo_sebelum,
    0 as saldo_sesudah,
    CONCAT('Pencairan Pinjaman - ', a.nama_lengkap) as keterangan_asli,
    NULL as transaksi_id,
    p.kode_pinjaman as kode_transaksi,
    NULL as jenis_transaksi,
    p.anggota_id,
    a.nama_lengkap as nama_anggota,
    a.kode_anggota,
    NULL as nama_simpanan,
    NULL as kode_jenis,
    u.nama_lengkap as nama_user,
    p.id as pinjaman_id,
    p.kode_pinjaman,
    jp.nama_jenis as jenis_pinjaman,
    p.pengajuan_id,
    CONCAT('Pencairan Pinjaman - ', a.nama_lengkap, ' (', jp.nama_jenis, ') - ', p.kode_pinjaman) as keterangan_lengkap,
    'pencairan_pinjaman' as tipe_transaksi
FROM pinjaman p
JOIN anggota a ON p.anggota_id = a.id
JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
LEFT JOIN users u ON p.id = u.id
WHERE 1=1";

// ============================================
// GABUNGKAN KEDUA QUERY
// ============================================
$combined_query = "($query_kas) UNION ALL ($query_pinjaman)";
$params = [];

// Filter berdasarkan tanggal
if (!empty($start_date) && !empty($end_date)) {
    $combined_query = "SELECT * FROM ($combined_query) as semua WHERE DATE(tanggal) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
}

// Filter berdasarkan jenis
if (!empty($filter_jenis)) {
    if (!empty($start_date) && !empty($end_date)) {
        $combined_query .= " AND tipe_transaksi = :jenis";
    } else {
        $combined_query = "SELECT * FROM ($combined_query) as semua WHERE tipe_transaksi = :jenis";
    }
    $params[':jenis'] = $filter_jenis;
}

// Filter berdasarkan anggota
if (!empty($filter_anggota)) {
    if (!empty($start_date) && !empty($end_date) || !empty($filter_jenis)) {
        $combined_query .= " AND anggota_id = :anggota_id";
    } else {
        $combined_query = "SELECT * FROM ($combined_query) as semua WHERE anggota_id = :anggota_id";
    }
    $params[':anggota_id'] = $filter_anggota;
}

// Urutkan dari terlama ke terbaru
if (strpos($combined_query, 'ORDER BY') === false) {
    $combined_query .= " ORDER BY tanggal ASC, sumber_id ASC";
}

$stmt = $db->prepare($combined_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$kas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$__ui = ui_query_params([]);
$__q = $__ui['q'];
$__all = $kas;
if ($__q !== '') {
    $__fields = array (
  0 => 'keterangan',
  1 => 'jenis',
);
    $__all = array_values(array_filter($__all, static function ($r) use ($__q, $__fields) {
        $hay = '';
        foreach ($__fields as $f) { $hay .= ' ' . ($r[$f] ?? ''); }
        return stripos($hay, $__q) !== false;
    }));
}
$pager = ui_paginate(count($__all), $__ui['page'], $__ui['per_page']);
$kas = array_slice($__all, $pager['offset'], $pager['per_page']);
$q = $__q;


// ============================================
// HITUNG SALDO KAS YANG SEBENARNYA
// ============================================
// Ambil saldo awal dari tabel kas (sebelum semua transaksi)
$query_saldo_awal = "SELECT saldo_sebelum FROM kas ORDER BY tanggal ASC, id ASC LIMIT 1";
$stmt_saldo_awal = $db->prepare($query_saldo_awal);
$stmt_saldo_awal->execute();
$saldo_awal_data = $stmt_saldo_awal->fetch(PDO::FETCH_ASSOC);
$saldo_awal = $saldo_awal_data ? $saldo_awal_data['saldo_sebelum'] : 0;

// Hitung total per jenis (TERMASUK WASERDA)
$total_masuk = 0;
$total_keluar = 0;
$total_setoran = 0;
$total_penarikan = 0;
$total_angsuran = 0;
$total_pencairan = 0;
$total_waserda = 0;

foreach ($kas as $k) {
    if ($k['jenis'] == 'masuk') {
        $total_masuk += $k['jumlah'];
        if ($k['tipe_transaksi'] == 'angsuran') {
            $total_angsuran += $k['jumlah'];
        } elseif ($k['tipe_transaksi'] == 'setoran') {
            $total_setoran += $k['jumlah'];
        } elseif ($k['tipe_transaksi'] == 'waserda') {
            $total_waserda += $k['jumlah'];
        }
    } else {
        $total_keluar += $k['jumlah'];
        if ($k['tipe_transaksi'] == 'penarikan') {
            $total_penarikan += $k['jumlah'];
        } elseif ($k['tipe_transaksi'] == 'pencairan_pinjaman') {
            $total_pencairan += $k['jumlah'];
        }
    }
}

$saldo_akhir = $saldo_awal + $total_masuk - $total_keluar;

// Data untuk chart
$chart_data = [];
$chart_labels = [];
$saldo_chart = $saldo_awal;

foreach ($kas as $k) {
    $chart_labels[] = formatTanggalUi($k['tanggal'], true);
    if ($k['jenis'] == 'masuk') {
        $saldo_chart += $k['jumlah'];
    } else {
        $saldo_chart -= $k['jumlah'];
    }
    $chart_data[] = $saldo_chart;
}

// Jenis filter options (TAMBAHKAN WASERDA)
$jenis_filter = [
    '' => 'Semua Transaksi',
    'setoran' => 'Setoran Simpanan',
    'penarikan' => 'Penarikan Simpanan',
    'angsuran' => 'Pembayaran Angsuran',
    'pencairan_pinjaman' => 'Pencairan Pinjaman',
    'waserda' => 'Waserda'
];

// Hitung range tanggal otomatis
$min_date = '';
$max_date = '';
if (!empty($kas)) {
    $min_date = date('Y-m-d', strtotime($kas[0]['tanggal']));
    $max_date = date('Y-m-d', strtotime($kas[count($kas)-1]['tanggal']));
}

// Hitung jumlah transaksi per sumber untuk info
$jumlah_kas = 0;
$jumlah_pinjaman = 0;
$jumlah_waserda = 0;
foreach ($kas as $k) {
    if ($k['sumber'] == 'kas') $jumlah_kas++;
    elseif ($k['sumber'] == 'pinjaman') $jumlah_pinjaman++;
    if ($k['tipe_transaksi'] == 'waserda') $jumlah_waserda++;
}

// DEBUG - tampilkan angka di HTML comment
echo "<!-- DEBUG: total_setoran=$total_setoran, total_waserda=$total_waserda -->";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Laporan Kas - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary:#087f83;
            --secondary:#12666e;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #17a2b8;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            overflow-x: hidden;
        }
        
        .container-fluid {
            padding-left: 0;
            padding-right: 0;
        }
        
        @media (max-width: 767.98px) {
            main {
                margin-left: 0 !important;
                padding: 10px 12px !important;
                width: 100%;
                margin-top: 60px !important;
            }
            
            .row {
                margin-left: -5px;
                margin-right: -5px;
            }
            
            .col-6, .col-12 {
                padding-left: 5px;
                padding-right: 5px;
            }
            
            h1.h2 {
                font-size: 1.5rem;
            }
            
            .btn-group {
                display: flex;
                gap: 5px;
            }
            
            .btn-group .btn {
                padding: 0.3rem 0.6rem;
                font-size: 0.8rem;
            }
            
            .summary-card .card-body {
                padding: 12px 10px;
            }
            
            .summary-card h4 {
                font-size: 1.1rem;
            }
            
            .summary-card h6 {
                font-size: 0.8rem;
            }
            
            .table-responsive {
                margin-bottom: 0;
            }
            
            .table td, .table th {
                padding: 0.5rem 0.3rem;
                font-size: 0.7rem;
                white-space: nowrap;
            }
            
            .badge {
                padding: 0.2rem 0.4rem;
                font-size: 0.65rem;
            }
            
            .mobile-hide {
                display: none;
            }
            
            @media (max-width: 575.98px) {
                .table th:nth-child(4),
                .table td:nth-child(4) {
                    display: none;
                }
            }
        }
        
        @media (min-width: 992px) {
            main {
                margin-left: 0 !important; /* densify: shell owns sidebar */
                padding: 20px !important;
            }
        }
        
        .page-header {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        @media (min-width: 576px) {
            .page-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }
        
        .summary-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
        }
        
        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .summary-card.bg-success { 
            background: linear-gradient(135deg, #27ae60, #2ecc71) !important;
        }
        
        .summary-card.bg-danger { 
            background: linear-gradient(135deg, #e74c3c, #c0392b) !important;
        }
        
        .summary-card.bg-primary { 
            background: linear-gradient(135deg,#123d47,#087f83) !important;
        }
        
        .summary-card.bg-info { 
            background: linear-gradient(135deg, #3498db, #2980b9) !important;
        }
        
        .summary-card.bg-warning { 
            background: linear-gradient(135deg, #f39c12, #e67e22) !important;
        }
        
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.03);
            margin-bottom: 20px;
        }
        
        @media (max-width: 767.98px) {
            .filter-card {
                padding: 1rem;
            }
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: white;
            border-bottom: 1px solid #f0f0f0;
            padding: 1rem 1.25rem;
        }
        
        .card-body {
            padding: 1.25rem;
        }
        
        @media (max-width: 767.98px) {
            .card-body {
                padding: 0.75rem;
            }
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: #7f8c8d;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 1px solid #dee2e6;
            background-color: #f8f9fa;
        }
        
        .table td {
            font-size: 0.8rem;
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .table tr:last-child td {
            border-bottom: none;
        }
        
        .btn {
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }
        
        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg,#123d47,#087f83);
            border: none;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #34495e, #2980b9);
        }
        
        .badge {
            padding: 0.4rem 0.6rem;
            font-weight: 500;
            font-size: 0.7rem;
            border-radius: 10px;
        }
        
        .badge.bg-success { background: linear-gradient(135deg, #27ae60, #2ecc71) !important; }
        .badge.bg-danger { background: linear-gradient(135deg, #e74c3c, #c0392b) !important; }
        .badge.bg-warning { background: linear-gradient(135deg, #f39c12, #e67e22) !important; }
        .badge.bg-info { background: linear-gradient(135deg, #3498db, #2980b9) !important; }
        
        .form-label {
            font-size: 0.85rem;
            font-weight: 500;
            color: #495057;
            margin-bottom: 0.3rem;
        }
        
        .form-control, .form-select {
            font-size: 0.9rem;
            padding: 0.6rem 0.75rem;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        @media (max-width: 767.98px) {
            .form-control, .form-select {
                font-size: 0.85rem;
                padding: 0.5rem 0.65rem;
            }
        }
        
        .empty-state {
            text-align: center;
            padding: 22px 20px;
            color: #95a5a6;
        }
        
        .empty-state i {
            font-size: 1.6rem;
            margin-bottom: 1rem;
            color: #bdc3c7;
        }
        
        @media (max-width: 767.98px) {
            .empty-state {
                padding: 30px 15px;
            }
            
            .empty-state i {
                font-size: 1.4rem;
            }
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        @media (max-width: 767.98px) {
            .chart-container {
                height: 200px;
            }
        }
        
        .info-card {
            background-color: #e8f4fd;
            border-left:3px solid #087f83;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .stats-row {
            margin-bottom: 20px;
        }
        
        .range-info {
            background-color: #f0f7ff;
            border-radius: 8px;
            padding: 8px 15px;
            font-size: 0.9rem;
        }
        
        .pencairan-row {
            background-color: #fff3cd;
        }
        
        .waserda-row {
            background-color: #d1ecf1;
        }
        
        .sumber-badge {
            font-size: 0.65rem;
            padding: 0.2rem 0.4rem;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <?php ui_page_header([
                    'title' => 'Laporan Kas + Waserda',
                    'icon' => 'fas fa-wallet text-primary',
                    'subtitle' => 'Arus kas, pencairan pinjaman, dan waserda',
                    'actions' => '<div class="btn-group">'
                        . '<button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print me-1"></i><span class="d-none d-sm-inline">Cetak</span></button>'
                        . '<a href="export_kas.php?start_date=' . urlencode((string)$start_date) . '&end_date=' . urlencode((string)$end_date) . '&jenis=' . urlencode((string)$filter_jenis) . '&anggota_id=' . urlencode((string)$filter_anggota) . '" class="btn btn-sm btn-success"><i class="fas fa-file-excel me-1"></i><span class="d-none d-sm-inline">Excel</span></a>'
                        . '<a href="cetak_laporan_kas.php?start_date=' . urlencode((string)$start_date) . '&end_date=' . urlencode((string)$end_date) . '&jenis=' . urlencode((string)$filter_jenis) . '&anggota_id=' . urlencode((string)$filter_anggota) . '" class="btn btn-sm btn-danger" target="_blank"><i class="fas fa-file-pdf me-1"></i><span class="d-none d-sm-inline">PDF</span></a>'
                        . '</div>',
                ]); ?>
                <?php ui_flash(); ?>

                <!-- Info Data Kas -->
                <div class="info-card">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-database fa-2x me-3 text-primary"></i>
                        <div>
                            <h6 class="mb-1">Data Kas + Pencairan Pinjaman + Waserda</h6>
                            <p class="mb-0 small">
                                Total transaksi: <strong><?php echo count($kas); ?></strong> 
                                (Kas: <?php echo $jumlah_kas; ?>, Pinjaman: <?php echo $jumlah_pinjaman; ?>, Waserda: <?php echo $jumlah_waserda; ?>)
                                <?php if (!empty($min_date) && !empty($max_date)): ?>
                                | Rentang: <strong><?php echo formatTanggalUi($min_date); ?></strong> s/d <strong><?php echo formatTanggalUi($max_date); ?></strong>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">
                                <i class="fas fa-calendar-alt me-1"></i>Dari Tanggal
                            </label>
                            <input type="date" name="start_date" class="form-control" 
                                   value="<?php echo $start_date; ?>" placeholder="Kosongkan untuk semua">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">
                                <i class="fas fa-calendar-alt me-1"></i>Sampai Tanggal
                            </label>
                            <input type="date" name="end_date" class="form-control" 
                                   value="<?php echo $end_date; ?>" placeholder="Kosongkan untuk semua">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">
                                <i class="fas fa-filter me-1"></i>Jenis Transaksi
                            </label>
                            <select name="jenis" class="form-select">
                                <?php foreach ($jenis_filter as $key => $value): ?>
                                <option value="<?php echo $key; ?>" <?php echo $filter_jenis == $key ? 'selected' : ''; ?>>
                                    <?php echo $value; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">
                                <i class="fas fa-user me-1"></i>Anggota
                            </label>
                            <select name="anggota_id" class="form-select">
                                <option value="">Semua Anggota</option>
                                <?php foreach ($anggota_list as $a): ?>
                                <option value="<?php echo $a['id']; ?>" <?php echo $filter_anggota == $a['id'] ? 'selected' : ''; ?>>
                                    <?php echo $a['kode_anggota'] . ' - ' . $a['nama_lengkap']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-1"></i> Filter
                            </button>
                            <a href="laporan_kas.php" class="btn btn-secondary ms-2">
                                <i class="fas fa-undo me-1"></i> Tampilkan Semua
                            </a>
                        </div>
                    </form>
                    
                    <!-- Info Filter -->
                    <div class="mt-3 range-info">
                        <i class="fas fa-info-circle me-1 text-primary"></i>
                        <?php if (empty($start_date) && empty($end_date)): ?>
                            <strong>Menampilkan semua transaksi</strong> (kas + pencairan pinjaman + waserda)
                        <?php else: ?>
                            <strong>Periode filter:</strong> <?php echo formatTanggalUi($start_date); ?> - <?php echo formatTanggalUi($end_date); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                $count_masuk = count(array_filter($kas, static function ($k) { return ($k['jenis'] ?? '') === 'masuk'; }));
                $count_keluar = count(array_filter($kas, static function ($k) { return ($k['jenis'] ?? '') === 'keluar'; }));
                ui_stat_grid([
                    ['label' => 'Pemasukan', 'value' => formatRupiah($total_masuk), 'tone' => 'success', 'icon' => 'fas fa-arrow-down', 'hint' => $count_masuk . ' transaksi'],
                    ['label' => 'Pengeluaran', 'value' => formatRupiah($total_keluar), 'tone' => 'danger', 'icon' => 'fas fa-arrow-up', 'hint' => $count_keluar . ' transaksi'],
                    ['label' => 'Saldo akhir', 'value' => formatRupiah($saldo_akhir), 'tone' => 'primary', 'icon' => 'fas fa-coins', 'hint' => 'Saldo awal: ' . formatRupiah($saldo_awal)],
                ]);
                ?>

                <!-- Detail per Jenis (SEKARANG 5 KOLOM) -->
                <div class="row stats-row">
                    <div class="col-md-2 mb-2 mb-md-0">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">
                                    <i class="fas fa-plus-circle text-success me-1"></i>Setoran
                                </h6>
                                <h5 class="mb-0 text-success"><?php echo formatRupiah($total_setoran); ?></h5>
                                <small class="text-muted">Pemasukan simpanan</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-2 mb-2 mb-md-0">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">
                                    <i class="fas fa-minus-circle text-danger me-1"></i>Penarikan
                                </h6>
                                <h5 class="mb-0 text-danger"><?php echo formatRupiah($total_penarikan); ?></h5>
                                <small class="text-muted">Pengeluaran simpanan</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-2 mb-2 mb-md-0">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">
                                    <i class="fas fa-credit-card text-info me-1"></i>Angsuran
                                </h6>
                                <h5 class="mb-0 text-info"><?php echo formatRupiah($total_angsuran); ?></h5>
                                <small class="text-muted">Pemasukan angsuran</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-2 mb-2 mb-md-0">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">
                                    <i class="fas fa-store text-primary me-1"></i>Waserda
                                </h6>
                                <h5 class="mb-0 text-primary"><?php echo formatRupiah($total_waserda); ?></h5>
                                <small class="text-muted">Pemasukan waserda</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-2 mb-2 mb-md-0">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">
                                    <i class="fas fa-hand-holding-usd text-warning me-1"></i>Pencairan
                                </h6>
                                <h5 class="mb-0 text-warning"><?php echo formatRupiah($total_pencairan); ?></h5>
                                <small class="text-muted">Pengeluaran pinjaman</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">Total</h6>
                                <h5><?php echo formatRupiah($total_masuk + $total_keluar); ?></h5>
                                <small><?php echo count($kas); ?> transaksi</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chart Section -->
                <?php if (!empty($chart_data) && count($chart_data) > 1): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-line me-2"></i>Grafik Perkembangan Saldo
                            <small class="text-muted">(termasuk pencairan pinjaman dan waserda)</small>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="saldoChart"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Laporan Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-table me-2"></i>Detail Transaksi Kas + Pencairan Pinjaman + Waserda
                            <small class="text-muted">(urutan kronologis)</small>
                        </h5>
                        <div>
                            <span class="badge bg-info me-2"><?php echo count($kas); ?> Transaksi</span>
                            <?php if (!empty($filter_jenis)): ?>
                            <span class="badge bg-secondary">Filter: <?php echo $jenis_filter[$filter_jenis] ?? $filter_jenis; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-0 p-md-3">
                        <?php if (empty($kas)): ?>
                            <div class="empty-state">
                                <i class="fas fa-wallet mb-3"></i>
                                <p class="mb-3">Tidak ada transaksi</p>
                                <a href="laporan_kas.php" class="btn btn-primary">Tampilkan Semua</a>
                            </div>
                        <?php else: ?>
                            <?php if (isset($pager)) ui_table_toolbar(['action'=>'laporan_kas.php','q'=>$q ?? '','placeholder'=>'Cari…','per_page'=>$pager['per_page'],'reset_href'=>'laporan_kas.php']); ?>
                        <div class="table-responsive ui-table-scroll">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Tanggal</th>
                                            <th>Keterangan</th>
                                            <th class="mobile-hide">Kode</th>
                                            <th>Jenis</th>
                                            <th>Jumlah</th>
                                            <th class="mobile-hide">Sumber</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $no = 1;
                                        foreach ($kas as $k): 
                                            $row_class = '';
                                            if ($k['tipe_transaksi'] == 'pencairan_pinjaman') $row_class = 'pencairan-row';
                                            elseif ($k['tipe_transaksi'] == 'waserda') $row_class = 'waserda-row';
                                        ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <td><?php echo $no++; ?></td>
                                            <td>
                                                <?php echo formatTanggalUi($k['tanggal'], true); ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($k['keterangan_lengkap']); ?></div>
                                                <?php if (!empty($k['nama_anggota'])): ?>
                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($k['nama_anggota']); ?>
                                                        <?php if (!empty($k['kode_anggota'])): ?>
                                                            (<?php echo htmlspecialchars($k['kode_anggota']); ?>)
                                                        <?php endif; ?>
                                                    </small>
                                                <?php endif; ?>
                                                <?php if (!empty($k['kode_pinjaman']) && $k['tipe_transaksi'] == 'pencairan_pinjaman'): ?>
                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-tag me-1"></i>Pinjaman: <?php echo $k['kode_pinjaman']; ?> (<?php echo $k['jenis_pinjaman']; ?>)
                                                    </small>
                                                <?php endif; ?>
                                                <?php if (!empty($k['nama_user'])): ?>
                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-user-tie me-1"></i>Teller: <?php echo htmlspecialchars($k['nama_user']); ?>
                                                    </small>
                                                <?php endif; ?>
                                                <?php if ($k['tipe_transaksi'] == 'waserda'): ?>
                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-store me-1"></i>Waserda
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="mobile-hide">
                                                <?php if ($k['kode_transaksi']): ?>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($k['kode_transaksi']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $k['jenis'] == 'masuk' ? 'success' : 'danger'; ?>">
                                                    <i class="fas fa-arrow-<?php echo $k['jenis'] == 'masuk' ? 'down' : 'up'; ?> me-1"></i>
                                                    <?php echo ucfirst($k['jenis']); ?>
                                                </span>
                                                <br><small class="text-muted"><?php echo $k['tipe_transaksi']; ?></small>
                                            </td>
                                            <td class="<?php echo $k['jenis'] == 'masuk' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                                <?php echo ($k['jenis'] == 'masuk' ? '+' : '-') . formatRupiah($k['jumlah']); ?>
                                            </td>
                                            <td class="mobile-hide">
                                                <span class="badge bg-<?php 
                                                    if ($k['sumber'] == 'kas') echo 'info';
                                                    elseif ($k['sumber'] == 'pinjaman') echo 'warning';
                                                    else echo 'success';
                                                ?> sumber-badge">
                                                    <?php 
                                                    if ($k['sumber'] == 'kas') echo 'Kas';
                                                    elseif ($k['sumber'] == 'pinjaman') echo 'Pinjaman';
                                                    else echo 'Waserda';
                                                    ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="4" class="text-end">Total Pemasukan / Pengeluaran:</td>
                                            <td class="text-success">+<?php echo formatRupiah($total_masuk); ?></td>
                                            <td class="text-danger">-<?php echo formatRupiah($total_keluar); ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            
                            <!-- Info Penting -->
                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="alert alert-warning py-2 mb-0">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <small>
                                            <strong>Catatan:</strong> 
                                            <span class="badge bg-warning">Kuning</span> = Pencairan Pinjaman | 
                                            <span class="badge bg-info">Biru</span> = Waserda
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (isset($pager)) ui_pagination($pager, 'laporan_kas.php'); ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if (!empty($chart_data) && count($chart_data) > 1): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('saldoChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        label: 'Saldo Kas',
                        data: <?php echo json_encode($chart_data); ?>,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointBackgroundColor: function(context) {
                            const index = context.dataIndex;
                            const value = context.dataset.data[index];
                            return value >= 0 ? '#27ae60' : '#e74c3c';
                        },
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Saldo: ' + formatRupiah(context.raw);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            ticks: {
                                callback: function(value) {
                                    return formatRupiah(value);
                                }
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        });
        
        function formatRupiah(angka) {
            return 'Rp ' + angka.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
    </script>
    <?php endif; ?>
</body>
</html>
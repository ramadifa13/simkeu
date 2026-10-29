<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses teller
if (!isTeller()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

$page_title = "Laporan Harian";

// Get today's date or selected date
$selected_date = $_GET['tanggal'] ?? date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

// Handle export PDF
if (isset($_GET['export_pdf']) && $_GET['export_pdf'] == 1) {
    $params = http_build_query([
        'tanggal' => $selected_date
    ]);
    header("Location: cetak_laporan_harian.php?$params");
    exit();
}

// Handle export Excel
if (isset($_GET['export_excel']) && $_GET['export_excel'] == 1) {
    $params = http_build_query([
        'tanggal' => $selected_date
    ]);
    header("Location: export_excel_harian.php?$params");
    exit();
}

// ============================================
// RINGKASAN TRANSAKSI SIMPANAN (HANYA SIMPANAN REAL, EXCLUDE ANG)
// ============================================
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

// ============================================
// RINGKASAN PEMBAYARAN ANGSURAN
// ============================================
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

// ============================================
// RINGKASAN PENERIMAAN KAS
// ============================================
$query_kas_masuk = "SELECT COALESCE(SUM(jumlah), 0) as total_penerimaan
    FROM kas
    WHERE jenis = 'masuk' AND DATE(tanggal) = :tanggal";
$stmt_kas_masuk = $db->prepare($query_kas_masuk);
$stmt_kas_masuk->bindParam(':tanggal', $selected_date);
$stmt_kas_masuk->execute();
$penerimaan = $stmt_kas_masuk->fetch(PDO::FETCH_ASSOC)['total_penerimaan'];

// ============================================
// RINGKASAN PENGELUARAN KAS
// ============================================
$query_kas_keluar = "SELECT COALESCE(SUM(jumlah), 0) as total_pengeluaran
    FROM kas
    WHERE jenis = 'keluar' AND DATE(tanggal) = :tanggal";
$stmt_kas_keluar = $db->prepare($query_kas_keluar);
$stmt_kas_keluar->bindParam(':tanggal', $selected_date);
$stmt_kas_keluar->execute();
$pengeluaran = $stmt_kas_keluar->fetch(PDO::FETCH_ASSOC)['total_pengeluaran'];

// ============================================
// DETAIL TRANSAKSI SIMPANAN (HANYA SIMPANAN REAL, EXCLUDE ANG)
// ============================================
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
$__ui = ui_query_params([]);
$__q = $__ui['q'];
$__all = $transactions;
if ($__q !== '') {
    $__fields = array (
  0 => 'nama_lengkap',
  1 => 'kode_anggota',
  2 => 'kode_transaksi',
  3 => 'keterangan',
);
    $__all = array_values(array_filter($__all, static function ($r) use ($__q, $__fields) {
        $hay = '';
        foreach ($__fields as $f) { $hay .= ' ' . ($r[$f] ?? ''); }
        return stripos($hay, $__q) !== false;
    }));
}
$pager = ui_paginate(count($__all), $__ui['page'], $__ui['per_page']);
$transactions = array_slice($__all, $pager['offset'], $pager['per_page']);
$q = $__q;

// ============================================
// DETAIL PEMBAYARAN ANGSURAN
// ============================================
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

// ============================================
// DETAIL TRANSAKSI KAS
// ============================================
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

// Fungsi untuk mendapatkan badge class
function getBadgeClass($kode_jenis) {
    switch($kode_jenis) {
        case 'POKOK':
            return 'bg-secondary';
        case 'WAJIB':
            return 'bg-info';
        case 'SUKARELA':
            return 'bg-success';
        case 'DEPOSITO':
            return 'bg-warning text-dark';
        case 'HAJI':
            return 'bg-primary';
        case 'UMROH':
            return 'bg-dark';
        default:
            return 'bg-primary';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary:#087f83;
            --secondary:#12666e;
            --success: #27ae60;
            --info: #17a2b8;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --dark: #34495e;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            overflow-x: hidden;
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
            
            .stat-card .card-body {
                padding: 12px 10px;
            }
            
            .stat-card .h4 {
                font-size: 1.1rem;
            }
            
            .date-navigation {
                padding: 1rem !important;
            }
            
            .table td, .table th {
                padding: 0.5rem 0.3rem;
                font-size: 0.75rem;
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
            gap: 8px;
            margin-bottom: 20px;
        }
        
        @media (min-width: 576px) {
            .page-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }
        
        .date-navigation {
            background: linear-gradient(135deg,#123d47,#087f83);
            border-radius: 10px;
            color: white;
            padding: 14px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            background: white;
            height: 100%;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .stat-card.income { border-left-color: #27ae60; }
        .stat-card.outcome { border-left-color: #e74c3c; }
        .stat-card.transaction { border-left-color: #3498db; }
        .stat-card.angsuran { border-left-color: #f39c12; }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .recent-activity {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
            border: 1px solid rgba(0,0,0,0.03);
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.8rem;
            text-transform: uppercase;
            border-bottom: 1px solid #dee2e6;
        }
        
        .table td {
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .amount-positive {
            color: #27ae60;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #e74c3c;
            font-weight: 600;
        }
        
        .badge-transaction {
            padding: 0.3rem 0.8rem;
            border-radius: 10px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        .bg-success-subtle {
            background-color: rgba(39, 174, 96, 0.1);
        }
        
        .bg-warning-subtle {
            background-color: rgba(243, 156, 18, 0.1);
        }
        
        .bg-info-subtle {
            background-color: rgba(52, 152, 219, 0.1);
        }
        
        .empty-state {
            padding: 30px 15px;
            text-align: center;
        }
        
        .empty-state i {
            font-size: 1.4rem;
            color: #ccc;
            margin-bottom: 15px;
        }
        
        .empty-state p {
            color: #999;
            margin-bottom: 15px;
        }
        
        .btn-outline-light {
            border-color: rgba(255,255,255,0.3);
            color: white;
        }
        
        .btn-outline-light:hover {
            background-color: rgba(255,255,255,0.1);
            border-color: white;
            color: white;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
        }
        
        .btn-pdf {
            background:#c0392b;
            color: white;
            border: none;
        }
        
        .btn-pdf:hover {
            background:#a93226;
            color: white;
        }
        
        .btn-excel {
            background:#1f8a5b;
            color: white;
            border: none;
        }
        
        .btn-excel:hover {
            background:#196f4a;
            color: white;
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
                    'title' => 'Laporan Harian',
                    'icon' => 'fas fa-chart-line text-primary',
                    'subtitle' => 'Ringkasan transaksi teller per tanggal',
                    'actions' => '<div class="btn-group">'
                        . '<a href="?export_pdf=1&tanggal=' . urlencode((string)$selected_date) . '" class="btn btn-sm btn-danger" target="_blank"><i class="fas fa-file-pdf me-1"></i><span class="d-none d-sm-inline">PDF</span></a>'
                        . '<a href="?export_excel=1&tanggal=' . urlencode((string)$selected_date) . '" class="btn btn-sm btn-success"><i class="fas fa-file-excel me-1"></i><span class="d-none d-sm-inline">Excel</span></a>'
                        . '<a href="laporan_harian.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-sync me-1"></i><span class="d-none d-sm-inline">Reset</span></a>'
                        . '</div>',
                ]); ?>

                <!-- Date Navigation -->
                <div class="date-navigation">
                    <div class="row align-items-center">
                        <div class="col-md-8 mb-3 mb-md-0">
                            <h4 class="mb-2">
                                <i class="fas fa-calendar-alt me-2"></i>
                                Laporan Tanggal: <strong><?php echo formatTanggalUi($selected_date); ?></strong>
                            </h4>
                            <p class="mb-0 opacity-75">Ringkasan transaksi simpanan dan pembayaran angsuran</p>
                        </div>
                        <div class="col-md-4">
                            <form method="GET" class="d-flex gap-2">
                                <input type="date" class="form-control form-control-sm" name="tanggal" value="<?php echo $selected_date; ?>" 
                                       onchange="this.form.submit()" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white;">
                                <div class="btn-group">
                                    <a href="?tanggal=<?php echo $prev_date; ?>" class="btn btn-sm btn-outline-light">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                    <a href="?tanggal=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-light">
                                        Hari Ini
                                    </a>
                                    <a href="?tanggal=<?php echo $next_date; ?>" class="btn btn-sm btn-outline-light">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php ui_stat_grid([
                    ['label' => 'Transaksi simpanan', 'value' => (int)$summary['total_transaksi'], 'tone' => 'primary', 'icon' => 'fas fa-exchange-alt', 'hint' => 'Setoran: ' . (int)$summary['jumlah_setoran'] . ' · Penarikan: ' . (int)$summary['jumlah_penarikan']],
                    ['label' => 'Total setoran', 'value' => formatRupiah($summary['total_setoran']), 'tone' => 'success', 'icon' => 'fas fa-arrow-down', 'hint' => number_format((int)$summary['jumlah_setoran']) . ' transaksi'],
                    ['label' => 'Total penarikan', 'value' => formatRupiah($summary['total_penarikan']), 'tone' => 'danger', 'icon' => 'fas fa-arrow-up', 'hint' => number_format((int)$summary['jumlah_penarikan']) . ' transaksi'],
                    ['label' => 'Pembayaran angsuran', 'value' => formatRupiah($angsuran_summary['total_pembayaran_angsuran']), 'tone' => 'warning', 'icon' => 'fas fa-credit-card', 'hint' => number_format((int)$angsuran_summary['total_angsuran']) . ' transaksi'],
                ]); ?>

                <!-- Ringkasan Kas Harian -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-white">
                                <h6 class="mb-0"><i class="fas fa-calculator me-2 text-primary"></i>Ringkasan Kas Harian</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <div class="p-3 bg-light rounded text-center">
                                            <small class="text-muted d-block">Total Penerimaan Kas</small>
                                            <span class="fw-bold text-success fs-5"><?php echo formatRupiah($penerimaan); ?></span>
                                            <small class="d-block">(Setoran + Angsuran + Lainnya)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="p-3 bg-light rounded text-center">
                                            <small class="text-muted d-block">Total Pengeluaran Kas</small>
                                            <span class="fw-bold text-danger fs-5"><?php echo formatRupiah($pengeluaran); ?></span>
                                            <small class="d-block">(Penarikan + Lainnya)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="p-3 bg-light rounded text-center">
                                            <small class="text-muted d-block">Selisih Kas Harian</small>
                                            <?php $selisih_kas = $penerimaan - $pengeluaran; ?>
                                            <span class="fw-bold <?php echo $selisih_kas >= 0 ? 'text-success' : 'text-danger'; ?> fs-5">
                                                <?php echo formatRupiah($selisih_kas); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tiga Kolom: Transaksi Simpanan, Pembayaran Angsuran, Transaksi Kas -->
                <div class="row">
                    <!-- Transaksi Simpanan -->
                    <div class="col-lg-4 mb-4">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-exchange-alt text-success me-2"></i>Transaksi Simpanan
                                </h5>
                                <span class="badge bg-primary"><?php echo count($transactions); ?> Transaksi</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($transactions)): ?>
                                <?php if (isset($pager)) ui_table_toolbar(['action'=>'laporan_harian.php','q'=>$q ?? '','placeholder'=>'Cari…','per_page'=>$pager['per_page'],'reset_href'=>'laporan_harian.php']); ?>
                        <div class="table-responsive ui-table-scroll">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Waktu</th>
                                                <th>Anggota</th>
                                                <th>Jenis</th>
                                                <th class="text-end">Jumlah</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($transactions as $transaksi): 
                                                $badgeClass = getBadgeClass($transaksi['kode_jenis']);
                                            ?>
                                            <tr>
                                                <td>
                                                    <small><?php echo date('H:i', strtotime($transaksi['tanggal_transaksi'])); ?></small>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($transaksi['nama_lengkap']); ?></div>
                                                    <small class="text-muted"><?php echo $transaksi['kode_anggota']; ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo $transaksi['kode_jenis']; ?></span>
                                                    <br>
                                                    <small class="text-<?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'success' : 'warning'; ?>">
                                                        <?php echo ucfirst($transaksi['jenis_transaksi']); ?>
                                                    </small>
                                                </td>
                                                <td class="text-end <?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'amount-positive' : 'amount-negative'; ?>">
                                                    <?php echo $transaksi['jenis_transaksi'] == 'setoran' ? '+' : '-'; ?>
                                                    <?php echo formatRupiah($transaksi['jumlah']); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-exchange-alt"></i>
                                    <p class="text-muted">Tidak ada transaksi simpanan</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Pembayaran Angsuran -->
                    <div class="col-lg-4 mb-4">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-credit-card text-warning me-2"></i>Pembayaran Angsuran
                                </h5>
                                <span class="badge bg-warning"><?php echo count($angsuran); ?> Transaksi</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($angsuran)): ?>
                                <div class="table-responsive ui-table-scroll">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Waktu</th>
                                                <th>Tgl Bayar</th>
                                                <th>Jatuh Tempo</th>
                                                <th>Anggota</th>
                                                <th>Angsuran Ke</th>
                                                <th class="text-end">Jumlah</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($angsuran as $a): ?>
                                            <tr>
                                                <td>
                                                    <small><?php echo $a['waktu_bayar'] ? date('H:i', strtotime($a['waktu_bayar'])) : '-'; ?></small>
                                                </td>
                                                <td><small><?php echo $a['tanggal_bayar'] ? formatTanggalUi($a['tanggal_bayar']) : '-'; ?></small></td>
                                                <td><small><?php echo formatTanggalUi($a['tanggal_jatuh_tempo']); ?></small></td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($a['nama_lengkap']); ?></div>
                                                    <small class="text-muted"><?php echo $a['kode_anggota']; ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-info"><?php echo $a['angsuran_ke']; ?></span>
                                                </td>
                                                <td class="text-end">
                                                    <span class="amount-positive"><?php echo formatRupiah($a['jumlah_angsuran']); ?></span>
                                                    <?php if ($a['denda'] > 0): ?>
                                                    <br><small class="text-danger">+ denda <?php echo formatRupiah($a['denda']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-credit-card"></i>
                                    <p class="text-muted">Tidak ada pembayaran angsuran</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Transaksi Kas -->
                    <div class="col-lg-4 mb-4">
                        <div class="card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-wallet text-info me-2"></i>Transaksi Kas
                                </h5>
                                <span class="badge bg-info"><?php echo count($kas_transactions); ?> Transaksi</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($kas_transactions)): ?>
                                <div class="table-responsive ui-table-scroll">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Waktu</th>
                                                <th>Jenis</th>
                                                <th>Keterangan</th>
                                                <th class="text-end">Jumlah</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($kas_transactions as $kas): ?>
                                            <tr>
                                                <td>
                                                    <small><?php echo date('H:i', strtotime($kas['tanggal'])); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $kas['jenis'] == 'masuk' ? 'success' : 'danger'; ?>">
                                                        <?php echo ucfirst($kas['jenis']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?php echo $kas['keterangan'] ?: '-'; ?></small>
                                                    <?php if ($kas['anggota_nama']): ?>
                                                    <br><small class="text-muted"><?php echo $kas['anggota_nama']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end <?php echo $kas['jenis'] == 'masuk' ? 'amount-positive' : 'amount-negative'; ?>">
                                                    <?php echo $kas['jenis'] == 'masuk' ? '+' : '-'; ?>
                                                    <?php echo formatRupiah($kas['jumlah']); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-wallet"></i>
                                    <p class="text-muted">Tidak ada transaksi kas</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title mb-3"><i class="fas fa-bolt me-2"></i>Aksi Cepat</h6>
                                <div class="row g-2">
                                    <div class="col-md-3 col-6">
                                        <a href="setoran.php" class="btn btn-success w-100">
                                            <i class="fas fa-plus-circle me-1"></i> Setoran
                                        </a>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <a href="penarikan.php" class="btn btn-warning w-100 text-white">
                                            <i class="fas fa-minus-circle me-1"></i> Penarikan
                                        </a>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <a href="angsuran.php" class="btn btn-primary w-100">
                                            <i class="fas fa-credit-card me-1"></i> Bayar Angsuran
                                        </a>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <a href="dashboard.php" class="btn btn-secondary w-100">
                                            <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if (isset($pager)) ui_pagination($pager, 'laporan_harian.php'); ?>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
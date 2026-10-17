<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

// Set default filter
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_jenis = $_GET['jenis_transaksi'] ?? '';
$filter_sumber = $_GET['sumber'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';

// Get anggota list
$query_anggota = "SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// AMBIL DATA DARI TABEL
// ============================================

// 1. DATA TABUNGAN (dari tabel transaksi)
$tabungan = $db->query("SELECT 
    t.*, 
    a.nama_lengkap, 
    a.kode_anggota, 
    js.nama_jenis as jenis_tabungan,
    js.kode_jenis
FROM transaksi t 
LEFT JOIN anggota a ON t.anggota_id = a.id 
LEFT JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id
ORDER BY t.tanggal_transaksi DESC")->fetchAll(PDO::FETCH_ASSOC);

// 2. DATA PINJAMAN
$pinjaman = $db->query("SELECT 
    p.*, 
    a.nama_lengkap as nama_anggota, 
    a.kode_anggota,
    jp.nama_jenis as jenis_pinjaman
FROM pinjaman p 
LEFT JOIN anggota a ON p.anggota_id = a.id 
LEFT JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
ORDER BY p.tanggal_pencairan DESC")->fetchAll(PDO::FETCH_ASSOC);

// 3. DATA WASERDA (langsung dari tabel waserda_transaksi)
$waserda = $db->query("SELECT 
    w.*, 
    a.nama_lengkap, 
    a.kode_anggota,
    u.nama_lengkap as petugas
FROM waserda_transaksi w 
LEFT JOIN anggota a ON w.anggota_id = a.id
LEFT JOIN users u ON w.user_id = u.id
ORDER BY w.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// 4. DATA KAS LAIN (yang tidak terkait tabungan dan waserda)
$kas_lain = $db->query("SELECT 
    k.*,
    'KAS_LAIN' as sumber,
    'Kas Lain' as sumber_label
FROM kas k 
LEFT JOIN transaksi t ON k.transaksi_id = t.id
LEFT JOIN waserda_transaksi w ON k.transaksi_id = w.id
WHERE t.id IS NULL AND w.id IS NULL
ORDER BY k.tanggal DESC")->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// GABUNGKAN SEMUA DATA
// ============================================
$all_transaksi = array();

// FORMAT DATA TABUNGAN
foreach ($tabungan as $t) {
    $tipe = ($t['jenis_transaksi'] == 'setoran') ? 'setoran' : 'penarikan';
    $jenis_display = $t['jenis_tabungan'] ?? 'Tabungan';
    
    $tgl = date('Y-m-d', strtotime($t['tanggal_transaksi']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = array(
            'tanggal' => $t['tanggal_transaksi'],
            'sumber' => 'TABUNGAN',
            'sumber_label' => 'Tabungan',
            'sumber_detail' => $jenis_display,
            'kode_transaksi' => $t['kode_transaksi'],
            'anggota_id' => $t['anggota_id'],
            'kode_anggota' => $t['kode_anggota'] ?? '-',
            'nama_anggota' => $t['nama_lengkap'] ?? '-',
            'jenis_kode' => $t['kode_jenis'] ?? 'TAB',
            'tipe' => $tipe,
            'tipe_label' => strtoupper($tipe),
            'jenis' => ($tipe == 'setoran') ? 'masuk' : 'keluar',
            'jumlah' => $t['jumlah'],
            'keterangan' => $jenis_display . ' - ' . ucfirst($tipe),
            'petugas' => 'Administrator'
        );
    }
}

// FORMAT DATA PINJAMAN
foreach ($pinjaman as $p) {
    $tgl = date('Y-m-d', strtotime($p['tanggal_pencairan']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = array(
            'tanggal' => $p['tanggal_pencairan'] . ' 23:59:59',
            'sumber' => 'PINJAMAN',
            'sumber_label' => 'Pinjaman',
            'sumber_detail' => $p['jenis_pinjaman'] ?? 'Pinjaman',
            'kode_transaksi' => $p['kode_pinjaman'],
            'anggota_id' => $p['anggota_id'],
            'kode_anggota' => $p['kode_anggota'] ?? '-',
            'nama_anggota' => $p['nama_anggota'] ?? '-',
            'jenis_kode' => 'PJN',
            'tipe' => 'pencairan',
            'tipe_label' => 'PENCAIRAN',
            'jenis' => 'keluar',
            'jumlah' => $p['jumlah_pinjaman'],
            'keterangan' => 'Pencairan Pinjaman',
            'petugas' => 'Administrator'
        );
    }
}

// FORMAT DATA WASERDA
foreach ($waserda as $w) {
    $items = json_decode($w['items'], true);
    $jml_item = is_array($items) ? count($items) : 1;
    $nama_barang = is_array($items) ? $items[0]['nama'] : 'barang';
    
    $tgl = date('Y-m-d', strtotime($w['created_at']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = array(
            'tanggal' => $w['created_at'],
            'sumber' => 'WASERDA',
            'sumber_label' => 'Waserda',
            'sumber_detail' => $jml_item . ' item',
            'kode_transaksi' => $w['kode_transaksi'],
            'anggota_id' => $w['anggota_id'],
            'kode_anggota' => $w['kode_anggota'] ?? '-',
            'nama_anggota' => $w['nama_lengkap'] ?? '-',
            'jenis_kode' => 'WSR',
            'tipe' => 'waserda',
            'tipe_label' => 'WASERDA',
            'jenis' => 'masuk',
            'jumlah' => $w['total_bayar'],
            'keterangan' => 'Waserda - ' . $nama_barang . ' (' . $jml_item . ' item) - ' . ($w['metode_bayar'] ?? 'Tunai'),
            'petugas' => $w['petugas'] ?? 'Administrator'
        );
    }
}

// FORMAT DATA KAS LAIN
foreach ($kas_lain as $k) {
    $tgl = date('Y-m-d', strtotime($k['tanggal']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = array(
            'tanggal' => $k['tanggal'],
            'sumber' => 'KAS_LAIN',
            'sumber_label' => 'Kas Lain',
            'sumber_detail' => '-',
            'kode_transaksi' => 'KAS-' . $k['id'],
            'anggota_id' => null,
            'kode_anggota' => '-',
            'nama_anggota' => '-',
            'jenis_kode' => 'KAS',
            'tipe' => 'kas_lainnya',
            'tipe_label' => 'KAS LAIN',
            'jenis' => $k['jenis'],
            'jumlah' => $k['jumlah'],
            'keterangan' => $k['keterangan'],
            'petugas' => 'Administrator'
        );
    }
}

// Urutkan berdasarkan tanggal (terbaru ke terlama)
usort($all_transaksi, function($a, $b) {
    return strtotime($b['tanggal']) - strtotime($a['tanggal']);
});

// ============================================
// FILTER DATA
// ============================================
$filtered_transaksi = array();

foreach ($all_transaksi as $t) {
    // Filter sumber
    if (!empty($filter_sumber) && $t['sumber'] != $filter_sumber) {
        continue;
    }
    
    // Filter jenis
    if (!empty($filter_jenis) && strtolower($t['tipe']) != strtolower($filter_jenis)) {
        continue;
    }
    
    // Filter anggota
    if (!empty($filter_anggota) && $t['anggota_id'] != $filter_anggota) {
        continue;
    }
    
    $filtered_transaksi[] = $t;
}

// ============================================
// HITUNG STATISTIK
// ============================================
$total_setoran = 0;
$total_penarikan = 0;
$total_pencairan = 0;
$total_waserda = 0;
$total_kas_lain = 0;
$jumlah_setoran = 0;
$jumlah_penarikan = 0;
$jumlah_pencairan = 0;
$jumlah_waserda = 0;

foreach ($all_transaksi as $t) {
    if ($t['sumber'] == 'TABUNGAN') {
        if ($t['tipe'] == 'setoran') {
            $total_setoran += $t['jumlah'];
            $jumlah_setoran++;
        } elseif ($t['tipe'] == 'penarikan') {
            $total_penarikan += $t['jumlah'];
            $jumlah_penarikan++;
        }
    } elseif ($t['sumber'] == 'PINJAMAN') {
        $total_pencairan += $t['jumlah'];
        $jumlah_pencairan++;
    } elseif ($t['sumber'] == 'WASERDA') {
        $total_waserda += $t['jumlah'];
        $jumlah_waserda++;
    } else {
        $total_kas_lain += $t['jumlah'];
    }
}

$total_pemasukan = $total_setoran + $total_waserda;
$total_pengeluaran = $total_penarikan + $total_pencairan;
$selisih = $total_pemasukan - $total_pengeluaran;

// Ambil saldo akhir dari kas
$saldo_akhir = 0;
$query_saldo = "SELECT saldo_sesudah FROM kas ORDER BY id DESC LIMIT 1";
$stmt_saldo = $db->prepare($query_saldo);
$stmt_saldo->execute();
$saldo_data = $stmt_saldo->fetch(PDO::FETCH_ASSOC);
$saldo_akhir = $saldo_data ? $saldo_data['saldo_sesudah'] : 0;

// Fungsi format rupiah
function rp($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Daftar sumber untuk filter
$sumber_list = array(
    '' => 'Semua Sumber',
    'TABUNGAN' => 'Tabungan',
    'PINJAMAN' => 'Pinjaman',
    'WASERDA' => 'Waserda',
    'KAS_LAIN' => 'Kas Lain'
);

// Daftar jenis untuk filter
$jenis_list = array(
    '' => 'Semua Jenis',
    'setoran' => 'Setoran Tabungan',
    'penarikan' => 'Penarikan Tabungan',
    'pencairan' => 'Pencairan Pinjaman',
    'waserda' => 'Waserda'
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Semua Transaksi - Koperasi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; margin-top: 70px; padding: 20px; }
        @media (max-width: 768px) { .main-content { margin-left: 0; padding: 15px; } }
        
        .card { 
            border: none; 
            border-radius: 10px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.1); 
            margin-bottom: 20px; 
            background: white;
        }
        
        .card-header { 
            background: white; 
            border-bottom: 2px solid #f0f0f0; 
            font-weight: 600; 
            padding: 15px 20px;
            border-radius: 10px 10px 0 0 !important;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            height: 100%;
            border-left: 4px solid #3498db;
        }
        
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.danger { border-left-color: #dc3545; }
        .stat-card.warning { border-left-color: #ffc107; }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.85rem;
        }
        
        .table th { 
            background: #f8f9fa; 
            border-top: none;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        
        .table td { vertical-align: middle; }
        
        .amount-positive { color: #28a745; font-weight: 600; }
        .amount-negative { color: #dc3545; font-weight: 600; }
        
        .badge-setoran { background: rgba(40, 167, 69, 0.1); color: #28a745; padding: 3px 8px; border-radius: 20px; font-weight: 600; font-size: 0.7rem; }
        .badge-penarikan { background: rgba(220, 53, 69, 0.1); color: #dc3545; padding: 3px 8px; border-radius: 20px; }
        .badge-pencairan { background: rgba(253, 126, 20, 0.1); color: #fd7e14; padding: 3px 8px; border-radius: 20px; }
        .badge-waserda { background: rgba(23, 162, 184, 0.1); color: #17a2b8; padding: 3px 8px; border-radius: 20px; }
        .badge-kas { background: rgba(108, 117, 125, 0.1); color: #6c757d; padding: 3px 8px; border-radius: 20px; }
        
        .sumber-badge {
            font-size: 0.7rem;
            padding: 3px 6px;
            border-radius: 4px;
            color: white;
        }
        .sumber-TABUNGAN { background-color: #007bff; }
        .sumber-PINJAMAN { background-color: #fd7e14; }
        .sumber-WASERDA { background-color: #28a745; }
        .sumber-KAS_LAIN { background-color: #6c757d; }
        
        .pencairan-row { background-color: #fff3cd !important; }
        .waserda-row { background-color: #d1ecf1 !important; }
        .tabungan-row { background-color: #cce5ff !important; }
        
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .info-box {
            background: #e8f4fd;
            border-left: 4px solid #3498db;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="mb-0">
                        <i class="fas fa-file-alt text-primary me-2"></i>Laporan Semua Transaksi
                    </h3>
                    <div>
                        <a href="laporan_transaksi.php" class="btn btn-sm btn-outline-primary me-2">
                            <i class="fas fa-sync"></i> Refresh
                        </a>
                        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-print"></i> Cetak
                        </button>
                    </div>
                </div>

                <!-- Info Periode -->
                <div class="info-box">
                    <i class="fas fa-calendar me-2"></i>
                    <strong>Periode:</strong> 
                    <?php echo date('d/m/Y', strtotime($start_date)); ?> - <?php echo date('d/m/Y', strtotime($end_date)); ?>
                    <span class="badge bg-primary ms-2"><?php echo count($all_transaksi); ?> transaksi</span>
                    <span class="badge bg-secondary ms-2">Tabungan: <?php echo count($tabungan); ?></span>
                    <span class="badge bg-secondary ms-2">Pinjaman: <?php echo count($pinjaman); ?></span>
                    <span class="badge bg-secondary ms-2">Waserda: <?php echo count($waserda); ?></span>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-primary"><?php echo count($all_transaksi); ?></div>
                            <div class="stat-label">Total Transaksi</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card success">
                            <div class="stat-value text-success"><?php echo rp($total_pemasukan); ?></div>
                            <div class="stat-label">Total Pemasukan</div>
                            <small>Setoran: <?php echo rp($total_setoran); ?> (<?php echo $jumlah_setoran; ?>x), Waserda: <?php echo rp($total_waserda); ?> (<?php echo $jumlah_waserda; ?>x)</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card danger">
                            <div class="stat-value text-danger"><?php echo rp($total_pengeluaran); ?></div>
                            <div class="stat-label">Total Pengeluaran</div>
                            <small>Penarikan: <?php echo rp($total_penarikan); ?> (<?php echo $jumlah_penarikan; ?>x), Pencairan: <?php echo rp($total_pencairan); ?> (<?php echo $jumlah_pencairan; ?>x)</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card warning">
                            <div class="stat-value <?php echo $selisih >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo rp($selisih); ?>
                            </div>
                            <div class="stat-label">Selisih Kas</div>
                            <small>Saldo akhir: <?php echo rp($saldo_akhir); ?></small>
                        </div>
                    </div>
                </div>

                <!-- Detail Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6>Setoran Tabungan</h6>
                                <h5 class="text-success"><?php echo rp($total_setoran); ?></h5>
                                <small><?php echo $jumlah_setoran; ?>x</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6>Penarikan Tabungan</h6>
                                <h5 class="text-danger"><?php echo rp($total_penarikan); ?></h5>
                                <small><?php echo $jumlah_penarikan; ?>x</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6>Waserda</h6>
                                <h5 class="text-info"><?php echo rp($total_waserda); ?></h5>
                                <small><?php echo $jumlah_waserda; ?>x</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6>Pencairan</h6>
                                <h5 class="text-warning"><?php echo rp($total_pencairan); ?></h5>
                                <small><?php echo $jumlah_pencairan; ?>x</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Dari Tanggal</label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Sampai Tanggal</label>
                            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Sumber</label>
                            <select name="sumber" class="form-control">
                                <?php foreach ($sumber_list as $key => $value): ?>
                                <option value="<?php echo $key; ?>" <?php echo $filter_sumber == $key ? 'selected' : ''; ?>>
                                    <?php echo $value; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Jenis</label>
                            <select name="jenis_transaksi" class="form-control">
                                <?php foreach ($jenis_list as $key => $value): ?>
                                <option value="<?php echo $key; ?>" <?php echo $filter_jenis == $key ? 'selected' : ''; ?>>
                                    <?php echo $value; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Anggota</label>
                            <select name="anggota_id" class="form-control">
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
                                <i class="fas fa-search me-1"></i> Filter
                            </button>
                            <a href="laporan_transaksi.php" class="btn btn-secondary">
                                <i class="fas fa-undo me-1"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Tabel Transaksi -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-list me-2"></i>Detail Semua Transaksi</span>
                        <span class="badge bg-primary"><?php echo count($filtered_transaksi); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Sumber</th>
                                        <th>Detail</th>
                                        <th>Kode</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th>Keterangan</th>
                                        <th class="text-end">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_display = 0;
                                    foreach ($filtered_transaksi as $t): 
                                        $total_display += $t['jumlah'];
                                        
                                        $row_class = '';
                                        if ($t['sumber'] == 'PINJAMAN') $row_class = 'pencairan-row';
                                        elseif ($t['sumber'] == 'WASERDA') $row_class = 'waserda-row';
                                        elseif ($t['sumber'] == 'TABUNGAN') $row_class = 'tabungan-row';
                                        
                                        $badge_class = 'badge-setoran';
                                        if ($t['tipe'] == 'penarikan') $badge_class = 'badge-penarikan';
                                        elseif ($t['tipe'] == 'waserda') $badge_class = 'badge-waserda';
                                        elseif ($t['tipe'] == 'pencairan') $badge_class = 'badge-pencairan';
                                        elseif ($t['tipe'] == 'kas_lainnya') $badge_class = 'badge-kas';
                                    ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td><?php echo date('d/m/Y H:i', strtotime($t['tanggal'])); ?></td>
                                        <td>
                                            <span class="sumber-badge sumber-<?php echo $t['sumber']; ?>">
                                                <?php echo $t['sumber_label']; ?>
                                            </span>
                                        </td>
                                        <td><small><?php echo $t['sumber_detail'] ?? '-'; ?></small></td>
                                        <td><small><?php echo $t['kode_transaksi']; ?></small></td>
                                        <td>
                                            <?php if ($t['nama_anggota'] && $t['nama_anggota'] != '-'): ?>
                                                <?php echo htmlspecialchars($t['nama_anggota']); ?>
                                                <br><small class="text-muted"><?php echo $t['kode_anggota']; ?></small>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo $badge_class; ?>">
                                                <?php echo $t['tipe_label']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $t['keterangan']; ?></td>
                                        <td class="text-end <?php echo $t['jenis'] == 'masuk' ? 'amount-positive' : 'amount-negative'; ?>">
                                            <?php echo $t['jenis'] == 'masuk' ? '+' : '-'; ?>
                                            <?php echo rp($t['jumlah']); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="7" class="text-end">TOTAL:</th>
                                        <th class="text-end"><?php echo rp($total_display); ?></th>
                                    </tr>
                                    <tr class="bg-danger text-white">
                                        <th colspan="7" class="text-end">TOTAL PENGELUARAN (Penarikan + Pencairan):</th>
                                        <th class="text-end"><?php echo rp($total_pengeluaran); ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <!-- Catatan -->
                        <div class="alert alert-info m-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Catatan:</strong> 
                            <span class="badge bg-primary">Biru</span> = Tabungan |
                            <span class="badge bg-warning">Kuning</span> = Pencairan Pinjaman |
                            <span class="badge bg-success">Hijau</span> = Waserda
                        </div>
                    </div>
                </div>

                <!-- Export Options -->
                <div class="mt-3 text-end">
                    <a href="export_excel_transaksi.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&jenis_transaksi=<?php echo $filter_jenis; ?>&sumber=<?php echo $filter_sumber; ?>&anggota_id=<?php echo $filter_anggota; ?>" class="btn btn-sm btn-success">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </a>
                    <a href="cetak_pdf_transaksi.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&jenis_transaksi=<?php echo $filter_jenis; ?>&sumber=<?php echo $filter_sumber; ?>&anggota_id=<?php echo $filter_anggota; ?>" class="btn btn-sm btn-danger" target="_blank">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </a>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
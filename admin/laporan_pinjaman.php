<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

// Check if user has access to this page
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: dashboard.php");
    exit();
}

// Filter parameters
$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_jenis = $_GET['jenis_pinjaman_id'] ?? '';
$filter_status = $_GET['status'] ?? '';

// Handle export PDF
if (isset($_GET['export_pdf']) && $_GET['export_pdf'] == 1) {
    $params = http_build_query([
        'anggota_id' => $filter_anggota,
        'jenis_pinjaman_id' => $filter_jenis,
        'status' => $filter_status
    ]);
    header("Location: cetak_laporan_pinjaman.php?$params");
    exit();
}

// Handle export Excel
if (isset($_GET['export_excel']) && $_GET['export_excel'] == 1) {
    $params = http_build_query([
        'anggota_id' => $filter_anggota,
        'jenis_pinjaman_id' => $filter_jenis,
        'status' => $filter_status
    ]);
    header("Location: export_excel_pinjaman.php?$params");
    exit();
}

// Build query dengan perhitungan yang benar
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
$__ui = ui_query_params([]);
$__q = $__ui['q'];
$__all = $pinjaman;
if ($__q !== '') {
    $__fields = array (
  0 => 'nama_lengkap',
  1 => 'kode_anggota',
  2 => 'kode_pinjaman',
  3 => 'nama_jenis',
);
    $__all = array_values(array_filter($__all, static function ($r) use ($__q, $__fields) {
        $hay = '';
        foreach ($__fields as $f) { $hay .= ' ' . ($r[$f] ?? ''); }
        return stripos($hay, $__q) !== false;
    }));
}
$pager = ui_paginate(count($__all), $__ui['page'], $__ui['per_page']);
$pinjaman = array_slice($__all, $pager['offset'], $pager['per_page']);
$q = $__q;


// Get data for filters
$query_anggota = "SELECT * FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

$query_jenis = "SELECT * FROM jenis_pinjaman WHERE status = 'aktif' ORDER BY nama_jenis";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_list = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// Status options
$status_list = [
    'aktif' => 'Aktif',
    'lunas' => 'Lunas', 
    'macet' => 'Macet'
];

// Hitung statistics untuk card
$total_pinjaman_aktif = 0;
$total_pinjaman_lunas = 0;
$total_sisa_pokok = 0;
$total_dibayar = 0;

foreach ($pinjaman as $p) {
    if ($p['status'] == 'aktif') $total_pinjaman_aktif++;
    if ($p['status'] == 'lunas') $total_pinjaman_lunas++;
    $total_sisa_pokok += $p['sisa_pokok'];
    $total_dibayar += $p['total_pokok_dibayar'];
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Pinjaman - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* stat-card via app-ui */
        .progress {
            height: 6px;
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
                    'title' => 'Laporan Pinjaman',
                    'icon' => 'fas fa-chart-bar text-primary',
                    'subtitle' => 'Rekap pinjaman aktif, lunas, dan outstanding',
                    'actions' => '<div class="btn-group">'
                        . '<a href="?export_pdf=1&' . http_build_query($_GET) . '" class="btn btn-sm btn-danger" target="_blank"><i class="fas fa-file-pdf"></i> <span class="d-none d-sm-inline">PDF</span></a>'
                        . '<a href="?export_excel=1&' . http_build_query($_GET) . '" class="btn btn-sm btn-success"><i class="fas fa-file-excel"></i> <span class="d-none d-sm-inline">Excel</span></a>'
                        . '<a href="laporan_pinjaman.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-sync"></i> <span class="d-none d-sm-inline">Reset</span></a>'
                        . '</div>',
                ]); ?>

                <?php ui_stat_grid([
                    ['label' => 'Total pinjaman', 'value' => count($pinjaman), 'tone' => 'primary', 'icon' => 'fas fa-hand-holding-usd', 'hint' => 'Hasil filter'],
                    ['label' => 'Aktif', 'value' => (int)$total_pinjaman_aktif, 'tone' => 'success', 'icon' => 'fas fa-play-circle', 'hint' => 'Belum lunas'],
                    ['label' => 'Lunas', 'value' => (int)$total_pinjaman_lunas, 'tone' => 'info', 'icon' => 'fas fa-flag-checkered', 'hint' => 'Selesai'],
                    ['label' => 'Total dibayar', 'value' => formatRupiah($total_dibayar), 'tone' => 'warning', 'icon' => 'fas fa-money-bill-wave', 'hint' => 'Akumulasi'],
                ]); ?>

                <!-- Filter Form -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-filter me-2"></i>Filter Laporan
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Filter Anggota</label>
                                    <select name="anggota_id" class="form-select">
                                        <option value="">-- Semua Anggota --</option>
                                        <?php foreach ($anggota_list as $a): ?>
                                        <option value="<?php echo $a['id']; ?>" <?php echo $filter_anggota == $a['id'] ? 'selected' : ''; ?>>
                                            <?php echo $a['kode_anggota'] . ' - ' . $a['nama_lengkap']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Filter Jenis Pinjaman</label>
                                    <select name="jenis_pinjaman_id" class="form-select">
                                        <option value="">-- Semua Jenis --</option>
                                        <?php foreach ($jenis_list as $j): ?>
                                        <option value="<?php echo $j['id']; ?>" <?php echo $filter_jenis == $j['id'] ? 'selected' : ''; ?>>
                                            <?php echo $j['nama_jenis']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Filter Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">-- Semua Status --</option>
                                        <?php foreach ($status_list as $key => $value): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $filter_status == $key ? 'selected' : ''; ?>>
                                            <?php echo $value; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search me-1"></i> Terapkan Filter
                                        </button>
                                        <a href="laporan_pinjaman.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-refresh me-1"></i> Reset
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Laporan Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-table me-2"></i>Data Pinjaman
                        </h5>
                        <div class="text-muted">
                            <?php echo count($pinjaman); ?> data ditemukan
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pinjaman)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">Tidak ada data pinjaman</h5>
                            <p class="text-muted">Tidak ada data pinjaman yang sesuai dengan filter yang dipilih.</p>
                        </div>
                        <?php else: ?>
                        <?php if (isset($pager)) ui_table_toolbar(['action'=>'laporan_pinjaman.php','q'=>$q ?? '','placeholder'=>'Cari…','per_page'=>$pager['per_page'],'reset_href'=>'laporan_pinjaman.php']); ?>
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-striped table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kode Pinjaman</th>
                                        <th>Anggota</th>
                                        <th>Jenis Pinjaman</th>
                                        <th>Jumlah Pinjaman</th>
                                        <th>Sisa Pokok</th>
                                        <th>Sudah Dibayar</th>
                                        <th>Progress</th>
                                        <th>Tenor</th>
                                        <th>Bunga</th>
                                        <th>Tanggal Cair</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_pinjaman = 0;
                                    $total_sisa = 0;
                                    $total_dibayar = 0;
                                    $total_bunga_dibayar = 0;
                                    $total_denda_dibayar = 0;
                                    
                                    foreach ($pinjaman as $p): 
                                        $total_pinjaman += $p['jumlah_pinjaman'];
                                        $total_sisa += $p['sisa_pokok'];
                                        $total_dibayar += $p['total_pokok_dibayar'];
                                        $total_bunga_dibayar += $p['total_bunga_dibayar'];
                                        $total_denda_dibayar += $p['total_denda_dibayar'];
                                        
                                        // Calculate progress percentage
                                        $progress = $p['jumlah_pinjaman'] > 0 ? ($p['total_pokok_dibayar'] / $p['jumlah_pinjaman']) * 100 : 0;
                                        $progress = min(100, max(0, $progress)); // Ensure between 0-100
                                        
                                        // Status badge color
                                        $status_class = 'bg-secondary';
                                        if ($p['status'] == 'aktif') $status_class = 'bg-warning';
                                        if ($p['status'] == 'lunas') $status_class = 'bg-success';
                                        if ($p['status'] == 'macet') $status_class = 'bg-danger';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $p['kode_pinjaman']; ?></strong>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo $p['nama_lengkap']; ?></div>
                                            <small class="text-muted"><?php echo $p['kode_anggota']; ?></small>
                                        </td>
                                        <td><?php echo $p['nama_jenis']; ?></td>
                                        <td>
                                            <div class="fw-bold text-primary"><?php echo formatRupiah($p['jumlah_pinjaman']); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-danger"><?php echo formatRupiah($p['sisa_pokok']); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-success"><?php echo formatRupiah($p['total_pokok_dibayar']); ?></div>
                                            <?php if ($p['total_bunga_dibayar'] > 0 || $p['total_denda_dibayar'] > 0): ?>
                                            <small class="text-muted">
                                                Bunga: <?php echo formatRupiah($p['total_bunga_dibayar']); ?>
                                                <?php if ($p['total_denda_dibayar'] > 0): ?>
                                                <br>Denda: <?php echo formatRupiah($p['total_denda_dibayar']); ?>
                                                <?php endif; ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1 me-2" style="width: 100px;">
                                                    <div class="progress-bar bg-success" role="progressbar" 
                                                         style="width: <?php echo $progress; ?>%" 
                                                         aria-valuenow="<?php echo $progress; ?>" 
                                                         aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <small class="text-muted"><?php echo round($progress, 1); ?>%</small>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo $p['angsuran_terbayar']; ?>/<?php echo $p['tenor']; ?> angsuran
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo labelTenorRow($p); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo labelBungaRow($p); ?></span>
                                        </td>
                                        <td>
                                            <small><?php echo formatTanggalUi($p['tanggal_pencairan']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $status_class; ?>">
                                                <?php echo ucfirst($p['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td colspan="3" class="text-end">Total:</td>
                                        <td class="text-primary"><?php echo formatRupiah($total_pinjaman); ?></td>
                                        <td class="text-danger"><?php echo formatRupiah($total_sisa); ?></td>
                                        <td class="text-success">
                                            <?php echo formatRupiah($total_dibayar); ?>
                                            <?php if ($total_bunga_dibayar > 0 || $total_denda_dibayar > 0): ?>
                                            <br>
                                            <small class="text-muted">
                                                Bunga: <?php echo formatRupiah($total_bunga_dibayar); ?>
                                                <?php if ($total_denda_dibayar > 0): ?>
                                                | Denda: <?php echo formatRupiah($total_denda_dibayar); ?>
                                                <?php endif; ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td colspan="5"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (isset($pager)) ui_pagination($pager, 'laporan_pinjaman.php'); ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

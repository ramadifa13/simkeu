<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

// Check if user has access to this page
if (!isAdmin() && !isTeller()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: dashboard.php");
    exit();
}

// Filter parameters - Default to wider date range to show more data
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-1 month'));
$end_date = $_GET['end_date'] ?? date('Y-m-d', strtotime('+6 months'));
$filter_status = $_GET['status'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_pinjaman = $_GET['pinjaman_id'] ?? '';

// Handle export PDF
if (isset($_GET['export_pdf']) && $_GET['export_pdf'] == 1) {
    $params = http_build_query([
        'start_date' => $start_date,
        'end_date' => $end_date,
        'status' => $filter_status,
        'anggota_id' => $filter_anggota,
        'pinjaman_id' => $filter_pinjaman
    ]);
    header("Location: cetak_laporan_angsuran.php?$params");
    exit();
}

// Handle export Excel
if (isset($_GET['export_excel']) && $_GET['export_excel'] == 1) {
    $params = http_build_query([
        'start_date' => $start_date,
        'end_date' => $end_date,
        'status' => $filter_status,
        'anggota_id' => $filter_anggota,
        'pinjaman_id' => $filter_pinjaman
    ]);
    header("Location: export_excel_angsuran.php?$params");
    exit();
}

// Build query - Modified to show all angsuran by default
$query = "SELECT a.*, p.kode_pinjaman, p.anggota_id, p.tanggal_pencairan,
                 ag.kode_anggota, ag.nama_lengkap,
                 jp.nama_jenis as jenis_pinjaman
          FROM angsuran a 
          JOIN pinjaman p ON a.pinjaman_id = p.id 
          JOIN anggota ag ON p.anggota_id = ag.id 
          JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id 
          WHERE 1=1";

$params = [];

// Add date filter (optional)
if (!empty($start_date) && !empty($end_date)) {
    $query .= " AND (a.tanggal_jatuh_tempo BETWEEN :tanggal_mulai AND :tanggal_selesai
                OR a.tanggal_bayar BETWEEN :bayar_mulai AND :bayar_selesai)";
    $params[':tanggal_mulai'] = $start_date;
    $params[':tanggal_selesai'] = $end_date;
    $params[':bayar_mulai'] = $start_date;
    $params[':bayar_selesai'] = $end_date;
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

// Get anggota list for filter
$query_anggota = "SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

// Get pinjaman list for filter
$query_pinjaman = "SELECT p.id, p.kode_pinjaman, a.nama_lengkap 
                   FROM pinjaman p 
                   JOIN anggota a ON p.anggota_id = a.id 
                   WHERE p.status = 'aktif' 
                   ORDER BY p.kode_pinjaman";
$stmt_pinjaman = $db->prepare($query_pinjaman);
$stmt_pinjaman->execute();
$pinjaman_list = $stmt_pinjaman->fetchAll(PDO::FETCH_ASSOC);

// Status options
$status_list = [
    '' => 'Semua Status',
    'belum_bayar' => 'Belum Bayar',
    'lunas' => 'Lunas',
    'telat' => 'Telat'
];

// Calculate totals
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

// Fungsi format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Angsuran - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .table-actions {
            white-space: nowrap;
        }
        .loan-info {
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
            padding: 10px;
            margin-bottom: 15px;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-chart-bar me-2"></i>Laporan Angsuran
                    </h1>
                    <div class="btn-group">
                        <a href="?export_pdf=1&<?php echo http_build_query($_GET); ?>" class="btn btn-sm btn-danger" target="_blank">
                            <i class="fas fa-file-pdf"></i> <span class="d-none d-sm-inline">PDF</span>
                        </a>
                        <a href="?export_excel=1&<?php echo http_build_query($_GET); ?>" class="btn btn-sm btn-success">
                            <i class="fas fa-file-excel"></i> <span class="d-none d-sm-inline">Excel</span>
                        </a>
                        <a href="laporan_angsuran.php" class="btn btn-sm btn-primary ms-2">
                            <i class="fas fa-sync"></i> <span class="d-none d-sm-inline">Reset</span>
                        </a>
                    </div>
                </div>
                <div class="alert alert-info no-print">
                    <i class="fas fa-info-circle me-1"></i>
                    Halaman ini hanya laporan (tidak bisa mengedit denda). Denda otomatis muncul pada cicilan belum lunas yang lewat jatuh tempo.
                    Untuk koreksi: perbaiki riwayat di <a href="import_migrasi.php">Import Migrasi</a> (sheet Angsuran_Historis),
                    bayar/ubah denda di <a href="angsuran.php">Angsuran</a>, atau kelola di <a href="denda.php">Denda</a>.
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo count($angsuran); ?></h4>
                                        <p>Total Angsuran</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-list fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $total_lunas; ?></h4>
                                        <p>Lunas</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $total_belum_bayar; ?></h4>
                                        <p>Belum Bayar</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-clock fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-danger text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $total_telat; ?></h4>
                                        <p>Telat Bayar</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ringkasan Nilai -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">
                                    <i class="fas fa-money-bill-wave text-primary me-1"></i>Total Nilai Angsuran
                                </h6>
                                <h4 class="fw-bold text-primary"><?php echo formatRupiah($total_angsuran); ?></h4>
                                <small class="text-muted">Dari <?php echo count($angsuran); ?> transaksi</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">
                                    <i class="fas fa-percent text-info me-1"></i>Total Pokok & Bunga
                                </h6>
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <small>Pokok</small>
                                        <h6 class="fw-bold"><?php echo formatRupiah($total_pokok); ?></h6>
                                    </div>
                                    <div>
                                        <small>Bunga</small>
                                        <h6 class="fw-bold text-info"><?php echo formatRupiah($total_bunga); ?></h6>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">
                                    <i class="fas fa-exclamation-circle text-danger me-1"></i>Total Denda
                                </h6>
                                <h4 class="fw-bold text-danger"><?php echo formatRupiah($total_denda); ?></h4>
                                <small class="text-muted">Dari <?php echo $total_telat; ?> angsuran telat</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-filter me-2"></i>Filter Laporan
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Dari Tanggal</label>
                                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Sampai Tanggal</label>
                                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <?php foreach ($status_list as $key => $value): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $filter_status == $key ? 'selected' : ''; ?>>
                                            <?php echo $value; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="mb-3">
                                    <label class="form-label">Anggota</label>
                                    <select name="anggota_id" class="form-select">
                                        <option value="">Semua Anggota</option>
                                        <?php foreach ($anggota_list as $anggota): ?>
                                        <option value="<?php echo $anggota['id']; ?>" <?php echo $filter_anggota == $anggota['id'] ? 'selected' : ''; ?>>
                                            <?php echo $anggota['kode_anggota'] . ' - ' . $anggota['nama_lengkap']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="mb-3">
                                    <label class="form-label">Pinjaman</label>
                                    <select name="pinjaman_id" class="form-select">
                                        <option value="">Semua Pinjaman</option>
                                        <?php foreach ($pinjaman_list as $pinjaman): ?>
                                        <option value="<?php echo $pinjaman['id']; ?>" <?php echo $filter_pinjaman == $pinjaman['id'] ? 'selected' : ''; ?>>
                                            <?php echo $pinjaman['kode_pinjaman'] . ' - ' . $pinjaman['nama_lengkap']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-1"></i> Terapkan Filter
                                </button>
                                <a href="laporan_angsuran.php" class="btn btn-secondary">
                                    <i class="fas fa-refresh me-1"></i> Reset
                                </a>
                                <small class="text-muted ms-2">
                                    Menampilkan <?php echo count($angsuran); ?> data angsuran
                                </small>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Laporan Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-table me-2"></i>Data Angsuran
                            <small class="text-muted">
                                (<?php echo date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)); ?>)
                            </small>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($angsuran)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Tidak ada data angsuran</h5>
                                <p class="text-muted">Tidak ditemukan data angsuran dengan filter yang dipilih.</p>
                                <a href="laporan_angsuran.php" class="btn btn-primary">
                                    <i class="fas fa-refresh me-1"></i> Tampilkan Semua Data
                                </a>
                            </div>
                        <?php else: ?>
                        
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
                        <div class="loan-info">
                            <h6 class="mb-1">
                                <i class="fas fa-file-invoice-dollar me-2"></i>
                                <?php echo $kode_pinjaman; ?>
                            </h6>
                            <p class="mb-1">
                                <strong>Anggota:</strong> <?php echo $data['kode_anggota']; ?> - <?php echo $data['anggota']; ?> |
                                <strong>Jenis:</strong> <?php echo $data['jenis_pinjaman']; ?> |
                                <strong>Total Angsuran:</strong> <?php echo count($data['angsuran']); ?>
                            </p>
                        </div>
                        
                        <div class="table-responsive mb-4">
                            <table class="table table-striped table-bordered table-sm">
                                <thead class="table-light">
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
                                    <?php foreach ($data['angsuran'] as $a): 
                                        // Status badge color
                                        $status_class = '';
                                        if ($a['status'] == 'belum_bayar') $status_class = 'bg-warning';
                                        if ($a['status'] == 'lunas') $status_class = 'bg-success';
                                        if ($a['status'] == 'telat') $status_class = 'bg-danger';
                                    ?>
                                    <tr>
                                        <td class="text-center">
                                            <span class="badge bg-primary"><?php echo $a['angsuran_ke']; ?></span>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($a['tanggal_jatuh_tempo'])); ?>
                                        </td>
                                        <td class="text-end"><?php echo formatRupiah($a['pokok']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($a['bunga']); ?></td>
                                        <td class="text-end text-danger fw-bold"><?php echo formatRupiah($a['denda']); ?></td>
                                        <td class="text-end fw-bold text-primary"><?php echo formatRupiah($a['jumlah_angsuran']); ?></td>
                                        <td>
                                            <?php if ($a['tanggal_bayar']): ?>
                                                <span class="text-success">
                                                    <?php echo date('d/m/Y', strtotime($a['tanggal_bayar'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $status_class; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $a['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($a['keterlambatan_hari'] > 0): ?>
                                                <span class="text-danger fw-bold">
                                                    +<?php echo $a['keterlambatan_hari']; ?> hari
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <hr class="my-4">
                        <?php endforeach; ?>
                        
                        <!-- Summary -->
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">Ringkasan Laporan</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <td>Periode Laporan:</td>
                                                <td class="fw-bold">
                                                    <?php echo date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)); ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>Total Data:</td>
                                                <td class="fw-bold"><?php echo count($angsuran); ?> angsuran</td>
                                            </tr>
                                            <tr>
                                                <td>Total Pinjaman:</td>
                                                <td class="fw-bold"><?php echo count($grouped_angsuran); ?> pinjaman</td>
                                            </tr>
                                            <tr>
                                                <td>Total Nilai Angsuran:</td>
                                                <td class="fw-bold text-primary"><?php echo formatRupiah($total_angsuran); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Total Denda:</td>
                                                <td class="fw-bold text-danger"><?php echo formatRupiah($total_denda); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-submit form when dates change for better UX
        document.addEventListener('DOMContentLoaded', function() {
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                input.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });
    </script>
</body>
</html>

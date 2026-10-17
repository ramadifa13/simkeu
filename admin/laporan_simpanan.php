<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

// Filter parameters - SET DEFAULT KE FEBRUARI 2026
$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_jenis = $_GET['jenis_simpanan_id'] ?? '';
$filter_tanggal_awal = $_GET['tanggal_awal'] ?? '2026-02-01'; // DEFAULT 1 FEB 2026
$filter_tanggal_akhir = $_GET['tanggal_akhir'] ?? '2026-02-28'; // DEFAULT 28 FEB 2026

// Handle export PDF
if (isset($_GET['export_pdf']) && $_GET['export_pdf'] == 1) {
    // Redirect ke halaman cetak_pdf.php dengan parameter yang sama
    $params = http_build_query([
        'anggota_id' => $filter_anggota,
        'jenis_simpanan_id' => $filter_jenis,
        'tanggal_awal' => $filter_tanggal_awal,
        'tanggal_akhir' => $filter_tanggal_akhir
    ]);
    header("Location: cetak_laporan_simpanan.php?$params");
    exit();
}

// Handle export Excel
if (isset($_GET['export_excel']) && $_GET['export_excel'] == 1) {
    // Redirect ke halaman export_excel.php dengan parameter yang sama
    $params = http_build_query([
        'anggota_id' => $filter_anggota,
        'jenis_simpanan_id' => $filter_jenis,
        'tanggal_awal' => $filter_tanggal_awal,
        'tanggal_akhir' => $filter_tanggal_akhir
    ]);
    header("Location: export_excel_simpanan.php?$params");
    exit();
}

// Get data for filters - HANYA JENIS SIMPANAN REAL (EXCLUDE ANG)
$query_anggota = "SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

$query_jenis = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' AND kode_jenis != 'ANG' ORDER BY id";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_list = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// HITUNG SALDO DARI TRANSAKSI - EXCLUDE JENIS ANG
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

// Hitung total per jenis dari transaksi - EXCLUDE JENIS ANG
$query_summary = "SELECT 
    j.kode_jenis,
    j.nama_jenis,
    COUNT(DISTINCT t.anggota_id) as jumlah_anggota,
    SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END) as total_saldo
FROM transaksi t
JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
WHERE DATE(t.tanggal_transaksi) BETWEEN :tanggal_awal AND :tanggal_akhir
AND j.kode_jenis != 'ANG'
GROUP BY j.kode_jenis, j.nama_jenis
HAVING total_saldo != 0
ORDER BY j.kode_jenis";

$stmt_summary = $db->prepare($query_summary);
$stmt_summary->bindParam(':tanggal_awal', $filter_tanggal_awal);
$stmt_summary->bindParam(':tanggal_akhir', $filter_tanggal_akhir);
$stmt_summary->execute();
$summary_per_jenis = $stmt_summary->fetchAll(PDO::FETCH_ASSOC);

// Hitung total keseluruhan
$total_semua = array_sum(array_column($summary_per_jenis, 'total_saldo'));

// Fungsi format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Laporan Simpanan - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --info: #17a2b8;
            --warning: #f39c12;
            --danger: #e74c3c;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        @media (max-width: 767.98px) {
            main {
                margin-left: 0 !important;
                padding: 10px 12px !important;
                width: 100%;
                margin-top: 60px !important;
            }
            
            .table td, .table th {
                font-size: 0.7rem;
                padding: 0.3rem;
            }
        }
        
        @media (min-width: 992px) {
            main {
                margin-left: 280px !important;
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
        
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            background: white;
            padding: 15px;
            height: 100%;
            border-left: 4px solid var(--primary);
        }
        
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.info { border-left-color: var(--info); }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.85rem;
        }
        
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.8rem;
            text-transform: uppercase;
            background-color: #f8f9fa;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .badge-pokok { background-color: #2c3e50; color: white; }
        .badge-wajib { background-color: #3498db; color: white; }
        .badge-sukarela { background-color: #27ae60; color: white; }
        .badge-deposito { background-color: #f39c12; color: white; }
        .badge-haji { background-color: #9b59b6; color: white; }
        .badge-umroh { background-color: #1abc9c; color: white; }
        
        .info-periode {
            background: #e8f4fd;
            border-radius: 5px;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-left: 4px solid #3498db;
        }
        
        .info-simpanan {
            background: #e6f7e6;
            border-radius: 5px;
            padding: 5px 10px;
            border-left: 4px solid #28a745;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-piggy-bank text-success me-2"></i>Laporan Simpanan
                        <small class="text-muted fs-6 ms-2">(Tidak termasuk Angsuran)</small>
                    </h1>
                    <div class="btn-group">
                        <a href="?export_pdf=1&<?php echo http_build_query($_GET); ?>" class="btn btn-sm btn-danger" target="_blank">
                            <i class="fas fa-file-pdf"></i> <span class="d-none d-sm-inline">PDF</span>
                        </a>
                        <a href="?export_excel=1&<?php echo http_build_query($_GET); ?>" class="btn btn-sm btn-success">
                            <i class="fas fa-file-excel"></i> <span class="d-none d-sm-inline">Excel</span>
                        </a>
                        <a href="laporan_simpanan.php" class="btn btn-sm btn-primary ms-2">
                            <i class="fas fa-sync"></i> <span class="d-none d-sm-inline">Reset</span>
                        </a>
                    </div>
                </div>

                <!-- Info Periode -->
                <div class="info-periode">
                    <i class="fas fa-calendar-alt me-2 text-primary"></i>
                    <strong>Periode:</strong> <?php echo date('d/m/Y', strtotime($filter_tanggal_awal)); ?> s/d <?php echo date('d/m/Y', strtotime($filter_tanggal_akhir)); ?>
                    <?php if (empty($filter_anggota) && empty($filter_jenis)): ?>
                        <span class="badge bg-info ms-2">Semua Anggota & Jenis Simpanan</span>
                    <?php endif; ?>
                    <span class="badge bg-success ms-2">Simpanan Real (Non-Angsuran)</span>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="stat-card success">
                            <div class="stat-value text-success"><?php echo formatRupiah($total_semua); ?></div>
                            <div class="stat-label">Total Simpanan Periode Ini</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="stat-value text-primary"><?php echo count($simpanan); ?></div>
                            <div class="stat-label">Jumlah Record Simpanan</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card info">
                            <div class="stat-value text-info"><?php echo count($summary_per_jenis); ?></div>
                            <div class="stat-label">Jenis Simpanan Aktif</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Tanggal Awal</label>
                            <input type="date" name="tanggal_awal" class="form-control form-control-sm" value="<?php echo $filter_tanggal_awal; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Tanggal Akhir</label>
                            <input type="date" name="tanggal_akhir" class="form-control form-control-sm" value="<?php echo $filter_tanggal_akhir; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Anggota</label>
                            <select name="anggota_id" class="form-select form-select-sm">
                                <option value="">Semua Anggota</option>
                                <?php foreach ($anggota_list as $a): ?>
                                <option value="<?php echo $a['id']; ?>" <?php echo $filter_anggota == $a['id'] ? 'selected' : ''; ?>>
                                    <?php echo $a['kode_anggota'] . ' - ' . $a['nama_lengkap']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Jenis Simpanan</label>
                            <select name="jenis_simpanan_id" class="form-select form-select-sm">
                                <option value="">Semua Jenis</option>
                                <?php foreach ($jenis_list as $j): ?>
                                <option value="<?php echo $j['id']; ?>" <?php echo $filter_jenis == $j['id'] ? 'selected' : ''; ?>>
                                    <?php echo $j['nama_jenis']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-search me-1"></i> Tampilkan
                            </button>
                            <a href="laporan_simpanan.php" class="btn btn-secondary btn-sm">
                                <i class="fas fa-undo me-1"></i> Reset ke Februari 2026
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Summary per Jenis -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-chart-pie text-primary me-2"></i>Ringkasan per Jenis Simpanan <small class="text-muted">(Non-Angsuran)</small></h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($summary_per_jenis)): ?>
                            <div class="text-center py-3">
                                <p class="text-muted">Tidak ada data simpanan untuk periode ini</p>
                            </div>
                        <?php else: ?>
                        <div class="row">
                            <?php foreach ($summary_per_jenis as $s): 
                                $badgeClass = '';
                                switch ($s['kode_jenis']) {
                                    case 'POKOK': $badgeClass = 'badge-pokok'; break;
                                    case 'WAJIB': $badgeClass = 'badge-wajib'; break;
                                    case 'SUKARELA': $badgeClass = 'badge-sukarela'; break;
                                    case 'DEPOSITO': $badgeClass = 'badge-deposito'; break;
                                    case 'HAJI': $badgeClass = 'badge-haji'; break;
                                    case 'UMROH': $badgeClass = 'badge-umroh'; break;
                                    default: $badgeClass = 'bg-secondary';
                                }
                            ?>
                            <div class="col-md-4 col-6 mb-3">
                                <div class="p-3 bg-light rounded">
                                    <span class="badge <?php echo $badgeClass; ?> mb-2"><?php echo $s['kode_jenis']; ?></span>
                                    <h6 class="fw-bold text-success"><?php echo formatRupiah($s['total_saldo']); ?></h6>
                                    <small class="text-muted"><?php echo $s['nama_jenis']; ?> (<?php echo $s['jumlah_anggota']; ?> anggota)</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tabel Simpanan -->
                <div class="card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-list me-2"></i>Detail Simpanan Anggota <small class="text-muted">(Non-Angsuran)</small></span>
                        <span class="badge bg-primary"><?php echo count($simpanan); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($simpanan)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-piggy-bank fa-4x text-muted mb-3"></i>
                                <h5 class="text-muted">Tidak ada data simpanan</h5>
                                <p class="text-muted mb-3">Untuk periode <?php echo date('d/m/Y', strtotime($filter_tanggal_awal)); ?> - <?php echo date('d/m/Y', strtotime($filter_tanggal_akhir)); ?></p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Kode</th>
                                        <th>Nama Anggota</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Saldo</th>
                                        <th class="text-end">Minimal</th>
                                        <th class="text-end">Maksimal</th>
                                        <th class="text-center">Bagi Hasil</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $no = 1;
                                    $total_display = 0;
                                    foreach ($simpanan as $s): 
                                        $total_display += $s['saldo'];
                                        
                                        $badgeClass = '';
                                        switch ($s['kode_jenis']) {
                                            case 'POKOK': $badgeClass = 'badge-pokok'; break;
                                            case 'WAJIB': $badgeClass = 'badge-wajib'; break;
                                            case 'SUKARELA': $badgeClass = 'badge-sukarela'; break;
                                            case 'DEPOSITO': $badgeClass = 'badge-deposito'; break;
                                            case 'HAJI': $badgeClass = 'badge-haji'; break;
                                            case 'UMROH': $badgeClass = 'badge-umroh'; break;
                                            default: $badgeClass = 'bg-secondary';
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo $s['kode_anggota']; ?></td>
                                        <td><?php echo htmlspecialchars($s['nama_lengkap']); ?></td>
                                        <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $s['kode_jenis']; ?></span></td>
                                        <td class="text-end fw-bold text-success"><?php echo formatRupiah($s['saldo']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($s['minimum']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($s['maksimum']); ?></td>
                                        <td class="text-center"><?php echo $s['bagi_hasil']; ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="4" class="text-end">TOTAL</th>
                                        <th class="text-end text-success"><?php echo formatRupiah($total_display); ?></th>
                                        <th colspan="3"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
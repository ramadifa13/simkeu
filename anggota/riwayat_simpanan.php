<?php
require_once '../includes/auth.php';
checkAnggota();

$anggota_id = $_SESSION['user_id'];

// Get anggota data
$query_anggota = "SELECT a.* FROM anggota a WHERE a.user_id = :user_id";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->bindParam(':user_id', $anggota_id);
$stmt_anggota->execute();
$anggota_data = $stmt_anggota->fetch(PDO::FETCH_ASSOC);

// Get filter parameters (opsional)
$filter_jenis = $_GET['jenis_simpanan'] ?? '';

// Get earliest and latest transaction dates for info
$query_tanggal = "SELECT MIN(DATE(tanggal_transaksi)) as earliest, MAX(DATE(tanggal_transaksi)) as latest
                  FROM transaksi t
                  JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id
                  WHERE t.anggota_id = :anggota_id AND js.kode_jenis != 'ANG'";
$stmt_tanggal = $db->prepare($query_tanggal);
$stmt_tanggal->bindParam(':anggota_id', $anggota_data['id']);
$stmt_tanggal->execute();
$tanggal_range = $stmt_tanggal->fetch(PDO::FETCH_ASSOC);

$earliest_date = $tanggal_range['earliest'] ?? date('Y-m-d');
$latest_date = $tanggal_range['latest'] ?? date('Y-m-d');

// Get all jenis simpanan aktif (untuk referensi)
$query_all_jenis = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' AND kode_jenis != 'ANG' ORDER BY id";
$stmt_all_jenis = $db->prepare($query_all_jenis);
$stmt_all_jenis->execute();
$all_jenis = $stmt_all_jenis->fetchAll(PDO::FETCH_ASSOC);

// Get current saldo per jenis (sinkron dengan simpanan.php)
$query_saldo_saat_ini = "SELECT js.id, js.kode_jenis, js.nama_jenis, COALESCE(s.saldo, 0) as saldo
                         FROM jenis_simpanan js
                         LEFT JOIN simpanan s ON s.jenis_simpanan_id = js.id AND s.anggota_id = :anggota_id
                         WHERE js.status = 'aktif' AND js.kode_jenis != 'ANG'
                         ORDER BY js.id";
$stmt_saldo = $db->prepare($query_saldo_saat_ini);
$stmt_saldo->bindParam(':anggota_id', $anggota_data['id']);
$stmt_saldo->execute();
$saldo_saat_ini = $stmt_saldo->fetchAll(PDO::FETCH_ASSOC);

$total_saldo_keseluruhan = 0;
$jenis_dengan_saldo = [];
foreach ($saldo_saat_ini as $s) {
    if ($s['saldo'] > 0) {
        $total_saldo_keseluruhan += $s['saldo'];
        $jenis_dengan_saldo[] = $s;
    }
}

// Build query untuk SEMUA TRANSAKSI (tanpa filter tanggal)
$query = "SELECT t.*, js.nama_jenis, js.kode_jenis
          FROM transaksi t 
          JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id 
          WHERE t.anggota_id = :anggota_id 
            AND js.kode_jenis != 'ANG'";

if (!empty($filter_jenis)) {
    $query .= " AND t.jenis_simpanan_id = :jenis_simpanan";
}

$query .= " ORDER BY t.tanggal_transaksi DESC";

$stmt = $db->prepare($query);
$stmt->bindParam(':anggota_id', $anggota_data['id']);

if (!empty($filter_jenis)) {
    $stmt->bindParam(':jenis_simpanan', $filter_jenis);
}

$stmt->execute();
$riwayat = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get jenis simpanan untuk filter (dari semua transaksi dan simpanan)
$query_jenis = "SELECT DISTINCT js.id, js.nama_jenis, js.kode_jenis
                FROM transaksi t 
                JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id 
                WHERE t.anggota_id = :anggota_id 
                  AND js.kode_jenis != 'ANG'
                UNION
                SELECT js.id, js.nama_jenis, js.kode_jenis
                FROM simpanan s
                JOIN jenis_simpanan js ON s.jenis_simpanan_id = js.id
                WHERE s.anggota_id = :anggota_id2 AND js.kode_jenis != 'ANG' AND s.saldo > 0
                ORDER BY nama_jenis";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->bindParam(':anggota_id', $anggota_data['id']);
$stmt_jenis->bindParam(':anggota_id2', $anggota_data['id']);
$stmt_jenis->execute();
$jenis_list = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// Hitung total setoran dan penarikan untuk SEMUA transaksi
$total_setoran = 0;
$total_penarikan = 0;
$total_setoran_deposito = 0;
$total_setoran_haji = 0;
$total_transaksi_deposito = 0;
$total_transaksi_haji = 0;
$saldo_terakhir = 0; // Untuk menyimpan saldo terakhir (setelah semua transaksi)

foreach ($riwayat as $transaksi) {
    if ($transaksi['jenis_transaksi'] == 'setoran') {
        $total_setoran += $transaksi['jumlah'];
        if ($transaksi['kode_jenis'] == 'DEPOSITO') {
            $total_setoran_deposito += $transaksi['jumlah'];
            $total_transaksi_deposito++;
        } elseif ($transaksi['kode_jenis'] == 'HAJI') {
            $total_setoran_haji += $transaksi['jumlah'];
            $total_transaksi_haji++;
        }
    } else {
        $total_penarikan += $transaksi['jumlah'];
    }
    
    // Saldo terakhir adalah saldo_sesudah dari transaksi terbaru (pertama dalam loop)
    if (!isset($saldo_terakhir) && $transaksi === reset($riwayat)) {
        $saldo_terakhir = $transaksi['saldo_sesudah'];
    }
}

// Jika tidak ada transaksi, gunakan total saldo keseluruhan
if (empty($riwayat)) {
    $saldo_terakhir = $total_saldo_keseluruhan;
}

// Fungsi format rupiah
function formatRupiah($angka) {
    if ($angka == 0) {
        return 'Rp 0';
    }
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Simpanan - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .main-content {
            margin-left: 280px;
            margin-top: 10px;
            padding: 15px;
        }
        .page-header {
            padding-top: 5px;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .filter-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn-pdf {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
        }
        .btn-pdf:hover {
            background: linear-gradient(135deg, #c82333, #b21f2d);
            color: white;
        }
        .summary-card {
            background-color: #f8f9fa;
            border-left: 4px solid #17a2b8;
            border-radius: 5px;
            padding: 10px;
        }
        .badge-deposito { background-color: #f39c12; color: white; }
        .badge-haji { background-color: #9b59b6; color: white; }
        .badge-umroh { background-color: #1abc9c; color: white; }
        .saldo-card {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .info-box {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 15px;
        }
        .periode-info {
            font-size: 0.9rem;
            background-color: #e9ecef;
            padding: 8px 12px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .stat-per-jenis {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 8px;
            margin-bottom: 10px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 60px;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center page-header border-bottom">
                    <h1 class="h3">
                        <i class="fas fa-history me-2"></i>Riwayat Simpanan
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">

                    </div>
                </div>

                <!-- Informasi Rentang Transaksi -->
                <div class="periode-info">
                    <i class="fas fa-calendar-alt me-2 text-primary"></i>
                    <strong>Rentang Transaksi:</strong> 
                    <?php echo date('d/m/Y', strtotime($earliest_date)); ?> - <?php echo date('d/m/Y', strtotime($latest_date)); ?>
                    <?php if (!empty($filter_jenis)): ?>
                        <?php 
                        $selected_jenis = array_filter($jenis_list, function($j) use ($filter_jenis) {
                            return $j['id'] == $filter_jenis;
                        });
                        $selected = reset($selected_jenis);
                        ?>
                        <span class="badge bg-info ms-2">Filter: <?php echo $selected['nama_jenis'] ?? ''; ?></span>
                        <a href="riwayat_simpanan.php" class="btn btn-sm btn-link p-0 ms-2 text-danger">
                            <i class="fas fa-times"></i> Hapus Filter
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Saldo Saat Ini (Sinkron dengan simpanan.php) -->
                <div class="saldo-card">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="mb-3">
                                <i class="fas fa-piggy-bank me-2"></i>Saldo Simpanan Saat Ini
                                <span class="badge bg-light text-dark ms-2"><?php echo date('d/m/Y'); ?></span>
                            </h5>
                            <div class="row">
                                <?php 
                                $total_saldo_display = 0;
                                foreach ($saldo_saat_ini as $saldo): 
                                    if ($saldo['saldo'] > 0): 
                                        $total_saldo_display += $saldo['saldo'];
                                        $badgeClass = '';
                                        if ($saldo['kode_jenis'] == 'DEPOSITO') $badgeClass = 'badge-deposito';
                                        elseif ($saldo['kode_jenis'] == 'HAJI') $badgeClass = 'badge-haji';
                                        elseif ($saldo['kode_jenis'] == 'UMROH') $badgeClass = 'badge-umroh';
                                        else $badgeClass = 'bg-secondary';
                                ?>
                                <div class="col-md-4 col-6 mb-2">
                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo $saldo['kode_jenis']; ?></span>
                                    <h6 class="fw-bold mt-1 text-white"><?php echo formatRupiah($saldo['saldo']); ?></h6>
                                    <small class="text-white-50"><?php echo $saldo['nama_jenis']; ?></small>
                                </div>
                                <?php endif; endforeach; ?>
                                <div class="col-12 mt-2 pt-2 border-top border-light">
                                    <div class="d-flex justify-content-between">
                                        <strong>Total Simpanan:</strong>
                                        <strong class="text-warning fs-5"><?php echo formatRupiah($total_saldo_display); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <i class="fas fa-piggy-bank fa-5x opacity-50"></i>
                        </div>
                    </div>
                </div>

                <!-- Filter Form (Hanya filter jenis) -->
                <div class="card filter-card mb-3">
                    <div class="card-header py-2">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-filter me-2"></i>Filter Berdasarkan Jenis Simpanan
                        </h6>
                    </div>
                    <div class="card-body p-3">
                        <form method="GET" class="row g-2">
                            <div class="col-md-4">
                                <select name="jenis_simpanan" class="form-select form-select-sm">
                                    <option value="">Semua Jenis Simpanan</option>
                                    <?php foreach ($jenis_list as $jenis): 
                                        $badgeClass = '';
                                        if ($jenis['kode_jenis'] == 'DEPOSITO') $badgeClass = 'badge-deposito';
                                        elseif ($jenis['kode_jenis'] == 'HAJI') $badgeClass = 'badge-haji';
                                        elseif ($jenis['kode_jenis'] == 'UMROH') $badgeClass = 'badge-umroh';
                                    ?>
                                    <option value="<?php echo $jenis['id']; ?>" <?php echo $filter_jenis == $jenis['id'] ? 'selected' : ''; ?>>
                                        <?php echo $jenis['nama_jenis']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                    <i class="fas fa-search me-1"></i> Filter
                                </button>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Menampilkan <?php echo count($riwayat); ?> dari <?php echo count($riwayat); ?> transaksi
                                </small>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Summary Cards - Ringkasan Semua Transaksi -->
                <div class="row mb-3">
                    <div class="col-md-3 mb-2">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <small class="text-muted">Total Transaksi</small>
                                    <h5 class="text-primary mb-0"><?php echo count($riwayat); ?></h5>
                                </div>
                                <i class="fas fa-exchange-alt text-primary"></i>
                            </div>
                            <small class="text-muted">Semua transaksi</small>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <small class="text-muted">Total Setoran</small>
                                    <h5 class="text-success mb-0"><?php echo formatRupiah($total_setoran); ?></h5>
                                </div>
                                <i class="fas fa-arrow-down text-success"></i>
                            </div>
                            <small class="text-muted"><?php echo count(array_filter($riwayat, function($t) { return $t['jenis_transaksi'] == 'setoran'; })); ?> transaksi</small>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <small class="text-muted">Total Penarikan</small>
                                    <h5 class="text-danger mb-0"><?php echo formatRupiah($total_penarikan); ?></h5>
                                </div>
                                <i class="fas fa-arrow-up text-danger"></i>
                            </div>
                            <small class="text-muted"><?php echo count(array_filter($riwayat, function($t) { return $t['jenis_transaksi'] == 'penarikan'; })); ?> transaksi</small>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <small class="text-muted">Saldo Akhir</small>
                                    <h5 class="text-info mb-0"><?php echo formatRupiah($total_saldo_display); ?></h5>
                                </div>
                                <i class="fas fa-wallet text-info"></i>
                            </div>
                            <small class="text-muted">Total semua simpanan</small>
                        </div>
                    </div>
                </div>

                <!-- Statistik per Jenis -->
                <?php if (count($jenis_dengan_saldo) > 0): ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header py-2 bg-light">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-chart-pie me-2 text-primary"></i>Ringkasan per Jenis Simpanan
                                </h6>
                            </div>
                            <div class="card-body p-2">
                                <div class="row">
                                    <?php foreach ($jenis_dengan_saldo as $jenis): 
                                        $badgeClass = '';
                                        if ($jenis['kode_jenis'] == 'DEPOSITO') $badgeClass = 'badge-deposito';
                                        elseif ($jenis['kode_jenis'] == 'HAJI') $badgeClass = 'badge-haji';
                                        elseif ($jenis['kode_jenis'] == 'UMROH') $badgeClass = 'badge-umroh';
                                        else $badgeClass = 'bg-secondary';
                                        
                                        // Hitung jumlah transaksi untuk jenis ini
                                        $jml_transaksi = count(array_filter($riwayat, function($t) use ($jenis) {
                                            return $t['kode_jenis'] == $jenis['kode_jenis'];
                                        }));
                                        
                                        // Hitung total setoran untuk jenis ini
                                        $total_setoran_jenis = array_reduce($riwayat, function($carry, $t) use ($jenis) {
                                            if ($t['kode_jenis'] == $jenis['kode_jenis'] && $t['jenis_transaksi'] == 'setoran') {
                                                return $carry + $t['jumlah'];
                                            }
                                            return $carry;
                                        }, 0);
                                    ?>
                                    <div class="col-md-4 col-6 mb-2">
                                        <div class="stat-per-jenis">
                                            <div class="d-flex align-items-center">
                                                <span class="badge <?php echo $badgeClass; ?> me-2"><?php echo $jenis['kode_jenis']; ?></span>
                                                <small class="text-muted"><?php echo $jml_transaksi; ?> transaksi</small>
                                            </div>
                                            <div class="d-flex justify-content-between mt-1">
                                                <small>Total Setoran:</small>
                                                <strong class="text-success"><?php echo formatRupiah($total_setoran_jenis); ?></strong>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <small>Saldo Saat Ini:</small>
                                                <strong class="text-primary"><?php echo formatRupiah($jenis['saldo']); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Riwayat Table -->
                <div class="card">
                    <div class="card-header py-2 bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-list me-2 text-primary"></i>Daftar Semua Transaksi
                            </h6>
                            <div>
                                <span class="badge bg-primary me-2"><?php echo count($riwayat); ?> transaksi</span>
                                <?php if (!empty($filter_jenis)): ?>
                                    <a href="riwayat_simpanan.php" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-times me-1"></i> Hapus Filter
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($riwayat)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>No</th>
                                            <th>Tanggal</th>
                                            <th>Kode Transaksi</th>
                                            <th>Jenis Simpanan</th>
                                            <th>Transaksi</th>
                                            <th class="text-end">Jumlah</th>
                                            <th class="text-end">Saldo Sesudah</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $no = 1;
                                        foreach ($riwayat as $transaksi): 
                                            $badgeClass = '';
                                            if ($transaksi['kode_jenis'] == 'DEPOSITO') $badgeClass = 'badge-deposito';
                                            elseif ($transaksi['kode_jenis'] == 'HAJI') $badgeClass = 'badge-haji';
                                            elseif ($transaksi['kode_jenis'] == 'UMROH') $badgeClass = 'badge-umroh';
                                            else $badgeClass = 'bg-secondary';
                                        ?>
                                        <tr>
                                            <td class="text-center"><?php echo $no++; ?></td>
                                            <td><small><?php echo date('d/m/Y H:i', strtotime($transaksi['tanggal_transaksi'])); ?></small></td>
                                            <td><small><code><?php echo $transaksi['kode_transaksi']; ?></code></small></td>
                                            <td>
                                                <span class="badge <?php echo $badgeClass; ?>">
                                                    <?php echo $transaksi['kode_jenis']; ?>
                                                </span>
                                                <small class="ms-1"><?php echo $transaksi['nama_jenis']; ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'success' : 'warning'; ?>">
                                                    <small><?php echo ucfirst($transaksi['jenis_transaksi']); ?></small>
                                                </span>
                                            </td>
                                            <td class="text-end fw-bold text-<?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'success' : 'warning'; ?>">
                                                <small><?php echo $transaksi['jenis_transaksi'] == 'setoran' ? '+' : '-'; ?>
                                                <?php echo formatRupiah($transaksi['jumlah']); ?></small>
                                            </td>
                                            <td class="text-end"><small><?php echo formatRupiah($transaksi['saldo_sesudah']); ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="5" class="text-end">TOTAL SETORAN / PENARIKAN</td>
                                            <td class="text-end text-success">+<?php echo formatRupiah($total_setoran); ?></td>
                                            <td class="text-end text-danger">-<?php echo formatRupiah($total_penarikan); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-search fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Tidak ada transaksi</h5>
                                <p class="text-muted mb-3">Belum ada transaksi simpanan.</p>
                                <?php if ($total_saldo_display > 0): ?>
                                <div class="alert alert-info d-inline-block py-2 px-3">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Saldo simpanan Anda saat ini: <strong class="text-primary"><?php echo formatRupiah($total_saldo_display); ?></strong>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body py-2">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <div class="alert alert-info py-2 mb-0">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <small>
                                                <strong>Info:</strong> 
                                                Menampilkan <strong><?php echo count($riwayat); ?> transaksi</strong> dari 
                                                <strong><?php echo count($jenis_dengan_saldo); ?> jenis simpanan</strong>.
                                                Total simpanan: <strong><?php echo formatRupiah($total_saldo_display); ?></strong>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                                        </a>
                                        <a href="simpanan.php" class="btn btn-sm btn-outline-primary ms-1">
                                            <i class="fas fa-piggy-bank me-1"></i> Simpanan
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
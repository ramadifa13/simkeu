<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses teller
if (!isTeller()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

$page_title = "Laporan Simpanan";

// Get date range or default to current month
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Handle export PDF
if (isset($_GET['export_pdf']) && $_GET['export_pdf'] == 1) {
    $params = http_build_query([
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
    header("Location: cetak_laporan_simpanan_teller.php?$params");
    exit();
}

// Handle export Excel
if (isset($_GET['export_excel']) && $_GET['export_excel'] == 1) {
    $params = http_build_query([
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
    header("Location: export_excel_simpanan_teller.php?$params");
    exit();
}

// ============================================
// AMBIL DATA JENIS SIMPANAN DARI DATABASE
// ============================================
$query_jenis = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' AND kode_jenis NOT IN ('ANG','BONUS','SHU') ORDER BY id";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// STATISTIK TOTAL
// ============================================
$query_total = "SELECT 
    COUNT(*) as total_transaksi,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE 0 END), 0) as total_setoran,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'penarikan' THEN t.jumlah ELSE 0 END), 0) as total_penarikan
    FROM transaksi t
    JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
    WHERE DATE(t.tanggal_transaksi) BETWEEN :start_date AND :end_date AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')";
$stmt_total = $db->prepare($query_total);
$stmt_total->bindParam(':start_date', $start_date);
$stmt_total->bindParam(':end_date', $end_date);
$stmt_total->execute();
$total_stats = $stmt_total->fetch(PDO::FETCH_ASSOC);

if (!$total_stats) {
    $total_stats = [
        'total_transaksi' => 0,
        'total_setoran' => 0,
        'total_penarikan' => 0
    ];
}

// ============================================
// SALDO AKHIR PER JENIS SIMPANAN
// ============================================
$query_saldo = "SELECT 
    j.kode_jenis,
    COALESCE(SUM(s.saldo), 0) as saldo_akhir
    FROM jenis_simpanan j
    LEFT JOIN simpanan s ON j.id = s.jenis_simpanan_id
    WHERE j.status = 'aktif' AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
    GROUP BY j.id, j.kode_jenis
    ORDER BY j.id";
$stmt_saldo = $db->prepare($query_saldo);
$stmt_saldo->execute();
$saldo_list = $stmt_saldo->fetchAll(PDO::FETCH_ASSOC);

// Buat array saldo dengan kode_jenis sebagai key
$saldo_map = [];
foreach ($saldo_list as $s) {
    $saldo_map[$s['kode_jenis']] = $s['saldo_akhir'];
}

// ============================================
// DETAIL TRANSAKSI
// ============================================
$query_transactions = "SELECT t.*, 
                              a.nama_lengkap,
                              a.kode_anggota,
                              j.kode_jenis,
                              j.nama_jenis,
                              u.nama_lengkap as teller
                      FROM transaksi t 
                      JOIN anggota a ON t.anggota_id = a.id 
                      JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                      JOIN users u ON t.user_id = u.id
                      WHERE DATE(t.tanggal_transaksi) BETWEEN :start_date AND :end_date 
                          AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
                      ORDER BY t.tanggal_transaksi DESC";
$stmt_transactions = $db->prepare($query_transactions);
$stmt_transactions->bindParam(':start_date', $start_date);
$stmt_transactions->bindParam(':end_date', $end_date);
$stmt_transactions->execute();
$transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

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
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
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
            --light: #ecf0f1;
            --dark: #34495e;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }
        
        @media (min-width: 992px) {
            main {
                margin-left: 280px !important;
                padding: 20px !important;
            }
        }
        
        @media (max-width: 767.98px) {
            main {
                margin-left: 0 !important;
                padding: 10px 12px !important;
                width: 100%;
                margin-top: 60px !important;
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
        
        .date-filter {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 16px;
            color: white;
            padding: 1.5rem;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            height: 100%;
        }
        
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: white;
            border-bottom: 1px solid #f0f0f0;
            padding: 1rem 1.25rem;
        }
        
        .savings-card {
            border-left: 4px solid;
            background: white;
            border-radius: 12px;
            padding: 1rem;
            height: 100%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        
        .savings-card:hover {
            transform: translateY(-3px);
        }
        
        .savings-card.pokok { border-left-color: #2c3e50; }
        .savings-card.wajib { border-left-color: #3498db; }
        .savings-card.sukarela { border-left-color: #27ae60; }
        .savings-card.deposito { border-left-color: #f39c12; }
        .savings-card.haji { border-left-color: #9b59b6; }
        .savings-card.umroh { border-left-color: #1abc9c; }
        
        .badge-pokok { background-color: #2c3e50; color: white; }
        .badge-wajib { background-color: #3498db; color: white; }
        .badge-sukarela { background-color: #27ae60; color: white; }
        .badge-deposito { background-color: #f39c12; color: white; }
        .badge-haji { background-color: #9b59b6; color: white; }
        .badge-umroh { background-color: #1abc9c; color: white; }
        
        .badge-transaction {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.8rem;
            text-transform: uppercase;
            background-color: #f8f9fa;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .amount-positive {
            color: #27ae60;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #e74c3c;
            font-weight: 600;
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
        
        .btn-pdf {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
        }
        
        .btn-pdf:hover {
            background: linear-gradient(135deg, #c82333, #b21f2d);
            color: white;
        }
        
        .btn-excel {
            background: linear-gradient(135deg, #28a745, #218838);
            color: white;
            border: none;
        }
        
        .btn-excel:hover {
            background: linear-gradient(135deg, #218838, #1e7e34);
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
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-piggy-bank text-primary me-2"></i><?php echo $page_title; ?>
                    </h1>
                    <div class="btn-group">
                        <a href="?export_pdf=1&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-sm btn-pdf" target="_blank">
                            <i class="fas fa-file-pdf me-1"></i> <span class="d-none d-sm-inline">PDF</span>
                        </a>
                        <a href="?export_excel=1&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-sm btn-excel">
                            <i class="fas fa-file-excel me-1"></i> <span class="d-none d-sm-inline">Excel</span>
                        </a>
                        <a href="laporan_simpanan.php" class="btn btn-sm btn-outline-primary ms-2">
                            <i class="fas fa-sync"></i> <span class="d-none d-sm-inline">Reset</span>
                        </a>
                    </div>
                </div>

                <!-- Date Filter -->
                <div class="date-filter">
                    <div class="row align-items-center">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <h4 class="mb-2">
                                <i class="fas fa-calendar me-2"></i>
                                Periode Laporan
                            </h4>
                            <p class="mb-0 opacity-75">Pilih periode untuk melihat laporan simpanan</p>
                        </div>
                        <div class="col-md-6">
                            <form method="GET" class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label text-white">Dari Tanggal</label>
                                    <input type="date" class="form-control form-control-sm" name="start_date" value="<?php echo $start_date; ?>">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label text-white">Sampai Tanggal</label>
                                    <input type="date" class="form-control form-control-sm" name="end_date" value="<?php echo $end_date; ?>">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-light w-100">
                                        <i class="fas fa-filter me-1"></i>Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Total Statistics -->
                <div class="row mb-4">
                    <div class="col-xl-4 col-md-4 mb-4">
                        <div class="stat-card bg-primary text-white p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-white-50 mb-2">TOTAL TRANSAKSI</h6>
                                    <h3 class="fw-bold"><?php echo number_format($total_stats['total_transaksi']); ?></h3>
                                </div>
                                <i class="fas fa-exchange-alt fa-3x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-4 col-md-4 mb-4">
                        <div class="stat-card bg-success text-white p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-white-50 mb-2">TOTAL SETORAN</h6>
                                    <h3 class="fw-bold"><?php echo formatRupiah($total_stats['total_setoran']); ?></h3>
                                </div>
                                <i class="fas fa-arrow-down fa-3x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-4 col-md-4 mb-4">
                        <div class="stat-card bg-warning text-white p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-white-50 mb-2">TOTAL PENARIKAN</h6>
                                    <h3 class="fw-bold"><?php echo formatRupiah($total_stats['total_penarikan']); ?></h3>
                                </div>
                                <i class="fas fa-arrow-up fa-3x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rekap per Jenis Simpanan -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-pie me-2"></i>Rekap per Jenis Simpanan
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php 
                                    // Ikon untuk setiap jenis simpanan
                                    $icons = [
                                        'POKOK' => 'lock',
                                        'WAJIB' => 'calendar-check',
                                        'SUKARELA' => 'hand-holding-heart',
                                        'DEPOSITO' => 'clock',
                                        'HAJI' => 'mosque',
                                        'UMROH' => 'kaaba'
                                    ];
                                    
                                    // Warna untuk setiap jenis simpanan
                                    $colors = [
                                        'POKOK' => '#2c3e50',
                                        'WAJIB' => '#3498db',
                                        'SUKARELA' => '#27ae60',
                                        'DEPOSITO' => '#f39c12',
                                        'HAJI' => '#9b59b6',
                                        'UMROH' => '#1abc9c'
                                    ];
                                    
                                    // Loop untuk menampilkan semua jenis simpanan
                                    foreach ($jenis_simpanan as $jenis): 
                                        $kode = $jenis['kode_jenis'];
                                        $nama = $jenis['nama_jenis'];
                                        $icon = $icons[$kode] ?? 'piggy-bank';
                                        $color = $colors[$kode] ?? '#95a5a6';
                                        
                                        // Hitung total setoran untuk jenis ini
                                        $query_setoran = "SELECT COALESCE(SUM(jumlah), 0) as total 
                                                          FROM transaksi 
                                                          WHERE jenis_simpanan_id = :id 
                                                          AND jenis_transaksi = 'setoran'
                                                          AND DATE(tanggal_transaksi) BETWEEN :start AND :end";
                                        $stmt_setoran = $db->prepare($query_setoran);
                                        $stmt_setoran->bindParam(':id', $jenis['id']);
                                        $stmt_setoran->bindParam(':start', $start_date);
                                        $stmt_setoran->bindParam(':end', $end_date);
                                        $stmt_setoran->execute();
                                        $total_setoran = $stmt_setoran->fetch(PDO::FETCH_ASSOC)['total'];
                                        
                                        // Hitung total penarikan untuk jenis ini
                                        $query_penarikan = "SELECT COALESCE(SUM(jumlah), 0) as total 
                                                             FROM transaksi 
                                                             WHERE jenis_simpanan_id = :id 
                                                             AND jenis_transaksi = 'penarikan'
                                                             AND DATE(tanggal_transaksi) BETWEEN :start AND :end";
                                        $stmt_penarikan = $db->prepare($query_penarikan);
                                        $stmt_penarikan->bindParam(':id', $jenis['id']);
                                        $stmt_penarikan->bindParam(':start', $start_date);
                                        $stmt_penarikan->bindParam(':end', $end_date);
                                        $stmt_penarikan->execute();
                                        $total_penarikan = $stmt_penarikan->fetch(PDO::FETCH_ASSOC)['total'];
                                        
                                        // Hitung jumlah transaksi
                                        $query_jumlah = "SELECT COUNT(*) as total 
                                                          FROM transaksi 
                                                          WHERE jenis_simpanan_id = :id 
                                                          AND DATE(tanggal_transaksi) BETWEEN :start AND :end";
                                        $stmt_jumlah = $db->prepare($query_jumlah);
                                        $stmt_jumlah->bindParam(':id', $jenis['id']);
                                        $stmt_jumlah->bindParam(':start', $start_date);
                                        $stmt_jumlah->bindParam(':end', $end_date);
                                        $stmt_jumlah->execute();
                                        $jumlah_transaksi = $stmt_jumlah->fetch(PDO::FETCH_ASSOC)['total'];
                                        
                                        // Netto
                                        $netto = $total_setoran - $total_penarikan;
                                        
                                        // Saldo akhir
                                        $saldo_akhir = $saldo_map[$kode] ?? 0;
                                    ?>
                                    <div class="col-xl-4 col-md-6 mb-4">
                                        <div class="savings-card <?php echo strtolower($kode); ?>" style="border-left-color: <?php echo $color; ?>;">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <div>
                                                    <span class="badge" style="background-color: <?php echo $color; ?>; color: white;">
                                                        <?php echo $kode; ?>
                                                    </span>
                                                    <h6 class="mt-2 mb-0"><?php echo $nama; ?></h6>
                                                </div>
                                                <i class="fas fa-<?php echo $icon; ?> fa-2x opacity-25"></i>
                                            </div>
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted">Setoran</small>
                                                    <div class="fw-bold text-success"><?php echo formatRupiah($total_setoran); ?></div>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Penarikan</small>
                                                    <div class="fw-bold text-warning"><?php echo formatRupiah($total_penarikan); ?></div>
                                                </div>
                                            </div>
                                            <hr class="my-2">
                                            <div class="d-flex justify-content-between">
                                                <div>
                                                    <small class="text-muted">Netto</small>
                                                    <div class="fw-bold text-primary"><?php echo formatRupiah($netto); ?></div>
                                                </div>
                                                <div class="text-end">
                                                    <small class="text-muted">Saldo Akhir</small>
                                                    <div class="fw-bold <?php echo $saldo_akhir > 0 ? 'text-info' : 'text-muted'; ?>">
                                                        <?php echo formatRupiah($saldo_akhir); ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <small class="text-muted d-block mt-2">
                                                <?php echo number_format($jumlah_transaksi); ?> transaksi
                                            </small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Transactions -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>Detail Transaksi Simpanan
                        </h5>
                        <span class="badge bg-primary"><?php echo count($transactions); ?> Transaksi</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($transactions)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Kode Transaksi</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th>Transaksi</th>
                                        <th class="text-end">Jumlah</th>
                                        <th>Teller</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $transaksi): 
                                        $color = $colors[$transaksi['kode_jenis']] ?? '#95a5a6';
                                    ?>
                                    <tr>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($transaksi['tanggal_transaksi'])); ?>
                                            <br>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($transaksi['tanggal_transaksi'])); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo $transaksi['kode_transaksi']; ?></strong>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo $transaksi['nama_lengkap']; ?></div>
                                            <small class="text-muted"><?php echo $transaksi['kode_anggota']; ?></small>
                                        </td>
                                        <td>
                                            <span class="badge" style="background-color: <?php echo $color; ?>; color: white;">
                                                <?php echo $transaksi['kode_jenis']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-transaction bg-<?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'success' : 'warning'; ?>-subtle text-<?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'success' : 'warning'; ?>">
                                                <i class="fas fa-<?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'plus' : 'minus'; ?> me-1"></i>
                                                <?php echo ucfirst($transaksi['jenis_transaksi']); ?>
                                            </span>
                                        </td>
                                        <td class="text-end <?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'amount-positive' : 'amount-negative'; ?>">
                                            <?php echo $transaksi['jenis_transaksi'] == 'setoran' ? '+' : '-'; ?>
                                            <?php echo formatRupiah($transaksi['jumlah']); ?>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?php echo $transaksi['teller']; ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-piggy-bank fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Tidak ada transaksi simpanan pada periode ini</p>
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
<?php
require_once '../includes/auth.php';
checkAnggota();

$anggota_id = $_SESSION['user_id'];
$nama_lengkap = $_SESSION['nama_lengkap'];

// Get anggota data
$query_anggota = "SELECT a.*, u.username 
                  FROM anggota a 
                  JOIN users u ON a.user_id = u.id 
                  WHERE a.user_id = :user_id";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->bindParam(':user_id', $anggota_id);
$stmt_anggota->execute();
$anggota_data = $stmt_anggota->fetch(PDO::FETCH_ASSOC);

$anggota_id_db = $anggota_data['id'];

// Get total simpanan (HANYA SIMPANAN REAL, EXCLUDE ANG)
$query_total_simpanan = "SELECT COALESCE(SUM(s.saldo), 0) as total_simpanan 
                         FROM simpanan s 
                         JOIN jenis_simpanan j ON s.jenis_simpanan_id = j.id
                         WHERE s.anggota_id = :anggota_id AND j.kode_jenis != 'ANG'";
$stmt_simpanan = $db->prepare($query_total_simpanan);
$stmt_simpanan->bindParam(':anggota_id', $anggota_id_db);
$stmt_simpanan->execute();
$total_simpanan = $stmt_simpanan->fetch(PDO::FETCH_ASSOC)['total_simpanan'];

// Get pinjaman dengan perhitungan sisa yang benar (pokok + bunga)
$query_pinjaman = "SELECT 
                      COUNT(*) as total_pinjaman,
                      COALESCE(SUM(CASE WHEN status = 'aktif' THEN sisa_pokok ELSE 0 END), 0) as total_sisa_pokok,
                      COALESCE(SUM(CASE WHEN status = 'aktif' THEN (SELECT COALESCE(SUM(bunga), 0) FROM angsuran WHERE pinjaman_id = p.id AND status != 'lunas') ELSE 0 END), 0) as total_sisa_bunga
                   FROM pinjaman p 
                   WHERE anggota_id = :anggota_id";
$stmt_pinjaman = $db->prepare($query_pinjaman);
$stmt_pinjaman->bindParam(':anggota_id', $anggota_id_db);
$stmt_pinjaman->execute();
$pinjaman_data = $stmt_pinjaman->fetch(PDO::FETCH_ASSOC);

// Hitung total tagihan aktif (sisa pokok + sisa bunga)
$total_tagihan_aktif = $pinjaman_data['total_sisa_pokok'] + $pinjaman_data['total_sisa_bunga'];

// Jadwal angsuran pinjaman aktif (bulanan & mingguan), dibaca langsung dari tabel angsuran
// sehingga pembayaran oleh teller langsung terlihat
$query_jadwal = "SELECT p.id AS pinjaman_id, p.kode_pinjaman, p.tenor, p.frekuensi, p.jumlah_cicilan,
                        a.angsuran_ke, a.tanggal_jatuh_tempo, a.jumlah_angsuran, a.tanggal_bayar, a.status
                 FROM angsuran a
                 JOIN pinjaman p ON a.pinjaman_id = p.id
                 WHERE p.anggota_id = :anggota_id AND p.status = 'aktif'
                 ORDER BY p.tanggal_pencairan, p.id, a.angsuran_ke";
$stmt_jadwal = $db->prepare($query_jadwal);
$stmt_jadwal->bindParam(':anggota_id', $anggota_id_db);
$stmt_jadwal->execute();
$jadwal_angsuran = [];
foreach ($stmt_jadwal->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $jadwal_angsuran[$row['pinjaman_id']][] = $row;
}

// Get total pinjaman yang sudah lunas
$query_pinjaman_lunas = "SELECT COUNT(*) as total_lunas 
                         FROM pinjaman 
                         WHERE anggota_id = :anggota_id AND status = 'lunas'";
$stmt_lunas = $db->prepare($query_pinjaman_lunas);
$stmt_lunas->bindParam(':anggota_id', $anggota_id_db);
$stmt_lunas->execute();
$pinjaman_lunas = $stmt_lunas->fetch(PDO::FETCH_ASSOC)['total_lunas'];

// Get total pinjaman aktif (jumlah pinjaman, bukan nominal)
$query_pinjaman_aktif_count = "SELECT COUNT(*) as total 
                               FROM pinjaman 
                               WHERE anggota_id = :anggota_id AND status = 'aktif'";
$stmt_aktif_count = $db->prepare($query_pinjaman_aktif_count);
$stmt_aktif_count->bindParam(':anggota_id', $anggota_id_db);
$stmt_aktif_count->execute();
$pinjaman_aktif_count = $stmt_aktif_count->fetch(PDO::FETCH_ASSOC)['total'];

// Get jenis simpanan (HANYA SIMPANAN REAL, EXCLUDE ANG)
$query_jenis_simpanan = "SELECT j.nama_jenis, j.kode_jenis, s.saldo 
                         FROM simpanan s 
                         JOIN jenis_simpanan j ON s.jenis_simpanan_id = j.id 
                         WHERE s.anggota_id = :anggota_id 
                           AND s.saldo > 0 
                           AND j.kode_jenis != 'ANG'
                         ORDER BY j.id";
$stmt_jenis = $db->prepare($query_jenis_simpanan);
$stmt_jenis->bindParam(':anggota_id', $anggota_id_db);
$stmt_jenis->execute();
$jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// Get transaksi terbaru (HANYA SIMPANAN REAL, EXCLUDE ANG)
$query_transaksi = "SELECT t.*, js.nama_jenis, js.kode_jenis
                    FROM transaksi t 
                    JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id 
                    WHERE t.anggota_id = :anggota_id 
                      AND js.kode_jenis != 'ANG'
                    ORDER BY t.tanggal_transaksi DESC 
                    LIMIT 10";
$stmt_transaksi = $db->prepare($query_transaksi);
$stmt_transaksi->bindParam(':anggota_id', $anggota_id_db);
$stmt_transaksi->execute();
$transaksi_terbaru = $stmt_transaksi->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// TAMBAHKAN DATA WASERDA
// ============================================

// Get total transaksi waserda
$query_waserda_total = "SELECT 
                          COUNT(*) as total_transaksi,
                          COALESCE(SUM(total_bayar), 0) as total_belanja
                        FROM waserda_transaksi 
                        WHERE anggota_id = :anggota_id";
$stmt_waserda_total = $db->prepare($query_waserda_total);
$stmt_waserda_total->bindParam(':anggota_id', $anggota_id_db);
$stmt_waserda_total->execute();
$waserda_total = $stmt_waserda_total->fetch(PDO::FETCH_ASSOC);

// Get transaksi waserda terbaru
$query_waserda_terbaru = "SELECT 
                            w.*,
                            u.nama_lengkap as petugas
                          FROM waserda_transaksi w
                          LEFT JOIN users u ON w.user_id = u.id
                          WHERE w.anggota_id = :anggota_id
                          ORDER BY w.created_at DESC
                          LIMIT 5";
$stmt_waserda_terbaru = $db->prepare($query_waserda_terbaru);
$stmt_waserda_terbaru->bindParam(':anggota_id', $anggota_id_db);
$stmt_waserda_terbaru->execute();
$waserda_terbaru = $stmt_waserda_terbaru->fetchAll(PDO::FETCH_ASSOC);

// Proses items waserda untuk ditampilkan
foreach ($waserda_terbaru as &$w) {
    $items = json_decode($w['items'], true);
    $w['jml_item'] = is_array($items) ? count($items) : 0;
    $w['nama_barang'] = is_array($items) && isset($items[0]['nama']) ? $items[0]['nama'] : 'Barang';
    $w['total_item'] = $w['jml_item'];
}

// Fungsi format rupiah
function formatRupiah($angka) {
    if ($angka == 0) {
        return 'Rp 0';
    }
    if ($angka < 0) {
        return '-Rp ' . number_format(abs($angka), 0, ',', '.');
    }
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .main-content {
            margin-left: 280px;
            margin-top: 10px;
            padding: 15px;
        }
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border-left: 4px solid #3498db;
            background: white;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .stat-card.success { border-left-color: #27ae60; }
        .stat-card.warning { border-left-color: #f39c12; }
        .stat-card.info { border-left-color: #17a2b8; }
        .stat-card.secondary { border-left-color: #95a5a6; }
        .stat-card.danger { border-left-color: #e74c3c; }
        .stat-card.waserda { border-left-color: #28a745; }
        
        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .quick-action {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
            height: 100%;
        }
        
        .quick-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            border-color: #3498db;
        }
        
        .action-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }

        .page-header {
            padding-top: 5px;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 60px;
            }
        }
        
        .badge-deposito { background-color: #f39c12; color: white; }
        .badge-waserda { background-color: #28a745; color: white; }
        
        .waserda-card {
            border-left: 4px solid #28a745;
        }
        
        .waserda-item {
            background-color: #f0f9f0;
            border-radius: 5px;
            padding: 8px;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <!-- Welcome Section -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center page-header border-bottom">
                    <div>
                        <h1 class="h3">Selamat Datang, <?php echo htmlspecialchars($nama_lengkap); ?></h1>
                        <p class="text-muted mb-0">
                            Kode Anggota: <?php echo htmlspecialchars($anggota_data['kode_anggota']); ?> | 
                            Status: <span class="badge bg-success"><?php echo ucfirst($anggota_data['status']); ?></span>
                        </p>
                        <small class="text-muted">Mari kelola simpanan, pinjaman, dan waserda Anda dengan mudah.</small>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-calendar me-1"></i> <?php echo date('d/m/Y'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards - SEKARANG 5 CARD (TAMBAH WASERDA) -->
                <div class="row mb-3">
                    <!-- Total Simpanan -->
                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="card stat-card success h-100">
                            <div class="card-body py-3">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-1">SIMPANAN</h6>
                                        <h6 class="fw-bold text-success mb-1"><?php echo formatRupiah($total_simpanan); ?></h6>
                                        <small class="text-muted">Total simpanan</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-success text-white">
                                            <i class="fas fa-piggy-bank"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sisa Tagihan (Pokok + Bunga) -->
                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="card stat-card danger h-100">
                            <div class="card-body py-3">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-1">SISA TAGIHAN</h6>
                                        <h6 class="fw-bold text-danger mb-1"><?php echo formatRupiah($total_tagihan_aktif); ?></h6>
                                        <small class="text-muted">Pokok + Bunga</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-danger text-white">
                                            <i class="fas fa-credit-card"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pinjaman Aktif -->
                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="card stat-card warning h-100">
                            <div class="card-body py-3">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-1">PINJAMAN AKTIF</h6>
                                        <h6 class="fw-bold text-warning mb-1"><?php echo $pinjaman_aktif_count; ?></h6>
                                        <small class="text-muted">Jumlah pinjaman</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-warning text-white">
                                            <i class="fas fa-hand-holding-usd"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pinjaman Lunas -->
                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="card stat-card secondary h-100">
                            <div class="card-body py-3">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-1">PINJAMAN LUNAS</h6>
                                        <h6 class="fw-bold text-secondary mb-1"><?php echo $pinjaman_lunas; ?></h6>
                                        <small class="text-muted">Pinjaman selesai</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-secondary text-white">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Waserda (CARD BARU) -->
                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="card stat-card waserda h-100">
                            <div class="card-body py-3">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-1">WASERDA</h6>
                                        <h6 class="fw-bold text-success mb-1"><?php echo formatRupiah($waserda_total['total_belanja']); ?></h6>
                                        <small class="text-muted"><?php echo $waserda_total['total_transaksi']; ?> transaksi</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-success text-white">
                                            <i class="fas fa-store"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Spacer untuk genap 6 kolom? Kita tambah 1 card dummy? Atau biarkan 5 card di baris 1? -->
                    <!-- Karena grid 12, 5 card dengan col-2 (5*2=10) sisa 2 kolom kosong, tidak masalah -->
                </div>

                <!-- Quick Actions - TAMBAH WASERDA -->
                <div class="row mb-3">
                    <div class="col-12">
                        <h6 class="mb-2"><i class="fas fa-bolt me-2"></i>Aksi Cepat</h6>
                    </div>
                    <div class="col-xl-2 col-md-4 col-6 mb-2">
                        <a href="simpanan.php" class="text-decoration-none">
                            <div class="quick-action">
                                <div class="action-icon bg-primary text-white">
                                    <i class="fas fa-piggy-bank"></i>
                                </div>
                                <h6 class="fw-bold mb-1">Simpanan</h6>
                                <small class="text-muted">Detail simpanan</small>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-2 col-md-4 col-6 mb-2">
                        <a href="pinjaman.php" class="text-decoration-none">
                            <div class="quick-action">
                                <div class="action-icon bg-warning text-white">
                                    <i class="fas fa-hand-holding-usd"></i>
                                </div>
                                <h6 class="fw-bold mb-1">Pinjaman</h6>
                                <small class="text-muted">Status pinjaman</small>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-2 col-md-4 col-6 mb-2">
                        <a href="waserda.php" class="text-decoration-none">
                            <div class="quick-action">
                                <div class="action-icon bg-success text-white">
                                    <i class="fas fa-store"></i>
                                </div>
                                <h6 class="fw-bold mb-1">Waserda</h6>
                                <small class="text-muted">Riwayat belanja</small>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-2 col-md-4 col-6 mb-2">
                        <a href="riwayat_simpanan.php" class="text-decoration-none">
                            <div class="quick-action">
                                <div class="action-icon bg-info text-white">
                                    <i class="fas fa-history"></i>
                                </div>
                                <h6 class="fw-bold mb-1">Riwayat</h6>
                                <small class="text-muted">Transaksi</small>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-2 col-md-4 col-6 mb-2">
                        <a href="profil.php" class="text-decoration-none">
                            <div class="quick-action">
                                <div class="action-icon bg-success text-white">
                                    <i class="fas fa-user"></i>
                                </div>
                                <h6 class="fw-bold mb-1">Profil</h6>
                                <small class="text-muted">Edit profil</small>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-2 col-md-4 col-6 mb-2">
                        <a href="shu_saya.php" class="text-decoration-none">
                            <div class="quick-action">
                                <div class="action-icon bg-secondary text-white">
                                    <i class="fas fa-calculator"></i>
                                </div>
                                <h6 class="fw-bold mb-1">SHU</h6>
                                <small class="text-muted">Sisa Hasil Usaha</small>
                            </div>
                        </a>
                    </div>
                </div>

                <div class="row">
                    <!-- Transaksi Terbaru (Simpanan) -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-history me-2"></i>Transaksi Simpanan Terbaru
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <?php if (!empty($transaksi_terbaru)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Tanggal</th>
                                                    <th>Jenis</th>
                                                    <th>Transaksi</th>
                                                    <th class="text-end">Jumlah</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($transaksi_terbaru as $transaksi): 
                                                    $badgeClass = $transaksi['kode_jenis'] == 'DEPOSITO' ? 'badge-deposito' : 'bg-secondary';
                                                ?>
                                                <tr>
                                                    <td><small><?php echo date('d/m/Y', strtotime($transaksi['tanggal_transaksi'])); ?></small></td>
                                                    <td>
                                                        <span class="badge <?php echo $badgeClass; ?>">
                                                            <?php echo $transaksi['kode_jenis']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'success' : 'warning'; ?>">
                                                            <?php echo ucfirst($transaksi['jenis_transaksi']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-end fw-bold text-<?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'success' : 'warning'; ?>">
                                                        <?php echo $transaksi['jenis_transaksi'] == 'setoran' ? '+' : '-'; ?>
                                                        <?php echo formatRupiah($transaksi['jumlah']); ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-3">
                                        <i class="fas fa-exchange-alt fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0 small">Belum ada transaksi simpanan</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Transaksi Waserda Terbaru (KOLOM BARU) -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-store me-2 text-success"></i>Transaksi Waserda Terbaru
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <?php if (!empty($waserda_terbaru)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Tanggal</th>
                                                    <th>Item</th>
                                                    <th>Metode</th>
                                                    <th class="text-end">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($waserda_terbaru as $w): ?>
                                                <tr>
                                                    <td><small><?php echo date('d/m/Y', strtotime($w['created_at'])); ?></small></td>
                                                    <td>
                                                        <span class="badge badge-waserda"><?php echo $w['jml_item']; ?> item</span>
                                                        <small class="ms-1"><?php echo $w['nama_barang']; ?></small>
                                                    </td>
                                                    <td><span class="badge bg-info"><?php echo ucfirst($w['metode_bayar']); ?></span></td>
                                                    <td class="text-end fw-bold text-success">
                                                        <?php echo formatRupiah($w['total_bayar']); ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-end mt-2">
                                        <a href="waserda.php" class="btn btn-sm btn-outline-success">
                                            Lihat Semua <i class="fas fa-arrow-right ms-1"></i>
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-3">
                                        <i class="fas fa-store fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-2">Belum ada transaksi waserda</p>
                                        <p class="text-muted mb-0 small">Kunjungi koperasi untuk berbelanja</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Baris Kedua: Jenis Simpanan (Tetap) -->
                <div class="row mt-3">
                    <div class="col-lg-5">
                        <div class="card">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-wallet me-2"></i>Jenis Simpanan
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <?php if (!empty($jenis_simpanan)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php 
                                        $total_saldo = 0;
                                        foreach ($jenis_simpanan as $jenis): 
                                            $total_saldo += $jenis['saldo'];
                                            $badgeClass = $jenis['kode_jenis'] == 'DEPOSITO' ? 'badge-deposito' : 'bg-secondary';
                                        ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0">
                                            <div>
                                                <span class="badge <?php echo $badgeClass; ?> me-2">
                                                    <?php echo $jenis['kode_jenis']; ?>
                                                </span>
                                                <small class="fw-bold"><?php echo $jenis['nama_jenis']; ?></small>
                                            </div>
                                            <span class="fw-bold text-success"><?php echo formatRupiah($jenis['saldo']); ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0 border-top mt-2">
                                            <div>
                                                <strong>Total Simpanan</strong>
                                            </div>
                                            <span class="fw-bold text-primary"><?php echo formatRupiah($total_saldo); ?></span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-2">
                                        <i class="fas fa-wallet fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0 small">Belum ada simpanan</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Informasi Singkat (Diperluas) -->
                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2 text-info"></i>Informasi Anggota
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td width="40%"><strong>Kode Anggota</strong></td>
                                                <td><?php echo $anggota_data['kode_anggota']; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Nama Lengkap</strong></td>
                                                <td><?php echo $anggota_data['nama_lengkap']; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Status</strong></td>
                                                <td><span class="badge bg-success"><?php echo ucfirst($anggota_data['status']); ?></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Total Simpanan</strong></td>
                                                <td class="text-success fw-bold"><?php echo formatRupiah($total_simpanan); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td width="40%"><strong>Tagihan Aktif</strong></td>
                                                <td class="text-danger fw-bold"><?php echo formatRupiah($total_tagihan_aktif); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Pinjaman Aktif</strong></td>
                                                <td><?php echo $pinjaman_aktif_count; ?> pinjaman</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Pinjaman Lunas</strong></td>
                                                <td><?php echo $pinjaman_lunas; ?> pinjaman</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Belanja Waserda</strong></td>
                                                <td class="text-success fw-bold"><?php echo formatRupiah($waserda_total['total_belanja']); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info py-2 mb-0 mt-2">
                                    <i class="fas fa-lightbulb me-2"></i>
                                    <small>
                                        <strong>Ringkasan:</strong> 
                                        Total simpanan: <?php echo formatRupiah($total_simpanan); ?> | 
                                        Sisa tagihan: <?php echo formatRupiah($total_tagihan_aktif); ?> |
                                        Belanja waserda: <?php echo formatRupiah($waserda_total['total_belanja']); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Jadwal Angsuran Pinjaman Aktif -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-calendar-alt me-2 text-danger"></i>Jadwal Angsuran Pinjaman Aktif
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <?php if (empty($jadwal_angsuran)): ?>
                                    <div class="text-center py-2">
                                        <i class="fas fa-check-circle fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0 small">Tidak ada pinjaman aktif</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($jadwal_angsuran as $jadwal): $info = $jadwal[0]; ?>
                                    <div class="mb-3">
                                        <div class="small mb-1">
                                            <strong><?php echo htmlspecialchars($info['kode_pinjaman']); ?></strong>
                                            <span class="text-muted">&middot; <?php echo labelTenorRow($info); ?></span>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Ke</th>
                                                        <th>Jatuh Tempo</th>
                                                        <th class="text-end">Nominal</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($jadwal as $a):
                                                        if ($a['status'] == 'lunas') {
                                                            $status_badge = '<span class="badge bg-success">Lunas</span>';
                                                        } elseif ($a['status'] == 'telat' || $a['tanggal_jatuh_tempo'] < date('Y-m-d')) {
                                                            $status_badge = '<span class="badge bg-danger">Terlambat</span>';
                                                        } else {
                                                            $status_badge = '<span class="badge bg-secondary">Belum</span>';
                                                        }
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $a['angsuran_ke']; ?>/<?php echo count($jadwal); ?></td>
                                                        <td><?php echo date('d/m/Y', strtotime($a['tanggal_jatuh_tempo'])); ?></td>
                                                        <td class="text-end"><?php echo formatRupiah($a['jumlah_angsuran']); ?></td>
                                                        <td><?php echo $status_badge; ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer Info -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="alert alert-light py-2 mb-0">
                            <i class="fas fa-store text-success me-2"></i>
                            <small>
                                <strong>Layanan Waserda:</strong> 
                                Anda dapat berbelanja kebutuhan sehari-hari di koperasi. 
                                Total belanja Anda: <?php echo formatRupiah($waserda_total['total_belanja']); ?> dari <?php echo $waserda_total['total_transaksi']; ?> transaksi.
                            </small>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
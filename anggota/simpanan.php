<?php
require_once '../includes/auth.php';
checkAnggota();

$anggota_id = $_SESSION['user_id'];

// Debug: cek session
error_log("Anggota ID dari session: " . $anggota_id);

// Get anggota data
$query_anggota = "SELECT a.* FROM anggota a WHERE a.user_id = :user_id";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->bindParam(':user_id', $anggota_id);
$stmt_anggota->execute();

if ($stmt_anggota->rowCount() == 0) {
    error_log("Data anggota tidak ditemukan untuk user_id: " . $anggota_id);
    die("Data anggota tidak ditemukan");
}

$anggota_data = $stmt_anggota->fetch(PDO::FETCH_ASSOC);
error_log("Data anggota ditemukan: id = " . $anggota_data['id']);

// Get simpanan data (HANYA SIMPANAN REAL, EXCLUDE ANG)
$query_simpanan = "SELECT s.*, js.nama_jenis, js.deskripsi, js.kode_jenis, js.minimum, js.maksimum, js.bagi_hasil
                   FROM simpanan s 
                   JOIN jenis_simpanan js ON s.jenis_simpanan_id = js.id 
                   WHERE s.anggota_id = :anggota_id 
                     AND js.kode_jenis != 'ANG'
                     AND s.saldo > 0
                   ORDER BY js.id";
$stmt_simpanan = $db->prepare($query_simpanan);
$stmt_simpanan->bindParam(':anggota_id', $anggota_data['id']);
$stmt_simpanan->execute();
$simpanan_list = $stmt_simpanan->fetchAll(PDO::FETCH_ASSOC);

error_log("Jumlah simpanan ditemukan: " . count($simpanan_list));

// Get total simpanan (HANYA SIMPANAN REAL, EXCLUDE ANG)
$query_total = "SELECT COALESCE(SUM(s.saldo), 0) as total_simpanan 
                FROM simpanan s 
                JOIN jenis_simpanan js ON s.jenis_simpanan_id = js.id 
                WHERE s.anggota_id = :anggota_id AND js.kode_jenis != 'ANG'";
$stmt_total = $db->prepare($query_total);
$stmt_total->bindParam(':anggota_id', $anggota_data['id']);
$stmt_total->execute();
$total_simpanan = $stmt_total->fetch(PDO::FETCH_ASSOC)['total_simpanan'];

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
    <title>Simpanan Saya - <?php echo APP_NAME; ?></title>
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
        .total-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .simpanan-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border-left: 4px solid;
            height: 100%;
        }
        .simpanan-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        /* Warna border untuk setiap jenis */
        .simpanan-card.deposito { border-left-color: #f39c12; }
        
        .badge-deposito { background-color: #f39c12; color: white; }
        .badge-info { background-color: #17a2b8; color: white; }
        
        .info-item {
            font-size: 0.8rem;
            padding: 2px 0;
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
                        <i class="fas fa-piggy-bank me-2"></i>Simpanan Saya
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="riwayat_simpanan.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-history me-1"></i> Riwayat
                            </a>

                        </div>
                    </div>
                </div>

                <!-- Total Simpanan Card -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card total-card bg-success text-white">
                            <div class="card-body py-3">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h4 class="card-title mb-1">Total Simpanan</h4>
                                        <h2 class="fw-bold mb-1"><?php echo formatRupiah($total_simpanan); ?></h2>
                                        <p class="card-text mb-0">Saldo total seluruh jenis simpanan</p>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <i class="fas fa-piggy-bank fa-4x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Informasi Simpanan -->
                <div class="alert alert-info py-2 mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <small>
                        <strong>Informasi:</strong> 
                        Berikut adalah daftar simpanan aktif Anda. 
                        Total simpanan Anda saat ini adalah <?php echo formatRupiah($total_simpanan); ?>.
                    </small>
                </div>

                <!-- Jenis Simpanan -->
                <div class="row">
                    <?php if (!empty($simpanan_list)): ?>
                        <?php foreach ($simpanan_list as $simpanan): 
                            $class = strtolower($simpanan['kode_jenis']);
                            $badgeClass = $simpanan['kode_jenis'] == 'DEPOSITO' ? 'badge-deposito' : 'badge-info';
                        ?>
                        <div class="col-lg-4 col-md-6 mb-3">
                            <div class="card simpanan-card <?php echo $class; ?>">
                                <div class="card-header bg-light py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-wallet me-2"></i><?php echo $simpanan['nama_jenis']; ?>
                                        </h6>
                                        <span class="badge <?php echo $badgeClass; ?>">
                                            <?php echo $simpanan['kode_jenis']; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="card-body py-3">
                                    <div class="text-center mb-3">
                                        <h3 class="text-primary fw-bold"><?php echo formatRupiah($simpanan['saldo']); ?></h3>
                                    </div>
                                    
                                    <?php if (!empty($simpanan['deskripsi'])): ?>
                                        <p class="text-muted small text-center mb-3"><?php echo $simpanan['deskripsi']; ?></p>
                                    <?php endif; ?>
                                    
                                    <hr class="my-2">
                                    
                                    <div class="row small">
                                        <div class="col-6">
                                            <div class="info-item">
                                                <span class="text-muted">Minimal:</span>
                                                <span class="fw-bold float-end"><?php echo formatRupiah($simpanan['minimum']); ?></span>
                                            </div>
                                            <div class="info-item">
                                                <span class="text-muted">Maksimal:</span>
                                                <span class="fw-bold float-end"><?php echo formatRupiah($simpanan['maksimum']); ?></span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-item">
                                                <span class="text-muted">Bagi Hasil:</span>
                                                <span class="fw-bold float-end"><?php echo $simpanan['bagi_hasil']; ?>%</span>
                                            </div>
                                            <div class="info-item">
                                                <span class="text-muted">ID:</span>
                                                <span class="fw-bold float-end"><?php echo $simpanan['id']; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-light py-2">
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i> Update: <?php echo date('d/m/Y H:i', strtotime($simpanan['updated_at'])); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="text-center py-5">
                                <i class="fas fa-piggy-bank fa-4x text-muted mb-3"></i>
                                <h4 class="text-muted">Belum ada simpanan</h4>
                                <p class="text-muted mb-3">Anda belum memiliki simpanan aktif.</p>
                                <p class="text-muted small">
                                    Silakan lakukan setoran simpanan melalui teller koperasi.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($simpanan_list)): ?>
                <!-- Ringkasan -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <div class="row">
                                    <div class="col-md-6">
                                        <small class="text-muted">Total Simpanan:</small>
                                        <span class="fw-bold text-success ms-2"><?php echo formatRupiah($total_simpanan); ?></span>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <small class="text-muted">Jumlah Jenis:</small>
                                        <span class="fw-bold text-primary ms-2"><?php echo count($simpanan_list); ?> jenis</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
// Start session jika belum
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/auth.php';
require_once '../config/config.php';

// Pastikan hanya teller yang bisa akses
if (!isTeller()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header('Location: ../dashboard.php');
    exit();
}

$page_title = "Persetujuan Pinjaman";

// Get data pengajuan pinjaman yang menunggu persetujuan
try {
    $query = "SELECT 
                pp.*,
                a.nama_lengkap,
                a.kode_anggota,
                a.telepon,
                jp.nama_jenis,
                jp.maksimal_pinjaman
              FROM pengajuan_pinjaman pp
              JOIN anggota a ON pp.anggota_id = a.id
              JOIN jenis_pinjaman jp ON pp.jenis_pinjaman_id = jp.id
              WHERE pp.status = 'menunggu'
              ORDER BY pp.tanggal_pengajuan DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $pengajuan_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hitung total angsuran
    foreach ($pengajuan_list as &$pengajuan) {
        // Rumus dari includes/pinjaman_helper.php (sesuai skema & frekuensi pengajuan)
        $hitung = hitungDariPinjaman($pengajuan);
        $pengajuan['angsuran_per_bulan'] = $hitung['angsuran'];
        $pengajuan['total_pinjaman'] = $hitung['total'];
    }

} catch (Exception $e) {
    $_SESSION['error'] = "Terjadi kesalahan: " . $e->getMessage();
    $pengajuan_list = [];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Persetujuan Pinjaman - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card-pengajuan {
            border-left: 4px solid #3498db;
            transition: all 0.3s ease;
        }
        
        .card-pengajuan:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.4rem 0.8rem;
        }
        
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .btn-action {
            font-size: 0.8rem;
            padding: 0.3rem 0.8rem;
        }
        .info-alert {
            background-color: #e7f3ff;
            border-left: 4px solid #3498db;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" style="margin-left: 280px; margin-top: 70px;">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-check-circle text-primary me-2"></i>
                        Persetujuan Pinjaman
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="pengajuan.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Kembali
                            </a>
                            <a href="pencairan.php" class="btn btn-sm btn-outline-primary">
                                Pencairan <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Info Alert -->
                <div class="alert info-alert alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-info-circle text-primary me-3 fa-lg"></i>
                        <div>
                            <h6 class="alert-heading mb-1">Informasi Akses</h6>
                            <p class="mb-0">Halaman ini hanya menampilkan data pengajuan pinjaman yang menunggu persetujuan. 
                            <strong>Hanya Administrator yang berhak melakukan persetujuan atau penolakan pengajuan.</strong></p>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Total Menunggu</h6>
                                        <h3 class="mb-0"><?php echo count($pengajuan_list); ?></h3>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-clock fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Total Pinjaman</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                            $total = 0;
                                            foreach ($pengajuan_list as $pengajuan) {
                                                $total += $pengajuan['jumlah_pinjaman'];
                                            }
                                            echo formatRupiah($total);
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-hand-holding-usd fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Rata-rata Tenor</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                            $total_tenor = 0;
                                            $count = count($pengajuan_list);
                                            foreach ($pengajuan_list as $pengajuan) {
                                                $total_tenor += $pengajuan['tenor'];
                                            }
                                            echo $count > 0 ? round($total_tenor / $count, 1) . ' bln' : '0 bln';
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-calendar-alt fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Rata-rata Bunga</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                            $total_bunga = 0;
                                            $count = count($pengajuan_list);
                                            foreach ($pengajuan_list as $pengajuan) {
                                                $total_bunga += $pengajuan['bunga'];
                                            }
                                            echo $count > 0 ? round($total_bunga / $count, 1) . '%' : '0%';
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-percentage fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pengajuan List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>
                            Daftar Pengajuan Menunggu Persetujuan
                            <span class="badge bg-primary ms-2"><?php echo count($pengajuan_list); ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pengajuan_list)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Tidak ada pengajuan yang menunggu persetujuan</h5>
                                <p class="text-muted">Semua pengajuan pinjaman sudah diproses.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Kode</th>
                                            <th>Anggota</th>
                                            <th>Jenis Pinjaman</th>
                                            <th>Jumlah</th>
                                            <th>Tenor</th>
                                            <th>Bunga</th>
                                            <th>Angsuran</th>
                                            <th>Tanggal Pengajuan</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pengajuan_list as $pengajuan): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($pengajuan['kode_pengajuan']); ?></strong>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($pengajuan['nama_lengkap']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($pengajuan['kode_anggota']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($pengajuan['nama_jenis']); ?></td>
                                            <td class="fw-bold text-primary"><?php echo formatRupiah($pengajuan['jumlah_pinjaman']); ?></td>
                                            <td>
                                                <span class="badge bg-info"><?php echo labelTenorRow($pengajuan); ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-warning"><?php echo labelBungaRow($pengajuan); ?></span>
                                            </td>
                                            <td class="fw-bold"><?php echo formatRupiah($pengajuan['angsuran_per_bulan']); ?><small class="text-muted fw-normal">/<?php echo strtolower(labelPeriode($pengajuan['frekuensi'])); ?></small></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('d/m/Y H:i', strtotime($pengajuan['tanggal_pengajuan'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary status-badge">
                                                    <i class="fas fa-clock me-1"></i> Menunggu
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-question-circle me-2"></i>Informasi
                                </h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success me-2"></i>
                                        <strong>Persetujuan:</strong> Hanya dapat dilakukan oleh Administrator
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-eye text-info me-2"></i>
                                        <strong>Fitur Teller:</strong> Hanya dapat melihat data pengajuan
                                    </li>
                                    <li>
                                        <i class="fas fa-money-bill-wave text-primary me-2"></i>
                                        <strong>Pencairan:</strong> Dapat diproses setelah pengajuan disetujui
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-shield-alt me-2"></i>Hak Akses
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning mb-0">
                                    <small>
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>Perhatian:</strong> Sebagai Teller, Anda hanya memiliki akses untuk:
                                        <ul class="mt-2 mb-0">
                                            <li>Melihat daftar pengajuan</li>
                                            <li>Memproses pencairan pinjaman yang sudah disetujui</li>
                                            <li>Melakukan pembayaran angsuran</li>
                                        </ul>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto close alerts after 5 seconds
        setTimeout(function() {
            var alerts = document.querySelectorAll('.alert-success'); // pesan error tetap tampil sampai ditutup
            alerts.forEach(function(alert) {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>
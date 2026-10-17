<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

$page_title = "Pengajuan Pinjaman";

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_pengajuan'])) {
    $anggota_id = $_POST['anggota_id'];
    $jenis_pinjaman_id = $_POST['jenis_pinjaman_id'];
    $jumlah_pinjaman = str_replace(['Rp', '.', ','], '', $_POST['jumlah_pinjaman']);
    $tenor = $_POST['tenor'];
    $tujuan_pinjaman = $_POST['tujuan_pinjaman'];
    
    try {
        // Get jenis pinjaman data
        $query_jenis = "SELECT * FROM jenis_pinjaman WHERE id = :id AND status = 'aktif'";
        $stmt_jenis = $db->prepare($query_jenis);
        $stmt_jenis->bindParam(':id', $jenis_pinjaman_id);
        $stmt_jenis->execute();
        $jenis_pinjaman = $stmt_jenis->fetch(PDO::FETCH_ASSOC);
        
        if ($jenis_pinjaman) {
            // Validate maximum amount
            if ($jumlah_pinjaman > $jenis_pinjaman['maksimal_pinjaman']) {
                $_SESSION['error'] = "Jumlah pinjaman melebihi batas maksimal (" . formatRupiah($jenis_pinjaman['maksimal_pinjaman']) . ")";
            } elseif ($tenor > $jenis_pinjaman['tenor_max']) {
                $_SESSION['error'] = "Tenor melebihi batas maksimal (" . $jenis_pinjaman['tenor_max'] . " bulan)";
            } else {
                // Generate kode pengajuan
                $kode_pengajuan = 'PJN-' . date('Ymd') . '-' . rand(1000, 9999);
                
                // Insert pengajuan
                $query = "INSERT INTO pengajuan_pinjaman 
                         (kode_pengajuan, anggota_id, jenis_pinjaman_id, jumlah_pinjaman, tenor, bunga, tujuan_pinjaman, status, tanggal_pengajuan) 
                         VALUES (:kode_pengajuan, :anggota_id, :jenis_pinjaman_id, :jumlah_pinjaman, :tenor, :bunga, :tujuan_pinjaman, 'menunggu', NOW())";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':kode_pengajuan', $kode_pengajuan);
                $stmt->bindParam(':anggota_id', $anggota_id);
                $stmt->bindParam(':jenis_pinjaman_id', $jenis_pinjaman_id);
                $stmt->bindParam(':jumlah_pinjaman', $jumlah_pinjaman);
                $stmt->bindParam(':tenor', $tenor);
                $stmt->bindParam(':bunga', $jenis_pinjaman['bunga']);
                $stmt->bindParam(':tujuan_pinjaman', $tujuan_pinjaman);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Pengajuan pinjaman berhasil diajukan dengan kode: " . $kode_pengajuan;
                    header("Location: pengajuan.php");
                    exit();
                } else {
                    $_SESSION['error'] = "Gagal mengajukan pinjaman. Silakan coba lagi.";
                }
            }
        } else {
            $_SESSION['error'] = "Jenis pinjaman tidak ditemukan!";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Terjadi kesalahan: " . $e->getMessage();
    }
}

// Get data for dropdowns
$query_anggota = "SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

$query_jenis = "SELECT * FROM jenis_pinjaman WHERE status = 'aktif' ORDER BY nama_jenis";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_pinjaman_list = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// Get pengajuan data
$query_pengajuan = "SELECT p.*, a.nama_lengkap, a.kode_anggota, j.nama_jenis, j.bunga,
                   (p.jumlah_pinjaman * (p.bunga/100) * p.tenor/12) as total_bunga,
                   (p.jumlah_pinjaman + (p.jumlah_pinjaman * (p.bunga/100) * p.tenor/12)) as total_pinjaman,
                   (p.jumlah_pinjaman + (p.jumlah_pinjaman * (p.bunga/100) * p.tenor/12)) / p.tenor as angsuran_per_bulan
                   FROM pengajuan_pinjaman p
                   JOIN anggota a ON p.anggota_id = a.id
                   JOIN jenis_pinjaman j ON p.jenis_pinjaman_id = j.id
                   ORDER BY p.tanggal_pengajuan DESC";
$stmt_pengajuan = $db->prepare($query_pengajuan);
$stmt_pengajuan->execute();
$pengajuan_list = $stmt_pengajuan->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$query_stats = "SELECT 
    COUNT(*) as total_pengajuan,
    COUNT(CASE WHEN status = 'menunggu' THEN 1 END) as menunggu,
    COUNT(CASE WHEN status = 'disetujui' THEN 1 END) as disetujui,
    COUNT(CASE WHEN status = 'ditolak' THEN 1 END) as ditolak
    FROM pengajuan_pinjaman";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            border-left: 4px solid;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.menunggu { border-left-color: #f39c12; }
        .stat-card.disetujui { border-left-color: #27ae60; }
        .stat-card.ditolak { border-left-color: #e74c3c; }
        .stat-card.total { border-left-color: #3498db; }
        
        .badge-status {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .calculation-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
        }
        
        .nav-pills .nav-link {
            border-radius: 10px;
            margin: 0 0.25rem;
        }
    </style>
</head>
<body style="background-color: #f8f9fa;">
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1"><i class="fas fa-file-alt me-2 text-primary"></i><?php echo $page_title; ?></h1>
                        <p class="text-muted mb-0">Kelola pengajuan pinjaman anggota</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Print
                        </button>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tambahPengajuanModal">
                            <i class="fas fa-plus me-2"></i>Ajukan Pinjaman
                        </button>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card total h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-2">TOTAL PENGAJUAN</h6>
                                        <h3 class="fw-bold text-primary"><?php echo number_format($stats['total_pengajuan']); ?></h3>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="fas fa-file-alt fa-2x text-primary opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card menunggu h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-2">MENUNGGU</h6>
                                        <h3 class="fw-bold text-warning"><?php echo number_format($stats['menunggu']); ?></h3>
                                        <small class="text-muted">Perlu persetujuan</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="fas fa-clock fa-2x text-warning opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card disetujui h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-2">DISETUJUI</h6>
                                        <h3 class="fw-bold text-success"><?php echo number_format($stats['disetujui']); ?></h3>
                                        <small class="text-muted">Telah disetujui</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="fas fa-check-circle fa-2x text-success opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card ditolak h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-2">DITOLAK</h6>
                                        <h3 class="fw-bold text-danger"><?php echo number_format($stats['ditolak']); ?></h3>
                                        <small class="text-muted">Tidak disetujui</small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <i class="fas fa-times-circle fa-2x text-danger opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="card">
                    <div class="card-header bg-white">
                        <ul class="nav nav-pills card-header-pills">
                            <li class="nav-item">
                                <a class="nav-link active" href="#semua" data-bs-toggle="tab">
                                    <i class="fas fa-list me-2"></i>Semua Pengajuan
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#menunggu" data-bs-toggle="tab">
                                    <i class="fas fa-clock me-2"></i>Menunggu
                                    <?php if ($stats['menunggu'] > 0): ?>
                                    <span class="badge bg-warning ms-1"><?php echo $stats['menunggu']; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">
                            <!-- Semua Pengajuan Tab -->
                            <div class="tab-pane fade show active" id="semua">
                                <div class="table-responsive">
                                    <table class="table table-hover" id="pengajuanTable">
                                        <thead>
                                            <tr>
                                                <th>Kode</th>
                                                <th>Tanggal</th>
                                                <th>Anggota</th>
                                                <th>Jenis</th>
                                                <th>Jumlah</th>
                                                <th>Tenor</th>
                                                <th>Bunga</th>
                                                <th>Angsuran/Bulan</th>
                                                <th>Status</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pengajuan_list as $pengajuan): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo $pengajuan['kode_pengajuan']; ?></strong>
                                                </td>
                                                <td>
                                                    <?php echo date('d/m/Y', strtotime($pengajuan['tanggal_pengajuan'])); ?>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo $pengajuan['nama_lengkap']; ?></div>
                                                    <small class="text-muted"><?php echo $pengajuan['kode_anggota']; ?></small>
                                                </td>
                                                <td><?php echo $pengajuan['nama_jenis']; ?></td>
                                                <td>
                                                    <span class="fw-bold text-primary"><?php echo formatRupiah($pengajuan['jumlah_pinjaman']); ?></span>
                                                </td>
                                                <td><?php echo $pengajuan['tenor']; ?> bulan</td>
                                                <td><?php echo $pengajuan['bunga']; ?>%</td>
                                                <td>
                                                    <span class="fw-bold text-success"><?php echo formatRupiah($pengajuan['angsuran_per_bulan']); ?></span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_class = [
                                                        'menunggu' => 'warning',
                                                        'disetujui' => 'success',
                                                        'ditolak' => 'danger'
                                                    ];
                                                    ?>
                                                    <span class="badge-status bg-<?php echo (isset($status_class[$pengajuan['status']]) ? $status_class[$pengajuan['status']] : 'info'); ?> text-white">
                                                        <?php echo ucfirst($pengajuan['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" title="Lihat Detail" 
                                                                data-bs-toggle="modal" data-bs-target="#detailModal<?php echo $pengajuan['id']; ?>">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <?php if ($pengajuan['status'] == 'menunggu'): ?>
                                                        <button class="btn btn-sm btn-outline-warning" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>

                                            <!-- Detail Modal -->
                                            <div class="modal fade" id="detailModal<?php echo $pengajuan['id']; ?>" tabindex="-1">
                                                <div class="modal-dialog modal-lg">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Detail Pengajuan Pinjaman</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="row">
                                                                <div class="col-md-6">
                                                                    <h6>Informasi Pengajuan</h6>
                                                                    <table class="table table-sm">
                                                                        <tr>
                                                                            <td><strong>Kode</strong></td>
                                                                            <td><?php echo $pengajuan['kode_pengajuan']; ?></td>
                                                                        </tr>
                                                                        <tr>
                                                                            <td><strong>Tanggal</strong></td>
                                                                            <td><?php echo date('d/m/Y H:i', strtotime($pengajuan['tanggal_pengajuan'])); ?></td>
                                                                        </tr>
                                                                        <tr>
                                                                            <td><strong>Status</strong></td>
                                                                            <td>
                                                                                <span class="badge-status bg-<?php echo (isset($status_class[$pengajuan['status']]) ? $status_class[$pengajuan['status']] : 'info'); ?> text-white">
                                                                                    <?php echo ucfirst($pengajuan['status']); ?>
                                                                                </span>
                                                                            </td>
                                                                        </tr>
                                                                    </table>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <h6>Informasi Anggota</h6>
                                                                    <table class="table table-sm">
                                                                        <tr>
                                                                            <td><strong>Nama</strong></td>
                                                                            <td><?php echo $pengajuan['nama_lengkap']; ?></td>
                                                                        </tr>
                                                                        <tr>
                                                                            <td><strong>Kode Anggota</strong></td>
                                                                            <td><?php echo $pengajuan['kode_anggota']; ?></td>
                                                                        </tr>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row mt-3">
                                                                <div class="col-12">
                                                                    <h6>Detail Pinjaman</h6>
                                                                    <div class="calculation-card">
                                                                        <div class="row text-center">
                                                                            <div class="col-3">
                                                                                <div class="fw-bold fs-4"><?php echo formatRupiah($pengajuan['jumlah_pinjaman']); ?></div>
                                                                                <small>Pokok Pinjaman</small>
                                                                            </div>
                                                                            <div class="col-3">
                                                                                <div class="fw-bold fs-4"><?php echo $pengajuan['tenor']; ?> bln</div>
                                                                                <small>Tenor</small>
                                                                            </div>
                                                                            <div class="col-3">
                                                                                <div class="fw-bold fs-4"><?php echo $pengajuan['bunga']; ?>%</div>
                                                                                <small>Bunga/Tahun</small>
                                                                            </div>
                                                                            <div class="col-3">
                                                                                <div class="fw-bold fs-4"><?php echo formatRupiah($pengajuan['angsuran_per_bulan']); ?></div>
                                                                                <small>Angsuran/Bulan</small>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <?php if (!empty($pengajuan['tujuan_pinjaman'])): ?>
                                                            <div class="row mt-3">
                                                                <div class="col-12">
                                                                    <h6>Tujuan Pinjaman</h6>
                                                                    <p class="text-muted"><?php echo $pengajuan['tujuan_pinjaman']; ?></p>
                                                                </div>
                                                            </div>
                                                            <?php endif; ?>
                                                            
                                                            <?php if (!empty($pengajuan['catatan_admin'])): ?>
                                                            <div class="row mt-3">
                                                                <div class="col-12">
                                                                    <h6>Catatan Admin</h6>
                                                                    <div class="alert alert-info">
                                                                        <?php echo $pengajuan['catatan_admin']; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Menunggu Tab -->
                            <div class="tab-pane fade" id="menunggu">
                                <?php 
                                $pengajuan_menunggu = array_filter($pengajuan_list, function($p) {
                                    return $p['status'] == 'menunggu';
                                });
                                ?>
                                
                                <?php if (!empty($pengajuan_menunggu)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Kode</th>
                                                <th>Tanggal</th>
                                                <th>Anggota</th>
                                                <th>Jumlah</th>
                                                <th>Tenor</th>
                                                <th>Angsuran/Bulan</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pengajuan_menunggu as $pengajuan): ?>
                                            <tr>
                                                <td><strong><?php echo $pengajuan['kode_pengajuan']; ?></strong></td>
                                                <td><?php echo date('d/m/Y', strtotime($pengajuan['tanggal_pengajuan'])); ?></td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo $pengajuan['nama_lengkap']; ?></div>
                                                    <small class="text-muted"><?php echo $pengajuan['kode_anggota']; ?></small>
                                                </td>
                                                <td class="fw-bold text-primary"><?php echo formatRupiah($pengajuan['jumlah_pinjaman']); ?></td>
                                                <td><?php echo $pengajuan['tenor']; ?> bulan</td>
                                                <td class="fw-bold text-success"><?php echo formatRupiah($pengajuan['angsuran_per_bulan']); ?></td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#detailModal<?php echo $pengajuan['id']; ?>">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-warning">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <h5 class="text-success">Tidak ada pengajuan yang menunggu</h5>
                                    <p class="text-muted">Semua pengajuan telah diproses</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Pengajuan Modal -->
    <div class="modal fade" id="tambahPengajuanModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ajukan Pinjaman Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formPengajuan">
                    <div class="modal-body">
                        <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger">
                            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success">
                            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pilih Anggota *</label>
                                <select class="form-select" name="anggota_id" required id="anggota_id">
                                    <option value="">Pilih Anggota</option>
                                    <?php foreach ($anggota_list as $anggota): ?>
                                    <option value="<?php echo $anggota['id']; ?>">
                                        <?php echo $anggota['kode_anggota']; ?> - <?php echo $anggota['nama_lengkap']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Jenis Pinjaman *</label>
                                <select class="form-select" name="jenis_pinjaman_id" required id="jenis_pinjaman_id">
                                    <option value="">Pilih Jenis Pinjaman</option>
                                    <?php foreach ($jenis_pinjaman_list as $jenis): ?>
                                    <option value="<?php echo $jenis['id']; ?>" 
                                            data-max="<?php echo $jenis['maksimal_pinjaman']; ?>"
                                            data-tenor="<?php echo $jenis['tenor_max']; ?>"
                                            data-bunga="<?php echo $jenis['bunga']; ?>">
                                        <?php echo $jenis['nama_jenis']; ?> 
                                        (Max: <?php echo formatRupiah($jenis['maksimal_pinjaman']); ?> | 
                                        Tenor: <?php echo $jenis['tenor_max']; ?> bln)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Jumlah Pinjaman *</label>
                                <input type="text" class="form-control" name="jumlah_pinjaman" id="jumlah_pinjaman" 
                                       placeholder="Masukkan jumlah pinjaman" required>
                                <small class="text-muted" id="max_info"></small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tenor (Bulan) *</label>
                                <select class="form-select" name="tenor" id="tenor" required>
                                    <option value="">Pilih Tenor</option>
                                    <?php for ($i = 1; $i <= 36; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> bulan</option>
                                    <?php endfor; ?>
                                </select>
                                <small class="text-muted" id="tenor_info"></small>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label class="form-label">Tujuan Pinjaman</label>
                                <textarea class="form-control" name="tujuan_pinjaman" rows="3" 
                                          placeholder="Jelaskan tujuan penggunaan pinjaman"></textarea>
                            </div>
                        </div>

                        <!-- Calculation Preview -->
                        <div class="calculation-card mt-3" id="calculationPreview" style="display: none;">
                            <h6 class="text-white mb-3">Simulasi Pinjaman</h6>
                            <div class="row text-center">
                                <div class="col-3">
                                    <div class="fw-bold fs-5" id="previewPokok">Rp 0</div>
                                    <small>Pokok Pinjaman</small>
                                </div>
                                <div class="col-3">
                                    <div class="fw-bold fs-5" id="previewBunga">0%</div>
                                    <small>Bunga/Tahun</small>
                                </div>
                                <div class="col-3">
                                    <div class="fw-bold fs-5" id="previewTotalBunga">Rp 0</div>
                                    <small>Total Bunga</small>
                                </div>
                                <div class="col-3">
                                    <div class="fw-bold fs-5" id="previewAngsuran">Rp 0</div>
                                    <small>Angsuran/Bulan</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="submit_pengajuan" class="btn btn-primary">Ajukan Pinjaman</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#pengajuanTable').DataTable({
                "order": [[1, "desc"]],
                "language": {
                    "search": "Cari:",
                    "lengthMenu": "Tampilkan _MENU_ data per halaman",
                    "zeroRecords": "Tidak ada data yang ditemukan",
                    "info": "Menampilkan halaman _PAGE_ dari _PAGES_",
                    "infoEmpty": "Tidak ada data tersedia",
                    "infoFiltered": "(disaring dari _MAX_ total data)"
                }
            });
        });

        // Format currency input
        document.getElementById('jumlah_pinjaman').addEventListener('input', function(e) {
            let value = this.value.replace(/[^\d]/g, '');
            if (value) {
                this.value = 'Rp ' + parseInt(value).toLocaleString('id-ID');
            }
            calculateLoan();
        });

        // Calculate loan when inputs change
        document.getElementById('jenis_pinjaman_id').addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const maxAmount = selected.getAttribute('data-max');
            const maxTenor = selected.getAttribute('data-tenor');
            const bunga = selected.getAttribute('data-bunga');
            
            document.getElementById('max_info').textContent = 'Maksimal: ' + formatCurrency(maxAmount);
            document.getElementById('tenor_info').textContent = 'Maksimal tenor: ' + maxTenor + ' bulan';
            
            // Update tenor options
            const tenorSelect = document.getElementById('tenor');
            tenorSelect.innerHTML = '<option value="">Pilih Tenor</option>';
            for (let i = 1; i <= maxTenor; i++) {
                tenorSelect.innerHTML += `<option value="${i}">${i} bulan</option>`;
            }
            
            calculateLoan();
        });

        document.getElementById('tenor').addEventListener('change', calculateLoan);

        function calculateLoan() {
            const jumlahInput = document.getElementById('jumlah_pinjaman').value;
            const tenorSelect = document.getElementById('tenor');
            const jenisSelect = document.getElementById('jenis_pinjaman_id');
            
            if (jenisSelect.value && jumlahInput && tenorSelect.value) {
                const jumlah = parseInt(jumlahInput.replace(/[^\d]/g, ''));
                const tenor = parseInt(tenorSelect.value);
                const bunga = parseFloat(jenisSelect.options[jenisSelect.selectedIndex].getAttribute('data-bunga'));
                
                const totalBunga = jumlah * (bunga/100) * (tenor/12);
                const totalPinjaman = jumlah + totalBunga;
                const angsuranPerBulan = totalPinjaman / tenor;
                
                document.getElementById('previewPokok').textContent = formatCurrency(jumlah);
                document.getElementById('previewBunga').textContent = bunga + '%';
                document.getElementById('previewTotalBunga').textContent = formatCurrency(totalBunga);
                document.getElementById('previewAngsuran').textContent = formatCurrency(angsuranPerBulan);
                
                document.getElementById('calculationPreview').style.display = 'block';
            } else {
                document.getElementById('calculationPreview').style.display = 'none';
            }
        }

        function formatCurrency(amount) {
            return 'Rp ' + parseInt(amount).toLocaleString('id-ID');
        }
    </script>
</body>
</html>
<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

$page_title = "SHU Anggota";

// Filter parameters
$filter_periode = $_GET['periode_id'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';

// Get all SHU periods
$periode_query = "SELECT * FROM shu_periode ORDER BY tahun DESC";
$periode_stmt = $db->prepare($periode_query);
$periode_stmt->execute();
$periode_list = $periode_stmt->fetchAll(PDO::FETCH_ASSOC);

// Jika tidak ada periode, set variable kosong
$shu_anggota = [];
$total_shu = 0;
$total_belum_bayar = 0;
$total_sudah_bayar = 0;

if (!empty($periode_list)) {
    // Get anggota list for filter
    $anggota_query = "SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
    $anggota_stmt = $db->prepare($anggota_query);
    $anggota_stmt->execute();
    $anggota_list = $anggota_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build query for SHU anggota
    $query = "SELECT sa.*, 
                     a.kode_anggota, 
                     a.nama_lengkap,
                     sp.tahun,
                     sp.nama_periode,
                     sp.status as periode_status
              FROM shu_anggota sa
              JOIN anggota a ON sa.anggota_id = a.id
              JOIN shu_periode sp ON sa.shu_periode_id = sp.id
              WHERE 1=1";

    $params = [];

    if (!empty($filter_periode)) {
        $query .= " AND sa.shu_periode_id = :periode_id";
        $params[':periode_id'] = $filter_periode;
    }

    if (!empty($filter_status)) {
        $query .= " AND sa.status_bayar = :status";
        $params[':status'] = $filter_status;
    }

    if (!empty($filter_anggota)) {
        $query .= " AND sa.anggota_id = :anggota_id";
        $params[':anggota_id'] = $filter_anggota;
    }

    $query .= " ORDER BY sp.tahun DESC, sa.total_shu DESC";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $shu_anggota = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hitung total keseluruhan
    $total_shu = array_sum(array_column($shu_anggota, 'total_shu'));
    $total_belum_bayar = array_sum(array_column(array_filter($shu_anggota, function($s) { return $s['status_bayar'] == 'belum'; }), 'total_shu'));
    $total_sudah_bayar = array_sum(array_column(array_filter($shu_anggota, function($s) { return $s['status_bayar'] == 'sudah'; }), 'total_shu'));
}

// Handle pembayaran SHU
if ($_POST && isset($_POST['bayar_shu'])) {
    $shu_anggota_id = $_POST['shu_anggota_id'];
    $tanggal_bayar = $_POST['tanggal_bayar'];
    
    try {
        $query = "UPDATE shu_anggota SET status_bayar = 'sudah', tanggal_bayar = :tanggal_bayar WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':tanggal_bayar', $tanggal_bayar);
        $stmt->bindParam(':id', $shu_anggota_id);
        $stmt->execute();
        
        $_SESSION['success'] = "Pembayaran SHU berhasil dicatat";
    } catch (Exception $e) {
        $_SESSION['error'] = "Gagal mencatat pembayaran: " . $e->getMessage();
    }
    
    header("Location: shu_anggota.php?" . http_build_query($_GET));
    exit();
}

// Handle pembatalan pembayaran
if ($_POST && isset($_POST['batalkan_bayar'])) {
    $shu_anggota_id = $_POST['shu_anggota_id'];
    
    try {
        $query = "UPDATE shu_anggota SET status_bayar = 'belum', tanggal_bayar = NULL WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $shu_anggota_id);
        $stmt->execute();
        
        $_SESSION['success'] = "Status pembayaran berhasil dibatalkan";
    } catch (Exception $e) {
        $_SESSION['error'] = "Gagal membatalkan pembayaran: " . $e->getMessage();
    }
    
    header("Location: shu_anggota.php?" . http_build_query($_GET));
    exit();
}

// Fungsi format rupiah
function formatRupiah($angka) {
    if ($angka == 0) return 'Rp 0';
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
        .card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 12px 16px;
            font-weight: 600;
        }
        .card-body {
            padding: 16px;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        .summary-box {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            background-color: #f8f9fa;
        }
        .summary-value {
            font-size: 1.5rem;
            font-weight: 600;
        }
        .filter-card {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .btn-action {
            min-width: 70px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3">
                        <i class="fas fa-users me-2"></i>SHU Anggota
                    </h1>
                    <a href="shu.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Kembali
                    </a>
                </div>

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

                <?php if (empty($periode_list)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Belum ada periode SHU. Silakan buat periode SHU terlebih dahulu di menu <a href="shu.php" class="alert-link">Manajemen SHU</a>.
                </div>
                <?php else: ?>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="summary-box">
                            <div class="text-muted">Total SHU</div>
                            <div class="summary-value text-primary"><?php echo formatRupiah($total_shu); ?></div>
                            <small><?php echo count($shu_anggota); ?> anggota</small>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="summary-box">
                            <div class="text-muted">Sudah Dibayar</div>
                            <div class="summary-value text-success"><?php echo formatRupiah($total_sudah_bayar); ?></div>
                            <small><?php echo count(array_filter($shu_anggota, function($s) { return $s['status_bayar'] == 'sudah'; })); ?> anggota</small>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="summary-box">
                            <div class="text-muted">Belum Dibayar</div>
                            <div class="summary-value text-warning"><?php echo formatRupiah($total_belum_bayar); ?></div>
                            <small><?php echo count(array_filter($shu_anggota, function($s) { return $s['status_bayar'] == 'belum'; })); ?> anggota</small>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-card">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Periode SHU</label>
                            <select name="periode_id" class="form-select">
                                <option value="">Semua Periode</option>
                                <?php foreach ($periode_list as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo $filter_periode == $p['id'] ? 'selected' : ''; ?>>
                                    <?php echo $p['nama_periode']; ?> (<?php echo ucfirst($p['status']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status Pembayaran</label>
                            <select name="status" class="form-select">
                                <option value="">Semua Status</option>
                                <option value="belum" <?php echo $filter_status == 'belum' ? 'selected' : ''; ?>>Belum Dibayar</option>
                                <option value="sudah" <?php echo $filter_status == 'sudah' ? 'selected' : ''; ?>>Sudah Dibayar</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Anggota</label>
                            <select name="anggota_id" class="form-select">
                                <option value="">Semua Anggota</option>
                                <?php foreach ($anggota_list as $a): ?>
                                <option value="<?php echo $a['id']; ?>" <?php echo $filter_anggota == $a['id'] ? 'selected' : ''; ?>>
                                    <?php echo $a['kode_anggota'] . ' - ' . $a['nama_lengkap']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-1"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Data Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-list me-2"></i>Daftar SHU Anggota</span>
                        <span class="badge bg-primary"><?php echo count($shu_anggota); ?> Data</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($shu_anggota)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-calculator fa-3x text-muted mb-3"></i>
                                <p class="text-muted">Tidak ada data SHU dengan filter yang dipilih</p>
                                <a href="shu_anggota.php" class="btn btn-primary btn-sm">
                                    <i class="fas fa-sync me-1"></i> Reset Filter
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Periode</th>
                                            <th>Kode</th>
                                            <th>Nama Anggota</th>
                                            <th class="text-end">Jasa Simpanan</th>
                                            <th class="text-end">Jasa Pinjaman</th>
                                            <th class="text-end">Jasa Transaksi</th>
                                            <th class="text-end">Total SHU</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $no = 1;
                                        foreach ($shu_anggota as $s): 
                                        ?>
                                        <tr>
                                            <td><?php echo $no++; ?></td>
                                            <td>
                                                <strong><?php echo $s['nama_periode']; ?></strong>
                                                <br><small class="text-muted"><?php echo $s['tahun']; ?></small>
                                            </td>
                                            <td><?php echo $s['kode_anggota']; ?></td>
                                            <td><?php echo $s['nama_lengkap']; ?></td>
                                            <td class="text-end"><?php echo formatRupiah($s['jasa_simpanan']); ?></td>
                                            <td class="text-end"><?php echo formatRupiah($s['jasa_pinjaman']); ?></td>
                                            <td class="text-end"><?php echo formatRupiah($s['jasa_transaksi']); ?></td>
                                            <td class="text-end fw-bold text-success"><?php echo formatRupiah($s['total_shu']); ?></td>
                                            <td class="text-center">
                                                <?php if ($s['status_bayar'] == 'sudah'): ?>
                                                    <span class="badge bg-success status-badge">
                                                        <i class="fas fa-check-circle me-1"></i>Sudah
                                                    </span>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo date('d/m/Y', strtotime($s['tanggal_bayar'])); ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="badge bg-warning status-badge">
                                                        <i class="fas fa-clock me-1"></i>Belum
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($s['status_bayar'] == 'belum'): ?>
                                                    <button class="btn btn-sm btn-success btn-action" 
                                                            onclick="showBayarModal(<?php echo $s['id']; ?>, '<?php echo $s['nama_lengkap']; ?>', <?php echo $s['total_shu']; ?>)">
                                                        <i class="fas fa-money-bill-wave me-1"></i>Bayar
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-warning btn-action" 
                                                            onclick="showBatalkanModal(<?php echo $s['id']; ?>, '<?php echo $s['nama_lengkap']; ?>')">
                                                        <i class="fas fa-undo me-1"></i>Batal
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <th colspan="4" class="text-end">TOTAL</th>
                                            <th class="text-end"><?php echo formatRupiah(array_sum(array_column($shu_anggota, 'jasa_simpanan'))); ?></th>
                                            <th class="text-end"><?php echo formatRupiah(array_sum(array_column($shu_anggota, 'jasa_pinjaman'))); ?></th>
                                            <th class="text-end"><?php echo formatRupiah(array_sum(array_column($shu_anggota, 'jasa_transaksi'))); ?></th>
                                            <th class="text-end"><?php echo formatRupiah($total_shu); ?></th>
                                            <th colspan="2"></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Modal Bayar SHU -->
    <div class="modal fade" id="bayarModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-money-bill-wave me-2"></i>Pembayaran SHU
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="shu_anggota_id" id="bayar_id">
                        <p class="mb-3">Konfirmasi pembayaran SHU untuk:</p>
                        <div class="alert alert-info">
                            <strong id="bayar_nama"></strong>
                            <br>
                            Total SHU: <strong class="text-success" id="bayar_total"></strong>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tanggal Pembayaran</label>
                            <input type="date" name="tanggal_bayar" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="bayar_shu" class="btn btn-success">
                            <i class="fas fa-check me-1"></i> Konfirmasi Pembayaran
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Batalkan Pembayaran -->
    <div class="modal fade" id="batalkanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title">
                            <i class="fas fa-exclamation-triangle me-2"></i>Batalkan Pembayaran
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="shu_anggota_id" id="batalkan_id">
                        <p class="mb-3">Apakah Anda yakin ingin membatalkan pembayaran SHU untuk:</p>
                        <div class="alert alert-warning">
                            <strong id="batalkan_nama"></strong>
                        </div>
                        <p class="text-danger small">
                            <i class="fas fa-info-circle me-1"></i>
                            Status akan dikembalikan menjadi "Belum Dibayar"
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tidak</button>
                        <button type="submit" name="batalkan_bayar" class="btn btn-warning">
                            <i class="fas fa-undo me-1"></i> Ya, Batalkan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showBayarModal(id, nama, total) {
            document.getElementById('bayar_id').value = id;
            document.getElementById('bayar_nama').textContent = nama;
            document.getElementById('bayar_total').textContent = 'Rp ' + new Intl.NumberFormat('id-ID').format(total);
            
            var bayarModal = new bootstrap.Modal(document.getElementById('bayarModal'));
            bayarModal.show();
        }
        
        function showBatalkanModal(id, nama) {
            document.getElementById('batalkan_id').value = id;
            document.getElementById('batalkan_nama').textContent = nama;
            
            var batalkanModal = new bootstrap.Modal(document.getElementById('batalkanModal'));
            batalkanModal.show();
        }
    </script>
</body>
</html>
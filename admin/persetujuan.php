<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Pastikan hanya admin yang bisa akses
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../dashboard.php');
    exit();
}

// Handle form actions
if ($_POST) {
    if (isset($_POST['approve'])) {
        $id = $_POST['id'];
        $catatan_admin = $_POST['catatan_admin'] ?? '';
        
        try {
            $db->beginTransaction();
            
            // Update pengajuan status
            $query_update = "UPDATE pengajuan_pinjaman SET status = 'disetujui', 
                            catatan_admin = :catatan_admin, admin_id = :admin_id,
                            tanggal_persetujuan = NOW() WHERE id = :id AND status = 'menunggu'";
            $stmt_update = $db->prepare($query_update);
            $stmt_update->bindParam(':id', $id);
            $stmt_update->bindParam(':catatan_admin', $catatan_admin);
            $stmt_update->bindParam(':admin_id', $_SESSION['user_id']);
            
            if ($stmt_update->execute()) {
                // Persetujuan TIDAK membuat pinjaman/jadwal angsuran. Pinjaman dan jadwal dibuat
                // saat Teller memproses pencairan (teller/pencairan.php), tanggal dihitung dari pencairan.
                if ($stmt_update->rowCount() < 1) {
                    throw new Exception("Data pengajuan tidak ditemukan atau sudah diproses");
                }
                $db->commit();
                $_SESSION['success'] = "Pengajuan disetujui. Menunggu Teller memproses pencairan"; 
            } else {
                throw new Exception("Gagal mengupdate status pengajuan");
            }
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Gagal menyetujui pengajuan: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject'])) {
        $id = $_POST['id'];
        $catatan_admin = $_POST['catatan_admin'] ?? '';
        
        $query = "UPDATE pengajuan_pinjaman SET status = 'ditolak', 
                  catatan_admin = :catatan_admin, admin_id = :admin_id,
                  tanggal_persetujuan = NOW() WHERE id = :id AND status = 'menunggu'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':catatan_admin', $catatan_admin);
        $stmt->bindParam(':admin_id', $_SESSION['user_id']);
        
        if ($stmt->execute() && $stmt->rowCount() === 1) {
            $_SESSION['success'] = "Pengajuan pinjaman berhasil ditolak";
        } else {
            $_SESSION['error'] = "Gagal menolak pengajuan";
        }
    }
    
    if (isset($_POST['delete'])) {
        $id = $_POST['id'];
        
        $query = "DELETE FROM pengajuan_pinjaman WHERE id = :id AND status IN ('menunggu','ditolak') AND NOT EXISTS (SELECT 1 FROM pinjaman WHERE pengajuan_id = pengajuan_pinjaman.id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute() && $stmt->rowCount() === 1) {
            $_SESSION['success'] = "Pengajuan pinjaman berhasil dihapus";
        } else {
            $_SESSION['error'] = "Gagal menghapus pengajuan";
        }
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_tanggal_mulai = $_GET['tanggal_mulai'] ?? '';
$filter_tanggal_selesai = $_GET['tanggal_selesai'] ?? '';

// Build query with filters
$query = "SELECT pp.*, a.nama_lengkap, a.foto_ktp, a.foto_kk, a.foto_ktp_pasangan,
          CASE WHEN jp.memerlukan_jaminan = 1 THEN COALESCE(a.kode_anggota_jaminan, a.kode_anggota) ELSE a.kode_anggota END AS kode_anggota,
          jp.nama_jenis, jp.memerlukan_jaminan,
          (SELECT COUNT(*) FROM pinjaman pj WHERE pj.pengajuan_id = pp.id) AS sudah_cair,
          jp.maksimal_pinjaman, u.nama_lengkap as admin_name
          FROM pengajuan_pinjaman pp
          JOIN anggota a ON pp.anggota_id = a.id
          JOIN jenis_pinjaman jp ON pp.jenis_pinjaman_id = jp.id
          LEFT JOIN users u ON pp.admin_id = u.id
          WHERE 1=1";

$params = [];

if (!empty($filter_status)) {
    $query .= " AND pp.status = :status";
    $params[':status'] = $filter_status;
}

if (!empty($filter_tanggal_mulai)) {
    $query .= " AND DATE(pp.tanggal_pengajuan) >= :tanggal_mulai";
    $params[':tanggal_mulai'] = $filter_tanggal_mulai;
}

if (!empty($filter_tanggal_selesai)) {
    $query .= " AND DATE(pp.tanggal_pengajuan) <= :tanggal_selesai";
    $params[':tanggal_selesai'] = $filter_tanggal_selesai;
}

$query .= " ORDER BY pp.tanggal_pengajuan DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$pengajuan_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$query_stats = "SELECT 
    COUNT(*) as total_pengajuan,
    COUNT(CASE WHEN status = 'menunggu' THEN 1 END) as menunggu,
    COUNT(CASE WHEN status = 'disetujui' AND NOT EXISTS (SELECT 1 FROM pinjaman pj WHERE pj.pengajuan_id = pengajuan_pinjaman.id) THEN 1 END) as disetujui,
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
    <title>Persetujuan Pinjaman - <?php echo APP_NAME; ?></title>
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
        .status-badge {
            font-size: 0.75rem;
            padding: 0.5rem 0.75rem;
        }
        .filter-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
        }
        .document-card { height: 100%; border: 1px solid #dee2e6; border-radius: .5rem; padding: .75rem; background: #f8f9fa; }
        .document-card img { width: 100%; height: 160px; object-fit: contain; background: #fff; border: 1px solid #dee2e6; border-radius: .375rem; }
        .document-empty { height: 160px; display: flex; align-items: center; justify-content: center; text-align: center; color: #842029; background: #f8d7da; border-radius: .375rem; }
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
                        <i class="fas fa-check-circle me-2"></i>Persetujuan Pinjaman
                    </h1>
                    <div>
                        <a href="pencairan.php" class="btn btn-success">
                            <i class="fas fa-money-bill-wave me-1"></i> Pencairan
                        </a>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['total_pengajuan']; ?></h4>
                                        <p>Total Pengajuan</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-file-alt fa-2x"></i>
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
                                        <h4><?php echo $stats['menunggu']; ?></h4>
                                        <p>Menunggu</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-clock fa-2x"></i>
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
                                        <h4><?php echo $stats['disetujui']; ?></h4>
                                        <p>Disetujui</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x"></i>
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
                                        <h4><?php echo $stats['ditolak']; ?></h4>
                                        <p>Ditolak</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-times-circle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">Semua Status</option>
                                    <option value="menunggu" <?php echo $filter_status == 'menunggu' ? 'selected' : ''; ?>>Menunggu</option>
                                    <option value="disetujui" <?php echo $filter_status == 'disetujui' ? 'selected' : ''; ?>>Disetujui</option>
                                    <option value="ditolak" <?php echo $filter_status == 'ditolak' ? 'selected' : ''; ?>>Ditolak</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Tanggal Mulai</label>
                                <input type="date" name="tanggal_mulai" class="form-control" 
                                       value="<?php echo htmlspecialchars($filter_tanggal_mulai); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Tanggal Selesai</label>
                                <input type="date" name="tanggal_selesai" class="form-control" 
                                       value="<?php echo htmlspecialchars($filter_tanggal_selesai); ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-1"></i> Filter
                                </button>
                            </div>
                        </form>
                        <?php if ($filter_status || $filter_tanggal_mulai || $filter_tanggal_selesai): ?>
                        <div class="mt-3">
                            <a href="persetujuan.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-times me-1"></i> Hapus Filter
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pengajuan Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>Daftar Pengajuan Pinjaman
                        </h5>
                        <div>
                            <span class="text-muted me-2">
                                <?php echo count($pengajuan_list); ?> data ditemukan
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pengajuan_list)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-file-alt fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">Belum ada pengajuan pinjaman</h5>
                            <p class="text-muted">Semua pengajuan pinjaman dari anggota akan muncul di sini</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Anggota</th>
                                        <th>Jenis Pinjaman</th>
                                        <th>Jumlah</th>
                                        <th>Tenor</th>
                                        <th>Bunga</th>
                                        <th>Status</th>
                                        <th>Tanggal</th>
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
                                            <div class="fw-semibold"><?php echo $pengajuan['nama_lengkap']; ?></div>
                                            <small class="text-muted"><?php echo $pengajuan['kode_anggota']; ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo $pengajuan['nama_jenis']; ?></div>
                                            <small class="text-muted">Maks: <?php echo formatRupiah($pengajuan['maksimal_pinjaman']); ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-primary"><?php echo formatRupiah($pengajuan['jumlah_pinjaman']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo labelTenorRow($pengajuan); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?php echo labelBungaRow($pengajuan); ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            // Tahap pencairan hanya ditampilkan di menu Pencairan.
                                            $statusTampil = $pengajuan['status']==='dicairkan' ? 'disetujui' : $pengajuan['status'];
                                            $status_class = [
                                                'menunggu' => 'warning',
                                                'disetujui' => 'success',
                                                'ditolak' => 'danger',
                                                'batal_cair' => 'secondary'
                                            ];
                                            $label_status = $statusTampil === 'batal_cair' ? 'Batal Cair' : ucfirst($statusTampil);
                                            ?>
                                            <span class="badge bg-<?php echo $status_class[$statusTampil] ?? 'secondary'; ?> status-badge">
                                                <?php echo $label_status; ?>
                                            </span>
                                            <?php if ($pengajuan['admin_name']): ?>
                                                <br>
                                                <small class="text-muted">Oleh: <?php echo $pengajuan['admin_name']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?php echo date('d/m/Y', strtotime($pengajuan['tanggal_pengajuan'])); ?></small>
                                            <br>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($pengajuan['tanggal_pengajuan'])); ?></small>
                                        </td>
                                        <td class="table-actions">
                                            <?php
                                            $dokumen_detail = [
                                                'foto_ktp' => urlDokumenAnggota($pengajuan['foto_ktp']),
                                                'foto_kk' => urlDokumenAnggota($pengajuan['foto_kk']),
                                                'foto_ktp_pasangan' => urlDokumenAnggota($pengajuan['foto_ktp_pasangan']),
                                            ];
                                            ?>
                                            <!-- View Details Button -->
                                            <button class="btn btn-sm btn-info" data-bs-toggle="modal" 
                                                    data-bs-target="#detailModal" 
                                                    data-kode="<?php echo $pengajuan['kode_pengajuan']; ?>"
                                                    data-nama="<?php echo $pengajuan['nama_lengkap']; ?>"
                                                    data-kode_anggota="<?php echo $pengajuan['kode_anggota']; ?>"
                                                    data-jenis="<?php echo $pengajuan['nama_jenis']; ?>"
                                                    data-jumlah="<?php echo $pengajuan['jumlah_pinjaman']; ?>"
                                                    data-tenor="<?php echo $pengajuan['tenor']; ?>"
                                                    data-bunga="<?php echo labelBungaRow($pengajuan); ?>"
                                                    data-tujuan="<?php echo $pengajuan['tujuan_pinjaman']; ?>"
                                                    data-status="<?php echo $statusTampil; ?>"
                                                    data-tanggal="<?php echo date('d/m/Y H:i', strtotime($pengajuan['tanggal_pengajuan'])); ?>"
                                                    data-catatan="<?php echo $pengajuan['catatan_admin']; ?>"
                                                    data-jaminan="<?php echo !empty($pengajuan['memerlukan_jaminan']) ? '1' : '0'; ?>"
                                                    data-dokumen="<?php echo htmlspecialchars(json_encode($dokumen_detail), ENT_QUOTES, 'UTF-8'); ?>"
                                                    title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </button>

                                            <!-- Action Buttons based on status -->
                                            <?php if ($pengajuan['status'] == 'menunggu'): ?>
                                                <!-- Approve Button -->
                                                <button class="btn btn-sm btn-success" data-bs-toggle="modal" 
                                                        data-bs-target="#approveModal" 
                                                        data-id="<?php echo $pengajuan['id']; ?>"
                                                        data-kode="<?php echo $pengajuan['kode_pengajuan']; ?>"
                                                        data-nama="<?php echo $pengajuan['nama_lengkap']; ?>"
                                                        title="Setujui">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                
                                                <!-- Reject Button -->
                                                <button class="btn btn-sm btn-danger" data-bs-toggle="modal" 
                                                        data-bs-target="#rejectModal" 
                                                        data-id="<?php echo $pengajuan['id']; ?>"
                                                        data-kode="<?php echo $pengajuan['kode_pengajuan']; ?>"
                                                        data-nama="<?php echo $pengajuan['nama_lengkap']; ?>"
                                                        title="Tolak">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>

                                            <!-- Delete Button (only for admin) -->
                                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" 
                                                    data-bs-target="#deleteModal" 
                                                    data-id="<?php echo $pengajuan['id']; ?>"
                                                    data-kode="<?php echo $pengajuan['kode_pengajuan']; ?>"
                                                    data-nama="<?php echo $pengajuan['nama_lengkap']; ?>"
                                                    title="Hapus">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Detail Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detail Pengajuan Pinjaman</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Kode Pengajuan</th>
                                    <td id="detail_kode"></td>
                                </tr>
                                <tr>
                                    <th>Nama Anggota</th>
                                    <td id="detail_nama"></td>
                                </tr>
                                <tr>
                                    <th>Kode Anggota</th>
                                    <td id="detail_kode_anggota"></td>
                                </tr>
                                <tr>
                                    <th>Jenis Pinjaman</th>
                                    <td id="detail_jenis"></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Jumlah Pinjaman</th>
                                    <td id="detail_jumlah" class="fw-bold text-primary"></td>
                                </tr>
                                <tr>
                                    <th>Tenor</th>
                                    <td id="detail_tenor"></td>
                                </tr>
                                <tr>
                                    <th>Bunga</th>
                                    <td id="detail_bunga"></td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td id="detail_status"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Tujuan Pinjaman</label>
                                <div id="detail_tujuan" class="p-3 bg-light rounded"></div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Tanggal Pengajuan</label>
                                <div id="detail_tanggal" class="p-2 bg-light rounded"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Catatan Admin</label>
                                <div id="detail_catatan" class="p-2 bg-light rounded"></div>
                            </div>
                        </div>
                    </div>
                    <div class="border-top pt-3 mt-1">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold mb-0">Kelengkapan Dokumen Anggota</label>
                            <span id="detail_dokumen_status" class="badge bg-secondary">Memeriksa</span>
                        </div>
                        <div id="detail_dokumen_info" class="small text-muted mb-3"></div>
                        <div id="detail_dokumen" class="row g-3"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Approve Modal -->
    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title text-success">Setujui Pengajuan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="approve_id">
                        <p>Apakah Anda yakin ingin menyetujui pengajuan pinjaman:</p>
                        <p class="fw-bold" id="approve_nama"></p>
                        <p><strong>Kode:</strong> <span id="approve_kode"></span></p>
                        
                        <div class="mb-3">
                            <label class="form-label">Catatan (Opsional)</label>
                            <textarea name="catatan_admin" class="form-control" rows="3" 
                                      placeholder="Berikan catatan untuk anggota..."></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                Setelah disetujui, pengajuan menunggu diproses pencairan oleh Teller (jadwal angsuran dibuat saat pencairan).
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="approve" class="btn btn-success">Setujui</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title text-danger">Tolak Pengajuan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="reject_id">
                        <p>Apakah Anda yakin ingin menolak pengajuan pinjaman:</p>
                        <p class="fw-bold" id="reject_nama"></p>
                        <p><strong>Kode:</strong> <span id="reject_kode"></span></p>
                        
                        <div class="mb-3">
                            <label class="form-label">Alasan Penolakan *</label>
                            <textarea name="catatan_admin" class="form-control" rows="3" 
                                      placeholder="Berikan alasan penolakan..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="reject" class="btn btn-danger">Tolak</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title text-danger">Hapus Pengajuan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="delete_id">
                        <p>Apakah Anda yakin ingin menghapus pengajuan pinjaman:</p>
                        <p class="fw-bold" id="delete_nama"></p>
                        <p><strong>Kode:</strong> <span id="delete_kode"></span></p>
                        
                        <div class="alert alert-warning">
                            <small>
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Data yang dihapus tidak dapat dikembalikan.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="delete" class="btn btn-danger">Hapus</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Detail Modal
        var detailModal = document.getElementById('detailModal');
        detailModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('detail_kode').textContent = button.getAttribute('data-kode');
            document.getElementById('detail_nama').textContent = button.getAttribute('data-nama');
            document.getElementById('detail_kode_anggota').textContent = button.getAttribute('data-kode_anggota');
            document.getElementById('detail_jenis').textContent = button.getAttribute('data-jenis');
            document.getElementById('detail_jumlah').textContent = 'Rp ' + Number(button.getAttribute('data-jumlah')).toLocaleString('id-ID');
            document.getElementById('detail_tenor').textContent = button.getAttribute('data-tenor') + ' bulan';
            document.getElementById('detail_bunga').textContent = button.getAttribute('data-bunga');
            
            var status = button.getAttribute('data-status');
            var statusClass = {
                'menunggu': 'warning',
                'disetujui': 'success',
                'ditolak': 'danger'
            };
            document.getElementById('detail_status').innerHTML = '<span class="badge bg-' + statusClass[status] + '">' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
            
            document.getElementById('detail_tujuan').textContent = button.getAttribute('data-tujuan') || '-';
            document.getElementById('detail_tanggal').textContent = button.getAttribute('data-tanggal');
            document.getElementById('detail_catatan').textContent = button.getAttribute('data-catatan') || '-';

            var denganJaminan = button.getAttribute('data-jaminan') === '1';
            var dokumen = {};
            try { dokumen = JSON.parse(button.getAttribute('data-dokumen') || '{}'); } catch (e) { dokumen = {}; }
            var daftar = denganJaminan
                ? [['foto_ktp', 'Foto KTP']]
                : [['foto_ktp', 'Foto KTP'], ['foto_kk', 'Foto Kartu Keluarga (KK)'], ['foto_ktp_pasangan', 'Foto KTP Suami/Istri']];
            var wadah = document.getElementById('detail_dokumen');
            wadah.replaceChildren();
            var lengkap = true;
            daftar.forEach(function (item) {
                var url = dokumen[item[0]] || '';
                if (!url) lengkap = false;
                var kolom = document.createElement('div');
                kolom.className = 'col-md-' + (daftar.length === 1 ? '12' : '4');
                var kartu = document.createElement('div');
                kartu.className = 'document-card';
                var judul = document.createElement('div');
                judul.className = 'fw-semibold mb-2';
                judul.textContent = item[1];
                kartu.appendChild(judul);
                if (url) {
                    var tautan = document.createElement('a');
                    tautan.href = url;
                    tautan.target = '_blank';
                    tautan.rel = 'noopener noreferrer';
                    var gambar = document.createElement('img');
                    gambar.src = url;
                    gambar.alt = item[1];
                    tautan.appendChild(gambar);
                    kartu.appendChild(tautan);
                } else {
                    var kosong = document.createElement('div');
                    kosong.className = 'document-empty';
                    kosong.textContent = item[1] + ' belum tersedia';
                    kartu.appendChild(kosong);
                }
                kolom.appendChild(kartu);
                wadah.appendChild(kolom);
            });
            document.getElementById('detail_dokumen_info').textContent = denganJaminan
                ? 'Pinjaman dengan jaminan: dokumen wajib berupa KTP.'
                : 'Pinjaman tanpa jaminan: dokumen wajib berupa KTP, KK, dan KTP suami/istri.';
            var statusDokumen = document.getElementById('detail_dokumen_status');
            statusDokumen.className = 'badge bg-' + (lengkap ? 'success' : 'danger');
            statusDokumen.textContent = lengkap ? 'Dokumen lengkap' : 'Dokumen belum lengkap';
        });

        // Approve Modal
        var approveModal = document.getElementById('approveModal');
        approveModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('approve_id').value = button.getAttribute('data-id');
            document.getElementById('approve_nama').textContent = button.getAttribute('data-nama');
            document.getElementById('approve_kode').textContent = button.getAttribute('data-kode');
        });

        // Reject Modal
        var rejectModal = document.getElementById('rejectModal');
        rejectModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('reject_id').value = button.getAttribute('data-id');
            document.getElementById('reject_nama').textContent = button.getAttribute('data-nama');
            document.getElementById('reject_kode').textContent = button.getAttribute('data-kode');
        });

        // Delete Modal
        var deleteModal = document.getElementById('deleteModal');
        deleteModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('delete_id').value = button.getAttribute('data-id');
            document.getElementById('delete_nama').textContent = button.getAttribute('data-nama');
            document.getElementById('delete_kode').textContent = button.getAttribute('data-kode');
        });
    </script>
</body>
</html>

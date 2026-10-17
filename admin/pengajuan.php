<?php
// Start session jika belum
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include config dan auth dengan path yang benar
require_once '../config/config.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

// Check if user has access to this page
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: dashboard.php");
    exit();
}

$page_title = "Persetujuan Pengajuan Pinjaman";

// Handle approval/rejection
if ($_POST) {
    if (isset($_POST['setujui']) || isset($_POST['tolak'])) {
        $id = $_POST['id'];
        $status = isset($_POST['setujui']) ? 'disetujui' : 'ditolak';
        $catatan_admin = $_POST['catatan_admin'] ?? '';
        
        try {
            $db->beginTransaction();
            if ($status === 'disetujui') {
                $cek_jaminan = $db->prepare("SELECT jp.memerlukan_jaminan,
                    EXISTS(SELECT 1 FROM jaminan_pinjaman j WHERE j.pengajuan_id = pp.id) AS ada_jaminan
                    FROM pengajuan_pinjaman pp JOIN jenis_pinjaman jp ON jp.id = pp.jenis_pinjaman_id
                    WHERE pp.id = ?");
                $cek_jaminan->execute([$id]);
                $validasi_jaminan = $cek_jaminan->fetch(PDO::FETCH_ASSOC);
                if ($validasi_jaminan && $validasi_jaminan['memerlukan_jaminan'] && !$validasi_jaminan['ada_jaminan']) {
                    throw new Exception("Pengajuan gadai belum memiliki data barang jaminan");
                }
            }
            
            // Update pengajuan status
            $query_update = "UPDATE pengajuan_pinjaman SET 
                            status = :status, 
                            admin_id = :admin_id,
                            tanggal_persetujuan = NOW(),
                            catatan_admin = :catatan_admin 
                            WHERE id = :id AND status = 'menunggu'";
            $stmt_update = $db->prepare($query_update);
            $stmt_update->bindParam(':status', $status);
            $stmt_update->bindParam(':admin_id', $_SESSION['user_id']);
            $stmt_update->bindParam(':catatan_admin', $catatan_admin);
            $stmt_update->bindParam(':id', $id);
            $stmt_update->execute();
            if ($stmt_update->rowCount() < 1) {
                throw new Exception("Pengajuan tidak ditemukan atau sudah diproses");
            }
            // Persetujuan TIDAK membuat pinjaman/jadwal angsuran: itu dilakukan Teller saat pencairan.
            
            $db->commit();
            $action_text = $status == 'disetujui' ? 'disetujui' : 'ditolak';
            $_SESSION['success'] = $status == 'disetujui'
                ? "Pengajuan disetujui. Menunggu Teller memproses pencairan"
                : "Pengajuan berhasil ditolak";
            header("Location: pengajuan.php");
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Gagal memproses pengajuan: " . $e->getMessage();
        }
    }
}

// Get all loan applications waiting for approval
$query = "SELECT pp.*, a.nama_lengkap,
          CASE WHEN jp.memerlukan_jaminan = 1 THEN COALESCE(a.kode_anggota_jaminan, a.kode_anggota) ELSE a.kode_anggota END AS kode_anggota,
          jp.nama_jenis,
          jp.maksimal_pinjaman, jp.memerlukan_jaminan, u.nama_lengkap as teller_name,
          j.jenis_barang, j.identitas_barang, j.deskripsi_barang, j.status_jaminan
          FROM pengajuan_pinjaman pp
          JOIN anggota a ON pp.anggota_id = a.id
          JOIN jenis_pinjaman jp ON pp.jenis_pinjaman_id = jp.id
          LEFT JOIN users u ON pp.user_id = u.id
          LEFT JOIN jaminan_pinjaman j ON j.pengajuan_id = pp.id
          WHERE pp.status = 'menunggu'
          ORDER BY pp.tanggal_pengajuan ASC";

$stmt = $db->prepare($query);
$stmt->execute();
$pengajuan_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$query_stats = "SELECT 
    COUNT(*) as total_pengajuan,
    COUNT(CASE WHEN status = 'menunggu' THEN 1 END) as menunggu,
    COUNT(CASE WHEN status = 'disetujui' AND NOT EXISTS (SELECT 1 FROM pinjaman pj WHERE pj.pengajuan_id = pengajuan_pinjaman.id) THEN 1 END) as disetujui,
    COUNT(CASE WHEN status = 'dicairkan' THEN 1 END) as dicairkan,
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
    <title>Persetujuan Pengajuan Pinjaman - <?php echo APP_NAME; ?></title>
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
        .calculation-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
        }
        .pengajuan-card {
            border-left: 4px solid #0d6efd;
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
                        <i class="fas fa-clipboard-check me-2"></i>Persetujuan Pengajuan Pinjaman
                    </h1>
                    <div>
                        <a href="pencairan.php" class="btn btn-success">
                            <i class="fas fa-money-bill-wave me-1"></i> Pencairan
                        </a>
                    </div>
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

                <!-- Pengajuan Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>Daftar Pengajuan Menunggu Persetujuan
                        </h5>
                        <div>
                            <span class="text-muted me-2">
                                <?php echo count($pengajuan_list); ?> data menunggu persetujuan
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pengajuan_list)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h5 class="text-muted">Tidak ada pengajuan yang menunggu persetujuan</h5>
                            <p class="text-muted">Semua pengajuan telah diproses atau belum ada pengajuan baru.</p>
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
                                        <th>Diajukan Oleh</th>
                                        <th>Tanggal Pengajuan</th>
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
                                            <br><small class="text-muted">Hari angsuran: <?php echo labelHariAngsuran(isset($pengajuan['hari_angsuran']) ? $pengajuan['hari_angsuran'] : null); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?php echo labelBungaRow($pengajuan); ?></span>
                                        </td>
                                        <td>
                                            <small><?php echo $pengajuan['teller_name'] ?? 'System'; ?></small>
                                        </td>
                                        <td>
                                            <small><?php echo date('d/m/Y', strtotime($pengajuan['tanggal_pengajuan'])); ?></small>
                                            <br>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($pengajuan['tanggal_pengajuan'])); ?></small>
                                        </td>
                                        <td class="table-actions">
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
                                                    data-status="<?php echo $pengajuan['status']; ?>"
                                                    data-tanggal="<?php echo date('d/m/Y H:i', strtotime($pengajuan['tanggal_pengajuan'])); ?>"
                                                    data-teller="<?php echo $pengajuan['teller_name'] ?? 'System'; ?>"
                                                    data-jaminan="<?php echo htmlspecialchars(!empty($pengajuan['jenis_barang']) ? $pengajuan['jenis_barang'] . ' — ' . $pengajuan['identitas_barang'] . ' — ' . $pengajuan['deskripsi_barang'] : '', ENT_QUOTES); ?>"
                                                    title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </button>

                                            <!-- Setujui Button -->
                                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" 
                                                    data-bs-target="#setujuiModal" 
                                                    data-id="<?php echo $pengajuan['id']; ?>"
                                                    data-kode="<?php echo $pengajuan['kode_pengajuan']; ?>"
                                                    data-nama="<?php echo $pengajuan['nama_lengkap']; ?>"
                                                    data-jumlah="<?php echo $pengajuan['jumlah_pinjaman']; ?>"
                                                    data-tenor="<?php echo $pengajuan['tenor']; ?>"
                                                    data-bunga="<?php echo labelBungaRow($pengajuan); ?>"
                                                    title="Setujui">
                                                <i class="fas fa-check"></i>
                                            </button>

                                            <!-- Tolak Button -->
                                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" 
                                                    data-bs-target="#tolakModal" 
                                                    data-id="<?php echo $pengajuan['id']; ?>"
                                                    data-kode="<?php echo $pengajuan['kode_pengajuan']; ?>"
                                                    data-nama="<?php echo $pengajuan['nama_lengkap']; ?>"
                                                    title="Tolak">
                                                <i class="fas fa-times"></i>
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
                                    <th>Diajukan Oleh</th>
                                    <td id="detail_teller"></td>
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
                    <div class="row" id="detail_jaminan_wrap" style="display:none">
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">Barang Jaminan / Gadai</label>
                            <div id="detail_jaminan" class="p-3 border border-warning rounded bg-light"></div>
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
                                <label class="form-label fw-bold">Status</label>
                                <div id="detail_status" class="p-2 bg-light rounded"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Setujui Modal -->
    <div class="modal fade" id="setujuiModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title text-success">Setujui Pengajuan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="setujui_id">
                        <p>Apakah Anda yakin ingin menyetujui pengajuan:</p>
                        <p class="fw-bold" id="setujui_nama"></p>
                        <p><strong>Kode:</strong> <span id="setujui_kode"></span></p>
                        <p><strong>Jumlah:</strong> <span id="setujui_jumlah"></span></p>
                        <p><strong>Tenor:</strong> <span id="setujui_tenor"></span> bulan</p>
                        <p><strong>Bunga:</strong> <span id="setujui_bunga"></span>%</p>
                        
                        <div class="mb-3">
                            <label for="setujui_catatan" class="form-label">Catatan (Opsional)</label>
                            <textarea class="form-control" id="setujui_catatan" name="catatan_admin" rows="3" placeholder="Catatan persetujuan..."></textarea>
                        </div>
                        
                        <div class="alert alert-success">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                Setelah disetujui, pengajuan menunggu diproses pencairan oleh Teller (jadwal angsuran dibuat saat pencairan).
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="setujui" class="btn btn-success">Setujui</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Tolak Modal -->
    <div class="modal fade" id="tolakModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title text-danger">Tolak Pengajuan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="tolak_id">
                        <p>Apakah Anda yakin ingin menolak pengajuan:</p>
                        <p class="fw-bold" id="tolak_nama"></p>
                        <p><strong>Kode:</strong> <span id="tolak_kode"></span></p>
                        
                        <div class="mb-3">
                            <label for="tolak_catatan" class="form-label">Alasan Penolakan *</label>
                            <textarea class="form-control" id="tolak_catatan" name="catatan_admin" rows="3" required placeholder="Jelaskan alasan penolakan..."></textarea>
                        </div>
                        
                        <div class="alert alert-warning">
                            <small>
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Penolakan akan diberitahukan kepada teller yang mengajukan.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="tolak" class="btn btn-danger">Tolak</button>
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
            document.getElementById('detail_teller').textContent = button.getAttribute('data-teller');
            
            var status = button.getAttribute('data-status');
            var statusClass = {
                'menunggu': 'warning',
                'disetujui': 'success',
                'ditolak': 'danger'
            };
            document.getElementById('detail_status').innerHTML = '<span class="badge bg-' + statusClass[status] + '">' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
            
            document.getElementById('detail_tujuan').textContent = button.getAttribute('data-tujuan') || '-';
            document.getElementById('detail_tanggal').textContent = button.getAttribute('data-tanggal');
            var jaminan = button.getAttribute('data-jaminan') || '';
            document.getElementById('detail_jaminan_wrap').style.display = jaminan ? '' : 'none';
            document.getElementById('detail_jaminan').textContent = jaminan;
        });

        // Setujui Modal
        var setujuiModal = document.getElementById('setujuiModal');
        setujuiModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('setujui_id').value = button.getAttribute('data-id');
            document.getElementById('setujui_nama').textContent = button.getAttribute('data-nama');
            document.getElementById('setujui_kode').textContent = button.getAttribute('data-kode');
            document.getElementById('setujui_jumlah').textContent = 'Rp ' + Number(button.getAttribute('data-jumlah')).toLocaleString('id-ID');
            document.getElementById('setujui_tenor').textContent = button.getAttribute('data-tenor');
            document.getElementById('setujui_bunga').textContent = button.getAttribute('data-bunga');
        });

        // Tolak Modal
        var tolakModal = document.getElementById('tolakModal');
        tolakModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('tolak_id').value = button.getAttribute('data-id');
            document.getElementById('tolak_nama').textContent = button.getAttribute('data-nama');
            document.getElementById('tolak_kode').textContent = button.getAttribute('data-kode');
        });
    </script>
</body>
</html>

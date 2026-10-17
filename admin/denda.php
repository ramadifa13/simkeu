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

$page_title = "Denda & Keterlambatan";

// Denda otomatis (sekali sehari); denda manual tidak ditimpa
perbaruiDendaOtomatis($db);

// Handle fine update
if ($_POST) {
    if (isset($_POST['update_denda'])) {
        $id = (int) $_POST['id'];
        $input = isset($_POST['denda']) ? trim($_POST['denda']) : '';

        try {
            $stmt = $db->prepare("SELECT a.*, p.jumlah_pinjaman FROM angsuran a
                                  JOIN pinjaman p ON a.pinjaman_id = p.id
                                  WHERE a.id = ? AND a.status <> 'lunas'");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception("Angsuran tidak ditemukan atau sudah lunas");
            }
            $punya_flag = kolomAda($db, 'angsuran', 'denda_manual');

            if ($input === '') {
                // Kosong = kembali ke hitungan otomatis
                $d = hitungDendaAngsuran($db, $row, $row['jumlah_pinjaman']);
                $set = "denda = ?, keterlambatan_hari = ?" . ($punya_flag ? ", denda_manual = 0" : '')
                     . ($d['hari_telat'] > 0 ? ", status = 'telat'" : '');
                $db->prepare("UPDATE angsuran SET $set WHERE id = ?")->execute([$d['denda'], $d['hari_telat'], $id]);
                $_SESSION['success'] = "Denda dikembalikan ke hitungan otomatis (Rp " . number_format($d['denda'], 0, ',', '.') . ")";
            } else {
                if (!is_numeric($input) || (float) $input < 0) {
                    throw new Exception("Denda harus berupa angka 0 atau lebih");
                }
                $set = "denda = ?" . ($punya_flag ? ", denda_manual = 1" : '');
                $db->prepare("UPDATE angsuran SET $set WHERE id = ?")->execute([(float) $input, $id]);
                $_SESSION['success'] = "Denda diatur manual (Rp " . number_format((float) $input, 0, ',', '.') . ")";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal mengupdate denda: " . $e->getMessage();
        }
    }
}

// Get late installments - PERBAIKAN: Ambil semua angsuran yang memiliki denda atau telat
$query = "SELECT a.*, p.kode_pinjaman, p.anggota_id, p.jumlah_pinjaman, ag.nama_lengkap, ag.kode_anggota,
                 DATEDIFF(CURDATE(), a.tanggal_jatuh_tempo) as hari_keterlambatan,
                 jp.nama_jenis,
                 (a.jumlah_angsuran + COALESCE(a.denda, 0)) as total_bayar
          FROM angsuran a 
          JOIN pinjaman p ON a.pinjaman_id = p.id 
          JOIN anggota ag ON p.anggota_id = ag.id
          JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id 
          WHERE (a.denda > 0 OR a.status IN ('belum_bayar', 'telat') AND a.tanggal_jatuh_tempo < CURDATE())
          ORDER BY a.tanggal_jatuh_tempo ASC";

$stmt = $db->prepare($query);
$stmt->execute();
$keterlambatan_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics - PERBAIKAN: Hitung total denda dari semua angsuran
$query_stats = "SELECT 
    COUNT(*) as total_tunggakan,
    SUM(COALESCE(denda, 0)) as total_denda,
    AVG(DATEDIFF(CURDATE(), tanggal_jatuh_tempo)) as rata_keterlambatan,
    COUNT(CASE WHEN DATEDIFF(CURDATE(), tanggal_jatuh_tempo) > 30 THEN 1 END) as keterlambatan_berat,
    COUNT(CASE WHEN denda > 0 THEN 1 END) as total_kena_denda
    FROM angsuran 
    WHERE (denda > 0 OR (status IN ('belum_bayar', 'telat') AND tanggal_jatuh_tempo < CURDATE()))";

$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Hitung ulang SEMUA denda otomatis sekarang (kecuali yang diinput manual)
if (isset($_GET['update_auto_denda'])) {
    $jumlah = perbaruiDendaOtomatis($db, true);
    $_SESSION['success'] = "Denda otomatis dihitung ulang: " . $jumlah . " angsuran diperbarui (denda manual tidak diubah)";
    header("Location: denda.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Denda & Keterlambatan - <?php echo APP_NAME; ?></title>
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
        .keterlambatan-berat {
            background-color: #fff3cd !important;
            border-left: 4px solid #ffc107;
        }
        .btn-action {
            margin: 2px;
        }
        .auto-denda-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
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
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-exclamation-triangle me-2"></i>Denda & Keterlambatan
                    </h1>
                    <div>
                        <a href="?update_auto_denda=1" class="btn auto-denda-btn">
                            <i class="fas fa-calculator me-1"></i> Hitung Denda Otomatis
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
                        <div class="card stat-card bg-danger text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['total_tunggakan']; ?></h4>
                                        <p>Total Tunggakan</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-clock fa-2x"></i>
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
                                        <h4><?php echo round($stats['rata_keterlambatan'], 1); ?> hr</h4>
                                        <p>Rata Keterlambatan</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-calendar-times fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo formatRupiah($stats['total_denda']); ?></h4>
                                        <p>Total Denda</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-money-bill-wave fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-dark text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['total_kena_denda']; ?></h4>
                                        <p>Kena Denda</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-exclamation-circle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Keterlambatan Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>Daftar Angsuran dengan Denda
                        </h5>
                        <div>
                            <span class="text-muted me-2">
                                <?php echo count($keterlambatan_list); ?> data ditemukan
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($keterlambatan_list)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h5 class="text-muted">Tidak ada angsuran dengan denda</h5>
                            <p class="text-muted">Semua angsuran dibayar tepat waktu atau belum ada yang dikenakan denda.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Kode Pinjaman</th>
                                        <th>Anggota</th>
                                        <th>Jenis Pinjaman</th>
                                        <th>Angsuran Ke</th>
                                        <th>Jatuh Tempo</th>
                                        <th>Keterlambatan</th>
                                        <th>Angsuran</th>
                                        <th>Denda</th>
                                        <th>Total Bayar</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($keterlambatan_list as $keterlambatan): ?>
                                    <tr class="<?php echo $keterlambatan['hari_keterlambatan'] > 30 ? 'keterlambatan-berat' : ''; ?>">
                                        <td>
                                            <strong><?php echo $keterlambatan['kode_pinjaman']; ?></strong>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo $keterlambatan['nama_lengkap']; ?></div>
                                            <small class="text-muted"><?php echo $keterlambatan['kode_anggota']; ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo $keterlambatan['nama_jenis']; ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $keterlambatan['angsuran_ke']; ?></span>
                                        </td>
                                        <td>
                                            <small><?php echo date('d/m/Y', strtotime($keterlambatan['tanggal_jatuh_tempo'])); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($keterlambatan['hari_keterlambatan'] > 0): ?>
                                                <?php if ($keterlambatan['hari_keterlambatan'] > 30): ?>
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-exclamation-circle me-1"></i>
                                                        <?php echo $keterlambatan['hari_keterlambatan']; ?> hari
                                                    </span>
                                                <?php elseif ($keterlambatan['hari_keterlambatan'] > 15): ?>
                                                    <span class="badge bg-warning">
                                                        <?php echo $keterlambatan['hari_keterlambatan']; ?> hari
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">
                                                        <?php echo $keterlambatan['hari_keterlambatan']; ?> hari
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-success">Tepat waktu</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-primary"><?php echo formatRupiah($keterlambatan['jumlah_angsuran']); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-danger"><?php echo formatRupiah($keterlambatan['denda']); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-success"><?php echo formatRupiah($keterlambatan['total_bayar']); ?></div>
                                        </td>
                                        <td>
                                            <?php 
                                            $status = $keterlambatan['status'];
                                            if ($status == 'telat') {
                                                echo '<span class="badge bg-warning status-badge">Telat</span>';
                                            } elseif ($status == 'belum_bayar') {
                                                echo '<span class="badge bg-danger status-badge">Belum Bayar</span>';
                                            } elseif ($status == 'lunas') {
                                                echo '<span class="badge bg-success status-badge">Lunas</span>';
                                            } else {
                                                echo '<span class="badge bg-secondary status-badge">' . $status . '</span>';
                                            }
                                            ?>
                                        </td>
                                        <td class="table-actions">
                                            <!-- View Details Button -->
                                            <button class="btn btn-sm btn-info btn-action" data-bs-toggle="modal" 
                                                    data-bs-target="#detailModal" 
                                                    data-kode="<?php echo $keterlambatan['kode_pinjaman']; ?>"
                                                    data-nama="<?php echo $keterlambatan['nama_lengkap']; ?>"
                                                    data-kode_anggota="<?php echo $keterlambatan['kode_anggota']; ?>"
                                                    data-jenis="<?php echo $keterlambatan['nama_jenis']; ?>"
                                                    data-angsuran_ke="<?php echo $keterlambatan['angsuran_ke']; ?>"
                                                    data-tanggal_tempo="<?php echo date('d/m/Y', strtotime($keterlambatan['tanggal_jatuh_tempo'])); ?>"
                                                    data-keterlambatan="<?php echo $keterlambatan['hari_keterlambatan']; ?>"
                                                    data-total_angsuran="<?php echo $keterlambatan['jumlah_angsuran']; ?>"
                                                    data-denda="<?php echo $keterlambatan['denda']; ?>"
                                                    data-total_bayar="<?php echo $keterlambatan['total_bayar']; ?>"
                                                    data-status="<?php echo $keterlambatan['status']; ?>"
                                                    title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </button>

                                            <?php
                                            $d_auto = hitungDendaAngsuran($db, $keterlambatan, $keterlambatan['jumlah_pinjaman']);
                                            $d_info = 'Otomatis: Rp ' . number_format($d_auto['per_hari'], 0, ',', '.') . '/hari x ' . $d_auto['hari_dihitung'] . ' hari = Rp ' . number_format($d_auto['denda'], 0, ',', '.');
                                            ?>
                                            <!-- Update Denda Button -->
                                            <button class="btn btn-sm btn-warning btn-action" data-bs-toggle="modal" 
                                                    data-bs-target="#dendaModal" 
                                                    data-id="<?php echo $keterlambatan['id']; ?>"
                                                    data-kode="<?php echo $keterlambatan['kode_pinjaman']; ?>"
                                                    data-nama="<?php echo $keterlambatan['nama_lengkap']; ?>"
                                                    data-angsuran_ke="<?php echo $keterlambatan['angsuran_ke']; ?>"
                                                    data-keterlambatan="<?php echo $keterlambatan['hari_keterlambatan']; ?>"
                                                    data-denda="<?php echo $keterlambatan['denda']; ?>"
                                                    data-denda_otomatis="<?php echo $d_auto['denda']; ?>"
                                                    data-denda_info="<?php echo htmlspecialchars($d_info); ?>"
                                                    data-manual="<?php echo !empty($keterlambatan['denda_manual']) ? 1 : 0; ?>"
                                                    data-jumlah_angsuran="<?php echo $keterlambatan['jumlah_angsuran']; ?>"
                                                    title="Atur Denda">
                                                <i class="fas fa-edit"></i>
                                            </button>

                                            <!-- Bayar Button -->
                                            <a href="angsuran.php?pinjaman_id=<?php echo $keterlambatan['pinjaman_id']; ?>" 
                                               class="btn btn-sm btn-success btn-action" title="Bayar Angsuran">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </a>
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
                    <h5 class="modal-title">Detail Angsuran dengan Denda</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Kode Pinjaman</th>
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
                                    <th width="40%">Angsuran Ke</th>
                                    <td id="detail_angsuran_ke"></td>
                                </tr>
                                <tr>
                                    <th>Tanggal Jatuh Tempo</th>
                                    <td id="detail_tanggal_tempo"></td>
                                </tr>
                                <tr>
                                    <th>Keterlambatan</th>
                                    <td id="detail_keterlambatan"></td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td id="detail_status"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Angsuran Pokok</label>
                                <div id="detail_total_angsuran" class="p-2 bg-light rounded fw-bold text-primary"></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Denda</label>
                                <div id="detail_denda" class="p-2 bg-light rounded fw-bold text-danger"></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Total Bayar</label>
                                <div id="detail_total_bayar" class="p-2 bg-light rounded fw-bold text-success"></div>
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

    <!-- Denda Modal -->
    <div class="modal fade" id="dendaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title text-warning">Pengaturan Denda</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="denda_id">
                        <p>Atur denda untuk:</p>
                        <p class="fw-bold" id="denda_nama"></p>
                        <p><strong>Kode Pinjaman:</strong> <span id="denda_kode"></span></p>
                        <p><strong>Angsuran Ke:</strong> <span id="denda_angsuran_ke"></span></p>
                        <p><strong>Keterlambatan:</strong> <span id="denda_keterlambatan"></span> hari</p>
                        <p><strong>Jumlah Angsuran:</strong> <span id="denda_jumlah_angsuran"></span></p>
                        
                        <div class="mb-3">
                            <label for="denda_jumlah" class="form-label">Jumlah Denda</label>
                            <input type="number" class="form-control" id="denda_jumlah" name="denda" min="0" step="1" placeholder="Kosongkan untuk otomatis">
                            <div class="form-text">
                                Denda otomatis: <span id="denda_otomatis" class="text-info"></span>
                                <span id="denda_status_manual" class="badge bg-warning text-dark ms-1 d-none">diatur manual</span>
                            </div>
                            <div class="form-text">Isi angka untuk denda manual; kosongkan lalu simpan untuk kembali ke hitungan otomatis.</div>
                        </div>
                        
                        <div class="alert alert-info">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                Denda akan ditambahkan ke total pembayaran angsuran.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="update_denda" class="btn btn-warning">Update Denda</button>
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
            document.getElementById('detail_angsuran_ke').textContent = button.getAttribute('data-angsuran_ke');
            document.getElementById('detail_tanggal_tempo').textContent = button.getAttribute('data-tanggal_tempo');
            
            var keterlambatan = button.getAttribute('data-keterlambatan');
            if (keterlambatan > 0) {
                document.getElementById('detail_keterlambatan').innerHTML = '<span class="badge bg-danger">' + keterlambatan + ' hari</span>';
            } else {
                document.getElementById('detail_keterlambatan').innerHTML = '<span class="badge bg-success">Tepat waktu</span>';
            }
            
            var status = button.getAttribute('data-status');
            var statusClass = {
                'belum_bayar': 'danger',
                'telat': 'warning',
                'lunas': 'success'
            };
            var statusText = {
                'belum_bayar': 'Belum Bayar',
                'telat': 'Telat',
                'lunas': 'Lunas'
            };
            document.getElementById('detail_status').innerHTML = '<span class="badge bg-' + (statusClass[status] || 'secondary') + '">' + (statusText[status] || status) + '</span>';
            
            document.getElementById('detail_total_angsuran').textContent = 'Rp ' + Number(button.getAttribute('data-total_angsuran')).toLocaleString('id-ID');
            document.getElementById('detail_denda').textContent = 'Rp ' + Number(button.getAttribute('data-denda')).toLocaleString('id-ID');
            document.getElementById('detail_total_bayar').textContent = 'Rp ' + Number(button.getAttribute('data-total_bayar')).toLocaleString('id-ID');
        });

        // Denda Modal
        var dendaModal = document.getElementById('dendaModal');
        dendaModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('denda_id').value = button.getAttribute('data-id');
            document.getElementById('denda_nama').textContent = button.getAttribute('data-nama');
            document.getElementById('denda_kode').textContent = button.getAttribute('data-kode');
            document.getElementById('denda_angsuran_ke').textContent = button.getAttribute('data-angsuran_ke');
            document.getElementById('denda_keterlambatan').textContent = button.getAttribute('data-keterlambatan') + ' hari';
            document.getElementById('denda_jumlah_angsuran').textContent = 'Rp ' + Number(button.getAttribute('data-jumlah_angsuran')).toLocaleString('id-ID');
            document.getElementById('denda_jumlah').value = button.getAttribute('data-denda');
            
            // Denda otomatis dihitung server (tarif per hari menurut besar pinjaman)
            document.getElementById('denda_otomatis').textContent = button.getAttribute('data-denda_info');
            document.getElementById('denda_status_manual').classList.toggle('d-none', button.getAttribute('data-manual') !== '1');
        });

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
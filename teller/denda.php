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

$page_title = "Denda & Keterlambatan";

// Denda otomatis (sekali sehari); denda manual tidak ditimpa
perbaruiDendaOtomatis($db);

// PERBAIKAN: Hapus semua fungsi pembayaran denda
// Teller hanya bisa melihat, tidak bisa melakukan pembayaran denda

// Filter daftar denda
$filter_q = trim($_GET['q'] ?? '');
$filter_jenis = (int) ($_GET['jenis'] ?? 0);
$filter_jaminan = $_GET['jaminan'] ?? '';
$filter_telat = $_GET['telat'] ?? '';
$filter_mulai = $_GET['mulai'] ?? '';
$filter_sampai = $_GET['sampai'] ?? '';
$filter_params = [];
$filter_where = ["(a.denda > 0 OR (a.status IN ('belum_bayar', 'telat') AND a.tanggal_jatuh_tempo < CURDATE()))"];
if ($filter_q !== '') {
    $filter_where[] = '(ag.nama_lengkap LIKE :q_nama OR ag.kode_anggota LIKE :q_anggota OR p.kode_pinjaman LIKE :q_pinjaman)';
    $filter_params[':q_nama'] = $filter_params[':q_anggota'] = $filter_params[':q_pinjaman'] = '%' . $filter_q . '%';
}
if ($filter_jenis > 0) { $filter_where[] = 'p.jenis_pinjaman_id = :jenis'; $filter_params[':jenis'] = $filter_jenis; }
if (in_array($filter_jaminan, ['0', '1'], true)) { $filter_where[] = 'COALESCE(jp.memerlukan_jaminan, 0) = :jaminan'; $filter_params[':jaminan'] = (int) $filter_jaminan; }
if ($filter_telat === 'ringan') { $filter_where[] = 'DATEDIFF(CURDATE(), a.tanggal_jatuh_tempo) BETWEEN 1 AND 15'; }
elseif ($filter_telat === 'sedang') { $filter_where[] = 'DATEDIFF(CURDATE(), a.tanggal_jatuh_tempo) BETWEEN 16 AND 30'; }
elseif ($filter_telat === 'berat') { $filter_where[] = 'DATEDIFF(CURDATE(), a.tanggal_jatuh_tempo) > 30'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_mulai)) { $filter_where[] = 'a.tanggal_jatuh_tempo >= :mulai'; $filter_params[':mulai'] = $filter_mulai; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_sampai)) { $filter_where[] = 'a.tanggal_jatuh_tempo <= :sampai'; $filter_params[':sampai'] = $filter_sampai; }

// PERBAIKAN: Query yang SAMA dengan admin/denda.php
$query = "SELECT a.*, p.kode_pinjaman, p.anggota_id, ag.nama_lengkap, ag.kode_anggota,
                 DATEDIFF(CURDATE(), a.tanggal_jatuh_tempo) as hari_keterlambatan,
                 jp.nama_jenis,
                 (a.jumlah_angsuran + COALESCE(a.denda, 0)) as total_bayar
          FROM angsuran a 
          JOIN pinjaman p ON a.pinjaman_id = p.id 
          JOIN anggota ag ON p.anggota_id = ag.id
          JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id 
          WHERE " . implode(' AND ', $filter_where) . "
          ORDER BY a.tanggal_jatuh_tempo ASC";

$stmt = $db->prepare($query);
$stmt->execute($filter_params);
$keterlambatan_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
$jenis_filter = $db->query("SELECT id, nama_jenis FROM jenis_pinjaman ORDER BY nama_jenis")->fetchAll(PDO::FETCH_ASSOC);

// PERBAIKAN: Get statistics yang SAMA dengan admin/denda.php
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
        .keterlambatan-berat {
            background-color: #fff3cd !important;
            border-left: 4px solid #ffc107;
        }
        .btn-action {
            margin: 2px;
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
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-exclamation-triangle me-2"></i>Denda & Keterlambatan
                    </h1>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <!-- Info Alert -->
                <div class="alert info-alert alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-info-circle text-primary me-3 fa-lg"></i>
                        <div>
                            <h6 class="alert-heading mb-1">Informasi Akses</h6>
                            <p class="mb-0">Halaman ini hanya menampilkan data denda dan keterlambatan. 
                            <strong>Hanya Administrator yang berhak melakukan pembayaran dan pengaturan denda.</strong></p>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards - SAMA DENGAN ADMIN -->
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

                <!-- Keterlambatan Table - HANYA VIEW -->
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
                        <?php include '../includes/filter_denda.php'; ?>
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
                                    <i class="fas fa-info-circle me-2"></i>Informasi Status
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <span class="badge bg-warning me-2">Telat</span>
                                    <small>Angsuran melewati tanggal jatuh tempo</small>
                                </div>
                                <div class="mb-3">
                                    <span class="badge bg-danger me-2">Belum Bayar</span>
                                    <small>Angsuran belum dibayar dan sudah melewati jatuh tempo</small>
                                </div>
                                <div class="mb-3">
                                    <span class="badge bg-success me-2">Lunas</span>
                                    <small>Angsuran sudah dibayar lengkap</small>
                                </div>
                                <div>
                                    <span class="badge bg-info me-2">Keterlambatan Berat</span>
                                    <small>Angsuran terlambat lebih dari 30 hari (warna kuning)</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-shield-alt me-2"></i>Hak Akses Teller
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning mb-0">
                                    <small>
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>Perhatian:</strong> Sebagai Teller, Anda hanya memiliki akses untuk:
                                        <ul class="mt-2 mb-0">
                                            <li>Melihat data denda dan keterlambatan</li>
                                            <li>Melakukan pembayaran angsuran</li>
                                            <li>Melihat riwayat transaksi</li>
                                        </ul>
                                        <strong class="mt-2 d-block">Tidak termasuk:</strong>
                                        <ul class="mb-0">
                                            <li>Melakukan pembayaran denda</li>
                                            <li>Mengatur atau mengedit jumlah denda</li>
                                            <li>Menghitung denda otomatis</li>
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

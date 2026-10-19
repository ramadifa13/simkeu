<?php
require_once __DIR__ . '/../config/bootstrap.php';

// Include config dan auth dengan path yang benar
require_once '../config/config.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

// Check if user has access to this page (teller only)
if ($_SESSION['role'] !== 'teller') {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: dashboard.php");
    exit();
}

$page_title = "Pelunasan Pinjaman";

// Pertahankan tampilan pelunasan lama; pencatatan kas tetap melalui layanan bersama.
require_once __DIR__.'/../includes/layanan_actions.php';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['lunaskan'])) {
    $files=[];
    try {
        layananValidasiToken();
        if (!layananSiap($db)) throw new Exception('Jalankan migrasi layanan terlebih dahulu.');
        $db->beginTransaction();
        $_POST['jumlah']=$_POST['jumlah_pelunasan'] ?? '';
        $_POST['catatan']=$_POST['keterangan'] ?? '';
        $_POST['metode']=$_POST['metode'] ?? 'cash';
        prosesLayanan($db,'pelunasan_langsung',(int)($_POST['id'] ?? 0),$files);
        $db->commit();
        $_SESSION['success']='Pinjaman berhasil dilunaskan dan pembayaran dicatat ke kas.';
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($files as $file) hapusFotoUpload($file);
        $_SESSION['error']='Gagal melunaskan pinjaman: '.$e->getMessage();
    }
    header('Location: pelunasan.php');exit;
}
perbaruiDendaOtomatis($db);

// Filter daftar pelunasan
$filter_q = trim($_GET['q'] ?? '');
$filter_jenis = (int) ($_GET['jenis'] ?? 0);
$filter_jaminan = $_GET['jaminan'] ?? '';
$filter_frekuensi = $_GET['frekuensi'] ?? '';
$filter_mulai = $_GET['mulai'] ?? '';
$filter_sampai = $_GET['sampai'] ?? '';
$filter_params = [];
$filter_where = ["p.status = 'aktif'"];
if ($filter_q !== '') {
    $filter_where[] = '(a.nama_lengkap LIKE :q_nama OR a.kode_anggota LIKE :q_anggota OR p.kode_pinjaman LIKE :q_pinjaman)';
    $filter_params[':q_nama'] = $filter_params[':q_anggota'] = $filter_params[':q_pinjaman'] = '%' . $filter_q . '%';
}
if ($filter_jenis > 0) { $filter_where[] = 'p.jenis_pinjaman_id = :jenis'; $filter_params[':jenis'] = $filter_jenis; }
if (in_array($filter_jaminan, ['0', '1'], true)) { $filter_where[] = 'COALESCE(jp.memerlukan_jaminan, 0) = :jaminan'; $filter_params[':jaminan'] = (int) $filter_jaminan; }
if (in_array($filter_frekuensi, ['bulanan', 'mingguan', 'dua_mingguan'], true)) { $filter_where[] = 'p.frekuensi = :frekuensi'; $filter_params[':frekuensi'] = $filter_frekuensi; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_mulai)) { $filter_where[] = 'DATE(p.tanggal_pencairan) >= :mulai'; $filter_params[':mulai'] = $filter_mulai; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_sampai)) { $filter_where[] = 'DATE(p.tanggal_pencairan) <= :sampai'; $filter_params[':sampai'] = $filter_sampai; }

// Get active loans that can be settled early
$query = "SELECT p.*, a.nama_lengkap, a.kode_anggota, jp.nama_jenis, jp.memerlukan_jaminan,
                 (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status IN ('belum_bayar','telat')) as sisa_angsuran,
                 (SELECT SUM(jumlah_angsuran + denda) FROM angsuran WHERE pinjaman_id = p.id AND status IN ('belum_bayar','telat')) as total_sisa_bayar
          FROM pinjaman p
          JOIN anggota a ON p.anggota_id = a.id
          JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
          WHERE " . implode(' AND ', $filter_where) . "
          ORDER BY p.tanggal_pencairan DESC";

$stmt = $db->prepare($query);
$stmt->execute($filter_params);
$pinjaman_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (layananSiap($db)) foreach ($pinjaman_list as &$pinjaman) {
    $sisaLunas=layananSisa($db,$pinjaman['id']);
    $pinjaman['total_sisa_bayar']=$sisaLunas['total'];
} unset($pinjaman);
$jenis_filter = $db->query("SELECT id, nama_jenis FROM jenis_pinjaman ORDER BY nama_jenis")->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$query_stats = "SELECT
    COUNT(*) as total_pinjaman,
    COUNT(CASE WHEN status = 'aktif' THEN 1 END) as aktif,
    COUNT(CASE WHEN status = 'lunas' THEN 1 END) as lunas,
    SUM(sisa_pokok) as total_sisa_pokok
    FROM pinjaman";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pelunasan Pinjaman - <?php echo APP_NAME; ?></title>
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
        .progress {
            height: 8px;
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
                        <i class="fas fa-flag-checkered me-2"></i>Pelunasan Pinjaman
                    </h1>
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
                                        <h4><?php echo $stats['total_pinjaman']; ?></h4>
                                        <p>Total Pinjaman</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-hand-holding-usd fa-2x"></i>
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
                                        <h4><?php echo $stats['aktif']; ?></h4>
                                        <p>Pinjaman Aktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-play-circle fa-2x"></i>
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
                                        <h4><?php echo $stats['lunas']; ?></h4>
                                        <p>Lunas</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-flag-checkered fa-2x"></i>
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
                                        <h4><?php echo formatRupiah($stats['total_sisa_pokok']); ?></h4>
                                        <p>Total Sisa Pokok</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-money-bill-wave fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pinjaman Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>Daftar Pinjaman yang Dapat Dilunaskan
                        </h5>
                        <div>
                            <span class="text-muted me-2">
                                <?php echo count($pinjaman_list); ?> data ditemukan
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php include '../includes/filter_pelunasan.php'; ?>
                        <?php if (empty($pinjaman_list)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h5 class="text-muted">Tidak ada pinjaman yang dapat dilunaskan</h5>
                            <p class="text-muted">Semua pinjaman telah lunas atau belum ada pinjaman aktif.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Kode Pinjaman</th>
                                        <th>Anggota</th>
                                        <th>Jenis Pinjaman</th>
                                        <th>Jumlah Pinjaman</th>
                                        <th>Sisa Pokok</th>
                                        <th>Sisa Angsuran</th>
                                        <th>Total Sisa Bayar</th>
                                        <th>Progress</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pinjaman_list as $pinjaman):
                                        $progress = (($pinjaman['jumlah_pinjaman'] - $pinjaman['sisa_pokok']) / $pinjaman['jumlah_pinjaman']) * 100;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $pinjaman['kode_pinjaman']; ?></strong>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo $pinjaman['nama_lengkap']; ?></div>
                                            <small class="text-muted"><?php echo $pinjaman['kode_anggota']; ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo $pinjaman['nama_jenis']; ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-primary"><?php echo formatRupiah($pinjaman['jumlah_pinjaman']); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-warning"><?php echo formatRupiah($pinjaman['sisa_pokok']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $pinjaman['sisa_angsuran']; ?> angsuran</span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-danger"><?php echo formatRupiah($pinjaman['total_sisa_bayar']); ?></div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                                    <div class="progress-bar bg-success" role="progressbar"
                                                         style="width: <?php echo $progress; ?>%"
                                                         aria-valuenow="<?php echo $progress; ?>"
                                                         aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <small class="text-muted"><?php echo round($progress, 1); ?>%</small>
                                            </div>
                                        </td>
                                        <td class="table-actions">
                                            <!-- View Details Button -->
                                            <button class="btn btn-sm btn-info" data-bs-toggle="modal"
                                                    data-bs-target="#detailModal"
                                                    data-kode="<?php echo $pinjaman['kode_pinjaman']; ?>"
                                                    data-nama="<?php echo $pinjaman['nama_lengkap']; ?>"
                                                    data-kode_anggota="<?php echo $pinjaman['kode_anggota']; ?>"
                                                    data-jenis="<?php echo $pinjaman['nama_jenis']; ?>"
                                                    data-jumlah_pinjaman="<?php echo $pinjaman['jumlah_pinjaman']; ?>"
                                                    data-sisa_pokok="<?php echo $pinjaman['sisa_pokok']; ?>"
                                                    data-sisa_angsuran="<?php echo $pinjaman['sisa_angsuran']; ?>"
                                                    data-total_sisa_bayar="<?php echo $pinjaman['total_sisa_bayar']; ?>"
                                                    data-progress="<?php echo round($progress, 1); ?>"
                                                    data-tanggal_pencairan="<?php echo date('d/m/Y', strtotime($pinjaman['tanggal_pencairan'])); ?>"
                                                    data-jatuh_tempo="<?php echo date('d/m/Y', strtotime($pinjaman['jatuh_tempo'])); ?>"
                                                    title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </button>

                                            <!-- Lunaskan Button -->
                                            <button class="btn btn-sm btn-success" data-bs-toggle="modal"
                                                    data-bs-target="#lunaskanModal"
                                                    data-id="<?php echo $pinjaman['id']; ?>"
                                                    data-kode="<?php echo $pinjaman['kode_pinjaman']; ?>"
                                                    data-nama="<?php echo $pinjaman['nama_lengkap']; ?>"
                                                    data-sisa_pokok="<?php echo $pinjaman['sisa_pokok']; ?>"
                                                    data-total_sisa_bayar="<?php echo $pinjaman['total_sisa_bayar']; ?>"
                                                    title="Lunaskan">
                                                <i class="fas fa-flag-checkered"></i>
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
                    <h5 class="modal-title">Detail Pinjaman</h5>
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
                                    <th width="40%">Tanggal Pencairan</th>
                                    <td id="detail_tanggal_pencairan"></td>
                                </tr>
                                <tr>
                                    <th>Jatuh Tempo</th>
                                    <td id="detail_jatuh_tempo"></td>
                                </tr>
                                <tr>
                                    <th>Progress</th>
                                    <td id="detail_progress"></td>
                                </tr>
                                <tr>
                                    <th>Sisa Angsuran</th>
                                    <td id="detail_sisa_angsuran"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Jumlah Pinjaman</label>
                                <div id="detail_jumlah_pinjaman" class="p-2 bg-light rounded fw-bold text-primary"></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Sisa Pokok</label>
                                <div id="detail_sisa_pokok" class="p-2 bg-light rounded fw-bold text-warning"></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Total Sisa Bayar</label>
                                <div id="detail_total_sisa_bayar" class="p-2 bg-light rounded fw-bold text-danger"></div>
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

    <!-- Lunaskan Modal -->
    <div class="modal fade" id="lunaskanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title text-success">Pelunasan Pinjaman</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?= layananToken() ?>
                        <input type="hidden" name="id" id="lunaskan_id">
                        <label class="form-label">Metode pembayaran</label><select name="metode" class="form-select mb-3"><option value="cash">Cash</option><option value="transfer">Transfer</option></select>
                        <p>Apakah Anda yakin ingin melunaskan pinjaman:</p>
                        <p class="fw-bold" id="lunaskan_nama"></p>
                        <p><strong>Kode Pinjaman:</strong> <span id="lunaskan_kode"></span></p>

                        <div class="alert alert-info">
                            <h6>Detail Pelunasan</h6>
                            <p class="mb-1">Sisa Pokok: <span id="lunaskan_sisa_pokok" class="fw-bold"></span></p>
                            <p class="mb-0">Total Sisa Bayar: <span id="lunaskan_total_sisa_bayar" class="fw-bold text-danger"></span></p>
                        </div>

                        <div class="mb-3">
                            <label for="lunaskan_jumlah" class="form-label">Jumlah Pelunasan *</label>
                            <input type="number" class="form-control" id="lunaskan_jumlah" name="jumlah_pelunasan" min="0" step="0.01" readonly required>
                            <div class="form-text">Total dihitung dari sisa tagihan setelah pembayaran denda dan kredit yang telah tercatat.</div>
                        </div>

                        <div class="mb-3">
                            <label for="lunaskan_keterangan" class="form-label">Keterangan (Opsional)</label>
                            <textarea class="form-control" id="lunaskan_keterangan" name="keterangan" rows="3" placeholder="Catatan pelunasan..."></textarea>
                        </div>

                        <div class="alert alert-warning">
                            <small>
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Dengan melunaskan, semua angsuran yang tersisa akan ditandai sebagai lunas.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="lunaskan" class="btn btn-success">Konfirmasi Pelunasan</button>
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
            document.getElementById('detail_tanggal_pencairan').textContent = button.getAttribute('data-tanggal_pencairan');
            document.getElementById('detail_jatuh_tempo').textContent = button.getAttribute('data-jatuh_tempo');
            document.getElementById('detail_progress').innerHTML = '<span class="badge bg-success">' + button.getAttribute('data-progress') + '%</span>';
            document.getElementById('detail_sisa_angsuran').innerHTML = '<span class="badge bg-info">' + button.getAttribute('data-sisa_angsuran') + ' angsuran</span>';

            document.getElementById('detail_jumlah_pinjaman').textContent = 'Rp ' + Number(button.getAttribute('data-jumlah_pinjaman')).toLocaleString('id-ID');
            document.getElementById('detail_sisa_pokok').textContent = 'Rp ' + Number(button.getAttribute('data-sisa_pokok')).toLocaleString('id-ID');
            document.getElementById('detail_total_sisa_bayar').textContent = 'Rp ' + Number(button.getAttribute('data-total_sisa_bayar')).toLocaleString('id-ID');
        });

        // Lunaskan Modal
        var lunaskanModal = document.getElementById('lunaskanModal');
        lunaskanModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('lunaskan_id').value = button.getAttribute('data-id');
            document.getElementById('lunaskan_nama').textContent = button.getAttribute('data-nama');
            document.getElementById('lunaskan_kode').textContent = button.getAttribute('data-kode');

            var totalSisaBayar = Number(button.getAttribute('data-total_sisa_bayar'));
            document.getElementById('lunaskan_sisa_pokok').textContent = 'Rp ' + Number(button.getAttribute('data-sisa_pokok')).toLocaleString('id-ID');
            document.getElementById('lunaskan_total_sisa_bayar').textContent = 'Rp ' + totalSisaBayar.toLocaleString('id-ID');
            document.getElementById('lunaskan_jumlah').value = totalSisaBayar;
        });
    </script>
</body>
</html>

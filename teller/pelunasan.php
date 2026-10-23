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
// Halaman ini hanya memiliki satu formulir POST, yaitu pelunasan. Jangan
// bergantung pada field tombol submit karena browser dapat menghilangkannya
// saat tombol dinonaktifkan oleh indikator "Memproses".
if ($_SERVER['REQUEST_METHOD']==='POST') {
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
        if (!empty($_POST['ambil_jaminan_langsung']) && ($_POST['mode_cetak_akad'] ?? '') === 'otomatis') {
            $bast=$db->prepare("SELECT id FROM layanan_jaminan_kembali WHERE pinjaman_id=? AND status='diserahkan' ORDER BY id DESC LIMIT 1");
            $bast->execute([(int)($_POST['id'] ?? 0)]);
            if ($bastId=(int)$bast->fetchColumn()) { header('Location: kwitansi_layanan.php?jenis=bast&id='.$bastId.'&kembali=pelunasan'); exit; }
        }
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
$query = "SELECT p.*, a.nama_lengkap, a.telepon, a.kode_anggota, jp.nama_jenis, jp.memerlukan_jaminan,
                 j.jenis_barang, j.identitas_barang, j.deskripsi_barang, j.foto_barang, j.foto_nasabah_barang,
                 (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status IN ('belum_bayar','telat')) as sisa_angsuran,
                 (SELECT SUM(jumlah_angsuran + denda) FROM angsuran WHERE pinjaman_id = p.id AND status IN ('belum_bayar','telat')) as total_sisa_bayar
          FROM pinjaman p
          JOIN anggota a ON p.anggota_id = a.id
          JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
          LEFT JOIN jaminan_pinjaman j ON j.pinjaman_id = p.id
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
        /* Area TTD harus menangkap sapuan jari, bukan menggeser modal di HP/tablet. */
        #lunaskanModal .signature-canvas-wrap {
            position: relative;
            overflow: hidden;
            border: 1px dashed #b9cdd2;
            border-radius: .5rem;
            background: repeating-linear-gradient(0deg, #fff, #fff 31px, #f5f9fa 32px);
        }
        #lunaskanModal canvas.signature {
            display: block;
            width: 100%;
            height: 220px !important;
            max-width: 100%;
            background: transparent;
            cursor: crosshair;
            touch-action: none;
            -webkit-user-select: none;
            user-select: none;
        }
        @media (max-width: 576px) {
            #lunaskanModal canvas.signature { height: 200px !important; }
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
                                                    data-telepon="<?php echo htmlspecialchars($pinjaman['telepon'] ?? '', ENT_QUOTES); ?>"
                                                    data-gadai="<?php echo !empty($pinjaman['memerlukan_jaminan']) ? '1' : '0'; ?>"
                                                    data-jaminan-jenis="<?php echo htmlspecialchars($pinjaman['jenis_barang'] ?? '', ENT_QUOTES); ?>"
                                                    data-jaminan-identitas="<?php echo htmlspecialchars($pinjaman['identitas_barang'] ?? '', ENT_QUOTES); ?>"
                                                    data-jaminan-deskripsi="<?php echo htmlspecialchars($pinjaman['deskripsi_barang'] ?? '', ENT_QUOTES); ?>"
                                                    data-jaminan-foto="<?php echo htmlspecialchars(urlFoto($pinjaman['foto_barang'] ?? ''), ENT_QUOTES); ?>"
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
                                                    data-telepon="<?php echo htmlspecialchars($pinjaman['telepon'] ?? '', ENT_QUOTES); ?>"
                                                    data-gadai="<?php echo !empty($pinjaman['memerlukan_jaminan']) ? '1' : '0'; ?>"
                                                    data-jaminan-jenis="<?php echo htmlspecialchars($pinjaman['jenis_barang'] ?? '', ENT_QUOTES); ?>"
                                                    data-jaminan-identitas="<?php echo htmlspecialchars($pinjaman['identitas_barang'] ?? '', ENT_QUOTES); ?>"
                                                    data-jaminan-deskripsi="<?php echo htmlspecialchars($pinjaman['deskripsi_barang'] ?? '', ENT_QUOTES); ?>"
                                                    data-jaminan-foto="<?php echo htmlspecialchars(urlFoto($pinjaman['foto_barang'] ?? ''), ENT_QUOTES); ?>"
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
                <form method="POST" enctype="multipart/form-data" id="formPelunasan">
                    <div class="modal-header">
                        <h5 class="modal-title text-success">Pelunasan Pinjaman</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="pelunasanFeedback" class="alert d-none" role="alert" aria-live="polite"></div>
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

                        <div id="konfirmasiJaminanPelunasan" class="border rounded p-3 mb-3 bg-light d-none">
                            <label class="form-check d-flex gap-2 align-items-start mb-3"><input class="form-check-input mt-1" type="checkbox" name="ambil_jaminan_langsung" id="ambilJaminanLangsung"><span><strong>Barang jaminan diambil sekarang</strong><br><small class="text-muted">Pilih hanya jika nasabah mengambil barang bersamaan dengan pelunasan.</small></span></label>
                            <div id="detailPengambilanJaminan" class="d-none">
                            <h6 class="text-success"><i class="fas fa-box-open me-1"></i>Konfirmasi Barang Jaminan</h6>
                            <p class="small mb-2" id="detailJaminanPelunasan"></p><img id="fotoJaminanPelunasan" class="img-fluid rounded border mb-3 d-none" style="max-height:180px;object-fit:contain" alt="Foto barang jaminan">
                            <label class="form-check d-flex gap-2 align-items-start mb-3"><input class="form-check-input mt-1" type="checkbox" name="cek_jaminan_nasabah" id="cekJaminanNasabah"><span>Saya sudah mengecek barang jaminan secara keseluruhan dan menyatakan barang dalam kondisi normal/tidak rusak.</span></label>
                            <label class="form-label">TTD Nasabah sebagai bukti pemeriksaan *</label>
                            <div class="signature-canvas-wrap"><canvas id="ttdJaminanPelunasan" width="420" height="154" class="signature" style="height:220px"></canvas></div>
                            <input type="hidden" name="ttd_jaminan_nasabah" id="ttdJaminanNasabah">
                            <button type="button" class="btn btn-sm btn-outline-danger mt-2 reset-ttd" data-canvas="ttdJaminanPelunasan"><i class="fas fa-eraser me-1"></i>Reset TTD Nasabah</button>
                            <label class="form-label mt-3">TTD Teller / petugas penyerahan *</label>
                            <div class="signature-canvas-wrap"><canvas id="ttdJaminanTellerCanvas" width="420" height="154" class="signature" style="height:220px"></canvas></div>
                            <input type="hidden" name="ttd_jaminan_teller" id="ttdJaminanTeller">
                            <button type="button" class="btn btn-sm btn-outline-danger mt-2 reset-ttd" data-canvas="ttdJaminanTellerCanvas"><i class="fas fa-eraser me-1"></i>Reset TTD Teller</button>
                            <small class="text-muted d-block mt-2">Tanda tangan nasabah dan Teller dicetak pada akad serah terima barang.</small>
                            <div class="mt-3"><label for="fotoSerahTerimaJaminan" class="form-label">Foto penyerahan barang jaminan *</label><input type="file" class="form-control" name="foto_serah_terima_jaminan" id="fotoSerahTerimaJaminan" accept="image/jpeg,image/png,image/webp"><div class="form-text">Foto kondisi barang saat diserahkan kepada nasabah. Maksimal 1 MB; otomatis dikompres sebelum dikirim.</div></div>
                            <div class="mt-3 pt-3 border-top"><label class="form-label fw-bold mb-2">Cetak akad serah terima</label><label class="form-check"><input class="form-check-input" type="radio" name="mode_cetak_akad" value="otomatis" checked> Otomatis — akad langsung terbuka dengan data, foto, dan TTD terisi.</label><label class="form-check mt-1"><input class="form-check-input" type="radio" name="mode_cetak_akad" value="manual"> Manual — formulir kosong dicetak dari Laporan Pelunasan.</label></div>
                            </div>
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
                        <a href="#" id="waPelunasan" class="btn btn-outline-success d-none" target="_blank" rel="noopener"><i class="fab fa-whatsapp me-1"></i>Cek WhatsApp</a>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="lunaskan" id="submitPelunasan" class="btn btn-success"><span class="submit-label">Konfirmasi Pelunasan</span></button>
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
        var ttdJaminanPads = {};
        function pasangTtdJaminan(canvasId,inputId){
            var c=document.getElementById(canvasId),x=c?c.getContext('2d'):null,drawing=false;
            if(!c)return;
            x.lineWidth=2.4;x.lineCap='round';x.lineJoin='round';x.strokeStyle='#183f49';
            function pos(e){var r=c.getBoundingClientRect();return [(e.clientX-r.left)*c.width/r.width,(e.clientY-r.top)*c.height/r.height]}
            // Kanvas dibuat lebih kecil agar data TTD tidak membebani POST/WAF server.
            // PNG tetap dipakai agar garis tanda tangan tajam saat dicetak.
            function simpan(){document.getElementById(inputId).value=c.toDataURL('image/png');}
            function berhenti(e){if(!drawing)return;drawing=false;if(c.hasPointerCapture && c.hasPointerCapture(e.pointerId))c.releasePointerCapture(e.pointerId);simpan();}
            c.addEventListener('pointerdown',function(e){
                e.preventDefault();drawing=true;c.setPointerCapture(e.pointerId);
                var p=pos(e);x.beginPath();x.moveTo(p[0],p[1]);
            },{passive:false});
            c.addEventListener('pointermove',function(e){
                if(!drawing)return;e.preventDefault();var p=pos(e);x.lineTo(p[0],p[1]);x.stroke();
            },{passive:false});
            ['pointerup','pointercancel','lostpointercapture'].forEach(function(ev){c.addEventListener(ev,berhenti);});
            ttdJaminanPads[canvasId]=function(){x.clearRect(0,0,c.width,c.height);document.getElementById(inputId).value='';drawing=false;};
        }
        pasangTtdJaminan('ttdJaminanPelunasan','ttdJaminanNasabah');pasangTtdJaminan('ttdJaminanTellerCanvas','ttdJaminanTeller');
        document.querySelectorAll('.reset-ttd').forEach(function(button){button.addEventListener('click',function(){var reset=ttdJaminanPads[button.dataset.canvas];if(reset)reset();});});
        function resetSemuaTtdJaminan(){Object.keys(ttdJaminanPads).forEach(function(canvasId){ttdJaminanPads[canvasId]();});}
        var lunaskanModal = document.getElementById('lunaskanModal');
        var formPelunasan = document.getElementById('formPelunasan'), feedbackPelunasan = document.getElementById('pelunasanFeedback'), submitPelunasan = document.getElementById('submitPelunasan');
        function tampilkanFeedbackPelunasan(pesan, tipe) { feedbackPelunasan.textContent=pesan; feedbackPelunasan.className='alert alert-'+tipe; feedbackPelunasan.classList.remove('d-none'); feedbackPelunasan.scrollIntoView({block:'nearest'}); }
        if (formPelunasan) formPelunasan.addEventListener('submit', function(e) {
            var ambil=document.getElementById('ambilJaminanLangsung'), cek=document.getElementById('cekJaminanNasabah'), nasabah=document.getElementById('ttdJaminanNasabah'), teller=document.getElementById('ttdJaminanTeller'), foto=document.getElementById('fotoSerahTerimaJaminan');
            feedbackPelunasan.classList.add('d-none');
            if (ambil && ambil.checked) {
                var kurang=[];
                if (!cek.checked) kurang.push('konfirmasi pemeriksaan barang');
                if (!nasabah.value) kurang.push('TTD digital nasabah');
                if (!teller.value) kurang.push('TTD digital Teller');
                if (!foto.files || !foto.files.length) kurang.push('foto penyerahan barang');
                if (kurang.length) { e.preventDefault(); tampilkanFeedbackPelunasan('Belum dapat diproses. Lengkapi: '+kurang.join(', ')+'.','danger'); return; }
            }
            submitPelunasan.disabled=true; submitPelunasan.querySelector('.submit-label').textContent='Memproses pelunasan...'; tampilkanFeedbackPelunasan('Data sedang disimpan. Mohon jangan menutup halaman.','info');
        });
        lunaskanModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            feedbackPelunasan.classList.add('d-none'); submitPelunasan.disabled=false; submitPelunasan.querySelector('.submit-label').textContent='Konfirmasi Pelunasan';
            document.getElementById('lunaskan_id').value = button.getAttribute('data-id');
            document.getElementById('lunaskan_nama').textContent = button.getAttribute('data-nama');
            document.getElementById('lunaskan_kode').textContent = button.getAttribute('data-kode');

            var totalSisaBayar = Number(button.getAttribute('data-total_sisa_bayar'));
            document.getElementById('lunaskan_sisa_pokok').textContent = 'Rp ' + Number(button.getAttribute('data-sisa_pokok')).toLocaleString('id-ID');
            document.getElementById('lunaskan_total_sisa_bayar').textContent = 'Rp ' + totalSisaBayar.toLocaleString('id-ID');
            document.getElementById('lunaskan_jumlah').value = totalSisaBayar;
            var wa = document.getElementById('waPelunasan');
            var telepon = (button.getAttribute('data-telepon') || '').replace(/[^0-9]/g, '');
            if (wa && button.getAttribute('data-gadai') === '1' && telepon) {
                if (telepon.indexOf('0') === 0) telepon = '62' + telepon.substring(1);
                wa.href = 'https://wa.me/' + telepon + '?text=' + encodeURIComponent('Halo ' + button.getAttribute('data-nama') + ', proses pelunasan pinjaman ' + button.getAttribute('data-kode') + ' sedang diproses.');
                wa.classList.remove('d-none');
            } else if (wa) { wa.classList.add('d-none'); wa.removeAttribute('href'); }
            var blokJaminan = document.getElementById('konfirmasiJaminanPelunasan'), detailJaminan = document.getElementById('detailJaminanPelunasan'), cekJaminan = document.getElementById('cekJaminanNasabah'), ttdJaminan = document.getElementById('ttdJaminanNasabah'), ttdJaminanTeller=document.getElementById('ttdJaminanTeller'), fotoSerahTerima=document.getElementById('fotoSerahTerimaJaminan');
            var isGadai = button.getAttribute('data-gadai') === '1';
            var ambilJaminan = document.getElementById('ambilJaminanLangsung');
            var detailPengambilan = document.getElementById('detailPengambilanJaminan');
            ambilJaminan.checked = false;
            function aturPengambilanJaminan() {
                var ambil = isGadai && ambilJaminan.checked;
                detailPengambilan.classList.toggle('d-none', !ambil);
                cekJaminan.required = ambil;
                fotoSerahTerima.required = ambil;
                if (!ambil) { cekJaminan.checked = false; resetSemuaTtdJaminan(); fotoSerahTerima.value=''; }
            }
            ambilJaminan.onchange = aturPengambilanJaminan;
            aturPengambilanJaminan();
            if (blokJaminan) { blokJaminan.classList.toggle('d-none', !isGadai); var fotoJaminan=document.getElementById('fotoJaminanPelunasan'); if (!isGadai) { cekJaminan.checked=false; ttdJaminan.value=''; fotoJaminan.classList.add('d-none'); } else { detailJaminan.textContent = [button.getAttribute('data-jaminan-jenis'), button.getAttribute('data-jaminan-identitas'), button.getAttribute('data-jaminan-deskripsi')].filter(Boolean).join(' · ') || 'Detail barang jaminan belum tersedia'; var foto=button.getAttribute('data-jaminan-foto')||'';fotoJaminan.src=foto;fotoJaminan.classList.toggle('d-none',!foto); } }
        });
    </script>
</body>
</html>

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

// Check if user has access to this page
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: dashboard.php");
    exit();
}

$page_title = "Pencairan Pinjaman";

// Keputusan TC: Admin memeriksa alasan Teller. Approve mempertahankan
// penundaan, Tolak membuka kembali pengajuan untuk diproses Teller.
if ($_POST && isset($_POST['keputusan_tc'])) {
    try {
        layananValidasiToken();
        $tcId=(int)($_POST['tc_id'] ?? 0); $aksi=$_POST['keputusan_tc']; $catatan=trim((string)($_POST['catatan_tc'] ?? ''));
        if (!in_array($aksi,['disetujui','ditolak'],true)) throw new Exception('Keputusan TC tidak valid.');
        if (strlen($catatan)<5 || strlen($catatan)>2000) throw new Exception('Keterangan Admin wajib 5–2000 karakter.');
        $stmt=$db->prepare("UPDATE layanan_tunda_cair SET status=?,admin_id=?,catatan_admin=?,diputuskan_at=NOW() WHERE id=? AND status='menunggu'");
        $stmt->execute([$aksi,$_SESSION['user_id'],$catatan,$tcId]);
        if(!$stmt->rowCount()) throw new Exception('Pengajuan TC tidak ditemukan atau sudah diputuskan.');
        $_SESSION['success']='Keputusan TC berhasil disimpan.';
    } catch(Exception $e) { $_SESSION['error']='Gagal menyimpan keputusan TC: '.$e->getMessage(); }
    header('Location: pencairan.php'); exit;
}

// ============================================
// SINKRONISASI DATA
// ============================================
if (isset($_GET['sync'])) {
    try {
        $db->beginTransaction();
        
        // Update status pengajuan yang sudah ada di tabel pinjaman menjadi 'dicairkan'
        $query_sync = "UPDATE pengajuan_pinjaman pp 
                       JOIN pinjaman p ON pp.id = p.pengajuan_id 
                       SET pp.status = 'dicairkan' 
                       WHERE pp.status != 'dicairkan'";
        $stmt_sync = $db->prepare($query_sync);
        $stmt_sync->execute();
        
        $affected = $stmt_sync->rowCount();
        
        $db->commit();
        $_SESSION['success'] = "Sinkronisasi data berhasil. $affected data diupdate.";
        header("Location: pencairan.php");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Gagal sinkronisasi: " . $e->getMessage();
    }
}

// Pencairan hanya dilakukan oleh Teller (teller/pencairan.php, wajib foto nasabah).
// Aksi pencairan dari halaman Admin ditolak di server.
if ($_POST && isset($_POST['cairkan'])) {
    $_SESSION['error'] = "Pencairan hanya dapat diproses oleh Teller";
    header("Location: pencairan.php");
    exit();
}

// ============================================
// AMBIL DATA PENGUJIAN YANG BENAR-BENAR SIAP DICAIRKAN
// ============================================

// Ambil semua pengajuan yang statusnya 'disetujui' 
// TAPI belum memiliki data di tabel pinjaman
$filter_cari = trim(isset($_GET['q']) ? $_GET['q'] : '');
$filter_tanggal_mulai = trim(isset($_GET['tanggal_mulai']) ? $_GET['tanggal_mulai'] : '');
$filter_tanggal_selesai = trim(isset($_GET['tanggal_selesai']) ? $_GET['tanggal_selesai'] : '');
if ($filter_tanggal_mulai !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_tanggal_mulai)) $filter_tanggal_mulai = '';
if ($filter_tanggal_selesai !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_tanggal_selesai)) $filter_tanggal_selesai = '';
if ($filter_tanggal_mulai !== '' && $filter_tanggal_selesai !== '' && $filter_tanggal_mulai > $filter_tanggal_selesai) {
    $tmp_tanggal = $filter_tanggal_mulai;
    $filter_tanggal_mulai = $filter_tanggal_selesai;
    $filter_tanggal_selesai = $tmp_tanggal;
}

$query = "SELECT pp.*, a.nama_lengkap,
          CASE WHEN jp.memerlukan_jaminan = 1 THEN COALESCE(a.kode_anggota_jaminan, a.kode_anggota) ELSE a.kode_anggota END AS kode_anggota,
          jp.nama_jenis, jp.maksimal_pinjaman, u.nama_lengkap as admin_name
          FROM pengajuan_pinjaman pp
          JOIN anggota a ON pp.anggota_id = a.id
          JOIN jenis_pinjaman jp ON pp.jenis_pinjaman_id = jp.id
          LEFT JOIN users u ON pp.admin_id = u.id
          WHERE pp.status = 'disetujui'
          AND pp.id NOT IN (SELECT pengajuan_id FROM pinjaman WHERE pengajuan_id IS NOT NULL)";
$params_siap = [];
if ($filter_cari !== '') {
    $query .= " AND (a.nama_lengkap LIKE :siap_nama OR a.kode_anggota LIKE :siap_kode
                OR COALESCE(a.kode_anggota_jaminan, '') LIKE :siap_jaminan OR pp.kode_pengajuan LIKE :siap_pengajuan)";
    $nilai_cari = '%' . $filter_cari . '%';
    $params_siap = [':siap_nama'=>$nilai_cari, ':siap_kode'=>$nilai_cari, ':siap_jaminan'=>$nilai_cari, ':siap_pengajuan'=>$nilai_cari];
}
$query .= " ORDER BY pp.tanggal_persetujuan DESC";
$stmt = $db->prepare($query);
$stmt->execute($params_siap);
$pengajuan_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tcMenunggu=$db->query("SELECT tc.*,pp.kode_pengajuan,a.nama_lengkap,a.kode_anggota,u.nama_lengkap teller FROM layanan_tunda_cair tc JOIN pengajuan_pinjaman pp ON pp.id=tc.pengajuan_id JOIN anggota a ON a.id=pp.anggota_id JOIN users u ON u.id=tc.user_id WHERE tc.status='menunggu' ORDER BY tc.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Get disbursed loans dengan JOIN lengkap
$query_dicairkan = "SELECT p.*, a.nama_lengkap,
                   CASE WHEN jp.memerlukan_jaminan = 1 THEN COALESCE(a.kode_anggota_jaminan, a.kode_anggota) ELSE a.kode_anggota END AS kode_anggota,
                   jp.nama_jenis, j.jenis_barang, j.foto_barang, j.foto_nasabah_barang, pr.id AS perjanjian_id,
                   (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status <> 'lunas') AS cicilan_belum,
          (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id) AS total_cicilan
                   FROM pinjaman p
                   LEFT JOIN anggota a ON p.anggota_id = a.id
                   LEFT JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                   LEFT JOIN jaminan_pinjaman j ON j.pinjaman_id = p.id
                   LEFT JOIN perjanjian_pinjaman pr ON pr.pinjaman_id = p.id
                   WHERE 1=1";
$params_dicairkan = [];
if ($filter_cari !== '') {
    $query_dicairkan .= " AND (a.nama_lengkap LIKE :cair_nama OR a.kode_anggota LIKE :cair_kode
                          OR COALESCE(a.kode_anggota_jaminan, '') LIKE :cair_jaminan OR p.kode_pinjaman LIKE :cair_pinjaman)";
    $nilai_cari = '%' . $filter_cari . '%';
    $params_dicairkan = [':cair_nama'=>$nilai_cari, ':cair_kode'=>$nilai_cari, ':cair_jaminan'=>$nilai_cari, ':cair_pinjaman'=>$nilai_cari];
}
if ($filter_tanggal_mulai !== '') {
    $query_dicairkan .= " AND p.tanggal_pencairan >= :tanggal_mulai";
    $params_dicairkan[':tanggal_mulai'] = $filter_tanggal_mulai;
}
if ($filter_tanggal_selesai !== '') {
    $query_dicairkan .= " AND p.tanggal_pencairan <= :tanggal_selesai";
    $params_dicairkan[':tanggal_selesai'] = $filter_tanggal_selesai;
}
$query_dicairkan .= " ORDER BY p.tanggal_pencairan DESC";
$stmt_dicairkan = $db->prepare($query_dicairkan);
$stmt_dicairkan->execute($params_dicairkan);
$dicairkan_list = $stmt_dicairkan->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// STATISTIK YANG AKURAT
// ============================================

// Hitung total pengajuan
$query_stats_pengajuan = "SELECT 
    COUNT(*) as total_pengajuan,
    COUNT(CASE WHEN status = 'menunggu' THEN 1 END) as menunggu,
    COUNT(CASE WHEN status = 'disetujui' THEN 1 END) as disetujui,
    COUNT(CASE WHEN status = 'ditolak' THEN 1 END) as ditolak
    FROM pengajuan_pinjaman";
$stmt_stats_pengajuan = $db->prepare($query_stats_pengajuan);
$stmt_stats_pengajuan->execute();
$stats_pengajuan = $stmt_stats_pengajuan->fetch(PDO::FETCH_ASSOC);

// Hitung jumlah pinjaman yang sudah dicairkan
$query_jumlah_dicairkan = "SELECT COUNT(*) as total FROM pinjaman";
$stmt_jumlah_dicairkan = $db->prepare($query_jumlah_dicairkan);
$stmt_jumlah_dicairkan->execute();
$jumlah_dicairkan = $stmt_jumlah_dicairkan->fetch(PDO::FETCH_ASSOC)['total'];

// Hitung pengajuan yang benar-benar siap dicairkan (disetujui tapi belum ada di pinjaman)
$query_siap_dicairkan = "SELECT COUNT(*) as total FROM pengajuan_pinjaman 
                         WHERE status = 'disetujui' 
                         AND id NOT IN (SELECT pengajuan_id FROM pinjaman)";
$stmt_siap_dicairkan = $db->prepare($query_siap_dicairkan);
$stmt_siap_dicairkan->execute();
$siap_dicairkan = $stmt_siap_dicairkan->fetch(PDO::FETCH_ASSOC)['total'];

// Gabungkan statistik
$stats = [
    'total_pengajuan' => $stats_pengajuan['total_pengajuan'],
    'menunggu' => $stats_pengajuan['menunggu'],
    'disetujui' => $siap_dicairkan, // Hanya yang benar-benar siap dicairkan
    'dicairkan' => $jumlah_dicairkan,
    'ditolak' => $stats_pengajuan['ditolak']
];

// Hitung total nominal
$total_nominal_dicairkan = 0;
foreach ($dicairkan_list as $d) {
    $total_nominal_dicairkan += $d['jumlah_pinjaman'];
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
    <title>Pencairan Pinjaman - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card { 
            border: none; 
            border-radius: 10px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .btn-sync {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        .btn-sync:hover {
            background: linear-gradient(135deg, #5a67d8 0%, #6b46a1 100%);
            color: white;
        }
        .action-menu {
            min-width: 255px;
            max-width: calc(100vw - 24px);
            padding: .45rem;
            border: 1px solid #dbe7ea;
            border-radius: .75rem;
            box-shadow: 0 .75rem 1.75rem rgba(31, 66, 80, .18);
            z-index: 1080;
        }
        .action-menu.show { display: grid !important; gap: .25rem; }
        .table-responsive td:has(.action-menu.show) { position: relative; z-index: 2000 !important; overflow: visible !important; }
        .ui-shell .ui-actions-cell:last-child:has(.action-menu.show) { z-index: 2000 !important; }
        .table-responsive .dropdown:has(.action-menu.show) { z-index: 2001; }
        .action-menu .btn {
            width: 100%;
            min-height: 38px;
            display: flex;
            align-items: center;
            gap: .55rem;
            text-align: left;
            white-space: normal;
        }
        .action-menu .btn i { width: 1rem; text-align: center; }
        @media (max-width: 576px) {
            .action-menu { min-width: min(255px, calc(100vw - 24px)); }
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
                        <i class="fas fa-money-bill-wave me-2 text-primary"></i>Pencairan Pinjaman
                    </h1>
                    <div>
                        <a href="?sync=1" class="btn btn-sync me-2" onclick="return confirm('Sinkronisasi data pengajuan dengan pinjaman?')">
                            <i class="fas fa-sync me-1"></i> Sinkronisasi
                        </a>
                        <a href="persetujuan.php" class="btn btn-primary">
                            <i class="fas fa-check-circle me-1"></i> Persetujuan
                        </a>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
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
                                <h4 class="mb-0"><?php echo $stats['total_pengajuan']; ?></h4>
                                <p class="mb-0">Total Pengajuan</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <h4 class="mb-0"><?php echo $stats['menunggu']; ?></h4>
                                <p class="mb-0">Menunggu</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <h4 class="mb-0"><?php echo $stats['disetujui']; ?></h4>
                                <p class="mb-0">Siap Dicairkan</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <h4 class="mb-0"><?php echo $stats['dicairkan']; ?></h4>
                                <p class="mb-0">Sudah Dicairkan</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body py-3">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label">Cari Nama/Kode Anggota</label>
                                <input type="search" name="q" class="form-control"
                                       value="<?php echo htmlspecialchars($filter_cari); ?>"
                                       placeholder="Nama, AGT/AGTJ, kode pengajuan/pinjaman">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Pencairan Mulai</label>
                                <input type="date" name="tanggal_mulai" class="form-control" value="<?php echo htmlspecialchars($filter_tanggal_mulai); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Pencairan Sampai</label>
                                <input type="date" name="tanggal_selesai" class="form-control" value="<?php echo htmlspecialchars($filter_tanggal_selesai); ?>">
                            </div>
                            <div class="col-md-3 d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1"><i class="fas fa-search me-1"></i>Filter</button>
                                <a href="pencairan.php" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                        <small class="text-muted">Pencarian berlaku pada data siap cair dan sudah cair. Rentang tanggal hanya memfilter data yang sudah dicairkan.</small>
                    </div>
                </div>

                <!-- Summary Card -->
                <?php if ($total_nominal_dicairkan > 0): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Total Pinjaman Dicairkan:</strong> <?php echo formatRupiah($total_nominal_dicairkan); ?> 
                    (<?php echo $stats['dicairkan']; ?> pinjaman)
                </div>
                <?php endif; ?>

                <!-- Persetujuan Tunda Cair -->
                <div class="card mb-4 border-warning"><div class="card-header bg-warning"><h5 class="mb-0"><i class="fas fa-pause-circle me-2"></i>Pengajuan Tunda Cair (TC) <span class="badge bg-light text-dark ms-2"><?= count($tcMenunggu) ?> Data</span></h5></div><div class="card-body"><?php if(!$tcMenunggu): ?><p class="text-muted mb-0">Tidak ada TC yang menunggu keputusan.</p><?php else: ?><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Pengajuan</th><th>Nasabah</th><th>Alasan Teller</th><th>Waktu</th><th>Keputusan Admin</th></tr></thead><tbody><?php foreach($tcMenunggu as $tc): ?><tr><td><?= htmlspecialchars($tc['kode_pengajuan']) ?></td><td><strong><?= htmlspecialchars($tc['nama_lengkap']) ?></strong><br><small><?= htmlspecialchars($tc['kode_anggota']) ?></small></td><td><?= nl2br(htmlspecialchars($tc['alasan'])) ?></td><td><?= date('d/m/Y H:i',strtotime($tc['created_at'])) ?><br><small><?= htmlspecialchars($tc['teller']) ?></small></td><td><form method="post" class="d-flex flex-column gap-1"><?= layananToken() ?><input type="hidden" name="tc_id" value="<?= (int)$tc['id'] ?>"><textarea name="catatan_tc" class="form-control form-control-sm" rows="2" minlength="5" maxlength="2000" required placeholder="Keterangan keputusan Admin"></textarea><div class="btn-group"><button name="keputusan_tc" value="disetujui" class="btn btn-sm btn-success">Approve TC</button><button name="keputusan_tc" value="ditolak" class="btn btn-sm btn-danger">Tolak TC</button></div></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div>

                <!-- Pengajuan Siap Dicairkan -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-clock me-2"></i>Pengajuan Siap Dicairkan
                            <span class="badge bg-light text-dark ms-2"><?php echo count($pengajuan_list); ?> Data</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pengajuan_list)): ?>
                            <p class="text-center text-muted py-3">Tidak ada pengajuan yang siap dicairkan</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Kode</th>
                                            <th>Anggota</th>
                                            <th>Jenis</th>
                                            <th>Jumlah</th>
                                            <th>Tenor</th>
                                            <th>Bunga</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pengajuan_list as $p): ?>
                                        <tr>
                                            <td><small><?php echo $p['kode_pengajuan']; ?></small></td>
                                            <td>
                                                <strong><?php echo $p['nama_lengkap']; ?></strong>
                                                <br><small class="text-muted"><?php echo $p['kode_anggota']; ?></small>
                                            </td>
                                            <td><?php echo $p['nama_jenis']; ?></td>
                                            <td class="fw-bold text-primary"><?php echo formatRupiah($p['jumlah_pinjaman']); ?></td>
                                            <td><?php echo labelTenorRow($p); ?></td>
                                            <td><?php echo labelBungaRow($p); ?></td>
                                            <td>
                                                <span class="badge bg-warning text-dark">Menunggu pencairan oleh Teller</span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pinjaman yang Sudah Dicairkan -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i>Pinjaman yang Sudah Dicairkan
                            <span class="badge bg-light text-dark ms-2"><?php echo count($dicairkan_list); ?> Data</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($dicairkan_list)): ?>
                            <p class="text-center text-muted py-3">Belum ada pinjaman yang dicairkan</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Kode Pinjaman</th>
                                            <th>Anggota</th>
                                            <th>Jenis</th>
                                            <th>Jumlah</th>
                                            <th>Angsuran</th>
                                            <th>Tenor</th>
                                            <th>Tanggal Cair</th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dicairkan_list as $p): ?>
                                        <tr>
                                            <td><small><?php echo $p['kode_pinjaman']; ?></small></td>
                                            <td>
                                                <strong><?php echo $p['nama_lengkap']; ?></strong>
                                                <br><small class="text-muted"><?php echo $p['kode_anggota']; ?></small>
                                            </td>
                                            <td><?php echo $p['nama_jenis']; ?></td>
                                            <td class="fw-bold text-primary"><?php echo formatRupiah($p['jumlah_pinjaman']); ?></td>
                                            <td class="text-info"><?php echo formatRupiah($p['angsuran_per_bulan']); ?><small class="text-muted">/<?php echo strtolower(labelPeriode($p['frekuensi'])); ?></small></td>
                                            <td><?php echo labelTenorRow($p); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($p['tanggal_pencairan'])); ?></td>
                                            <td>
                                                <span class="badge bg-info">Dicairkan</span>
                                            </td>
                                            <td><div class="dropdown"><button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-ellipsis-v me-1"></i>Aksi</button><div class="dropdown-menu dropdown-menu-end action-menu">
                                                <?php if (!empty($p['foto_pencairan'])): ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                        onclick="lihatDokumen('<?php echo htmlspecialchars(urlFoto($p['foto_pencairan']), ENT_QUOTES); ?>', 'Foto nasabah - <?php echo htmlspecialchars($p['nama_lengkap'], ENT_QUOTES); ?>')"
                                                        title="Foto nasabah saat pencairan">
                                                    <i class="fas fa-camera"></i><span>Foto nasabah</span>
                                                </button>
                                                <?php endif; ?>
                                                <?php if (!empty($p['foto_barang'])): ?>
                                                <button type="button" class="btn btn-sm btn-outline-warning"
                                                        onclick="lihatDokumen('<?php echo htmlspecialchars(urlFoto($p['foto_barang']), ENT_QUOTES); ?>', 'Foto <?php echo htmlspecialchars($p['jenis_barang'], ENT_QUOTES); ?>')"
                                                        title="Foto barang jaminan">
                                                    <i class="fas fa-box"></i><span>Foto barang jaminan</span>
                                                </button>
                                                <?php endif; ?>
                                                <?php if (!empty($p['foto_nasabah_barang'])): ?>
                                                <button type="button" class="btn btn-sm btn-outline-warning"
                                                        onclick="lihatDokumen('<?php echo htmlspecialchars(urlFoto($p['foto_nasabah_barang']), ENT_QUOTES); ?>', 'Foto nasabah dengan barang jaminan')"
                                                        title="Foto nasabah dengan barang jaminan">
                                                    <i class="fas fa-user-tag"></i><span>Foto nasabah &amp; barang</span>
                                                </button>
                                                <?php endif; ?>
                                                <a class="btn btn-sm btn-success" target="_blank" rel="noopener"
                                                   href="cetak_slip_pencairan.php?id=<?php echo (int) $p['id']; ?>" title="Cetak Slip">
                                                    <i class="fas fa-print"></i><span>Cetak slip pencairan</span>
                                                </a>
                                                <?php if (!empty($p['perjanjian_id'])): ?>
                                                <a class="btn btn-sm btn-primary" target="_blank" rel="noopener" href="cetak_perjanjian.php?id=<?php echo (int)$p['id']; ?>" title="Cetak Surat Perjanjian & Lampiran"><i class="fas fa-file-pdf"></i><span>PDF perjanjian</span></a>
                                                <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-secondary" disabled title="PDF tersedia untuk pencairan baru yang sudah menyimpan seluruh TTD"><i class="fas fa-file-pdf"></i><span>PDF perjanjian</span></button>
                                                <?php endif; ?>
                                            </div></div></td>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../includes/modal_dokumen.php'; ?>
</body>
</html>

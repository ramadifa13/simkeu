<?php
/**
 * Single implementation — Pelunasan Pinjaman (Admin & Teller).
 * Entry: admin|teller/pelunasan.php (thin wrappers).
 * Jaminan/BAST/TTD UI gated to teller (counter handover).
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}
if (!isAdmin() && !isTeller()) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$page_title = "Pelunasan Pinjaman";
$bolehJaminanLangsung = isTeller();

// Pertahankan tampilan pelunasan lama; pencatatan kas tetap melalui layanan bersama.
require_once __DIR__ . '/layanan_actions.php';
// Halaman ini hanya memiliki satu formulir POST, yaitu pelunasan. Jangan
// bergantung pada field tombol submit karena browser dapat menghilangkannya
// saat tombol dinonaktifkan oleh indikator "Memproses".
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $files=[];
    try {
        layananValidasiToken();
        if (!layananSiap($db)) throw new Exception('Jalankan migrasi layanan terlebih dahulu.');
        // Admin tidak boleh submit aliran ambil-jaminan/BAST lewat halaman ini.
        if (!$bolehJaminanLangsung) {
            unset($_POST['ambil_jaminan_langsung'], $_POST['cek_jaminan_nasabah'], $_POST['ttd_jaminan_nasabah'], $_POST['ttd_jaminan_teller'], $_POST['mode_cetak_akad']);
        }
        $db->beginTransaction();
        $_POST['jumlah']=$_POST['jumlah_pelunasan'] ?? '';
        $_POST['catatan']=$_POST['keterangan'] ?? '';
        $_POST['metode']=$_POST['metode'] ?? 'cash';
        prosesLayanan($db,'pelunasan_langsung',(int)($_POST['id'] ?? 0),$files);
        $db->commit();
        $_SESSION['success']='Pinjaman berhasil dilunaskan dan pembayaran dicatat ke kas.';
        if ($bolehJaminanLangsung && !empty($_POST['ambil_jaminan_langsung']) && ($_POST['mode_cetak_akad'] ?? '') === 'otomatis') {
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

$uiPage = max(1, (int)($_GET['page'] ?? 1));
$uiPerPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($uiPerPage, [10, 20, 50], true)) {
    $uiPerPage = 10;
}
$whereSql = implode(' AND ', $filter_where);
$countStmt = $db->prepare("SELECT COUNT(*) FROM pinjaman p JOIN anggota a ON p.anggota_id = a.id JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id LEFT JOIN jaminan_pinjaman j ON j.pinjaman_id = p.id WHERE $whereSql");
$countStmt->execute($filter_params);
$pager = ui_paginate((int)$countStmt->fetchColumn(), $uiPage, $uiPerPage);

// Get active loans that can be settled early
$query = "SELECT p.*, a.nama_lengkap, a.telepon, a.kode_anggota, jp.nama_jenis, jp.memerlukan_jaminan,
                 j.jenis_barang, j.identitas_barang, j.deskripsi_barang, j.foto_barang, j.foto_nasabah_barang,
                 (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status IN ('belum_bayar','telat')) as sisa_angsuran,
                 (SELECT SUM(jumlah_angsuran + denda) FROM angsuran WHERE pinjaman_id = p.id AND status IN ('belum_bayar','telat')) as total_sisa_bayar
          FROM pinjaman p
          JOIN anggota a ON p.anggota_id = a.id
          JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
          LEFT JOIN jaminan_pinjaman j ON j.pinjaman_id = p.id
          WHERE $whereSql
          ORDER BY p.tanggal_pencairan DESC
          LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";

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
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
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
            padding: 14px;
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
    <?php include __DIR__ . '/header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include __DIR__ . '/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <?php ui_page_header([
                    'title' => 'Pelunasan Pinjaman',
                    'icon' => 'fas fa-flag-checkered text-primary',
                    'subtitle' => 'Lunasi sisa pinjaman anggota',
                ]); ?>
                <?php ui_flash(); ?>

                <?php ui_stat_grid([
                    ['label' => 'Total pinjaman', 'value' => (int)$stats['total_pinjaman'], 'tone' => 'primary', 'icon' => 'fas fa-hand-holding-usd', 'hint' => 'Semua'],
                    ['label' => 'Aktif', 'value' => (int)$stats['aktif'], 'tone' => 'success', 'icon' => 'fas fa-play-circle', 'hint' => 'Dapat dilunasi'],
                    ['label' => 'Lunas', 'value' => (int)$stats['lunas'], 'tone' => 'info', 'icon' => 'fas fa-flag-checkered', 'hint' => 'Selesai'],
                    ['label' => 'Sisa pokok', 'value' => formatRupiah($stats['total_sisa_pokok']), 'tone' => 'warning', 'icon' => 'fas fa-money-bill-wave', 'hint' => 'Outstanding'],
                ]); ?>

                <!-- Pinjaman Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>Daftar Pinjaman yang Dapat Dilunaskan
                        </h5>
                        <div>
                            <span class="text-muted me-2">
                                <?php echo (int)$pager['total']; ?> data ditemukan
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php include __DIR__ . '/filter_pelunasan.php'; ?>
                        <?php if (empty($pinjaman_list)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h5 class="text-muted">Tidak ada pinjaman yang dapat dilunaskan</h5>
                            <p class="text-muted">Semua pinjaman telah lunas atau belum ada pinjaman aktif.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive ui-table-scroll">
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
                                        $dataGadai = $bolehJaminanLangsung && !empty($pinjaman['memerlukan_jaminan']) ? '1' : '0';
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
                                        <td class="table-actions text-end">
                                            <?php
                                            echo ui_action_menu([
                                                [
                                                    'label' => 'Detail',
                                                    'icon' => 'fas fa-eye',
                                                    'attrs' => [
                                                        'data-bs-toggle' => 'modal',
                                                        'data-bs-target' => '#detailModal',
                                                        'data-kode' => $pinjaman['kode_pinjaman'],
                                                        'data-nama' => $pinjaman['nama_lengkap'],
                                                        'data-telepon' => $pinjaman['telepon'] ?? '',
                                                        'data-gadai' => $dataGadai,
                                                        'data-jaminan-jenis' => $pinjaman['jenis_barang'] ?? '',
                                                        'data-jaminan-identitas' => $pinjaman['identitas_barang'] ?? '',
                                                        'data-jaminan-deskripsi' => $pinjaman['deskripsi_barang'] ?? '',
                                                        'data-jaminan-foto' => urlFoto($pinjaman['foto_barang'] ?? ''),
                                                        'data-kode_anggota' => $pinjaman['kode_anggota'],
                                                        'data-jenis' => $pinjaman['nama_jenis'],
                                                        'data-jumlah_pinjaman' => $pinjaman['jumlah_pinjaman'],
                                                        'data-sisa_pokok' => $pinjaman['sisa_pokok'],
                                                        'data-sisa_angsuran' => $pinjaman['sisa_angsuran'],
                                                        'data-total_sisa_bayar' => $pinjaman['total_sisa_bayar'],
                                                        'data-progress' => round($progress, 1),
                                                        'data-tanggal_pencairan' => formatTanggalUi($pinjaman['tanggal_pencairan']),
                                                        'data-jatuh_tempo' => formatTanggalUi($pinjaman['jatuh_tempo']),
                                                    ],
                                                ],
                                                [
                                                    'label' => 'Lunaskan',
                                                    'icon' => 'fas fa-flag-checkered',
                                                    'class' => 'text-success',
                                                    'attrs' => [
                                                        'data-bs-toggle' => 'modal',
                                                        'data-bs-target' => '#lunaskanModal',
                                                        'data-id' => (int)$pinjaman['id'],
                                                        'data-kode' => $pinjaman['kode_pinjaman'],
                                                        'data-nama' => $pinjaman['nama_lengkap'],
                                                        'data-telepon' => $pinjaman['telepon'] ?? '',
                                                        'data-gadai' => $dataGadai,
                                                        'data-jaminan-jenis' => $pinjaman['jenis_barang'] ?? '',
                                                        'data-jaminan-identitas' => $pinjaman['identitas_barang'] ?? '',
                                                        'data-jaminan-deskripsi' => $pinjaman['deskripsi_barang'] ?? '',
                                                        'data-jaminan-foto' => urlFoto($pinjaman['foto_barang'] ?? ''),
                                                        'data-sisa_pokok' => $pinjaman['sisa_pokok'],
                                                        'data-total_sisa_bayar' => $pinjaman['total_sisa_bayar'],
                                                    ],
                                                ],
                                            ]);
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php ui_pagination($pager, 'pelunasan.php'); ?>
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
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data" id="formPelunasan">
                    <div class="modal-header border-0 pb-0">
                        <div>
                            <div class="text-uppercase small text-muted fw-semibold">Pelunasan</div>
                            <h5 class="modal-title mb-0 text-success">Lunaskan pinjaman</h5>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div id="pelunasanFeedback" class="alert d-none" role="alert" aria-live="polite"></div>
                        <?= layananToken() ?>
                        <input type="hidden" name="id" id="lunaskan_id">
                        <div class="ui-detail-panel mb-3">
                            <div class="ui-detail-label">Nasabah</div>
                            <div class="fw-semibold" id="lunaskan_nama">—</div>
                            <div class="small text-muted mt-1">Kode: <span id="lunaskan_kode">—</span></div>
                        </div>
                        <label class="form-label">Metode pembayaran</label>
                        <select name="metode" class="form-select mb-3"><option value="cash">Cash</option><option value="transfer">Transfer</option></select>

                        <div class="alert alert-info">
                            <div class="fw-semibold mb-2">Ringkasan pelunasan</div>
                            <p class="mb-1">Sisa pokok: <span id="lunaskan_sisa_pokok" class="fw-bold"></span></p>
                            <p class="mb-0">Total sisa bayar: <span id="lunaskan_total_sisa_bayar" class="fw-bold text-danger"></span></p>
                        </div>

                        <div class="mb-3">
                            <label for="lunaskan_jumlah" class="form-label">Jumlah Pelunasan *</label>
                            <input type="number" class="form-control" id="lunaskan_jumlah" name="jumlah_pelunasan" min="0" step="0.01" readonly required>
                            <div class="form-text">Total dihitung dari sisa tagihan setelah pembayaran denda dan kredit yang telah tercatat.</div>
                        </div>

                        <?php if ($bolehJaminanLangsung): ?>
                        <div id="konfirmasiJaminanPelunasan" class="border rounded p-3 mb-3 bg-light d-none">
                            <label class="form-check d-flex gap-2 align-items-start mb-3"><input class="form-check-input mt-1" type="checkbox" name="ambil_jaminan_langsung" id="ambilJaminanLangsung"><span><strong>Barang jaminan diambil sekarang</strong><br><small class="text-muted">Pilih hanya jika nasabah mengambil barang bersamaan dengan pelunasan.</small></span></label>
                            <div id="detailPengambilanJaminan" class="d-none">
                            <h6 class="text-success"><i class="fas fa-box-open me-1"></i>Konfirmasi Barang Jaminan</h6>
                            <p class="small mb-2" id="detailJaminanPelunasan"></p><img id="fotoJaminanPelunasan" class="img-fluid rounded border mb-3 d-none" style="max-height:180px;object-fit:contain" alt="Foto barang jaminan">
                            <label class="form-check d-flex gap-2 align-items-start mb-3"><input class="form-check-input mt-1" type="checkbox" name="cek_jaminan_nasabah" id="cekJaminanNasabah"><span>Saya sudah mengecek barang jaminan secara keseluruhan dan menyatakan barang dalam kondisi normal/tidak rusak.</span></label>
                            <div class="signature-field mb-2">
                                <div class="signature-field-head"><div><span class="signature-field-title">TTD Nasabah sebagai bukti pemeriksaan *</span><small class="signature-field-help">Goreskan pada area putih; tepi aman agar tidak terpotong.</small></div><span class="signature-status"><i class="fas fa-pen-nib"></i> Belum diisi</span></div>
                                <div class="signature-canvas-wrap"><canvas id="ttdJaminanPelunasan" class="signature" data-target="ttd_jaminan_nasabah" data-required="1" width="700" height="220"></canvas></div>
                                <input type="hidden" name="ttd_jaminan_nasabah" id="ttdJaminanNasabah">
                                <div class="signature-field-foot"><span class="signature-field-note">Wajib sebelum konfirmasi.</span><button type="button" class="btn btn-sm btn-outline-danger clear-sign reset-ttd" data-canvas="ttdJaminanPelunasan"><i class="fas fa-eraser me-1"></i>Reset TTD Nasabah</button></div>
                            </div>
                            <div class="signature-field mb-2">
                                <div class="signature-field-head"><div><span class="signature-field-title">TTD Teller / petugas penyerahan *</span><small class="signature-field-help">Goreskan pada area putih.</small></div><span class="signature-status"><i class="fas fa-pen-nib"></i> Belum diisi</span></div>
                                <div class="signature-canvas-wrap"><canvas id="ttdJaminanTellerCanvas" class="signature" data-target="ttd_jaminan_teller" data-required="1" width="700" height="220"></canvas></div>
                                <input type="hidden" name="ttd_jaminan_teller" id="ttdJaminanTeller">
                                <div class="signature-field-foot"><span class="signature-field-note">Wajib sebelum konfirmasi.</span><button type="button" class="btn btn-sm btn-outline-danger clear-sign reset-ttd" data-canvas="ttdJaminanTellerCanvas"><i class="fas fa-eraser me-1"></i>Reset TTD Teller</button></div>
                            </div>
                            <small class="text-muted d-block mt-2">Tanda tangan nasabah dan Teller dicetak pada akad serah terima barang.</small>
                            <div class="mt-3"><label for="fotoSerahTerimaJaminan" class="form-label">Foto penyerahan barang jaminan *</label><input type="file" class="form-control" name="foto_serah_terima_jaminan" id="fotoSerahTerimaJaminan" accept="image/jpeg,image/png,image/webp"><div class="form-text">Foto kondisi barang saat diserahkan kepada nasabah. Maksimal 1 MB; otomatis dikompres sebelum dikirim.</div></div>
                            <div class="mt-3 pt-3 border-top"><label class="form-label fw-bold mb-2">Cetak akad serah terima</label><label class="form-check"><input class="form-check-input" type="radio" name="mode_cetak_akad" value="otomatis" checked> Otomatis — akad langsung terbuka dengan data, foto, dan TTD terisi.</label><label class="form-check mt-1"><input class="form-check-input" type="radio" name="mode_cetak_akad" value="manual"> Manual — formulir kosong dicetak dari Laporan Pelunasan.</label></div>
                            </div>
                        </div>
                        <?php endif; ?>

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

        // Lunaskan Modal — TTD via SimkeuSignaturePad (assets/js/tanda-tangan-layanan.js)
        function resetSemuaTtdJaminan(){
            ['ttdJaminanPelunasan','ttdJaminanTellerCanvas'].forEach(function(id){
                var c=document.getElementById(id);
                if(c && window.SimkeuSignaturePad) SimkeuSignaturePad.clear(c);
                else if(c){ var x=c.getContext('2d'); x.clearRect(0,0,c.width,c.height); }
            });
            var n=document.getElementById('ttdJaminanNasabah'), t=document.getElementById('ttdJaminanTeller');
            if(n) n.value=''; if(t) t.value='';
        }
        document.querySelectorAll('.reset-ttd').forEach(function(button){
            button.addEventListener('click', function(){
                var c=document.getElementById(button.dataset.canvas);
                if(c && window.SimkeuSignaturePad) SimkeuSignaturePad.clear(c);
            });
        });
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
            if (ambilJaminan && detailPengambilan && cekJaminan && fotoSerahTerima) {
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
            }
            if (blokJaminan) { blokJaminan.classList.toggle('d-none', !isGadai); var fotoJaminan=document.getElementById('fotoJaminanPelunasan'); if (!isGadai) { if (cekJaminan) cekJaminan.checked=false; if (ttdJaminan) ttdJaminan.value=''; if (fotoJaminan) fotoJaminan.classList.add('d-none'); } else if (detailJaminan) { detailJaminan.textContent = [button.getAttribute('data-jaminan-jenis'), button.getAttribute('data-jaminan-identitas'), button.getAttribute('data-jaminan-deskripsi')].filter(Boolean).join(' · ') || 'Detail barang jaminan belum tersedia'; var foto=button.getAttribute('data-jaminan-foto')||''; if (fotoJaminan) { fotoJaminan.src=foto; fotoJaminan.classList.toggle('d-none',!foto); } } }
        });
    </script>
</body>
</html>

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
redirectJikaPostMelebihiBatas('pelunasan.php');
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
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-pelunasan" aria-hidden="true"><i class="ti ti-flag-check"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Pinjaman</p>
                        <h1 class="ui-dash-title">Pelunasan Pinjaman</h1>
                        <p class="ui-dash-meta mb-0">Lunasi sisa pinjaman aktif — kas &amp; jadwal angsuran diperbarui otomatis</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="angsuran.php" class="btn ui-tx-btn-pelunasan-ghost">
                            <i class="ti ti-calendar-check me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Angsuran</span>
                        </a>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="alert alert-info ui-pl-info" role="status" data-ui-toast-skip>
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                    Pelunasan menutup semua cicilan tersisa. Bila ada sisa denda, lunasi denda dulu lewat menu Denda.
                    <?php if ($bolehJaminanLangsung): ?>
                    Untuk gadai, Anda dapat menyerahkan barang jaminan bersamaan dengan TTD &amp; foto di form pelunasan.
                    <?php endif; ?>
                </div>

                <div class="ui-pl-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total pinjaman', 'value' => (int)$stats['total_pinjaman'], 'tone' => 'primary', 'icon' => 'ti ti-moneybag', 'hint' => 'Semua'],
                    ['label' => 'Aktif', 'value' => (int)$stats['aktif'], 'tone' => 'success', 'icon' => 'ti ti-player-play', 'hint' => 'Dapat dilunasi'],
                    ['label' => 'Lunas', 'value' => (int)$stats['lunas'], 'tone' => 'info', 'icon' => 'ti ti-flag-check', 'hint' => 'Selesai'],
                    ['label' => 'Sisa pokok', 'value' => formatRupiah($stats['total_sisa_pokok']), 'tone' => 'warning', 'icon' => 'ti ti-cash', 'hint' => 'Outstanding'],
                ]); ?>
                </div>

                <div class="card mt-3 ui-pl-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list-check" aria-hidden="true"></i>Daftar pinjaman dapat dilunaskan</span>
                        <span class="ui-dash-sec-note"><?php echo (int)$pager['total']; ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($pinjaman_list)): ?>
                        <div class="ui-pl-empty">
                            <i class="ti ti-circle-check" aria-hidden="true"></i>
                            <strong>Tidak ada pinjaman aktif</strong>
                            <p>Semua pinjaman telah lunas atau belum ada pinjaman aktif.</p>
                        </div>
                        <?php else: ?>
                        <div class="ui-mobile-cards d-md-none p-3">
                            <?php foreach ($pinjaman_list as $pinjaman):
                                $progress = $pinjaman['jumlah_pinjaman'] > 0
                                    ? (($pinjaman['jumlah_pinjaman'] - $pinjaman['sisa_pokok']) / $pinjaman['jumlah_pinjaman']) * 100
                                    : 0;
                                $dataGadai = $bolehJaminanLangsung && !empty($pinjaman['memerlukan_jaminan']) ? '1' : '0';
                                $detailAttrs = [
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
                                ];
                                $lunaskanAttrs = [
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
                                ];
                            ?>
                            <div class="ui-mobile-card ui-pl-mobile-card">
                                <div class="ui-mobile-card-row">
                                    <div class="min-w-0">
                                        <div class="ui-mobile-card-title"><?php echo htmlspecialchars($pinjaman['nama_lengkap']); ?></div>
                                        <div class="ui-mobile-card-meta"><code><?php echo htmlspecialchars($pinjaman['kode_pinjaman']); ?></code> · <?php echo htmlspecialchars($pinjaman['kode_anggota']); ?></div>
                                    </div>
                                    <span class="badge <?php echo !empty($pinjaman['memerlukan_jaminan']) ? 'bg-warning text-dark' : 'bg-secondary-subtle text-secondary'; ?>"><?php echo !empty($pinjaman['memerlukan_jaminan']) ? 'Gadai' : 'AGT'; ?></span>
                                </div>
                                <div class="ui-pl-mobile-stats">
                                    <div><span>Sisa bayar</span><strong><?php echo formatRupiah($pinjaman['total_sisa_bayar']); ?></strong></div>
                                    <div><span>Sisa pokok</span><strong><?php echo formatRupiah($pinjaman['sisa_pokok']); ?></strong></div>
                                </div>
                                <div class="progress ui-pl-progress mb-2"><div class="progress-bar" style="width:<?php echo max(0, min(100, $progress)); ?>%"></div></div>
                                <div class="ui-mobile-card-actions">
                                    <?php echo ui_action_menu([
                                        ['label' => 'Detail', 'icon' => 'ti ti-eye', 'attrs' => $detailAttrs],
                                        ['label' => 'Lunaskan', 'icon' => 'ti ti-flag-check', 'class' => 'text-success', 'attrs' => $lunaskanAttrs],
                                    ]); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="table-responsive ui-table-scroll d-none d-md-block">
                            <table class="table table-hover align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="0,asc;7,desc"
                                   data-dt-filter="3"
                                   data-dt-date-col="8">
                                <thead>
                                    <tr>
                                        <th class="dt-prio">Prio</th>
                                        <th>Pinjaman</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Plafon</th>
                                        <th class="text-end">Sisa pokok</th>
                                        <th class="text-end">Sisa bayar</th>
                                        <th>Progress</th>
                                        <th class="d-none">Pencairan</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pinjaman_list as $pinjaman):
                                        $progress = $pinjaman['jumlah_pinjaman'] > 0
                                            ? (($pinjaman['jumlah_pinjaman'] - $pinjaman['sisa_pokok']) / $pinjaman['jumlah_pinjaman']) * 100
                                            : 0;
                                        $dataGadai = $bolehJaminanLangsung && !empty($pinjaman['memerlukan_jaminan']) ? '1' : '0';
                                        $isGadai = !empty($pinjaman['memerlukan_jaminan']);
                                        $prio = $isGadai ? 1 : 2;
                                        $jenisFilter = $isGadai ? 'Gadai' : 'Tanpa jaminan';
                                        $detailAttrs = [
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
                                        ];
                                        $lunaskanAttrs = [
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
                                        ];
                                    ?>
                                    <tr class="ui-row-action">
                                        <td><?php echo (int)$prio; ?></td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($pinjaman['kode_pinjaman']); ?></div>
                                            <small class="text-muted"><?php echo (int)$pinjaman['sisa_angsuran']; ?> angsuran tersisa</small>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($pinjaman['nama_lengkap']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($pinjaman['kode_anggota']); ?></small>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($jenisFilter); ?>">
                                            <div><?php echo htmlspecialchars($pinjaman['nama_jenis']); ?></div>
                                            <span class="ui-pl-tag <?php echo $isGadai ? 'is-gadai' : 'is-agt'; ?>"><?php echo $isGadai ? 'AGTJ · Gadai' : 'AGT'; ?></span>
                                        </td>
                                        <td class="text-end fw-semibold"><?php echo formatRupiah($pinjaman['jumlah_pinjaman']); ?></td>
                                        <td class="text-end fw-semibold text-warning"><?php echo formatRupiah($pinjaman['sisa_pokok']); ?></td>
                                        <td class="text-end fw-bold text-danger"><?php echo formatRupiah($pinjaman['total_sisa_bayar']); ?></td>
                                        <td data-order="<?php echo (int)round($progress); ?>">
                                            <div class="ui-pl-progress-cell">
                                                <div class="progress ui-pl-progress"><div class="progress-bar" style="width:<?php echo max(0, min(100, $progress)); ?>%"></div></div>
                                                <small><?php echo round($progress, 1); ?>%</small>
                                            </div>
                                        </td>
                                        <td class="d-none" data-order="<?php echo htmlspecialchars($pinjaman['tanggal_pencairan'] . ' 00:00:00'); ?>" data-date="<?php echo htmlspecialchars(substr((string)$pinjaman['tanggal_pencairan'], 0, 10)); ?>"><?php echo htmlspecialchars($pinjaman['tanggal_pencairan']); ?></td>
                                        <td class="table-actions text-end">
                                            <?php echo ui_action_menu([
                                                ['label' => 'Detail', 'icon' => 'ti ti-eye', 'attrs' => $detailAttrs],
                                                ['label' => 'Lunaskan', 'icon' => 'ti ti-flag-check', 'class' => 'text-success', 'attrs' => $lunaskanAttrs],
                                            ]); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="px-3 pb-3"><?php ui_pagination($pager, 'pelunasan.php'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
    <?php include __DIR__ . '/layout/shell_end.php'; ?>

    <div class="modal fade ui-tx-modal ui-tx-modal-pelunasan" id="detailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detail pinjaman</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body ui-pj-detail">
                    <div class="ui-pj-detail-hero">
                        <div class="min-w-0">
                            <div class="ui-pj-detail-kode" id="detail_kode"></div>
                            <div class="ui-pj-detail-nama" id="detail_nama"></div>
                            <div class="ui-pj-detail-meta"><span id="detail_kode_anggota"></span> · <span id="detail_jenis"></span></div>
                        </div>
                        <div class="ui-pj-detail-hero-status">
                            <span class="badge bg-success" id="detail_progress"></span>
                        </div>
                    </div>
                    <div class="ui-pj-detail-amount">
                        <div class="ui-pj-detail-amount-item">
                            <span class="ui-pj-detail-amount-label">Plafon</span>
                            <span class="ui-pj-detail-amount-value" id="detail_jumlah_pinjaman"></span>
                        </div>
                        <div class="ui-pj-detail-amount-item">
                            <span class="ui-pj-detail-amount-label">Sisa pokok</span>
                            <span class="ui-pj-detail-amount-sub" id="detail_sisa_pokok"></span>
                        </div>
                    </div>
                    <div class="ui-pj-detail-grid mt-3">
                        <div class="ui-pj-detail-card">
                            <h6 class="ui-pj-detail-card-title"><i class="ti ti-calendar" aria-hidden="true"></i>Jadwal</h6>
                            <dl class="ui-pj-detail-dl">
                                <div><dt>Pencairan</dt><dd id="detail_tanggal_pencairan"></dd></div>
                                <div><dt>Jatuh tempo</dt><dd id="detail_jatuh_tempo"></dd></div>
                                <div><dt>Sisa angsuran</dt><dd id="detail_sisa_angsuran"></dd></div>
                            </dl>
                        </div>
                        <div class="ui-pj-detail-card">
                            <h6 class="ui-pj-detail-card-title"><i class="ti ti-cash" aria-hidden="true"></i>Tagihan</h6>
                            <dl class="ui-pj-detail-dl">
                                <div><dt>Total sisa bayar</dt><dd id="detail_total_sisa_bayar" class="text-danger fw-bold"></dd></div>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade ui-tx-modal ui-tx-modal-pelunasan" id="lunaskanModal" tabindex="-1" aria-labelledby="lunaskanModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data" id="formPelunasan">
                    <div class="modal-header">
                        <div class="min-w-0">
                            <h2 class="modal-title h5 mb-0" id="lunaskanModalLabel"><i class="ti ti-flag-check me-1" aria-hidden="true"></i>Form Pelunasan</h2>
                            <p class="ui-pl-modal-sub mb-0">Konfirmasi nominal, metode, lalu lunaskan pinjaman</p>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="ui-pl-form">
                            <div id="pelunasanFeedback" class="alert d-none" role="alert" aria-live="polite"></div>
                            <?= layananToken() ?>
                            <input type="hidden" name="id" id="lunaskan_id">

                            <div class="ui-pl-hero">
                                <div class="ui-pl-hero-main min-w-0">
                                    <div class="ui-pl-hero-label">Nasabah</div>
                                    <div class="ui-pl-hero-nama text-truncate" id="lunaskan_nama">—</div>
                                    <div class="ui-pl-hero-meta">Kode <code id="lunaskan_kode">—</code></div>
                                </div>
                                <div class="ui-pl-hero-amount">
                                    <div class="ui-pl-hero-label">Total sisa bayar</div>
                                    <div class="ui-pl-hero-value" id="lunaskan_total_sisa_bayar">—</div>
                                    <div class="ui-pl-hero-meta">Sisa pokok <span id="lunaskan_sisa_pokok">—</span></div>
                                </div>
                            </div>

                            <section class="ui-form-section ui-pj-section ui-pl-section">
                                <h6 class="ui-form-section-title"><i class="ti ti-cash" aria-hidden="true"></i>1. Pembayaran</h6>
                                <p class="ui-pj-section-note mb-3">Nominal dihitung otomatis dari sisa tagihan (setelah denda &amp; kredit tercatat).</p>
                                <div class="row g-3">
                                    <div class="col-lg-7">
                                        <label class="form-label d-block">Metode pembayaran</label>
                                        <div class="ui-pl-metode" role="group" aria-label="Metode pembayaran">
                                            <label class="ui-pl-metode-opt">
                                                <input type="radio" name="metode" value="cash" checked>
                                                <span><i class="ti ti-cash" aria-hidden="true"></i>Cash</span>
                                            </label>
                                            <label class="ui-pl-metode-opt">
                                                <input type="radio" name="metode" value="transfer">
                                                <span><i class="ti ti-building-bank" aria-hidden="true"></i>Transfer</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-lg-5">
                                        <label for="lunaskan_jumlah" class="form-label">Jumlah pelunasan *</label>
                                        <div class="ui-pl-money">
                                            <span class="ui-pl-money-prefix">Rp</span>
                                            <input type="number" class="form-control" id="lunaskan_jumlah" name="jumlah_pelunasan" min="0" step="0.01" readonly required>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <?php if ($bolehJaminanLangsung): ?>
                            <section class="ui-form-section ui-pj-section ui-pl-section" id="blokJaminanWrap">
                                <h6 class="ui-form-section-title"><i class="ti ti-package" aria-hidden="true"></i>2. Serah terima jaminan</h6>
                                <div id="konfirmasiJaminanPelunasan" class="d-none">
                                    <p class="ui-pj-section-note mb-3">Hanya untuk pinjaman gadai. Lewati bila barang tidak diambil sekarang.</p>
                                    <label class="ui-pl-toggle">
                                        <input class="form-check-input" type="checkbox" name="ambil_jaminan_langsung" id="ambilJaminanLangsung">
                                        <span class="ui-pl-toggle-body">
                                            <strong>Barang jaminan diambil sekarang</strong>
                                            <small>TTD, cek kondisi, dan foto penyerahan wajib jika dicentang.</small>
                                        </span>
                                    </label>
                                    <div id="detailPengambilanJaminan" class="ui-pl-jaminan-panel d-none">
                                        <div class="ui-pl-jaminan-card">
                                            <div class="ui-pl-jaminan-card-label">Detail barang</div>
                                            <p class="mb-2" id="detailJaminanPelunasan"></p>
                                            <img id="fotoJaminanPelunasan" class="img-fluid rounded border d-none ui-pl-jaminan-foto" alt="Foto barang jaminan">
                                        </div>
                                        <label class="ui-pl-toggle is-check mt-3">
                                            <input class="form-check-input" type="checkbox" name="cek_jaminan_nasabah" id="cekJaminanNasabah">
                                            <span class="ui-pl-toggle-body">
                                                <strong>Barang sudah dicek</strong>
                                                <small>Kondisi normal / tidak rusak saat diserahkan ke nasabah.</small>
                                            </span>
                                        </label>
                                        <div class="row g-3 mt-1">
                                            <div class="col-md-6">
                                                <div class="signature-field mb-0 ui-pl-sign">
                                                    <div class="signature-field-head">
                                                        <div>
                                                            <span class="signature-field-title">TTD Nasabah *</span>
                                                            <small class="signature-field-help">Bukti pemeriksaan barang</small>
                                                        </div>
                                                        <span class="signature-status"><i class="ti ti-signature"></i> Belum</span>
                                                    </div>
                                                    <div class="signature-canvas-wrap"><canvas id="ttdJaminanPelunasan" class="signature" data-target="ttd_jaminan_nasabah" data-required="1" width="700" height="220"></canvas></div>
                                                    <input type="hidden" name="ttd_jaminan_nasabah" id="ttdJaminanNasabah">
                                                    <div class="signature-field-foot">
                                                        <span class="signature-field-note">Wajib</span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger clear-sign reset-ttd" data-canvas="ttdJaminanPelunasan"><i class="ti ti-eraser me-1"></i>Hapus</button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="signature-field mb-0 ui-pl-sign">
                                                    <div class="signature-field-head">
                                                        <div>
                                                            <span class="signature-field-title">TTD Teller *</span>
                                                            <small class="signature-field-help">Petugas penyerahan</small>
                                                        </div>
                                                        <span class="signature-status"><i class="ti ti-signature"></i> Belum</span>
                                                    </div>
                                                    <div class="signature-canvas-wrap"><canvas id="ttdJaminanTellerCanvas" class="signature" data-target="ttd_jaminan_teller" data-required="1" width="700" height="220"></canvas></div>
                                                    <input type="hidden" name="ttd_jaminan_teller" id="ttdJaminanTeller">
                                                    <div class="signature-field-foot">
                                                        <span class="signature-field-note">Wajib</span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger clear-sign reset-ttd" data-canvas="ttdJaminanTellerCanvas"><i class="ti ti-eraser me-1"></i>Hapus</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="ui-pc-upload mt-3">
                                            <label for="fotoSerahTerimaJaminan" class="form-label">Foto penyerahan barang *</label>
                                            <input type="file" class="form-control form-control-sm" name="foto_serah_terima_jaminan" id="fotoSerahTerimaJaminan" accept="image/jpeg,image/png,image/webp">
                                            <div class="form-text">Otomatis dikompres ke maksimal 1 MB.</div>
                                        </div>
                                        <div class="ui-pl-cetak mt-3">
                                            <div class="ui-pl-cetak-label">Cetak akad serah terima</div>
                                            <label class="ui-pl-cetak-opt">
                                                <input class="form-check-input" type="radio" name="mode_cetak_akad" value="otomatis" checked>
                                                <span><strong>Otomatis</strong> — akad terbuka dengan data, foto, dan TTD</span>
                                            </label>
                                            <label class="ui-pl-cetak-opt">
                                                <input class="form-check-input" type="radio" name="mode_cetak_akad" value="manual">
                                                <span><strong>Manual</strong> — formulir kosong dari Laporan Pelunasan</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div id="konfirmasiJaminanKosong" class="ui-pl-jaminan-skip text-muted small d-none">Pinjaman ini tanpa jaminan / bukan gadai — langkah ini dilewati.</div>
                            </section>
                            <?php endif; ?>

                            <section class="ui-form-section ui-pj-section ui-pl-section">
                                <h6 class="ui-form-section-title"><i class="ti ti-notes" aria-hidden="true"></i><?php echo $bolehJaminanLangsung ? '3' : '2'; ?>. Keterangan</h6>
                                <label for="lunaskan_keterangan" class="form-label">Catatan (opsional)</label>
                                <textarea class="form-control" id="lunaskan_keterangan" name="keterangan" rows="2" placeholder="Catatan pelunasan…"></textarea>
                                <div class="alert alert-warning ui-pl-info mt-3 mb-0">
                                    <small><i class="ti ti-alert-triangle me-1"></i>Semua angsuran tersisa akan ditandai lunas dan kas diperbarui.</small>
                                </div>
                            </section>
                        </div>
                    </div>
                    <div class="modal-footer ui-pl-footer flex-wrap">
                        <a href="#" id="waPelunasan" class="btn btn-outline-success d-none me-auto" target="_blank" rel="noopener"><i class="ti ti-brand-whatsapp me-1"></i>WhatsApp</a>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="lunaskan" id="submitPelunasan" class="btn btn-success px-4"><i class="ti ti-flag-check me-1" aria-hidden="true"></i><span class="submit-label">Konfirmasi Pelunasan</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        var detailModal = document.getElementById('detailModal');
        detailModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;
            document.getElementById('detail_kode').textContent = button.getAttribute('data-kode');
            document.getElementById('detail_nama').textContent = button.getAttribute('data-nama');
            document.getElementById('detail_kode_anggota').textContent = button.getAttribute('data-kode_anggota');
            document.getElementById('detail_jenis').textContent = button.getAttribute('data-jenis');
            document.getElementById('detail_tanggal_pencairan').textContent = button.getAttribute('data-tanggal_pencairan');
            document.getElementById('detail_jatuh_tempo').textContent = button.getAttribute('data-jatuh_tempo');
            document.getElementById('detail_progress').textContent = button.getAttribute('data-progress') + '%';
            document.getElementById('detail_sisa_angsuran').textContent = button.getAttribute('data-sisa_angsuran') + ' angsuran';
            document.getElementById('detail_jumlah_pinjaman').textContent = 'Rp ' + Number(button.getAttribute('data-jumlah_pinjaman')).toLocaleString('id-ID');
            document.getElementById('detail_sisa_pokok').textContent = 'Rp ' + Number(button.getAttribute('data-sisa_pokok')).toLocaleString('id-ID');
            document.getElementById('detail_total_sisa_bayar').textContent = 'Rp ' + Number(button.getAttribute('data-total_sisa_bayar')).toLocaleString('id-ID');
        });

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
            if (!button) return;
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
            var skipJaminan = document.getElementById('konfirmasiJaminanKosong');
            if (blokJaminan) {
                blokJaminan.classList.toggle('d-none', !isGadai);
                if (skipJaminan) skipJaminan.classList.toggle('d-none', isGadai);
                var fotoJaminan = document.getElementById('fotoJaminanPelunasan');
                if (!isGadai) {
                    if (cekJaminan) cekJaminan.checked = false;
                    if (ttdJaminan) ttdJaminan.value = '';
                    if (fotoJaminan) fotoJaminan.classList.add('d-none');
                } else if (detailJaminan) {
                    detailJaminan.textContent = [button.getAttribute('data-jaminan-jenis'), button.getAttribute('data-jaminan-identitas'), button.getAttribute('data-jaminan-deskripsi')].filter(Boolean).join(' · ') || 'Detail barang jaminan belum tersedia';
                    var foto = button.getAttribute('data-jaminan-foto') || '';
                    if (fotoJaminan) { fotoJaminan.src = foto; fotoJaminan.classList.toggle('d-none', !foto); }
                }
            } else if (skipJaminan) {
                skipJaminan.classList.add('d-none');
            }
        });
    </script>
</body>
</html>

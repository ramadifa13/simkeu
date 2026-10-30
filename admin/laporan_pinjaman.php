<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: dashboard.php");
    exit();
}

$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_jenis = $_GET['jenis_pinjaman_id'] ?? '';
$filter_status = $_GET['status'] ?? '';

if (isset($_GET['export_pdf']) && $_GET['export_pdf'] == 1) {
    $params = http_build_query([
        'anggota_id' => $filter_anggota,
        'jenis_pinjaman_id' => $filter_jenis,
        'status' => $filter_status
    ]);
    header("Location: cetak_laporan_pinjaman.php?$params");
    exit();
}

if (isset($_GET['export_excel']) && $_GET['export_excel'] == 1) {
    $params = http_build_query([
        'anggota_id' => $filter_anggota,
        'jenis_pinjaman_id' => $filter_jenis,
        'status' => $filter_status
    ]);
    header("Location: export_excel_pinjaman.php?$params");
    exit();
}

$query = "SELECT 
            p.*, 
            a.kode_anggota, 
            a.nama_lengkap, 
            j.nama_jenis,
            (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as angsuran_terbayar,
            (SELECT COALESCE(SUM(pokok), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_pokok_dibayar,
            (SELECT COALESCE(SUM(bunga), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_bunga_dibayar,
            (SELECT COALESCE(SUM(denda), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_denda_dibayar
          FROM pinjaman p 
          JOIN anggota a ON p.anggota_id = a.id 
          JOIN jenis_pinjaman j ON p.jenis_pinjaman_id = j.id 
          WHERE 1=1";

$params = [];

if (!empty($filter_anggota)) {
    $query .= " AND p.anggota_id = :anggota_id";
    $params[':anggota_id'] = $filter_anggota;
}

if (!empty($filter_jenis)) {
    $query .= " AND p.jenis_pinjaman_id = :jenis_pinjaman_id";
    $params[':jenis_pinjaman_id'] = $filter_jenis;
}

if (!empty($filter_status)) {
    $query .= " AND p.status = :status";
    $params[':status'] = $filter_status;
}

$query .= " ORDER BY p.tanggal_pencairan DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$pinjaman = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query_anggota = "SELECT * FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

$query_jenis = "SELECT * FROM jenis_pinjaman WHERE status = 'aktif' ORDER BY nama_jenis";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_list = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

$status_list = [
    'aktif' => 'Aktif',
    'lunas' => 'Lunas',
    'macet' => 'Macet'
];

$total_pinjaman_aktif = 0;
$total_pinjaman_lunas = 0;
$total_pinjaman_macet = 0;
$total_sisa_pokok = 0;
$total_dibayar = 0;
$total_nominal = 0;
$total_bunga_dibayar = 0;
$total_denda_dibayar = 0;

foreach ($pinjaman as $p) {
    if (($p['status'] ?? '') === 'aktif') {
        $total_pinjaman_aktif++;
    } elseif (($p['status'] ?? '') === 'lunas') {
        $total_pinjaman_lunas++;
    } elseif (($p['status'] ?? '') === 'macet') {
        $total_pinjaman_macet++;
    }
    $total_sisa_pokok += (float)$p['sisa_pokok'];
    $total_dibayar += (float)$p['total_pokok_dibayar'];
    $total_nominal += (float)$p['jumlah_pinjaman'];
    $total_bunga_dibayar += (float)$p['total_bunga_dibayar'];
    $total_denda_dibayar += (float)$p['total_denda_dibayar'];
}

$exportQs = http_build_query([
    'anggota_id' => $filter_anggota,
    'jenis_pinjaman_id' => $filter_jenis,
    'status' => $filter_status,
]);

$page_title = 'Laporan Pinjaman';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Pinjaman - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-lpin" aria-hidden="true"><i class="ti ti-report-money"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Laporan</p>
                        <h1 class="ui-dash-title">Laporan Pinjaman</h1>
                        <p class="ui-dash-meta mb-0">Rekap pinjaman aktif, lunas, outstanding, dan progress angsuran</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="?export_pdf=1&amp;<?php echo htmlspecialchars($exportQs); ?>" class="btn btn-outline-danger btn-sm" target="_blank" rel="noopener">
                            <i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">PDF</span>
                        </a>
                        <a href="?export_excel=1&amp;<?php echo htmlspecialchars($exportQs); ?>" class="btn btn-outline-success btn-sm">
                            <i class="ti ti-file-spreadsheet me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Excel</span>
                        </a>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-lpin-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total pinjaman', 'value' => count($pinjaman), 'tone' => 'primary', 'icon' => 'ti ti-moneybag', 'hint' => formatRupiah($total_nominal)],
                    ['label' => 'Aktif', 'value' => $total_pinjaman_aktif, 'tone' => 'warning', 'icon' => 'ti ti-player-play', 'hint' => 'Belum lunas'],
                    ['label' => 'Lunas', 'value' => $total_pinjaman_lunas, 'tone' => 'success', 'icon' => 'ti ti-flag-check', 'hint' => $total_pinjaman_macet ? $total_pinjaman_macet . ' macet' : 'Selesai'],
                    ['label' => 'Sisa pokok', 'value' => formatRupiah($total_sisa_pokok), 'tone' => 'danger', 'icon' => 'ti ti-cash', 'hint' => 'Dibayar ' . formatRupiah($total_dibayar)],
                ]); ?>
                </div>

                <div class="card ui-lpin-filter mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-filter" aria-hidden="true"></i>Filter laporan</span>
                        <span class="ui-dash-sec-note">Scope data pinjaman</span>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="ui-lpin-filter-form">
                            <div class="ui-lpin-filter-grid">
                                <div class="ui-lpin-field">
                                    <label class="form-label" for="lp_anggota">Anggota</label>
                                    <select id="lp_anggota" name="anggota_id" class="form-select">
                                        <option value="">Semua anggota</option>
                                        <?php foreach ($anggota_list as $a): ?>
                                        <option value="<?php echo (int)$a['id']; ?>" <?php echo (string)$filter_anggota === (string)$a['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(($a['kode_anggota'] ?? '') . ' — ' . ($a['nama_lengkap'] ?? '')); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ui-lpin-field">
                                    <label class="form-label" for="lp_jenis">Jenis pinjaman</label>
                                    <select id="lp_jenis" name="jenis_pinjaman_id" class="form-select">
                                        <option value="">Semua jenis</option>
                                        <?php foreach ($jenis_list as $j): ?>
                                        <option value="<?php echo (int)$j['id']; ?>" <?php echo (string)$filter_jenis === (string)$j['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($j['nama_jenis']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ui-lpin-field">
                                    <label class="form-label" for="lp_status">Status</label>
                                    <select id="lp_status" name="status" class="form-select">
                                        <option value="">Semua status</option>
                                        <?php foreach ($status_list as $key => $value): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $filter_status === $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($value); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ui-lpin-field ui-lpin-field-actions">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="ui-lpin-filter-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="ti ti-search me-1" aria-hidden="true"></i>Terapkan
                                        </button>
                                        <a href="laporan_pinjaman.php" class="btn btn-outline-secondary">
                                            <i class="ti ti-refresh me-1" aria-hidden="true"></i>Reset
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ui-lpin-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-table" aria-hidden="true"></i>Data pinjaman</span>
                        <span class="ui-dash-sec-note"><?php echo (int)count($pinjaman); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="9,desc"
                                   data-dt-filter="2,10"
                                   data-empty-message="Tidak ada data pinjaman untuk filter ini.">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Pinjaman</th>
                                        <th class="text-end">Sisa pokok</th>
                                        <th class="text-end">Dibayar</th>
                                        <th>Progress</th>
                                        <th>Tenor</th>
                                        <th>Bunga</th>
                                        <th>Tgl cair</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($pinjaman as $p):
                                    $progress = (float)$p['jumlah_pinjaman'] > 0
                                        ? ((float)$p['total_pokok_dibayar'] / (float)$p['jumlah_pinjaman']) * 100
                                        : 0;
                                    $progress = min(100, max(0, $progress));
                                    $st = (string)($p['status'] ?? '');
                                    $statusClass = 'bg-secondary';
                                    $statusLabel = ucfirst($st ?: '—');
                                    if ($st === 'aktif') {
                                        $statusClass = 'bg-warning text-dark';
                                        $statusLabel = 'Aktif';
                                    } elseif ($st === 'lunas') {
                                        $statusClass = 'bg-success';
                                        $statusLabel = 'Lunas';
                                    } elseif ($st === 'macet') {
                                        $statusClass = 'bg-danger';
                                        $statusLabel = 'Macet';
                                    }
                                    $cairTs = !empty($p['tanggal_pencairan']) ? strtotime($p['tanggal_pencairan']) : false;
                                ?>
                                    <tr>
                                        <td><code class="ui-lpin-kode"><?php echo htmlspecialchars($p['kode_pinjaman']); ?></code></td>
                                        <td>
                                            <strong class="ui-lpin-name"><?php echo htmlspecialchars($p['nama_lengkap']); ?></strong>
                                            <small class="ui-lpin-meta"><?php echo htmlspecialchars($p['kode_anggota']); ?></small>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($p['nama_jenis']); ?>"><?php echo htmlspecialchars($p['nama_jenis']); ?></td>
                                        <td class="text-end fw-semibold text-primary ui-lpin-money"><?php echo formatRupiah($p['jumlah_pinjaman']); ?></td>
                                        <td class="text-end fw-semibold text-danger ui-lpin-money"><?php echo formatRupiah($p['sisa_pokok']); ?></td>
                                        <td class="text-end">
                                            <strong class="text-success ui-lpin-money d-block"><?php echo formatRupiah($p['total_pokok_dibayar']); ?></strong>
                                            <?php if ((float)$p['total_bunga_dibayar'] > 0 || (float)$p['total_denda_dibayar'] > 0): ?>
                                            <small class="ui-lpin-meta">
                                                Bunga <?php echo formatRupiah($p['total_bunga_dibayar']); ?>
                                                <?php if ((float)$p['total_denda_dibayar'] > 0): ?>
                                                · Denda <?php echo formatRupiah($p['total_denda_dibayar']); ?>
                                                <?php endif; ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-order="<?php echo htmlspecialchars((string)round($progress, 1)); ?>">
                                            <div class="ui-lpin-progress">
                                                <div class="progress ui-lpin-progress-bar"><div class="progress-bar" style="width:<?php echo $progress; ?>%"></div></div>
                                                <small><?php echo round($progress, 1); ?>%</small>
                                            </div>
                                            <small class="ui-lpin-meta"><?php echo (int)$p['angsuran_terbayar']; ?>/<?php echo (int)$p['tenor']; ?> angsuran</small>
                                        </td>
                                        <td><span class="badge bg-info-subtle text-info"><?php echo htmlspecialchars(labelTenorRow($p)); ?></span></td>
                                        <td><span class="badge bg-secondary-subtle text-secondary"><?php echo htmlspecialchars(labelBungaRow($p)); ?></span></td>
                                        <td data-order="<?php echo $cairTs ? date('Y-m-d', $cairTs) : ''; ?>">
                                            <?php echo !empty($p['tanggal_pencairan']) ? htmlspecialchars(formatTanggalUi($p['tanggal_pencairan'])) : '—'; ?>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($statusLabel); ?>">
                                            <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($pinjaman): ?>
                        <div class="ui-lpin-totals">
                            <div><span>Total pinjaman</span><strong class="text-primary"><?php echo formatRupiah($total_nominal); ?></strong></div>
                            <div><span>Sisa pokok</span><strong class="text-danger"><?php echo formatRupiah($total_sisa_pokok); ?></strong></div>
                            <div><span>Pokok dibayar</span><strong class="text-success"><?php echo formatRupiah($total_dibayar); ?></strong></div>
                            <?php if ($total_bunga_dibayar > 0 || $total_denda_dibayar > 0): ?>
                            <div>
                                <span>Bunga / denda</span>
                                <strong><?php echo formatRupiah($total_bunga_dibayar); ?><?php if ($total_denda_dibayar > 0): ?> · <?php echo formatRupiah($total_denda_dibayar); ?><?php endif; ?></strong>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
</body>
</html>

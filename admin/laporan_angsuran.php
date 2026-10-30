<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

if (!isAdmin() && !isTeller()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: dashboard.php");
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-1 month'));
$end_date = $_GET['end_date'] ?? date('Y-m-d', strtotime('+6 months'));
$filter_status = $_GET['status'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_pinjaman = $_GET['pinjaman_id'] ?? '';

if (isset($_GET['export_pdf']) && $_GET['export_pdf'] == 1) {
    $params = http_build_query([
        'start_date' => $start_date,
        'end_date' => $end_date,
        'status' => $filter_status,
        'anggota_id' => $filter_anggota,
        'pinjaman_id' => $filter_pinjaman
    ]);
    header("Location: cetak_laporan_angsuran.php?$params");
    exit();
}

if (isset($_GET['export_excel']) && $_GET['export_excel'] == 1) {
    $params = http_build_query([
        'start_date' => $start_date,
        'end_date' => $end_date,
        'status' => $filter_status,
        'anggota_id' => $filter_anggota,
        'pinjaman_id' => $filter_pinjaman
    ]);
    header("Location: export_excel_angsuran.php?$params");
    exit();
}

$query = "SELECT a.*, p.kode_pinjaman, p.anggota_id, p.tanggal_pencairan,
                 ag.kode_anggota, ag.nama_lengkap,
                 jp.nama_jenis as jenis_pinjaman
          FROM angsuran a 
          JOIN pinjaman p ON a.pinjaman_id = p.id 
          JOIN anggota ag ON p.anggota_id = ag.id 
          JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id 
          WHERE 1=1";

$params = [];

if (!empty($start_date) && !empty($end_date)) {
    $query .= " AND (a.tanggal_jatuh_tempo BETWEEN :tanggal_mulai AND :tanggal_selesai
                OR a.tanggal_bayar BETWEEN :bayar_mulai AND :bayar_selesai)";
    $params[':tanggal_mulai'] = $start_date;
    $params[':tanggal_selesai'] = $end_date;
    $params[':bayar_mulai'] = $start_date;
    $params[':bayar_selesai'] = $end_date;
}

if (!empty($filter_status)) {
    $query .= " AND a.status = :status";
    $params[':status'] = $filter_status;
}

if (!empty($filter_anggota)) {
    $query .= " AND p.anggota_id = :anggota_id";
    $params[':anggota_id'] = $filter_anggota;
}

if (!empty($filter_pinjaman)) {
    $query .= " AND p.id = :pinjaman_id";
    $params[':pinjaman_id'] = $filter_pinjaman;
}

$query .= " ORDER BY a.tanggal_jatuh_tempo ASC, p.kode_pinjaman ASC, a.angsuran_ke ASC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$angsuran = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query_anggota = "SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

$query_pinjaman = "SELECT p.id, p.kode_pinjaman, a.nama_lengkap 
                   FROM pinjaman p 
                   JOIN anggota a ON p.anggota_id = a.id 
                   WHERE p.status = 'aktif' 
                   ORDER BY p.kode_pinjaman";
$stmt_pinjaman = $db->prepare($query_pinjaman);
$stmt_pinjaman->execute();
$pinjaman_list = $stmt_pinjaman->fetchAll(PDO::FETCH_ASSOC);

$status_list = [
    '' => 'Semua status',
    'belum_bayar' => 'Belum bayar',
    'lunas' => 'Lunas',
    'telat' => 'Telat'
];

$total_angsuran = 0;
$total_pokok = 0;
$total_bunga = 0;
$total_denda = 0;
$total_lunas = 0;
$total_belum_bayar = 0;
$total_telat = 0;
$pinjaman_unik = [];

foreach ($angsuran as $a) {
    $total_angsuran += (float)$a['jumlah_angsuran'];
    $total_pokok += (float)$a['pokok'];
    $total_bunga += (float)$a['bunga'];
    $total_denda += (float)$a['denda'];
    if (($a['status'] ?? '') === 'lunas') {
        $total_lunas++;
    } elseif (($a['status'] ?? '') === 'belum_bayar') {
        $total_belum_bayar++;
    } elseif (($a['status'] ?? '') === 'telat') {
        $total_telat++;
    }
    $pinjaman_unik[$a['kode_pinjaman']] = true;
}

$exportQs = http_build_query([
    'start_date' => $start_date,
    'end_date' => $end_date,
    'status' => $filter_status,
    'anggota_id' => $filter_anggota,
    'pinjaman_id' => $filter_pinjaman,
]);

$statusLabel = static function ($status) {
    $map = [
        'belum_bayar' => 'Belum bayar',
        'lunas' => 'Lunas',
        'telat' => 'Telat',
    ];
    return $map[$status] ?? ucfirst(str_replace('_', ' ', (string)$status));
};

$page_title = 'Laporan Angsuran';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Angsuran - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-lang" aria-hidden="true"><i class="ti ti-calendar-stats"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Laporan</p>
                        <h1 class="ui-dash-title">Laporan Angsuran</h1>
                        <p class="ui-dash-meta mb-0">Jadwal cicilan, status bayar, denda, dan keterlambatan</p>
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

                <div class="alert alert-info ui-lang-info no-print" role="status" data-ui-toast-skip>
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                    Halaman laporan saja. Koreksi denda lewat <a href="angsuran.php">Angsuran</a> / <a href="denda.php">Denda</a>, atau riwayat migrasi di <a href="import_migrasi.php">Import Migrasi</a>.
                </div>

                <div class="ui-lang-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total angsuran', 'value' => count($angsuran), 'tone' => 'primary', 'icon' => 'ti ti-list', 'hint' => count($pinjaman_unik) . ' pinjaman'],
                    ['label' => 'Lunas', 'value' => $total_lunas, 'tone' => 'success', 'icon' => 'ti ti-circle-check', 'hint' => 'Sudah dibayar'],
                    ['label' => 'Belum bayar', 'value' => $total_belum_bayar, 'tone' => 'warning', 'icon' => 'ti ti-clock', 'hint' => 'Menunggu'],
                    ['label' => 'Telat', 'value' => $total_telat, 'tone' => 'danger', 'icon' => 'ti ti-alert-triangle', 'hint' => 'Lewat jatuh tempo'],
                ]); ?>
                </div>

                <div class="ui-lang-nilai mb-3">
                    <div class="ui-lang-nilai-card">
                        <span>Total nilai</span>
                        <strong class="text-primary"><?php echo formatRupiah($total_angsuran); ?></strong>
                    </div>
                    <div class="ui-lang-nilai-card">
                        <span>Pokok</span>
                        <strong><?php echo formatRupiah($total_pokok); ?></strong>
                    </div>
                    <div class="ui-lang-nilai-card">
                        <span>Bunga</span>
                        <strong class="text-info"><?php echo formatRupiah($total_bunga); ?></strong>
                    </div>
                    <div class="ui-lang-nilai-card">
                        <span>Denda</span>
                        <strong class="text-danger"><?php echo formatRupiah($total_denda); ?></strong>
                    </div>
                </div>

                <div class="card ui-lang-filter mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-filter" aria-hidden="true"></i>Filter laporan</span>
                        <span class="ui-dash-sec-note"><?php echo htmlspecialchars(formatTanggalUi($start_date)); ?> – <?php echo htmlspecialchars(formatTanggalUi($end_date)); ?></span>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="ui-lang-filter-form">
                            <div class="ui-lang-filter-grid">
                                <div class="ui-lang-field">
                                    <label class="form-label" for="la_start">Dari tanggal</label>
                                    <input type="date" id="la_start" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                                </div>
                                <div class="ui-lang-field">
                                    <label class="form-label" for="la_end">Sampai tanggal</label>
                                    <input type="date" id="la_end" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                                </div>
                                <div class="ui-lang-field">
                                    <label class="form-label" for="la_status">Status</label>
                                    <select id="la_status" name="status" class="form-select">
                                        <?php foreach ($status_list as $key => $value): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo (string)$filter_status === (string)$key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($value); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ui-lang-field">
                                    <label class="form-label" for="la_anggota">Anggota</label>
                                    <select id="la_anggota" name="anggota_id" class="form-select">
                                        <option value="">Semua anggota</option>
                                        <?php foreach ($anggota_list as $anggota): ?>
                                        <option value="<?php echo (int)$anggota['id']; ?>" <?php echo (string)$filter_anggota === (string)$anggota['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($anggota['kode_anggota'] . ' — ' . $anggota['nama_lengkap']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ui-lang-field">
                                    <label class="form-label" for="la_pinjaman">Pinjaman aktif</label>
                                    <select id="la_pinjaman" name="pinjaman_id" class="form-select">
                                        <option value="">Semua pinjaman</option>
                                        <?php foreach ($pinjaman_list as $pinjaman): ?>
                                        <option value="<?php echo (int)$pinjaman['id']; ?>" <?php echo (string)$filter_pinjaman === (string)$pinjaman['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($pinjaman['kode_pinjaman'] . ' — ' . $pinjaman['nama_lengkap']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="ui-lang-filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="ti ti-search me-1" aria-hidden="true"></i>Terapkan
                                </button>
                                <a href="laporan_angsuran.php" class="btn btn-outline-secondary">
                                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ui-lang-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-table" aria-hidden="true"></i>Data angsuran</span>
                        <span class="ui-dash-sec-note"><?php echo (int)count($angsuran); ?> baris · <?php echo (int)count($pinjaman_unik); ?> pinjaman</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="25"
                                   data-dt-order="3,asc"
                                   data-dt-filter="10"
                                   data-empty-message="Tidak ada data angsuran untuk filter ini.">
                                <thead>
                                    <tr>
                                        <th>Pinjaman</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Ke</th>
                                        <th>Jatuh tempo</th>
                                        <th class="text-end">Pokok</th>
                                        <th class="text-end">Bunga</th>
                                        <th class="text-end">Denda</th>
                                        <th class="text-end">Total</th>
                                        <th>Tgl bayar</th>
                                        <th>Status</th>
                                        <th>Telat</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($angsuran as $a):
                                    $st = (string)($a['status'] ?? '');
                                    $statusClass = 'bg-secondary';
                                    if ($st === 'belum_bayar') {
                                        $statusClass = 'bg-warning text-dark';
                                    } elseif ($st === 'lunas') {
                                        $statusClass = 'bg-success';
                                    } elseif ($st === 'telat') {
                                        $statusClass = 'bg-danger';
                                    }
                                    $label = $statusLabel($st);
                                    $jt = !empty($a['tanggal_jatuh_tempo']) ? strtotime($a['tanggal_jatuh_tempo']) : false;
                                    $tb = !empty($a['tanggal_bayar']) ? strtotime($a['tanggal_bayar']) : false;
                                ?>
                                    <tr>
                                        <td><code class="ui-lang-kode"><?php echo htmlspecialchars($a['kode_pinjaman']); ?></code></td>
                                        <td>
                                            <strong class="ui-lang-name"><?php echo htmlspecialchars($a['nama_lengkap']); ?></strong>
                                            <small class="ui-lang-meta"><?php echo htmlspecialchars($a['kode_anggota']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($a['jenis_pinjaman']); ?></td>
                                        <td class="text-end"><span class="badge bg-primary"><?php echo (int)$a['angsuran_ke']; ?></span></td>
                                        <td data-order="<?php echo $jt ? date('Y-m-d', $jt) : ''; ?>">
                                            <?php echo !empty($a['tanggal_jatuh_tempo']) ? htmlspecialchars(formatTanggalUi($a['tanggal_jatuh_tempo'])) : '—'; ?>
                                        </td>
                                        <td class="text-end ui-lang-money"><?php echo formatRupiah($a['pokok']); ?></td>
                                        <td class="text-end ui-lang-money"><?php echo formatRupiah($a['bunga']); ?></td>
                                        <td class="text-end fw-semibold text-danger ui-lang-money"><?php echo formatRupiah($a['denda']); ?></td>
                                        <td class="text-end fw-semibold text-primary ui-lang-money"><?php echo formatRupiah($a['jumlah_angsuran']); ?></td>
                                        <td data-order="<?php echo $tb ? date('Y-m-d', $tb) : ''; ?>">
                                            <?php echo !empty($a['tanggal_bayar']) ? htmlspecialchars(formatTanggalUi($a['tanggal_bayar'])) : '<span class="text-muted">—</span>'; ?>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($label); ?>">
                                            <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($label); ?></span>
                                        </td>
                                        <td>
                                            <?php if ((int)($a['keterlambatan_hari'] ?? 0) > 0): ?>
                                            <span class="text-danger fw-semibold">+<?php echo (int)$a['keterlambatan_hari']; ?> hari</span>
                                            <?php else: ?>
                                            <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($angsuran): ?>
                        <div class="ui-lang-totals">
                            <div><span>Total angsuran</span><strong class="text-primary"><?php echo formatRupiah($total_angsuran); ?></strong></div>
                            <div><span>Pokok</span><strong><?php echo formatRupiah($total_pokok); ?></strong></div>
                            <div><span>Bunga</span><strong class="text-info"><?php echo formatRupiah($total_bunga); ?></strong></div>
                            <div><span>Denda</span><strong class="text-danger"><?php echo formatRupiah($total_denda); ?></strong></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
</body>
</html>

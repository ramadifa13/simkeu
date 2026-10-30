<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_jenis = $_GET['jenis_simpanan_id'] ?? '';
$filter_tanggal_awal = $_GET['tanggal_awal'] ?? date('Y-m-01');
$filter_tanggal_akhir = $_GET['tanggal_akhir'] ?? date('Y-m-t');

if (isset($_GET['export_pdf']) && $_GET['export_pdf'] == 1) {
    $params = http_build_query([
        'anggota_id' => $filter_anggota,
        'jenis_simpanan_id' => $filter_jenis,
        'tanggal_awal' => $filter_tanggal_awal,
        'tanggal_akhir' => $filter_tanggal_akhir
    ]);
    header("Location: cetak_laporan_simpanan.php?$params");
    exit();
}

if (isset($_GET['export_excel']) && $_GET['export_excel'] == 1) {
    $params = http_build_query([
        'anggota_id' => $filter_anggota,
        'jenis_simpanan_id' => $filter_jenis,
        'tanggal_awal' => $filter_tanggal_awal,
        'tanggal_akhir' => $filter_tanggal_akhir
    ]);
    header("Location: export_excel_simpanan.php?$params");
    exit();
}

$query_anggota = "SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

$query_jenis = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' AND kode_jenis NOT IN ('ANG','BONUS','SHU') ORDER BY id";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_list = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

$query = "SELECT 
            a.id as anggota_id,
            a.kode_anggota, 
            a.nama_lengkap,
            j.id as jenis_id,
            j.kode_jenis,
            j.nama_jenis,
            j.minimum,
            j.maksimum,
            j.bagi_hasil,
            COALESCE((
                SELECT SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END)
                FROM transaksi t
                WHERE t.anggota_id = a.id 
                AND t.jenis_simpanan_id = j.id
                AND DATE(t.tanggal_transaksi) BETWEEN :tanggal_awal AND :tanggal_akhir
            ), 0) as saldo
          FROM anggota a
          CROSS JOIN jenis_simpanan j
          WHERE a.status = 'aktif'
          AND j.status = 'aktif'
          AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
          HAVING saldo != 0";

$params = [
    ':tanggal_awal' => $filter_tanggal_awal,
    ':tanggal_akhir' => $filter_tanggal_akhir
];

if (!empty($filter_anggota)) {
    $query = str_replace("WHERE a.status = 'aktif'", "WHERE a.status = 'aktif' AND a.id = :anggota_id", $query);
    $params[':anggota_id'] = $filter_anggota;
}

if (!empty($filter_jenis)) {
    $query = str_replace("HAVING saldo != 0", "AND j.id = :jenis_id HAVING saldo != 0", $query);
    $params[':jenis_id'] = $filter_jenis;
}

$query .= " ORDER BY a.nama_lengkap, j.id";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$simpanan = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query_summary = "SELECT 
    j.kode_jenis,
    j.nama_jenis,
    COUNT(DISTINCT t.anggota_id) as jumlah_anggota,
    SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END) as total_saldo
FROM transaksi t
JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
WHERE DATE(t.tanggal_transaksi) BETWEEN :tanggal_awal AND :tanggal_akhir
AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
GROUP BY j.kode_jenis, j.nama_jenis
HAVING total_saldo != 0
ORDER BY j.kode_jenis";

$stmt_summary = $db->prepare($query_summary);
$stmt_summary->bindParam(':tanggal_awal', $filter_tanggal_awal);
$stmt_summary->bindParam(':tanggal_akhir', $filter_tanggal_akhir);
$stmt_summary->execute();
$summary_per_jenis = $stmt_summary->fetchAll(PDO::FETCH_ASSOC);

$total_semua = array_sum(array_column($summary_per_jenis, 'total_saldo'));
$total_baris = count($simpanan);
$anggota_unik = count(array_unique(array_column($simpanan, 'anggota_id')));

$exportQs = http_build_query([
    'anggota_id' => $filter_anggota,
    'jenis_simpanan_id' => $filter_jenis,
    'tanggal_awal' => $filter_tanggal_awal,
    'tanggal_akhir' => $filter_tanggal_akhir,
]);

$badgeJenis = static function ($kode) {
    $map = [
        'POKOK' => 'is-pokok',
        'WAJIB' => 'is-wajib',
        'SUKARELA' => 'is-sukarela',
        'DEPOSITO' => 'is-deposito',
        'HAJI' => 'is-haji',
        'UMROH' => 'is-umroh',
    ];
    return $map[$kode] ?? 'is-other';
};

$page_title = 'Laporan Simpanan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Simpanan - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-lsimp" aria-hidden="true"><i class="ti ti-pig-money"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Laporan</p>
                        <h1 class="ui-dash-title">Laporan Simpanan</h1>
                        <p class="ui-dash-meta mb-0">Mutasi simpanan real per periode — tanpa jurnal ANG / BONUS / SHU</p>
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

                <div class="ui-lsimp-periode" role="status">
                    <i class="ti ti-calendar-event" aria-hidden="true"></i>
                    <div>
                        <strong>Periode <?php echo htmlspecialchars(formatTanggalUi($filter_tanggal_awal)); ?> – <?php echo htmlspecialchars(formatTanggalUi($filter_tanggal_akhir)); ?></strong>
                        <span>Simpanan real · Non-angsuran</span>
                    </div>
                </div>

                <div class="ui-lsimp-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total mutasi', 'value' => formatRupiah($total_semua), 'tone' => 'success', 'icon' => 'ti ti-pig-money', 'hint' => 'Netto periode'],
                    ['label' => 'Baris data', 'value' => $total_baris, 'tone' => 'primary', 'icon' => 'ti ti-receipt-2', 'hint' => 'Anggota × jenis'],
                    ['label' => 'Anggota', 'value' => $anggota_unik, 'tone' => 'info', 'icon' => 'ti ti-users', 'hint' => 'Dengan mutasi'],
                    ['label' => 'Jenis aktif', 'value' => count($summary_per_jenis), 'tone' => 'warning', 'icon' => 'ti ti-stack-2', 'hint' => 'Jenis terpakai'],
                ]); ?>
                </div>

                <div class="card ui-lsimp-filter mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-filter" aria-hidden="true"></i>Filter laporan</span>
                        <span class="ui-dash-sec-note">Periode &amp; scope data</span>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="ui-lsimp-filter-form">
                            <div class="ui-lsimp-filter-grid">
                                <div class="ui-lsimp-field">
                                    <label class="form-label" for="ls_tgl_awal">Tanggal awal</label>
                                    <input type="date" id="ls_tgl_awal" name="tanggal_awal" class="form-control" value="<?php echo htmlspecialchars($filter_tanggal_awal); ?>">
                                </div>
                                <div class="ui-lsimp-field">
                                    <label class="form-label" for="ls_tgl_akhir">Tanggal akhir</label>
                                    <input type="date" id="ls_tgl_akhir" name="tanggal_akhir" class="form-control" value="<?php echo htmlspecialchars($filter_tanggal_akhir); ?>">
                                </div>
                                <div class="ui-lsimp-field">
                                    <label class="form-label" for="ls_anggota">Anggota</label>
                                    <select id="ls_anggota" name="anggota_id" class="form-select">
                                        <option value="">Semua anggota</option>
                                        <?php foreach ($anggota_list as $a): ?>
                                        <option value="<?php echo (int)$a['id']; ?>" <?php echo (string)$filter_anggota === (string)$a['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($a['kode_anggota'] . ' — ' . $a['nama_lengkap']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ui-lsimp-field">
                                    <label class="form-label" for="ls_jenis">Jenis simpanan</label>
                                    <select id="ls_jenis" name="jenis_simpanan_id" class="form-select">
                                        <option value="">Semua jenis</option>
                                        <?php foreach ($jenis_list as $j): ?>
                                        <option value="<?php echo (int)$j['id']; ?>" <?php echo (string)$filter_jenis === (string)$j['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($j['nama_jenis']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="ui-lsimp-filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="ti ti-search me-1" aria-hidden="true"></i>Tampilkan
                                </button>
                                <a href="laporan_simpanan.php" class="btn btn-outline-secondary">
                                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ui-lsimp-summary mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-chart-pie" aria-hidden="true"></i>Ringkasan per jenis</span>
                        <span class="ui-dash-sec-note"><?php echo (int)count($summary_per_jenis); ?> jenis</span>
                    </div>
                    <div class="card-body">
                        <?php if (!$summary_per_jenis): ?>
                        <div class="ui-lsimp-empty">
                            <i class="ti ti-chart-pie-off" aria-hidden="true"></i>
                            <strong>Tidak ada mutasi</strong>
                            <p>Tidak ada data simpanan untuk periode ini.</p>
                        </div>
                        <?php else: ?>
                        <div class="ui-lsimp-summary-grid">
                            <?php foreach ($summary_per_jenis as $s): ?>
                            <article class="ui-lsimp-summary-card">
                                <span class="ui-lsimp-kode <?php echo htmlspecialchars($badgeJenis($s['kode_jenis'])); ?>"><?php echo htmlspecialchars($s['kode_jenis']); ?></span>
                                <strong><?php echo formatRupiah($s['total_saldo']); ?></strong>
                                <span><?php echo htmlspecialchars($s['nama_jenis']); ?> · <?php echo (int)$s['jumlah_anggota']; ?> anggota</span>
                            </article>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card ui-lsimp-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Detail simpanan anggota</span>
                        <span class="ui-dash-sec-note"><?php echo (int)$total_baris; ?> data · total <?php echo formatRupiah(array_sum(array_column($simpanan, 'saldo'))); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="2,asc"
                                   data-dt-filter="3"
                                   data-empty-message="Tidak ada data simpanan untuk filter ini.">
                                <thead>
                                    <tr>
                                        <th class="text-muted">#</th>
                                        <th>Kode</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Saldo</th>
                                        <th class="text-end">Minimal</th>
                                        <th class="text-end">Maksimal</th>
                                        <th class="text-end">Bagi hasil</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($simpanan as $i => $s): ?>
                                    <tr>
                                        <td class="text-muted"><?php echo (int)($i + 1); ?></td>
                                        <td><code><?php echo htmlspecialchars($s['kode_anggota']); ?></code></td>
                                        <td><strong class="ui-lsimp-name"><?php echo htmlspecialchars($s['nama_lengkap']); ?></strong></td>
                                        <td data-filter="<?php echo htmlspecialchars($s['kode_jenis']); ?>">
                                            <span class="ui-lsimp-kode <?php echo htmlspecialchars($badgeJenis($s['kode_jenis'])); ?>" title="<?php echo htmlspecialchars($s['nama_jenis']); ?>">
                                                <?php echo htmlspecialchars($s['kode_jenis']); ?>
                                            </span>
                                        </td>
                                        <td class="text-end fw-semibold text-success ui-lsimp-money"><?php echo formatRupiah($s['saldo']); ?></td>
                                        <td class="text-end ui-lsimp-money"><?php echo formatRupiah($s['minimum']); ?></td>
                                        <td class="text-end ui-lsimp-money"><?php echo (float)$s['maksimum'] > 0 ? formatRupiah($s['maksimum']) : '—'; ?></td>
                                        <td class="text-end"><?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$s['bagi_hasil'], 2, ',', '.'), '0'), ',')); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
</body>
</html>

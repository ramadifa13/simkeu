<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isTeller()) {
    $_SESSION['error'] = 'Anda tidak memiliki akses ke halaman ini';
    header('Location: ../login.php');
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $start_date) || !strtotime($start_date)) {
    $start_date = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $end_date) || !strtotime($end_date)) {
    $end_date = date('Y-m-t');
}
if (strtotime($start_date) > strtotime($end_date)) {
    $_SESSION['error'] = 'Tanggal awal tidak boleh lebih besar dari tanggal akhir';
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
}

if (isset($_GET['export_pdf']) && (string) $_GET['export_pdf'] === '1') {
    header('Location: cetak_laporan_simpanan_teller.php?' . http_build_query([
        'start_date' => $start_date,
        'end_date' => $end_date,
    ]));
    exit();
}
if (isset($_GET['export_excel']) && (string) $_GET['export_excel'] === '1') {
    header('Location: export_excel_simpanan_teller.php?' . http_build_query([
        'start_date' => $start_date,
        'end_date' => $end_date,
    ]));
    exit();
}

$stmt_jenis = $db->prepare(
    "SELECT * FROM jenis_simpanan
     WHERE status = 'aktif' AND kode_jenis NOT IN ('ANG','BONUS','SHU')
     ORDER BY id"
);
$stmt_jenis->execute();
$jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

$stmt_total = $db->prepare(
    "SELECT
        COUNT(*) AS total_transaksi,
        COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE 0 END), 0) AS total_setoran,
        COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'penarikan' THEN t.jumlah ELSE 0 END), 0) AS total_penarikan
     FROM transaksi t
     JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
     WHERE DATE(t.tanggal_transaksi) BETWEEN :start_date AND :end_date
       AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')"
);
$stmt_total->execute([':start_date' => $start_date, ':end_date' => $end_date]);
$total_stats = $stmt_total->fetch(PDO::FETCH_ASSOC) ?: [
    'total_transaksi' => 0,
    'total_setoran' => 0,
    'total_penarikan' => 0,
];
$netto_periode = (float) $total_stats['total_setoran'] - (float) $total_stats['total_penarikan'];

$stmt_saldo = $db->prepare(
    "SELECT j.kode_jenis, COALESCE(SUM(s.saldo), 0) AS saldo_akhir
     FROM jenis_simpanan j
     LEFT JOIN simpanan s ON j.id = s.jenis_simpanan_id
     WHERE j.status = 'aktif' AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
     GROUP BY j.id, j.kode_jenis
     ORDER BY j.id"
);
$stmt_saldo->execute();
$saldo_map = [];
foreach ($stmt_saldo->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $saldo_map[$s['kode_jenis']] = (float) $s['saldo_akhir'];
}

$stmt_rekap = $db->prepare(
    "SELECT
        j.id,
        j.kode_jenis,
        j.nama_jenis,
        COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE 0 END), 0) AS total_setoran,
        COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'penarikan' THEN t.jumlah ELSE 0 END), 0) AS total_penarikan,
        COUNT(t.id) AS jumlah_transaksi
     FROM jenis_simpanan j
     LEFT JOIN transaksi t ON t.jenis_simpanan_id = j.id
          AND DATE(t.tanggal_transaksi) BETWEEN :start_date AND :end_date
     WHERE j.status = 'aktif' AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
     GROUP BY j.id, j.kode_jenis, j.nama_jenis
     ORDER BY j.id"
);
$stmt_rekap->execute([':start_date' => $start_date, ':end_date' => $end_date]);
$rekap_jenis = $stmt_rekap->fetchAll(PDO::FETCH_ASSOC);

$stmt_transactions = $db->prepare(
    "SELECT t.*, a.nama_lengkap, a.kode_anggota, j.kode_jenis, j.nama_jenis, u.nama_lengkap AS teller
     FROM transaksi t
     JOIN anggota a ON t.anggota_id = a.id
     JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
     JOIN users u ON t.user_id = u.id
     WHERE DATE(t.tanggal_transaksi) BETWEEN :start_date AND :end_date
       AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
     ORDER BY t.tanggal_transaksi DESC, t.id DESC"
);
$stmt_transactions->execute([':start_date' => $start_date, ':end_date' => $end_date]);
$transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

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

$exportQs = http_build_query([
    'start_date' => $start_date,
    'end_date' => $end_date,
]);
$page_title = 'Laporan Simpanan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-lsimp" aria-hidden="true"><i class="ti ti-pig-money"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Laporan Teller</p>
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
                        <strong>Periode <?php echo htmlspecialchars(formatTanggalUi($start_date)); ?> – <?php echo htmlspecialchars(formatTanggalUi($end_date)); ?></strong>
                        <span><?php echo (int) $total_stats['total_transaksi']; ?> transaksi · Netto <?php echo formatRupiah($netto_periode); ?></span>
                    </div>
                </div>

                <div class="ui-lsimp-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total transaksi', 'value' => (int) $total_stats['total_transaksi'], 'tone' => 'primary', 'icon' => 'ti ti-arrows-exchange', 'hint' => 'Dalam periode'],
                    ['label' => 'Total setoran', 'value' => formatRupiah($total_stats['total_setoran']), 'tone' => 'success', 'icon' => 'ti ti-arrow-down-left', 'hint' => 'Masuk kas'],
                    ['label' => 'Total penarikan', 'value' => formatRupiah($total_stats['total_penarikan']), 'tone' => 'danger', 'icon' => 'ti ti-arrow-up-right', 'hint' => 'Keluar kas'],
                    ['label' => 'Netto periode', 'value' => formatRupiah($netto_periode), 'tone' => 'info', 'icon' => 'ti ti-scale', 'hint' => 'Setoran − penarikan'],
                ]); ?>
                </div>

                <div class="card ui-lsimp-filter mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-filter" aria-hidden="true"></i>Filter laporan</span>
                        <span class="ui-dash-sec-note">Rentang tanggal</span>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="ui-lsimp-filter-form">
                            <div class="ui-lsimp-filter-grid ui-tls-filter-grid">
                                <div class="ui-lsimp-field">
                                    <label class="form-label" for="tls_start">Dari tanggal</label>
                                    <input type="date" id="tls_start" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                                </div>
                                <div class="ui-lsimp-field">
                                    <label class="form-label" for="tls_end">Sampai tanggal</label>
                                    <input type="date" id="tls_end" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                                </div>
                            </div>
                            <div class="ui-lsimp-filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="ti ti-search me-1" aria-hidden="true"></i>Tampilkan
                                </button>
                                <a href="laporan_simpanan.php" class="btn btn-outline-secondary">
                                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>Reset bulan ini
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ui-lsimp-summary mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-chart-pie" aria-hidden="true"></i>Rekap per jenis simpanan</span>
                        <span class="ui-dash-sec-note"><?php echo (int) count($jenis_simpanan); ?> jenis aktif</span>
                    </div>
                    <div class="card-body">
                        <?php if (!$rekap_jenis): ?>
                        <div class="ui-lsimp-empty">
                            <i class="ti ti-chart-pie-off" aria-hidden="true"></i>
                            <strong>Belum ada jenis simpanan</strong>
                            <p>Tidak ada jenis simpanan aktif untuk ditampilkan.</p>
                        </div>
                        <?php else: ?>
                        <div class="ui-tls-rekap-grid">
                            <?php foreach ($rekap_jenis as $r):
                                $kode = $r['kode_jenis'];
                                $netto = (float) $r['total_setoran'] - (float) $r['total_penarikan'];
                                $saldo_akhir = $saldo_map[$kode] ?? 0;
                                ?>
                            <article class="ui-tls-rekap-card">
                                <div class="ui-tls-rekap-head">
                                    <span class="ui-lsimp-kode <?php echo htmlspecialchars($badgeJenis($kode)); ?>"><?php echo htmlspecialchars($kode); ?></span>
                                    <strong><?php echo htmlspecialchars($r['nama_jenis']); ?></strong>
                                </div>
                                <div class="ui-tls-rekap-nums">
                                    <div>
                                        <span>Setoran</span>
                                        <strong class="is-in"><?php echo formatRupiah($r['total_setoran']); ?></strong>
                                    </div>
                                    <div>
                                        <span>Penarikan</span>
                                        <strong class="is-out"><?php echo formatRupiah($r['total_penarikan']); ?></strong>
                                    </div>
                                    <div>
                                        <span>Netto</span>
                                        <strong><?php echo formatRupiah($netto); ?></strong>
                                    </div>
                                    <div>
                                        <span>Saldo akhir</span>
                                        <strong class="is-saldo"><?php echo formatRupiah($saldo_akhir); ?></strong>
                                    </div>
                                </div>
                                <small><?php echo (int) $r['jumlah_transaksi']; ?> transaksi periode ini</small>
                            </article>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card ui-lsimp-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Detail transaksi simpanan</span>
                        <span class="ui-dash-sec-note"><?php echo (int) count($transactions); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="25"
                                   data-dt-order="0,desc"
                                   data-dt-filter="3,4"
                                   data-empty-message="Tidak ada transaksi simpanan pada periode ini.">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Kode</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th>Transaksi</th>
                                        <th class="text-end">Jumlah</th>
                                        <th>Teller</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($transactions as $t):
                                    $isSetor = ($t['jenis_transaksi'] ?? '') === 'setoran';
                                    ?>
                                    <tr>
                                        <td data-order="<?php echo htmlspecialchars((string) $t['tanggal_transaksi']); ?>">
                                            <?php echo htmlspecialchars(formatTanggalUi($t['tanggal_transaksi'], true)); ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($t['kode_transaksi']); ?></code></td>
                                        <td>
                                            <strong class="ui-lsimp-name"><?php echo htmlspecialchars($t['nama_lengkap']); ?></strong>
                                            <span class="ui-lhari-meta"><?php echo htmlspecialchars($t['kode_anggota']); ?></span>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($t['kode_jenis']); ?>">
                                            <span class="ui-lsimp-kode <?php echo htmlspecialchars($badgeJenis($t['kode_jenis'])); ?>" title="<?php echo htmlspecialchars($t['nama_jenis']); ?>">
                                                <?php echo htmlspecialchars($t['kode_jenis']); ?>
                                            </span>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($t['jenis_transaksi']); ?>">
                                            <span class="ui-lhari-arah <?php echo $isSetor ? 'is-in' : 'is-out'; ?>">
                                                <?php echo $isSetor ? 'Setoran' : 'Penarikan'; ?>
                                            </span>
                                        </td>
                                        <td class="text-end fw-semibold ui-lsimp-money <?php echo $isSetor ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo ($isSetor ? '+' : '-') . formatRupiah($t['jumlah']); ?>
                                        </td>
                                        <td><span class="ui-lhari-meta"><?php echo htmlspecialchars($t['teller'] ?? '—'); ?></span></td>
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

<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isTeller()) {
    $_SESSION['error'] = 'Anda tidak memiliki akses ke halaman ini';
    header('Location: ../login.php');
    exit();
}

$selected_date = $_GET['tanggal'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $selected_date) || !strtotime($selected_date)) {
    $selected_date = date('Y-m-d');
}
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));
$is_today = $selected_date === date('Y-m-d');

if (isset($_GET['export_pdf']) && (string) $_GET['export_pdf'] === '1') {
    header('Location: cetak_laporan_harian.php?' . http_build_query(['tanggal' => $selected_date]));
    exit();
}
if (isset($_GET['export_excel']) && (string) $_GET['export_excel'] === '1') {
    header('Location: export_excel_harian.php?' . http_build_query(['tanggal' => $selected_date]));
    exit();
}

$stmt_summary = $db->prepare(
    "SELECT
        COUNT(*) AS total_transaksi,
        COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE 0 END), 0) AS total_setoran,
        COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'penarikan' THEN t.jumlah ELSE 0 END), 0) AS total_penarikan,
        COUNT(CASE WHEN t.jenis_transaksi = 'setoran' THEN 1 END) AS jumlah_setoran,
        COUNT(CASE WHEN t.jenis_transaksi = 'penarikan' THEN 1 END) AS jumlah_penarikan
     FROM transaksi t
     JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
     WHERE DATE(t.tanggal_transaksi) = :tanggal AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')"
);
$stmt_summary->execute([':tanggal' => $selected_date]);
$summary = $stmt_summary->fetch(PDO::FETCH_ASSOC) ?: [
    'total_transaksi' => 0,
    'total_setoran' => 0,
    'total_penarikan' => 0,
    'jumlah_setoran' => 0,
    'jumlah_penarikan' => 0,
];

$stmt_angsuran_summary = $db->prepare(
    "SELECT COUNT(*) AS total_angsuran,
            COALESCE(SUM(jumlah_angsuran + COALESCE(denda, 0)), 0) AS total_pembayaran_angsuran
     FROM angsuran
     WHERE tanggal_bayar = :tanggal AND status = 'lunas'"
);
$stmt_angsuran_summary->execute([':tanggal' => $selected_date]);
$angsuran_summary = $stmt_angsuran_summary->fetch(PDO::FETCH_ASSOC) ?: [
    'total_angsuran' => 0,
    'total_pembayaran_angsuran' => 0,
];

$stmt_kas_masuk = $db->prepare("SELECT COALESCE(SUM(jumlah), 0) FROM kas WHERE jenis = 'masuk' AND DATE(tanggal) = :tanggal");
$stmt_kas_masuk->execute([':tanggal' => $selected_date]);
$penerimaan = (float) $stmt_kas_masuk->fetchColumn();

$stmt_kas_keluar = $db->prepare("SELECT COALESCE(SUM(jumlah), 0) FROM kas WHERE jenis = 'keluar' AND DATE(tanggal) = :tanggal");
$stmt_kas_keluar->execute([':tanggal' => $selected_date]);
$pengeluaran = (float) $stmt_kas_keluar->fetchColumn();
$selisih_kas = $penerimaan - $pengeluaran;

$stmt_transactions = $db->prepare(
    "SELECT t.*, a.kode_anggota, a.nama_lengkap, j.kode_jenis, j.nama_jenis, u.nama_lengkap AS teller
     FROM transaksi t
     JOIN anggota a ON t.anggota_id = a.id
     JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
     JOIN users u ON t.user_id = u.id
     WHERE DATE(t.tanggal_transaksi) = :tanggal AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
     ORDER BY t.tanggal_transaksi DESC, t.id DESC"
);
$stmt_transactions->execute([':tanggal' => $selected_date]);
$transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

$stmt_angsuran = $db->prepare(
    "SELECT a.*, ang.kode_anggota, ang.nama_lengkap, p.kode_pinjaman,
            (SELECT MAX(t.tanggal_transaksi) FROM transaksi t
              WHERE t.keterangan = CONCAT('Pembayaran angsuran ke-', a.angsuran_ke, ' - ', p.kode_pinjaman)
                AND t.jumlah > 0) AS waktu_bayar,
            jp.nama_jenis AS jenis_pinjaman
     FROM angsuran a
     JOIN pinjaman p ON a.pinjaman_id = p.id
     JOIN anggota ang ON p.anggota_id = ang.id
     JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
     WHERE a.tanggal_bayar = :tanggal AND a.status = 'lunas'
     ORDER BY a.tanggal_bayar DESC, a.created_at DESC"
);
$stmt_angsuran->execute([':tanggal' => $selected_date]);
$angsuran = $stmt_angsuran->fetchAll(PDO::FETCH_ASSOC);

$stmt_kas = $db->prepare(
    "SELECT k.*, t.kode_transaksi, a.nama_lengkap AS anggota_nama
     FROM kas k
     LEFT JOIN transaksi t ON k.transaksi_id = t.id
     LEFT JOIN anggota a ON t.anggota_id = a.id
     WHERE DATE(k.tanggal) = :tanggal
     ORDER BY k.tanggal ASC, k.id ASC"
);
$stmt_kas->execute([':tanggal' => $selected_date]);
$kas_transactions = $stmt_kas->fetchAll(PDO::FETCH_ASSOC);

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

$exportQs = http_build_query(['tanggal' => $selected_date]);
$page_title = 'Laporan Harian';

$hariId = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$bulanId = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$tsSelected = strtotime($selected_date);
$hariLabel = $hariId[(int) date('w', $tsSelected)];
$tanggalLabel = (int) date('j', $tsSelected) . ' ' . $bulanId[(int) date('n', $tsSelected)] . ' ' . date('Y', $tsSelected);
$countSimpanan = count($transactions);
$countAngsuran = count($angsuran);
$countKas = count($kas_transactions);
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
                    <div class="ui-dash-header-icon ui-tx-icon-lhari" aria-hidden="true"><i class="ti ti-calendar-stats"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Laporan Teller</p>
                        <h1 class="ui-dash-title">Laporan Harian</h1>
                        <p class="ui-dash-meta mb-0">Ringkasan simpanan, angsuran, dan kas per tanggal</p>
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

                <section class="ui-lhari-datepanel mb-3" aria-label="Pilih tanggal laporan">
                    <div class="ui-lhari-datepanel-main">
                        <div class="ui-lhari-datepanel-badge" aria-hidden="true"><i class="ti ti-calendar-month"></i></div>
                        <div class="ui-lhari-datepanel-copy">
                            <p class="ui-lhari-datepanel-eyebrow mb-0"><?php echo $is_today ? 'Laporan hari ini' : 'Laporan tanggal terpilih'; ?></p>
                            <h2 class="ui-lhari-datepanel-title"><?php echo htmlspecialchars($hariLabel . ', ' . $tanggalLabel); ?></h2>
                            <p class="ui-lhari-datepanel-meta mb-0">
                                <span><i class="ti ti-pig-money" aria-hidden="true"></i><?php echo (int) $countSimpanan; ?> simpanan</span>
                                <span><i class="ti ti-credit-card" aria-hidden="true"></i><?php echo (int) $countAngsuran; ?> angsuran</span>
                                <span><i class="ti ti-wallet" aria-hidden="true"></i><?php echo (int) $countKas; ?> kas</span>
                            </p>
                        </div>
                    </div>
                    <form method="GET" class="ui-lhari-datepanel-controls" id="formLaporanHarian">
                        <div class="ui-lhari-stepper" role="group" aria-label="Navigasi tanggal">
                            <a class="ui-lhari-step" href="?tanggal=<?php echo urlencode($prev_date); ?>" title="Hari sebelumnya" aria-label="Hari sebelumnya">
                                <i class="ti ti-chevron-left" aria-hidden="true"></i>
                            </a>
                            <label class="ui-lhari-datefield" for="lh_tanggal">
                                <span>Pilih tanggal</span>
                                <input type="date" id="lh_tanggal" name="tanggal" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="this.form.submit()">
                            </label>
                            <a class="ui-lhari-step" href="?tanggal=<?php echo urlencode($next_date); ?>" title="Hari berikutnya" aria-label="Hari berikutnya">
                                <i class="ti ti-chevron-right" aria-hidden="true"></i>
                            </a>
                        </div>
                        <div class="ui-lhari-datepanel-actions">
                            <a href="?tanggal=<?php echo urlencode(date('Y-m-d')); ?>" class="btn btn-sm <?php echo $is_today ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                <i class="ti ti-sun me-1" aria-hidden="true"></i>Hari ini
                            </a>
                            <?php if (!$is_today): ?>
                            <a href="laporan_harian.php" class="btn btn-sm btn-outline-secondary">
                                <i class="ti ti-refresh me-1" aria-hidden="true"></i>Reset
                            </a>
                            <?php endif; ?>
                            <noscript>
                                <button type="submit" class="btn btn-sm btn-primary">Tampilkan</button>
                            </noscript>
                        </div>
                    </form>
                </section>

                <div class="ui-lhari-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Transaksi simpanan', 'value' => (int) $summary['total_transaksi'], 'tone' => 'primary', 'icon' => 'ti ti-arrows-exchange', 'hint' => 'Setoran ' . (int) $summary['jumlah_setoran'] . ' · Penarikan ' . (int) $summary['jumlah_penarikan']],
                    ['label' => 'Total setoran', 'value' => formatRupiah($summary['total_setoran']), 'tone' => 'success', 'icon' => 'ti ti-arrow-down-left', 'hint' => (int) $summary['jumlah_setoran'] . ' transaksi'],
                    ['label' => 'Total penarikan', 'value' => formatRupiah($summary['total_penarikan']), 'tone' => 'danger', 'icon' => 'ti ti-arrow-up-right', 'hint' => (int) $summary['jumlah_penarikan'] . ' transaksi'],
                    ['label' => 'Pembayaran angsuran', 'value' => formatRupiah($angsuran_summary['total_pembayaran_angsuran']), 'tone' => 'warning', 'icon' => 'ti ti-credit-card', 'hint' => (int) $angsuran_summary['total_angsuran'] . ' pembayaran'],
                ]); ?>
                </div>

                <div class="ui-lhari-kas mb-3">
                    <article class="ui-lhari-kas-card is-in">
                        <span class="ui-lhari-kas-ico" aria-hidden="true"><i class="ti ti-arrow-down-left"></i></span>
                        <div>
                            <span>Penerimaan kas</span>
                            <strong><?php echo formatRupiah($penerimaan); ?></strong>
                            <small>Setoran + angsuran + lainnya</small>
                        </div>
                    </article>
                    <article class="ui-lhari-kas-card is-out">
                        <span class="ui-lhari-kas-ico" aria-hidden="true"><i class="ti ti-arrow-up-right"></i></span>
                        <div>
                            <span>Pengeluaran kas</span>
                            <strong><?php echo formatRupiah($pengeluaran); ?></strong>
                            <small>Penarikan + lainnya</small>
                        </div>
                    </article>
                    <article class="ui-lhari-kas-card <?php echo $selisih_kas >= 0 ? 'is-net-pos' : 'is-net-neg'; ?>">
                        <span class="ui-lhari-kas-ico" aria-hidden="true"><i class="ti ti-scale"></i></span>
                        <div>
                            <span>Selisih harian</span>
                            <strong><?php echo formatRupiah($selisih_kas); ?></strong>
                            <small>Penerimaan − pengeluaran</small>
                        </div>
                    </article>
                </div>

                <div class="card ui-lhari-daftar mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-pig-money" aria-hidden="true"></i>Transaksi simpanan</span>
                        <span class="ui-dash-sec-note"><?php echo (int) count($transactions); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="0,desc"
                                   data-dt-filter="3"
                                   data-empty-message="Tidak ada transaksi simpanan pada tanggal ini.">
                                <thead>
                                    <tr>
                                        <th>Waktu</th>
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
                                            <strong><?php echo htmlspecialchars(date('H:i', strtotime($t['tanggal_transaksi']))); ?></strong>
                                        </td>
                                        <td>
                                            <strong class="ui-lhari-name"><?php echo htmlspecialchars($t['nama_lengkap']); ?></strong>
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
                                        <td class="text-end fw-semibold ui-lhari-money <?php echo $isSetor ? 'is-in' : 'is-out'; ?>">
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

                <div class="card ui-lhari-daftar mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-credit-card" aria-hidden="true"></i>Pembayaran angsuran</span>
                        <span class="ui-dash-sec-note"><?php echo (int) count($angsuran); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="0,desc"
                                   data-empty-message="Tidak ada pembayaran angsuran pada tanggal ini.">
                                <thead>
                                    <tr>
                                        <th>Waktu</th>
                                        <th>Anggota</th>
                                        <th>Pinjaman</th>
                                        <th class="text-center">Ke</th>
                                        <th>Jatuh tempo</th>
                                        <th class="text-end">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($angsuran as $a):
                                    $waktu = $a['waktu_bayar'] ?: ($a['tanggal_bayar'] . ' 00:00:00');
                                    $totalBayar = (float) $a['jumlah_angsuran'] + (float) ($a['denda'] ?? 0);
                                    ?>
                                    <tr>
                                        <td data-order="<?php echo htmlspecialchars((string) $waktu); ?>">
                                            <strong><?php echo $a['waktu_bayar'] ? htmlspecialchars(date('H:i', strtotime($a['waktu_bayar']))) : '—'; ?></strong>
                                        </td>
                                        <td>
                                            <strong class="ui-lhari-name"><?php echo htmlspecialchars($a['nama_lengkap']); ?></strong>
                                            <span class="ui-lhari-meta"><?php echo htmlspecialchars($a['kode_anggota']); ?></span>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($a['kode_pinjaman']); ?></code>
                                            <span class="ui-lhari-meta"><?php echo htmlspecialchars($a['jenis_pinjaman'] ?? ''); ?></span>
                                        </td>
                                        <td class="text-center"><span class="badge bg-azure-lt text-azure"><?php echo (int) $a['angsuran_ke']; ?></span></td>
                                        <td><?php echo htmlspecialchars(formatTanggalUi($a['tanggal_jatuh_tempo'])); ?></td>
                                        <td class="text-end">
                                            <strong class="ui-lhari-money is-in"><?php echo formatRupiah($totalBayar); ?></strong>
                                            <?php if ((float) ($a['denda'] ?? 0) > 0): ?>
                                            <span class="ui-lhari-meta text-danger">incl. denda <?php echo formatRupiah($a['denda']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card ui-lhari-daftar mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-wallet" aria-hidden="true"></i>Transaksi kas</span>
                        <span class="ui-dash-sec-note"><?php echo (int) count($kas_transactions); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="0,asc"
                                   data-dt-filter="1"
                                   data-empty-message="Tidak ada transaksi kas pada tanggal ini.">
                                <thead>
                                    <tr>
                                        <th>Waktu</th>
                                        <th>Arah</th>
                                        <th>Keterangan</th>
                                        <th>Kode</th>
                                        <th class="text-end">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($kas_transactions as $kas):
                                    $isIn = ($kas['jenis'] ?? '') === 'masuk';
                                    ?>
                                    <tr>
                                        <td data-order="<?php echo htmlspecialchars((string) $kas['tanggal']); ?>">
                                            <strong><?php echo htmlspecialchars(date('H:i', strtotime($kas['tanggal']))); ?></strong>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($kas['jenis']); ?>">
                                            <span class="ui-lhari-arah <?php echo $isIn ? 'is-in' : 'is-out'; ?>">
                                                <?php echo $isIn ? 'Masuk' : 'Keluar'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="ui-lhari-ket"><?php echo htmlspecialchars($kas['keterangan'] ?: '—'); ?></span>
                                            <?php if (!empty($kas['anggota_nama'])): ?>
                                            <span class="ui-lhari-meta"><?php echo htmlspecialchars($kas['anggota_nama']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo !empty($kas['kode_transaksi']) ? '<code>' . htmlspecialchars($kas['kode_transaksi']) . '</code>' : '—'; ?></td>
                                        <td class="text-end fw-semibold ui-lhari-money <?php echo $isIn ? 'is-in' : 'is-out'; ?>">
                                            <?php echo ($isIn ? '+' : '-') . formatRupiah($kas['jumlah']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card ui-lhari-quick-card no-print mb-2">
                    <div class="card-body ui-lhari-quick">
                        <div class="ui-lhari-quick-label">
                            <i class="ti ti-bolt" aria-hidden="true"></i>
                            <div>
                                <strong>Aksi cepat</strong>
                                <span>Lanjut transaksi tanpa keluar halaman laporan</span>
                            </div>
                        </div>
                        <div class="ui-lhari-quick-btns">
                            <a href="setoran.php" class="btn btn-success btn-sm"><i class="ti ti-plus me-1" aria-hidden="true"></i>Setoran</a>
                            <a href="penarikan.php" class="btn btn-warning btn-sm text-white"><i class="ti ti-minus me-1" aria-hidden="true"></i>Penarikan</a>
                            <a href="angsuran.php" class="btn btn-primary btn-sm"><i class="ti ti-credit-card me-1" aria-hidden="true"></i>Bayar angsuran</a>
                            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="ti ti-dashboard me-1" aria-hidden="true"></i>Dashboard</a>
                        </div>
                    </div>
                </div>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
</body>
</html>

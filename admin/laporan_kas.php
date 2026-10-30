<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$filter_jenis = $_GET['jenis'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';

if (!empty($start_date) && !empty($end_date) && strtotime($start_date) > strtotime($end_date)) {
    $_SESSION['error'] = "Tanggal awal tidak boleh lebih besar dari tanggal akhir";
    $start_date = '';
    $end_date = '';
}

$query_anggota = "SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

$query_kas = "SELECT 
    'kas' as sumber,
    k.id as sumber_id,
    k.tanggal,
    k.jenis,
    k.jumlah,
    k.saldo_sebelum,
    k.saldo_sesudah,
    k.keterangan as keterangan_asli,
    t.id as transaksi_id,
    t.kode_transaksi,
    t.jenis_transaksi,
    t.anggota_id,
    a.nama_lengkap as nama_anggota,
    a.kode_anggota,
    js.nama_jenis as nama_simpanan,
    js.kode_jenis,
    u.nama_lengkap as nama_user,
    NULL as pinjaman_id,
    NULL as kode_pinjaman,
    NULL as jenis_pinjaman,
    NULL as pengajuan_id,
    CASE 
        WHEN k.keterangan LIKE '%Waserda%' THEN CONCAT('Waserda - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(k.keterangan, ''), ')')
        WHEN t.keterangan LIKE '%angsuran%' THEN CONCAT('Pembayaran Angsuran - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(t.keterangan, ''), ')')
        WHEN t.jenis_transaksi = 'setoran' THEN CONCAT('Setoran - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(js.nama_jenis, 'Simpanan'), ')')
        WHEN t.jenis_transaksi = 'penarikan' THEN CONCAT('Penarikan - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(js.nama_jenis, 'Simpanan'), ')')
        ELSE COALESCE(k.keterangan, 'Transaksi Kas')
    END as keterangan_lengkap,
    CASE 
        WHEN k.keterangan LIKE '%Waserda%' THEN 'waserda'
        WHEN t.keterangan LIKE '%angsuran%' THEN 'angsuran'
        WHEN t.jenis_transaksi = 'setoran' THEN 'setoran'
        WHEN t.jenis_transaksi = 'penarikan' THEN 'penarikan'
        ELSE 'lainnya'
    END as tipe_transaksi
FROM kas k 
LEFT JOIN transaksi t ON k.transaksi_id = t.id 
LEFT JOIN anggota a ON t.anggota_id = a.id
LEFT JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id
LEFT JOIN users u ON t.user_id = u.id
WHERE 1=1";

$query_pinjaman = "SELECT 
    'pinjaman' as sumber,
    p.id as sumber_id,
    CONCAT(p.tanggal_pencairan, ' ', '23:59:59') as tanggal,
    'keluar' as jenis,
    p.jumlah_pinjaman as jumlah,
    0 as saldo_sebelum,
    0 as saldo_sesudah,
    CONCAT('Pencairan Pinjaman - ', a.nama_lengkap) as keterangan_asli,
    NULL as transaksi_id,
    p.kode_pinjaman as kode_transaksi,
    NULL as jenis_transaksi,
    p.anggota_id,
    a.nama_lengkap as nama_anggota,
    a.kode_anggota,
    NULL as nama_simpanan,
    NULL as kode_jenis,
    u.nama_lengkap as nama_user,
    p.id as pinjaman_id,
    p.kode_pinjaman,
    jp.nama_jenis as jenis_pinjaman,
    p.pengajuan_id,
    CONCAT('Pencairan Pinjaman - ', a.nama_lengkap, ' (', jp.nama_jenis, ') - ', p.kode_pinjaman) as keterangan_lengkap,
    'pencairan_pinjaman' as tipe_transaksi
FROM pinjaman p
JOIN anggota a ON p.anggota_id = a.id
JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
LEFT JOIN users u ON p.id = u.id
WHERE 1=1";

$combined_query = "($query_kas) UNION ALL ($query_pinjaman)";
$params = [];

if (!empty($start_date) && !empty($end_date)) {
    $combined_query = "SELECT * FROM ($combined_query) as semua WHERE DATE(tanggal) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
}

if (!empty($filter_jenis)) {
    if (!empty($start_date) && !empty($end_date)) {
        $combined_query .= " AND tipe_transaksi = :jenis";
    } else {
        $combined_query = "SELECT * FROM ($combined_query) as semua WHERE tipe_transaksi = :jenis";
    }
    $params[':jenis'] = $filter_jenis;
}

if (!empty($filter_anggota)) {
    if ((!empty($start_date) && !empty($end_date)) || !empty($filter_jenis)) {
        $combined_query .= " AND anggota_id = :anggota_id";
    } else {
        $combined_query = "SELECT * FROM ($combined_query) as semua WHERE anggota_id = :anggota_id";
    }
    $params[':anggota_id'] = $filter_anggota;
}

if (strpos($combined_query, 'ORDER BY') === false) {
    $combined_query .= " ORDER BY tanggal ASC, sumber_id ASC";
}

$stmt = $db->prepare($combined_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$kas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query_saldo_awal = "SELECT saldo_sebelum FROM kas ORDER BY tanggal ASC, id ASC LIMIT 1";
$stmt_saldo_awal = $db->prepare($query_saldo_awal);
$stmt_saldo_awal->execute();
$saldo_awal_data = $stmt_saldo_awal->fetch(PDO::FETCH_ASSOC);
$saldo_awal = $saldo_awal_data ? (float)$saldo_awal_data['saldo_sebelum'] : 0;

$total_masuk = 0;
$total_keluar = 0;
$total_setoran = 0;
$total_penarikan = 0;
$total_angsuran = 0;
$total_pencairan = 0;
$total_waserda = 0;
$count_masuk = 0;
$count_keluar = 0;
$jumlah_kas = 0;
$jumlah_pinjaman = 0;
$jumlah_waserda = 0;
$count_setoran = 0;
$count_penarikan = 0;
$count_angsuran = 0;
$count_pencairan = 0;

foreach ($kas as $k) {
    if (($k['sumber'] ?? '') === 'kas') {
        $jumlah_kas++;
    } elseif (($k['sumber'] ?? '') === 'pinjaman') {
        $jumlah_pinjaman++;
    }
    if (($k['tipe_transaksi'] ?? '') === 'waserda') {
        $jumlah_waserda++;
    }

    if (($k['jenis'] ?? '') === 'masuk') {
        $total_masuk += (float)$k['jumlah'];
        $count_masuk++;
        if (($k['tipe_transaksi'] ?? '') === 'angsuran') {
            $total_angsuran += (float)$k['jumlah'];
            $count_angsuran++;
        } elseif (($k['tipe_transaksi'] ?? '') === 'setoran') {
            $total_setoran += (float)$k['jumlah'];
            $count_setoran++;
        } elseif (($k['tipe_transaksi'] ?? '') === 'waserda') {
            $total_waserda += (float)$k['jumlah'];
        }
    } else {
        $total_keluar += (float)$k['jumlah'];
        $count_keluar++;
        if (($k['tipe_transaksi'] ?? '') === 'penarikan') {
            $total_penarikan += (float)$k['jumlah'];
            $count_penarikan++;
        } elseif (($k['tipe_transaksi'] ?? '') === 'pencairan_pinjaman') {
            $total_pencairan += (float)$k['jumlah'];
            $count_pencairan++;
        }
    }
}

$saldo_akhir = $saldo_awal + $total_masuk - $total_keluar;

$chart_data = [];
$chart_labels = [];
$saldo_chart = $saldo_awal;
foreach ($kas as $k) {
    $chart_labels[] = formatTanggalUi($k['tanggal'], true);
    if (($k['jenis'] ?? '') === 'masuk') {
        $saldo_chart += (float)$k['jumlah'];
    } else {
        $saldo_chart -= (float)$k['jumlah'];
    }
    $chart_data[] = $saldo_chart;
}

$jenis_filter = [
    '' => 'Semua transaksi',
    'setoran' => 'Setoran simpanan',
    'penarikan' => 'Penarikan simpanan',
    'angsuran' => 'Pembayaran angsuran',
    'pencairan_pinjaman' => 'Pencairan pinjaman',
    'waserda' => 'Waserda',
];

$tipe_labels = [
    'setoran' => 'Setoran',
    'penarikan' => 'Penarikan',
    'angsuran' => 'Angsuran',
    'pencairan_pinjaman' => 'Pencairan',
    'waserda' => 'Waserda',
    'lainnya' => 'Lainnya',
];

$min_date = '';
$max_date = '';
if (!empty($kas)) {
    $min_date = date('Y-m-d', strtotime((string)$kas[0]['tanggal']));
    $max_date = date('Y-m-d', strtotime((string)$kas[count($kas) - 1]['tanggal']));
}

$exportQs = http_build_query([
    'start_date' => $start_date,
    'end_date' => $end_date,
    'jenis' => $filter_jenis,
    'anggota_id' => $filter_anggota,
]);

$periodeLabel = (empty($start_date) && empty($end_date))
    ? 'Semua periode'
    : (formatTanggalUi($start_date) . ' – ' . formatTanggalUi($end_date));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Kas - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <script src="../assets/vendor/chartjs/chart.umd.min.js"></script>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-lkas" aria-hidden="true"><i class="ti ti-wallet"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Laporan</p>
                        <h1 class="ui-dash-title">Laporan Kas</h1>
                        <p class="ui-dash-meta mb-0">Arus kas, pencairan pinjaman, dan waserda</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="export_kas.php?<?php echo htmlspecialchars($exportQs); ?>" class="btn btn-outline-success btn-sm">
                            <i class="ti ti-file-spreadsheet me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Excel</span>
                        </a>
                        <a href="cetak_laporan_kas.php?<?php echo htmlspecialchars($exportQs); ?>" class="btn btn-outline-danger btn-sm" target="_blank" rel="noopener">
                            <i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">PDF</span>
                        </a>
                        <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
                            <i class="ti ti-printer me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Cetak</span>
                        </button>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-lkas-periode" role="status">
                    <i class="ti ti-calendar-event" aria-hidden="true"></i>
                    <div>
                        <strong><?php echo htmlspecialchars($periodeLabel); ?></strong>
                        <span>
                            <?php echo (int)count($kas); ?> transaksi · Kas <?php echo (int)$jumlah_kas; ?> · Pinjaman <?php echo (int)$jumlah_pinjaman; ?> · Waserda <?php echo (int)$jumlah_waserda; ?>
                            <?php if ($min_date && $max_date): ?>
                             · Data <?php echo htmlspecialchars(formatTanggalUi($min_date)); ?> s/d <?php echo htmlspecialchars(formatTanggalUi($max_date)); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="ui-lkas-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Pemasukan', 'value' => formatRupiah($total_masuk), 'tone' => 'success', 'icon' => 'ti ti-arrow-down-left', 'hint' => $count_masuk . ' transaksi'],
                    ['label' => 'Pengeluaran', 'value' => formatRupiah($total_keluar), 'tone' => 'danger', 'icon' => 'ti ti-arrow-up-right', 'hint' => $count_keluar . ' transaksi'],
                    ['label' => 'Saldo akhir', 'value' => formatRupiah($saldo_akhir), 'tone' => 'primary', 'icon' => 'ti ti-coins', 'hint' => 'Saldo awal ' . formatRupiah($saldo_awal)],
                    ['label' => 'Total arus', 'value' => formatRupiah($total_masuk + $total_keluar), 'tone' => 'info', 'icon' => 'ti ti-arrows-exchange', 'hint' => count($kas) . ' baris'],
                ]); ?>
                </div>

                <div class="ui-lkas-breakdown mb-3">
                    <div class="ui-lkas-break-card is-setoran">
                        <span>Setoran</span>
                        <strong><?php echo formatRupiah($total_setoran); ?></strong>
                        <small><?php echo (int)$count_setoran; ?>×</small>
                    </div>
                    <div class="ui-lkas-break-card is-penarikan">
                        <span>Penarikan</span>
                        <strong><?php echo formatRupiah($total_penarikan); ?></strong>
                        <small><?php echo (int)$count_penarikan; ?>×</small>
                    </div>
                    <div class="ui-lkas-break-card is-angsuran">
                        <span>Angsuran</span>
                        <strong><?php echo formatRupiah($total_angsuran); ?></strong>
                        <small><?php echo (int)$count_angsuran; ?>×</small>
                    </div>
                    <div class="ui-lkas-break-card is-waserda">
                        <span>Waserda</span>
                        <strong><?php echo formatRupiah($total_waserda); ?></strong>
                        <small><?php echo (int)$jumlah_waserda; ?>×</small>
                    </div>
                    <div class="ui-lkas-break-card is-cair">
                        <span>Pencairan</span>
                        <strong><?php echo formatRupiah($total_pencairan); ?></strong>
                        <small><?php echo (int)$count_pencairan; ?>×</small>
                    </div>
                </div>

                <div class="card ui-lkas-filter mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-filter" aria-hidden="true"></i>Filter laporan</span>
                        <span class="ui-dash-sec-note">Periode, jenis &amp; anggota</span>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="ui-lkas-filter-form">
                            <div class="ui-lkas-filter-grid">
                                <div class="ui-lkas-field">
                                    <label class="form-label" for="lk_start">Dari tanggal</label>
                                    <input type="date" id="lk_start" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                                </div>
                                <div class="ui-lkas-field">
                                    <label class="form-label" for="lk_end">Sampai tanggal</label>
                                    <input type="date" id="lk_end" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                                </div>
                                <div class="ui-lkas-field">
                                    <label class="form-label" for="lk_jenis">Jenis transaksi</label>
                                    <select id="lk_jenis" name="jenis" class="form-select">
                                        <?php foreach ($jenis_filter as $key => $value): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $filter_jenis === $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($value); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ui-lkas-field">
                                    <label class="form-label" for="lk_anggota">Anggota</label>
                                    <select id="lk_anggota" name="anggota_id" class="form-select">
                                        <option value="">Semua anggota</option>
                                        <?php foreach ($anggota_list as $a): ?>
                                        <option value="<?php echo (int)$a['id']; ?>" <?php echo (string)$filter_anggota === (string)$a['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($a['kode_anggota'] . ' — ' . $a['nama_lengkap']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="ui-lkas-filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="ti ti-search me-1" aria-hidden="true"></i>Terapkan
                                </button>
                                <a href="laporan_kas.php" class="btn btn-outline-secondary">
                                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>Tampilkan semua
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!empty($chart_data) && count($chart_data) > 1): ?>
                <div class="card ui-lkas-chart mb-3 no-print">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-chart-line" aria-hidden="true"></i>Grafik perkembangan saldo</span>
                        <span class="ui-dash-sec-note">Termasuk pencairan &amp; waserda</span>
                    </div>
                    <div class="card-body">
                        <div class="ui-lkas-chart-wrap">
                            <canvas id="saldoChart" aria-label="Grafik saldo kas"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card ui-lkas-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Detail transaksi kas</span>
                        <span class="ui-dash-sec-note"><?php echo (int)count($kas); ?> data · urutan kronologis</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="25"
                                   data-dt-order="0,asc"
                                   data-dt-filter="3,5"
                                   data-empty-message="Tidak ada transaksi kas untuk filter ini.">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Keterangan</th>
                                        <th>Kode</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Jumlah</th>
                                        <th>Sumber</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($kas as $k):
                                    $ts = strtotime((string)$k['tanggal']);
                                    $orderTs = $ts ? date('Y-m-d H:i:s', $ts) : '';
                                    $tipe = (string)($k['tipe_transaksi'] ?? 'lainnya');
                                    $tipeLabel = $tipe_labels[$tipe] ?? ucfirst(str_replace('_', ' ', $tipe));
                                    $isMasuk = ($k['jenis'] ?? '') === 'masuk';
                                    $sumber = (string)($k['sumber'] ?? 'kas');
                                    $sumberLabel = $sumber === 'pinjaman' ? 'Pinjaman' : 'Kas';
                                    $rowTone = '';
                                    if ($tipe === 'pencairan_pinjaman') {
                                        $rowTone = 'is-cair';
                                    } elseif ($tipe === 'waserda') {
                                        $rowTone = 'is-waserda';
                                    }
                                    $tipeTone = 'is-lainnya';
                                    if ($tipe === 'setoran') {
                                        $tipeTone = 'is-setoran';
                                    } elseif ($tipe === 'penarikan') {
                                        $tipeTone = 'is-penarikan';
                                    } elseif ($tipe === 'angsuran') {
                                        $tipeTone = 'is-angsuran';
                                    } elseif ($tipe === 'waserda') {
                                        $tipeTone = 'is-waserda';
                                    } elseif ($tipe === 'pencairan_pinjaman') {
                                        $tipeTone = 'is-cair';
                                    }
                                ?>
                                    <tr class="ui-lkas-row <?php echo $rowTone; ?>">
                                        <td data-order="<?php echo htmlspecialchars($orderTs); ?>">
                                            <?php echo htmlspecialchars(formatTanggalUi($k['tanggal'], true)); ?>
                                        </td>
                                        <td>
                                            <strong class="ui-lkas-ket"><?php echo htmlspecialchars((string)$k['keterangan_lengkap']); ?></strong>
                                            <?php if (!empty($k['nama_anggota'])): ?>
                                            <small class="ui-lkas-meta">
                                                <?php echo htmlspecialchars((string)$k['nama_anggota']); ?>
                                                <?php if (!empty($k['kode_anggota'])): ?>
                                                 · <?php echo htmlspecialchars((string)$k['kode_anggota']); ?>
                                                <?php endif; ?>
                                            </small>
                                            <?php endif; ?>
                                            <?php if (!empty($k['kode_pinjaman']) && $tipe === 'pencairan_pinjaman'): ?>
                                            <small class="ui-lkas-meta">Pinjaman <?php echo htmlspecialchars((string)$k['kode_pinjaman']); ?><?php if (!empty($k['jenis_pinjaman'])): ?> · <?php echo htmlspecialchars((string)$k['jenis_pinjaman']); ?><?php endif; ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($k['nama_user'])): ?>
                                            <small class="ui-lkas-meta">Teller: <?php echo htmlspecialchars((string)$k['nama_user']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($k['kode_transaksi'])): ?>
                                            <code class="ui-lkas-kode"><?php echo htmlspecialchars((string)$k['kode_transaksi']); ?></code>
                                            <?php else: ?>
                                            <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($tipeLabel); ?>">
                                            <span class="ui-lkas-arah <?php echo $isMasuk ? 'is-in' : 'is-out'; ?>">
                                                <?php echo $isMasuk ? 'Masuk' : 'Keluar'; ?>
                                            </span>
                                            <span class="ui-lkas-tipe <?php echo $tipeTone; ?>"><?php echo htmlspecialchars($tipeLabel); ?></span>
                                        </td>
                                        <td class="text-end fw-semibold ui-lkas-money <?php echo $isMasuk ? 'is-in' : 'is-out'; ?>">
                                            <?php echo $isMasuk ? '+' : '−'; ?> <?php echo formatRupiah($k['jumlah']); ?>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($sumberLabel); ?>">
                                            <span class="ui-lkas-sumber is-<?php echo htmlspecialchars($sumber); ?>"><?php echo htmlspecialchars($sumberLabel); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="ui-lkas-totals">
                            <div><span>Pemasukan</span><strong class="text-success"><?php echo formatRupiah($total_masuk); ?></strong></div>
                            <div><span>Pengeluaran</span><strong class="text-danger"><?php echo formatRupiah($total_keluar); ?></strong></div>
                            <div><span>Saldo awal</span><strong><?php echo formatRupiah($saldo_awal); ?></strong></div>
                            <div><span>Saldo akhir</span><strong class="text-primary"><?php echo formatRupiah($saldo_akhir); ?></strong></div>
                        </div>
                        <div class="ui-lkas-legend no-print">
                            <span class="ui-lkas-leg is-cair">Pencairan</span>
                            <span class="ui-lkas-leg is-waserda">Waserda</span>
                            <span class="ui-lkas-leg is-in">Masuk</span>
                            <span class="ui-lkas-leg is-out">Keluar</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>

    <?php if (!empty($chart_data) && count($chart_data) > 1): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('saldoChart');
            if (!el || typeof Chart === 'undefined') return;
            var ctx = el.getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        label: 'Saldo Kas',
                        data: <?php echo json_encode($chart_data); ?>,
                        borderColor: '#0f766e',
                        backgroundColor: 'rgba(15, 118, 110, 0.12)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointBackgroundColor: function (context) {
                            var value = context.dataset.data[context.dataIndex];
                            return value >= 0 ? '#15803d' : '#b91c1c';
                        },
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return 'Saldo: ' + formatRupiahChart(context.raw);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            ticks: {
                                callback: function (value) {
                                    return formatRupiahChart(value);
                                }
                            },
                            grid: { color: 'rgba(15, 23, 42, 0.06)' }
                        },
                        x: { grid: { display: false } }
                    }
                }
            });
        });

        function formatRupiahChart(angka) {
            return 'Rp ' + Number(angka).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
    </script>
    <?php endif; ?>
</body>
</html>

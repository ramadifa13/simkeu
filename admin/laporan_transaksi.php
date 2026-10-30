<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_jenis = $_GET['jenis_transaksi'] ?? '';
$filter_sumber = $_GET['sumber'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';

$query_anggota = "SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

$tabungan = $db->query("SELECT 
    t.*, 
    a.nama_lengkap, 
    a.kode_anggota, 
    js.nama_jenis as jenis_tabungan,
    js.kode_jenis
FROM transaksi t 
LEFT JOIN anggota a ON t.anggota_id = a.id 
LEFT JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id
ORDER BY t.tanggal_transaksi DESC")->fetchAll(PDO::FETCH_ASSOC);

$pinjaman = $db->query("SELECT 
    p.*, 
    a.nama_lengkap as nama_anggota, 
    a.kode_anggota,
    jp.nama_jenis as jenis_pinjaman
FROM pinjaman p 
LEFT JOIN anggota a ON p.anggota_id = a.id 
LEFT JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
ORDER BY p.tanggal_pencairan DESC")->fetchAll(PDO::FETCH_ASSOC);

$waserda = $db->query("SELECT 
    w.*, 
    a.nama_lengkap, 
    a.kode_anggota,
    u.nama_lengkap as petugas
FROM waserda_transaksi w 
LEFT JOIN anggota a ON w.anggota_id = a.id
LEFT JOIN users u ON w.user_id = u.id
ORDER BY w.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$kas_lain = $db->query("SELECT 
    k.*,
    'KAS_LAIN' as sumber,
    'Kas Lain' as sumber_label
FROM kas k 
LEFT JOIN transaksi t ON k.transaksi_id = t.id
LEFT JOIN waserda_transaksi w ON k.transaksi_id = w.id
WHERE t.id IS NULL AND w.id IS NULL
ORDER BY k.tanggal DESC")->fetchAll(PDO::FETCH_ASSOC);

$all_transaksi = [];

foreach ($tabungan as $t) {
    $tipe = ($t['jenis_transaksi'] == 'setoran') ? 'setoran' : 'penarikan';
    $jenis_display = $t['jenis_tabungan'] ?? 'Tabungan';
    $tgl = date('Y-m-d', strtotime($t['tanggal_transaksi']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = [
            'tanggal' => $t['tanggal_transaksi'],
            'sumber' => 'TABUNGAN',
            'sumber_label' => 'Tabungan',
            'sumber_detail' => $jenis_display,
            'kode_transaksi' => $t['kode_transaksi'],
            'anggota_id' => $t['anggota_id'],
            'kode_anggota' => $t['kode_anggota'] ?? '-',
            'nama_anggota' => $t['nama_lengkap'] ?? '-',
            'jenis_kode' => $t['kode_jenis'] ?? 'TAB',
            'tipe' => $tipe,
            'tipe_label' => strtoupper($tipe),
            'jenis' => ($tipe == 'setoran') ? 'masuk' : 'keluar',
            'jumlah' => $t['jumlah'],
            'keterangan' => $jenis_display . ' - ' . ucfirst($tipe),
            'petugas' => 'Administrator'
        ];
    }
}

foreach ($pinjaman as $p) {
    $tgl = date('Y-m-d', strtotime($p['tanggal_pencairan']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = [
            'tanggal' => $p['tanggal_pencairan'] . ' 23:59:59',
            'sumber' => 'PINJAMAN',
            'sumber_label' => 'Pinjaman',
            'sumber_detail' => $p['jenis_pinjaman'] ?? 'Pinjaman',
            'kode_transaksi' => $p['kode_pinjaman'],
            'anggota_id' => $p['anggota_id'],
            'kode_anggota' => $p['kode_anggota'] ?? '-',
            'nama_anggota' => $p['nama_anggota'] ?? '-',
            'jenis_kode' => 'PJN',
            'tipe' => 'pencairan',
            'tipe_label' => 'PENCAIRAN',
            'jenis' => 'keluar',
            'jumlah' => $p['jumlah_pinjaman'],
            'keterangan' => 'Pencairan Pinjaman',
            'petugas' => 'Administrator'
        ];
    }
}

foreach ($waserda as $w) {
    $items = json_decode($w['items'], true);
    $jml_item = is_array($items) ? count($items) : 1;
    $nama_barang = is_array($items) ? ($items[0]['nama'] ?? 'barang') : 'barang';
    $tgl = date('Y-m-d', strtotime($w['created_at']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = [
            'tanggal' => $w['created_at'],
            'sumber' => 'WASERDA',
            'sumber_label' => 'Waserda',
            'sumber_detail' => $jml_item . ' item',
            'kode_transaksi' => $w['kode_transaksi'],
            'anggota_id' => $w['anggota_id'],
            'kode_anggota' => $w['kode_anggota'] ?? '-',
            'nama_anggota' => $w['nama_lengkap'] ?? '-',
            'jenis_kode' => 'WSR',
            'tipe' => 'waserda',
            'tipe_label' => 'WASERDA',
            'jenis' => 'masuk',
            'jumlah' => $w['total_bayar'],
            'keterangan' => 'Waserda - ' . $nama_barang . ' (' . $jml_item . ' item) - ' . ($w['metode_bayar'] ?? 'Tunai'),
            'petugas' => $w['petugas'] ?? 'Administrator'
        ];
    }
}

foreach ($kas_lain as $k) {
    $tgl = date('Y-m-d', strtotime($k['tanggal']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = [
            'tanggal' => $k['tanggal'],
            'sumber' => 'KAS_LAIN',
            'sumber_label' => 'Kas Lain',
            'sumber_detail' => '-',
            'kode_transaksi' => 'KAS-' . $k['id'],
            'anggota_id' => null,
            'kode_anggota' => '-',
            'nama_anggota' => '-',
            'jenis_kode' => 'KAS',
            'tipe' => 'kas_lainnya',
            'tipe_label' => 'KAS LAIN',
            'jenis' => $k['jenis'],
            'jumlah' => $k['jumlah'],
            'keterangan' => $k['keterangan'],
            'petugas' => 'Administrator'
        ];
    }
}

usort($all_transaksi, static function ($a, $b) {
    return strtotime($b['tanggal']) - strtotime($a['tanggal']);
});

$filtered_transaksi = [];
foreach ($all_transaksi as $t) {
    if (!empty($filter_sumber) && $t['sumber'] != $filter_sumber) {
        continue;
    }
    if (!empty($filter_jenis) && strtolower($t['tipe']) != strtolower($filter_jenis)) {
        continue;
    }
    if (!empty($filter_anggota) && (string)$t['anggota_id'] !== (string)$filter_anggota) {
        continue;
    }
    $filtered_transaksi[] = $t;
}

$total_setoran = 0;
$total_penarikan = 0;
$total_pencairan = 0;
$total_waserda = 0;
$total_kas_lain = 0;
$jumlah_setoran = 0;
$jumlah_penarikan = 0;
$jumlah_pencairan = 0;
$jumlah_waserda = 0;

foreach ($all_transaksi as $t) {
    if ($t['sumber'] == 'TABUNGAN') {
        if ($t['tipe'] == 'setoran') {
            $total_setoran += $t['jumlah'];
            $jumlah_setoran++;
        } elseif ($t['tipe'] == 'penarikan') {
            $total_penarikan += $t['jumlah'];
            $jumlah_penarikan++;
        }
    } elseif ($t['sumber'] == 'PINJAMAN') {
        $total_pencairan += $t['jumlah'];
        $jumlah_pencairan++;
    } elseif ($t['sumber'] == 'WASERDA') {
        $total_waserda += $t['jumlah'];
        $jumlah_waserda++;
    } else {
        $total_kas_lain += $t['jumlah'];
    }
}

$total_pemasukan = $total_setoran + $total_waserda;
$total_pengeluaran = $total_penarikan + $total_pencairan;
$selisih = $total_pemasukan - $total_pengeluaran;

$saldo_akhir = 0;
$query_saldo = "SELECT saldo_sesudah FROM kas ORDER BY id DESC LIMIT 1";
$stmt_saldo = $db->prepare($query_saldo);
$stmt_saldo->execute();
$saldo_data = $stmt_saldo->fetch(PDO::FETCH_ASSOC);
$saldo_akhir = $saldo_data ? $saldo_data['saldo_sesudah'] : 0;

$sumber_list = [
    '' => 'Semua sumber',
    'TABUNGAN' => 'Tabungan',
    'PINJAMAN' => 'Pinjaman',
    'WASERDA' => 'Waserda',
    'KAS_LAIN' => 'Kas Lain'
];

$jenis_list = [
    '' => 'Semua jenis',
    'setoran' => 'Setoran tabungan',
    'penarikan' => 'Penarikan tabungan',
    'pencairan' => 'Pencairan pinjaman',
    'waserda' => 'Waserda'
];

$exportQs = http_build_query([
    'start_date' => $start_date,
    'end_date' => $end_date,
    'jenis_transaksi' => $filter_jenis,
    'sumber' => $filter_sumber,
    'anggota_id' => $filter_anggota,
]);

$page_title = 'Laporan Transaksi';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Semua Transaksi - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-ltrx" aria-hidden="true"><i class="ti ti-arrows-exchange"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Laporan</p>
                        <h1 class="ui-dash-title">Laporan Semua Transaksi</h1>
                        <p class="ui-dash-meta mb-0">Gabungan tabungan, pinjaman, waserda, dan kas lain</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="export_excel_transaksi.php?<?php echo htmlspecialchars($exportQs); ?>" class="btn btn-outline-success btn-sm">
                            <i class="ti ti-file-spreadsheet me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Excel</span>
                        </a>
                        <a href="cetak_pdf_transaksi.php?<?php echo htmlspecialchars($exportQs); ?>" class="btn btn-outline-danger btn-sm" target="_blank" rel="noopener">
                            <i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">PDF</span>
                        </a>
                        <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
                            <i class="ti ti-printer me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Cetak</span>
                        </button>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-ltrx-periode" role="status">
                    <i class="ti ti-calendar-event" aria-hidden="true"></i>
                    <div>
                        <strong>Periode <?php echo htmlspecialchars(formatTanggalUi($start_date)); ?> – <?php echo htmlspecialchars(formatTanggalUi($end_date)); ?></strong>
                        <span><?php echo (int)count($all_transaksi); ?> transaksi · Tabungan <?php echo (int)count($tabungan); ?> · Pinjaman <?php echo (int)count($pinjaman); ?> · Waserda <?php echo (int)count($waserda); ?></span>
                    </div>
                </div>

                <div class="ui-ltrx-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total transaksi', 'value' => count($all_transaksi), 'tone' => 'primary', 'icon' => 'ti ti-arrows-exchange', 'hint' => 'Periode filter'],
                    ['label' => 'Pemasukan', 'value' => formatRupiah($total_pemasukan), 'tone' => 'success', 'icon' => 'ti ti-arrow-down-left', 'hint' => 'Setoran + waserda'],
                    ['label' => 'Pengeluaran', 'value' => formatRupiah($total_pengeluaran), 'tone' => 'danger', 'icon' => 'ti ti-arrow-up-right', 'hint' => 'Penarikan + pencairan'],
                    ['label' => 'Selisih', 'value' => formatRupiah($selisih), 'tone' => ($selisih >= 0 ? 'success' : 'danger'), 'icon' => 'ti ti-scale', 'hint' => 'Saldo kas ' . formatRupiah($saldo_akhir)],
                ]); ?>
                </div>

                <div class="ui-ltrx-breakdown mb-3">
                    <div class="ui-ltrx-break-card is-in">
                        <span>Setoran</span>
                        <strong><?php echo formatRupiah($total_setoran); ?></strong>
                        <small><?php echo (int)$jumlah_setoran; ?>×</small>
                    </div>
                    <div class="ui-ltrx-break-card is-out">
                        <span>Penarikan</span>
                        <strong><?php echo formatRupiah($total_penarikan); ?></strong>
                        <small><?php echo (int)$jumlah_penarikan; ?>×</small>
                    </div>
                    <div class="ui-ltrx-break-card is-waserda">
                        <span>Waserda</span>
                        <strong><?php echo formatRupiah($total_waserda); ?></strong>
                        <small><?php echo (int)$jumlah_waserda; ?>×</small>
                    </div>
                    <div class="ui-ltrx-break-card is-cair">
                        <span>Pencairan</span>
                        <strong><?php echo formatRupiah($total_pencairan); ?></strong>
                        <small><?php echo (int)$jumlah_pencairan; ?>×</small>
                    </div>
                </div>

                <div class="card ui-ltrx-filter mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-filter" aria-hidden="true"></i>Filter laporan</span>
                        <span class="ui-dash-sec-note">Periode &amp; sumber</span>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="ui-ltrx-filter-form">
                            <div class="ui-ltrx-filter-grid">
                                <div class="ui-ltrx-field">
                                    <label class="form-label" for="lt_start">Dari tanggal</label>
                                    <input type="date" id="lt_start" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" required>
                                </div>
                                <div class="ui-ltrx-field">
                                    <label class="form-label" for="lt_end">Sampai tanggal</label>
                                    <input type="date" id="lt_end" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" required>
                                </div>
                                <div class="ui-ltrx-field">
                                    <label class="form-label" for="lt_sumber">Sumber</label>
                                    <select id="lt_sumber" name="sumber" class="form-select">
                                        <?php foreach ($sumber_list as $key => $value): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $filter_sumber === $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($value); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ui-ltrx-field">
                                    <label class="form-label" for="lt_jenis">Jenis</label>
                                    <select id="lt_jenis" name="jenis_transaksi" class="form-select">
                                        <?php foreach ($jenis_list as $key => $value): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $filter_jenis === $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($value); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ui-ltrx-field">
                                    <label class="form-label" for="lt_anggota">Anggota</label>
                                    <select id="lt_anggota" name="anggota_id" class="form-select">
                                        <option value="">Semua anggota</option>
                                        <?php foreach ($anggota_list as $a): ?>
                                        <option value="<?php echo (int)$a['id']; ?>" <?php echo (string)$filter_anggota === (string)$a['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($a['kode_anggota'] . ' — ' . $a['nama_lengkap']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="ui-ltrx-filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="ti ti-search me-1" aria-hidden="true"></i>Terapkan
                                </button>
                                <a href="laporan_transaksi.php" class="btn btn-outline-secondary">
                                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ui-ltrx-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Detail transaksi</span>
                        <span class="ui-dash-sec-note"><?php echo (int)count($filtered_transaksi); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="25"
                                   data-dt-order="0,desc"
                                   data-dt-filter="1,5"
                                   data-empty-message="Tidak ada transaksi untuk filter ini.">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Sumber</th>
                                        <th>Detail</th>
                                        <th>Kode</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th>Keterangan</th>
                                        <th class="text-end">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($filtered_transaksi as $t):
                                    $ts = strtotime((string)$t['tanggal']);
                                    $orderTs = $ts ? date('Y-m-d H:i:s', $ts) : '';
                                    $rowTone = '';
                                    if ($t['sumber'] === 'PINJAMAN') {
                                        $rowTone = 'is-cair';
                                    } elseif ($t['sumber'] === 'WASERDA') {
                                        $rowTone = 'is-waserda';
                                    } elseif ($t['sumber'] === 'TABUNGAN') {
                                        $rowTone = 'is-tabungan';
                                    }
                                    $tipeTone = 'is-setoran';
                                    if ($t['tipe'] === 'penarikan') {
                                        $tipeTone = 'is-penarikan';
                                    } elseif ($t['tipe'] === 'waserda') {
                                        $tipeTone = 'is-waserda';
                                    } elseif ($t['tipe'] === 'pencairan') {
                                        $tipeTone = 'is-cair';
                                    } elseif ($t['tipe'] === 'kas_lainnya') {
                                        $tipeTone = 'is-kas';
                                    }
                                    $isMasuk = ($t['jenis'] ?? '') === 'masuk';
                                ?>
                                    <tr class="ui-ltrx-row <?php echo $rowTone; ?>">
                                        <td data-order="<?php echo htmlspecialchars($orderTs); ?>">
                                            <?php echo htmlspecialchars(formatTanggalUi($t['tanggal'], true)); ?>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($t['sumber_label']); ?>">
                                            <span class="ui-ltrx-sumber is-<?php echo htmlspecialchars(strtolower($t['sumber'])); ?>">
                                                <?php echo htmlspecialchars($t['sumber_label']); ?>
                                            </span>
                                        </td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars((string)($t['sumber_detail'] ?? '—')); ?></small></td>
                                        <td><code class="ui-ltrx-kode"><?php echo htmlspecialchars($t['kode_transaksi']); ?></code></td>
                                        <td>
                                            <?php if (!empty($t['nama_anggota']) && $t['nama_anggota'] !== '-'): ?>
                                            <strong class="ui-ltrx-name"><?php echo htmlspecialchars($t['nama_anggota']); ?></strong>
                                            <small class="ui-ltrx-meta"><?php echo htmlspecialchars($t['kode_anggota']); ?></small>
                                            <?php else: ?>
                                            <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($t['tipe_label']); ?>">
                                            <span class="ui-ltrx-tipe <?php echo $tipeTone; ?>"><?php echo htmlspecialchars($t['tipe_label']); ?></span>
                                        </td>
                                        <td><?php echo ui_cell_ellipsis((string)$t['keterangan'], 40); ?></td>
                                        <td class="text-end fw-semibold ui-ltrx-money <?php echo $isMasuk ? 'is-in' : 'is-out'; ?>">
                                            <?php echo $isMasuk ? '+' : '−'; ?> <?php echo formatRupiah($t['jumlah']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="ui-ltrx-totals">
                            <div><span>Pemasukan periode</span><strong class="text-success"><?php echo formatRupiah($total_pemasukan); ?></strong></div>
                            <div><span>Pengeluaran periode</span><strong class="text-danger"><?php echo formatRupiah($total_pengeluaran); ?></strong></div>
                            <div><span>Selisih</span><strong class="<?php echo $selisih >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatRupiah($selisih); ?></strong></div>
                            <div><span>Saldo kas terakhir</span><strong><?php echo formatRupiah($saldo_akhir); ?></strong></div>
                        </div>
                        <div class="ui-ltrx-legend no-print">
                            <span class="ui-ltrx-leg is-tabungan">Tabungan</span>
                            <span class="ui-ltrx-leg is-cair">Pencairan</span>
                            <span class="ui-ltrx-leg is-waserda">Waserda</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
</body>
</html>

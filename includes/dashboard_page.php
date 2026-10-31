<?php
/**
 * Dashboard staf (Admin & Teller) — satu layout, KPI & aksi sesuai role.
 * Teller: antrian kerja dulu. Admin: ringkasan operasional.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ui/helpers.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

$dashRole = isAdmin() ? 'admin' : (isTeller() ? 'teller' : '');
if ($dashRole === '') {
    http_response_code(403);
    exit('Akses ditolak.');
}

try {
    $d = dashboardLoadStaffData($db, $dashRole);
} catch (Throwable $e) {
    error_log('Dashboard error: ' . $e->getMessage());
    $d = dashboardLoadStaffDataDefaults($dashRole);
}

$nama = htmlspecialchars((string)($_SESSION['nama_lengkap'] ?? 'Pengguna'), ENT_QUOTES, 'UTF-8');
$isAdmin = $dashRole === 'admin';
$pageTitle = $isAdmin ? 'Dashboard Admin' : 'Dashboard Teller';

$setoranScopeHint = $isAdmin ? 'semua teller' : 'input saya';
$setoranHariIniHint = ((int)($d['setoran_hari_ini']['total_nasabah'] ?? 0)) . ' nasabah · '
    . ((int)$d['setoran_hari_ini']['total_transaksi']) . ' trx (' . $setoranScopeHint . ')';

$laba = $d['pertumbuhan_laba'] ?? [];
$labaPersen = (float)($laba['persen'] ?? 0);
$labaPersenTxt = number_format($labaPersen, 1, ',', '.') . '%';
$labaHint = trim(
    (string)($laba['bulan_label'] ?? '')
    . (!empty($laba['terkunci']) ? ' · terkunci' : ' · belum dikunci')
    . ' · Laba ' . formatRupiah((float)($laba['total_bunga'] ?? 0))
);

$primaryKpis = $isAdmin
    ? [
        ['label' => 'Anggota', 'value' => number_format((int)$d['stat_anggota']['total']), 'countup' => (int)$d['stat_anggota']['total'], 'hint' => (int)$d['stat_anggota']['aktif'] . ' aktif', 'icon' => 'ti ti-users', 'tone' => 'primary'],
        ['label' => 'Simpanan', 'value' => formatRupiah($d['total_simpanan']), 'countup' => (float)$d['total_simpanan'], 'countup_prefix' => 'Rp ', 'hint' => 'Saldo real', 'icon' => 'ti ti-pig-money', 'tone' => 'success'],
        ['label' => 'Setoran hari ini', 'value' => formatRupiah($d['setoran_hari_ini']['total_setoran']), 'countup' => (float)$d['setoran_hari_ini']['total_setoran'], 'countup_prefix' => 'Rp ', 'hint' => $setoranHariIniHint, 'icon' => 'ti ti-cash-banknote', 'tone' => 'success'],
        ['label' => 'Pinjaman cair', 'value' => formatRupiah($d['pinjaman_stats']['total_nominal']), 'countup' => (float)$d['pinjaman_stats']['total_nominal'], 'countup_prefix' => 'Rp ', 'hint' => (int)$d['pinjaman_stats']['aktif'] . ' aktif', 'icon' => 'ti ti-cash', 'tone' => 'danger'],
        ['label' => 'Sisa tagihan', 'value' => formatRupiah($d['pinjaman_stats']['total_sisa']), 'countup' => (float)$d['pinjaman_stats']['total_sisa'], 'countup_prefix' => 'Rp ', 'hint' => 'Pokok + bunga', 'icon' => 'ti ti-hourglass', 'tone' => 'warning'],
        ['label' => 'Saldo kas', 'value' => formatRupiah($d['stat_kas']['saldo']), 'countup' => (float)$d['stat_kas']['saldo'], 'countup_prefix' => 'Rp ', 'hint' => 'Tersedia', 'icon' => 'ti ti-wallet', 'tone' => 'info'],
        ['label' => 'Waserda', 'value' => formatRupiah($d['waserda_stats']['total_nominal']), 'countup' => (float)$d['waserda_stats']['total_nominal'], 'countup_prefix' => 'Rp ', 'hint' => (int)$d['waserda_stats']['total_transaksi'] . ' trx', 'icon' => 'ti ti-building-store', 'tone' => 'success'],
        [
            'label' => 'Pertumbuhan laba',
            'value' => $labaPersenTxt,
            'countup' => $labaPersen,
            'countup_decimals' => 1,
            'countup_suffix' => '%',
            'hint' => $labaHint,
            'icon' => $labaPersen > 0 ? 'ti ti-trending-up' : 'ti ti-minus',
            'tone' => $labaPersen > 0 ? 'success' : 'secondary',
            'trend' => $labaPersen > 0 ? 'up' : 'flat',
            'meta' => 'Bunga / angsuran',
        ],
    ]
    : [
        ['label' => 'Trx hari ini', 'value' => number_format((int)$d['transaksi_hari_ini']['total_transaksi']), 'countup' => (int)$d['transaksi_hari_ini']['total_transaksi'], 'hint' => 'Kas input saya', 'icon' => 'ti ti-activity', 'tone' => 'primary'],
        ['label' => 'Setoran hari ini', 'value' => formatRupiah($d['setoran_hari_ini']['total_setoran']), 'countup' => (float)$d['setoran_hari_ini']['total_setoran'], 'countup_prefix' => 'Rp ', 'hint' => $setoranHariIniHint, 'icon' => 'ti ti-cash-banknote', 'tone' => 'success'],
        ['label' => 'Anggota aktif', 'value' => number_format((int)$d['stat_anggota']['aktif']), 'countup' => (int)$d['stat_anggota']['aktif'], 'hint' => 'dari ' . number_format((int)$d['stat_anggota']['total']), 'icon' => 'ti ti-users', 'tone' => 'info'],
    ];

$chartSeries = $d['pertumbuhan_series'] ?? [];
$chartLabels = array_column($chartSeries, 'label');
$chartBunga = array_map('floatval', array_column($chartSeries, 'bunga'));
$chartAngsuran = array_map('floatval', array_column($chartSeries, 'angsuran'));
$chartPersen = array_map('floatval', array_column($chartSeries, 'persen'));

$workQueue = $isAdmin
    ? [
        ['label' => 'Pengajuan menunggu', 'value' => (int)$d['pengajuan_stats']['menunggu'], 'href' => 'persetujuan.php', 'tone' => 'warning', 'icon' => 'ti ti-file-check'],
        ['label' => 'Jatuh tempo 7 hari', 'value' => count($d['jatuh_tempo']), 'href' => 'angsuran.php', 'tone' => 'danger', 'icon' => 'ti ti-calendar-due'],
        ['label' => 'Siap cair', 'value' => (int)($d['siap_cair'] ?? 0), 'href' => 'pencairan.php', 'tone' => 'success', 'icon' => 'ti ti-cash-banknote'],
    ]
    : [
        ['label' => 'Pengajuan menunggu', 'value' => (int)$d['pengajuan_stats']['menunggu'], 'href' => 'persetujuan.php', 'tone' => 'warning', 'icon' => 'ti ti-file-check'],
        ['label' => 'Jatuh tempo 7 hari', 'value' => count($d['jatuh_tempo']), 'href' => 'angsuran.php', 'tone' => 'danger', 'icon' => 'ti ti-calendar-due'],
        ['label' => 'Siap cair', 'value' => (int)($d['siap_cair'] ?? 0), 'href' => 'pencairan.php', 'tone' => 'success', 'icon' => 'ti ti-cash-banknote'],
    ];

$quickActions = $isAdmin
    ? [
        ['href' => 'pengajuan.php', 'label' => 'Buat pengajuan', 'icon' => 'ti ti-file-plus', 'class' => 'btn-outline-warning'],
        ['href' => 'persetujuan.php', 'label' => 'Persetujuan', 'icon' => 'ti ti-file-check', 'class' => 'btn-outline-primary'],
        ['href' => 'angsuran.php', 'label' => 'Angsuran', 'icon' => 'ti ti-calendar-due', 'class' => 'btn-outline-primary'],
        ['href' => 'anggota.php', 'label' => 'Anggota', 'icon' => 'ti ti-users', 'class' => 'btn-outline-info'],
        ['href' => 'pembelian.php', 'label' => 'Waserda', 'icon' => 'ti ti-building-store', 'class' => 'btn-outline-success'],
        ['href' => 'laporan_kas.php', 'label' => 'Laporan kas', 'icon' => 'ti ti-wallet', 'class' => 'btn-outline-secondary'],
    ]
    : [
        ['href' => 'setoran.php', 'label' => 'Setoran', 'icon' => 'ti ti-cash-banknote', 'class' => 'btn-outline-success'],
        ['href' => 'angsuran.php', 'label' => 'Angsuran', 'icon' => 'ti ti-calendar-due', 'class' => 'btn-outline-primary'],
        ['href' => 'pencairan.php', 'label' => 'Pencairan', 'icon' => 'ti ti-cash', 'class' => 'btn-outline-success'],
        ['href' => 'penarikan.php', 'label' => 'Penarikan', 'icon' => 'ti ti-cash-off', 'class' => 'btn-outline-warning'],
        ['href' => 'pembelian.php', 'label' => 'Waserda', 'icon' => 'ti ti-building-store', 'class' => 'btn-outline-success'],
        ['href' => 'anggota.php', 'label' => 'Anggota', 'icon' => 'ti ti-users', 'class' => 'btn-outline-secondary'],
    ];

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
<?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-dash-page">
            <header class="ui-dash-header">
                <div class="ui-dash-header-icon" aria-hidden="true"><i class="ti ti-dashboard"></i></div>
                <div class="ui-dash-header-copy">
                    <p class="ui-dash-eyebrow mb-0">Ringkasan operasional</p>
                    <h1 class="ui-dash-title"><?php echo htmlspecialchars($pageTitle); ?></h1>
                    <p class="ui-dash-meta mb-0">Halo, <?php echo $nama; ?> · <?php echo htmlspecialchars($d['tanggal']); ?></p>
                </div>
            </header>

            <?php if (!$isAdmin): ?>
            <section class="ui-work-queue mb-3" aria-label="Antrian kerja hari ini">
                <div class="ui-dash-sec">
                    <h3 class="ui-dash-sec-title"><i class="ti ti-list-check" aria-hidden="true"></i>Antrian kerja hari ini</h3>
                    <span class="ui-dash-sec-note">Tindak lanjut prioritas</span>
                </div>
                <div class="ui-dash-queue" data-count="<?php echo count($workQueue); ?>">
                    <?php foreach ($workQueue as $item): ?>
                        <a href="<?php echo htmlspecialchars($item['href']); ?>" class="ui-dash-attention tone-<?php echo htmlspecialchars($item['tone']); ?> text-decoration-none">
                            <span class="ui-dash-attention-icon"><i class="<?php echo htmlspecialchars($item['icon']); ?>"></i></span>
                            <div class="min-w-0 flex-fill">
                                <div class="small text-muted text-truncate"><?php echo htmlspecialchars($item['label']); ?></div>
                            </div>
                            <div class="fw-semibold"><span class="js-countup" data-countup="<?php echo (int)$item['value']; ?>"><?php echo number_format((int)$item['value']); ?></span></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php ui_stat_grid($primaryKpis); ?>

            <?php if ($isAdmin): ?>
            <section class="ui-dash-panel mb-3" aria-label="KPI antrian">
                <div class="ui-dash-sec">
                    <h3 class="ui-dash-sec-title"><i class="ti ti-activity" aria-hidden="true"></i>KPI antrian</h3>
                    <span class="ui-dash-sec-note">Prioritas hari ini</span>
                </div>
                <div class="ui-dash-queue" data-count="<?php echo count($workQueue); ?>">
                    <?php foreach ($workQueue as $item): ?>
                        <a href="<?php echo htmlspecialchars($item['href']); ?>" class="ui-dash-attention tone-<?php echo htmlspecialchars($item['tone']); ?> text-decoration-none">
                            <span class="ui-dash-attention-icon"><i class="<?php echo htmlspecialchars($item['icon']); ?>"></i></span>
                            <div class="min-w-0 flex-fill">
                                <div class="small text-muted text-truncate"><?php echo htmlspecialchars($item['label']); ?></div>
                            </div>
                            <div class="fw-semibold"><span class="js-countup" data-countup="<?php echo (int)$item['value']; ?>"><?php echo number_format((int)$item['value']); ?></span></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($chartSeries): ?>
            <section class="card ui-dash-panel ui-dash-growth" aria-label="Grafik pertumbuhan laba">
                <div class="card-header ui-dash-sec">
                    <span class="ui-dash-sec-title"><i class="ti ti-chart-bar" aria-hidden="true"></i>Grafik pertumbuhan</span>
                    <span class="ui-dash-sec-note">Bunga vs angsuran · 6 bulan</span>
                </div>
                <div class="card-body">
                    <div id="dashGrowthChart" class="ui-dash-growth-chart" role="img" aria-label="Grafik pertumbuhan laba bulanan"></div>
                </div>
            </section>
            <?php endif; ?>
            <?php endif; ?>

            <section class="ui-dash-panel" aria-label="Aksi cepat">
                <div class="ui-dash-sec">
                    <h3 class="ui-dash-sec-title"><i class="ti ti-bolt" aria-hidden="true"></i>Aksi cepat</h3>
                    <span class="ui-dash-sec-note">Pintasan transaksi</span>
                </div>
                <div class="ui-dash-actions" data-count="<?php echo count($quickActions); ?>">
                    <?php foreach ($quickActions as $i => $act):
                        $tone = ['primary','warning','info','success','danger','secondary'][$i % 6];
                        if (str_contains($act['class'] ?? '', 'success')) $tone = 'success';
                        elseif (str_contains($act['class'] ?? '', 'warning')) $tone = 'warning';
                        elseif (str_contains($act['class'] ?? '', 'info')) $tone = 'info';
                        elseif (str_contains($act['class'] ?? '', 'danger')) $tone = 'danger';
                        elseif (str_contains($act['class'] ?? '', 'primary')) $tone = 'primary';
                    ?>
                        <a class="ui-dash-action tone-<?php echo $tone; ?><?php echo $i === 0 ? ' is-primary' : ''; ?>" href="<?php echo htmlspecialchars($act['href']); ?>">
                            <span class="ui-dash-action-icon"><i class="<?php echo htmlspecialchars($act['icon']); ?>" aria-hidden="true"></i></span>
                            <span class="ui-dash-action-label"><?php echo htmlspecialchars($act['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if (!empty($d['simpanan_per_jenis']) && $isAdmin): ?>
            <div class="card ui-dash-panel">
                <div class="card-header ui-dash-sec"><span class="ui-dash-sec-title"><i class="ti ti-wallet" aria-hidden="true"></i>Saldo simpanan per jenis</span></div>
                <div class="table-responsive ui-table-scroll">
                    <table class="table table-vcenter table-sm mb-0 js-datatable" data-dt-plain>
                        <thead>
                            <tr><th>Jenis</th><th class="text-end">Saldo</th><th class="text-end">Anggota</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($d['simpanan_per_jenis'] as $s): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['nama_jenis']); ?></td>
                                <td class="text-end"><?php echo formatRupiah($s['total_saldo']); ?></td>
                                <td class="text-end"><?php echo number_format((int)$s['jumlah_anggota']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="row g-2 mb-3">
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header ui-dash-sec">
                            <span class="ui-dash-sec-title"><i class="ti ti-calendar-due" aria-hidden="true"></i>Jatuh tempo (7 hari)</span>
                            <a href="angsuran.php" class="ui-dash-sec-link">Lihat semua</a>
                        </div>
                        <div class="table-responsive ui-table-scroll d-none d-md-block">
                            <table class="table table-vcenter table-sm mb-0 js-datatable" data-dt-plain>
                                <thead><tr><th>Anggota</th><th>Jatuh tempo</th><th class="text-end">Nominal</th></tr></thead>
                                <tbody>
                                <?php if (!$d['jatuh_tempo']): ?>
                                    <tr><td colspan="3" class="text-center text-muted py-3">Tidak ada angsuran jatuh tempo.</td></tr>
                                <?php else: foreach ($d['jatuh_tempo'] as $jt): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($jt['nama_lengkap']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($jt['kode_pinjaman']); ?></small>
                                        </td>
                                        <td><?php echo formatTanggalUi($jt['tanggal_jatuh_tempo']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah((float)$jt['jumlah_angsuran'] + (float)($jt['denda'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="ui-mobile-cards d-md-none">
                            <?php if (!$d['jatuh_tempo']): ?>
                                <div class="ui-dash-empty"><i class="ti ti-calendar-check"></i>Tidak ada angsuran jatuh tempo</div>
                            <?php else: foreach ($d['jatuh_tempo'] as $jt): ?>
                                <a class="ui-mobile-card" href="angsuran.php">
                                    <div class="ui-mobile-card-title"><?php echo htmlspecialchars($jt['nama_lengkap']); ?></div>
                                    <div class="ui-mobile-card-meta"><?php echo htmlspecialchars($jt['kode_pinjaman']); ?> · <?php echo formatTanggalUi($jt['tanggal_jatuh_tempo']); ?></div>
                                    <div class="ui-mobile-card-value"><?php echo formatRupiah((float)$jt['jumlah_angsuran'] + (float)($jt['denda'] ?? 0)); ?></div>
                                </a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header ui-dash-sec">
                            <span class="ui-dash-sec-title"><i class="ti ti-cash" aria-hidden="true"></i>Pinjaman aktif</span>
                            <a href="pencairan.php" class="ui-dash-sec-link">Pencairan</a>
                        </div>
                        <div class="table-responsive ui-table-scroll d-none d-md-block">
                            <table class="table table-vcenter table-sm mb-0 js-datatable" data-dt-plain>
                                <thead><tr><th>Anggota</th><th>Progress</th><th class="text-end">Sisa</th></tr></thead>
                                <tbody>
                                <?php if (!$d['pinjaman_aktif']): ?>
                                    <tr><td colspan="3" class="text-center text-muted py-3">Belum ada pinjaman aktif.</td></tr>
                                <?php else: foreach ($d['pinjaman_aktif'] as $p): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($p['nama_lengkap']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($p['kode_pinjaman']); ?></small>
                                        </td>
                                        <td><?php echo (int)$p['angsuran_lunas']; ?>/<?php echo (int)$p['total_angsuran']; ?></td>
                                        <td class="text-end"><?php echo formatRupiah((float)$p['sisa_pokok'] + (float)$p['sisa_bunga']); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="ui-mobile-cards d-md-none">
                            <?php if (!$d['pinjaman_aktif']): ?>
                                <div class="ui-dash-empty"><i class="ti ti-cash"></i>Belum ada pinjaman aktif</div>
                            <?php else: foreach ($d['pinjaman_aktif'] as $p): ?>
                                <div class="ui-mobile-card">
                                    <div class="ui-mobile-card-title"><?php echo htmlspecialchars($p['nama_lengkap']); ?></div>
                                    <div class="ui-mobile-card-meta"><?php echo htmlspecialchars($p['kode_pinjaman']); ?> · <?php echo (int)$p['angsuran_lunas']; ?>/<?php echo (int)$p['total_angsuran']; ?></div>
                                    <div class="ui-mobile-card-value"><?php echo formatRupiah((float)$p['sisa_pokok'] + (float)$p['sisa_bunga']); ?></div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-2">
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header ui-dash-sec"><span class="ui-dash-sec-title"><i class="ti ti-exchange" aria-hidden="true"></i><?php echo $isAdmin ? 'Transaksi simpanan' : 'Transaksi saya'; ?></span></div>
                        <div class="list-group list-group-flush ui-dash-list">
                            <?php if (!$d['transaksi_terbaru']): ?>
                                <div class="list-group-item text-muted">Belum ada transaksi.</div>
                            <?php else: foreach ($d['transaksi_terbaru'] as $t): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($t['nama_lengkap']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($t['nama_jenis']); ?> · <?php echo formatTanggalUi($t['tanggal_transaksi'], true); ?></small>
                                        </div>
                                        <div class="text-end fw-semibold <?php echo ($t['jenis_transaksi'] ?? '') === 'setoran' ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo formatRupiah($t['jumlah']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header ui-dash-sec"><span class="ui-dash-sec-title"><i class="ti ti-receipt" aria-hidden="true"></i>Angsuran terbaru</span></div>
                        <div class="list-group list-group-flush ui-dash-list">
                            <?php if (!$d['angsuran_terbaru']): ?>
                                <div class="list-group-item text-muted">Belum ada pembayaran.</div>
                            <?php else: foreach ($d['angsuran_terbaru'] as $a): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($a['nama_lengkap']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($a['kode_pinjaman']); ?> · ke-<?php echo (int)$a['angsuran_ke']; ?></small>
                                        </div>
                                        <div class="text-end fw-semibold"><?php echo formatRupiah((float)$a['jumlah_angsuran'] + (float)($a['denda'] ?? 0)); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header ui-dash-sec"><span class="ui-dash-sec-title"><i class="ti ti-building-store" aria-hidden="true"></i>Waserda terbaru</span></div>
                        <div class="list-group list-group-flush ui-dash-list">
                            <?php if (!$d['waserda_terbaru']): ?>
                                <div class="list-group-item text-muted">Belum ada transaksi waserda.</div>
                            <?php else: foreach ($d['waserda_terbaru'] as $w): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($w['nama_lengkap']); ?></div>
                                            <small class="text-muted"><?php echo (int)($w['jml_item'] ?? 1); ?> item · <?php echo formatTanggalUi($w['created_at'], true); ?></small>
                                        </div>
                                        <div class="text-end fw-semibold"><?php echo formatRupiah($w['total_bayar']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            </div>
<?php
$uiCharts = $isAdmin && !empty($chartSeries);
include __DIR__ . '/layout/shell_end.php';
?>
<script>
(function () {
  function formatId(n, decimals) {
    var fixed = Number(n).toFixed(decimals);
    var parts = fixed.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return decimals > 0 ? parts.join(',') : parts[0];
  }
  function animateCountup(el) {
    var target = parseFloat(el.getAttribute('data-countup') || '0');
    if (!isFinite(target)) return;
    var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10) || 0;
    var prefix = el.getAttribute('data-prefix') || '';
    var suffix = el.getAttribute('data-suffix') || '';
    var start = 0;
    var duration = 1100;
    var t0 = null;
    function frame(ts) {
      if (t0 === null) t0 = ts;
      var p = Math.min(1, (ts - t0) / duration);
      var eased = 1 - Math.pow(1 - p, 3);
      var val = start + (target - start) * eased;
      el.textContent = prefix + formatId(val, decimals) + suffix;
      if (p < 1) requestAnimationFrame(frame);
      else el.textContent = prefix + formatId(target, decimals) + suffix;
    }
    el.textContent = prefix + formatId(0, decimals) + suffix;
    requestAnimationFrame(frame);
  }
  document.querySelectorAll('.js-countup').forEach(animateCountup);

  var chartEl = document.getElementById('dashGrowthChart');
  if (chartEl && typeof ApexCharts !== 'undefined') {
    var labels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
    var bunga = <?php echo json_encode($chartBunga); ?>;
    var angsuran = <?php echo json_encode($chartAngsuran); ?>;
    var persen = <?php echo json_encode($chartPersen); ?>;
    var chart = new ApexCharts(chartEl, {
      chart: { type: 'line', height: 300, toolbar: { show: false }, fontFamily: 'inherit', animations: { enabled: true, speed: 800 } },
      series: [
        { name: 'Angsuran', type: 'column', data: angsuran },
        { name: 'Bunga (laba)', type: 'column', data: bunga },
        { name: 'Pertumbuhan %', type: 'line', data: persen }
      ],
      stroke: { width: [0, 0, 3], curve: 'smooth' },
      markers: { size: [0, 0, 4], strokeWidth: 0 },
      plotOptions: { bar: { columnWidth: '48%', borderRadius: 4 } },
      colors: ['#93c5fd', '#2563eb', '#16a34a'],
      dataLabels: { enabled: false },
      xaxis: { categories: labels, labels: { style: { colors: '#64748b' } } },
      yaxis: [
        {
          seriesName: 'Angsuran',
          title: { text: 'Nominal (Rp)', style: { color: '#64748b' } },
          labels: {
            formatter: function (v) {
              if (v >= 1e6) return (v / 1e6).toFixed(1) + ' jt';
              if (v >= 1e3) return (v / 1e3).toFixed(0) + ' rb';
              return String(v);
            }
          }
        },
        { seriesName: 'Bunga (laba)', show: false },
        {
          opposite: true,
          seriesName: 'Pertumbuhan %',
          title: { text: 'Pertumbuhan %', style: { color: '#16a34a' } },
          labels: { formatter: function (v) { return Number(v).toFixed(0) + '%'; } },
          min: 0,
          max: Math.max(30, Math.ceil(Math.max.apply(null, persen.concat([0])) / 5) * 5)
        }
      ],
      legend: { position: 'top', horizontalAlign: 'left' },
      grid: { borderColor: '#e2e8f0', strokeDashArray: 4 },
      tooltip: {
        shared: true,
        y: {
          formatter: function (val, opts) {
            if (opts.seriesIndex === 2) return Number(val).toFixed(1) + '%';
            return 'Rp ' + formatId(val, 0);
          }
        }
      }
    });
    chart.render();
  }
})();
</script>
</body>
</html>

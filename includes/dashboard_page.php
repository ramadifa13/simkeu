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

$primaryKpis = $isAdmin
    ? [
        ['label' => 'Anggota', 'value' => number_format((int)$d['stat_anggota']['total']), 'hint' => (int)$d['stat_anggota']['aktif'] . ' aktif', 'icon' => 'ti ti-users', 'tone' => 'primary'],
        ['label' => 'Simpanan', 'value' => formatRupiah($d['total_simpanan']), 'hint' => 'Saldo real', 'icon' => 'ti ti-pig-money', 'tone' => 'success'],
        ['label' => 'Pinjaman cair', 'value' => formatRupiah($d['pinjaman_stats']['total_nominal']), 'hint' => (int)$d['pinjaman_stats']['aktif'] . ' aktif', 'icon' => 'ti ti-cash', 'tone' => 'danger'],
        ['label' => 'Sisa tagihan', 'value' => formatRupiah($d['pinjaman_stats']['total_sisa']), 'hint' => 'Pokok + bunga', 'icon' => 'ti ti-hourglass', 'tone' => 'warning'],
        ['label' => 'Saldo kas', 'value' => formatRupiah($d['stat_kas']['saldo']), 'hint' => 'Tersedia', 'icon' => 'ti ti-wallet', 'tone' => 'info'],
        ['label' => 'Waserda', 'value' => formatRupiah($d['waserda_stats']['total_nominal']), 'hint' => (int)$d['waserda_stats']['total_transaksi'] . ' trx', 'icon' => 'ti ti-building-store', 'tone' => 'success'],
    ]
    : [
        ['label' => 'Trx hari ini', 'value' => number_format((int)$d['transaksi_hari_ini']['total_transaksi']), 'hint' => 'Semua kas', 'icon' => 'ti ti-activity', 'tone' => 'primary'],
        ['label' => 'Setoran hari ini', 'value' => formatRupiah($d['setoran_hari_ini']['total_setoran']), 'hint' => (int)$d['setoran_hari_ini']['total_transaksi'] . ' setoran', 'icon' => 'ti ti-cash-banknote', 'tone' => 'success'],
        ['label' => 'Anggota aktif', 'value' => number_format((int)$d['stat_anggota']['aktif']), 'hint' => 'dari ' . number_format((int)$d['stat_anggota']['total']), 'icon' => 'ti ti-users', 'tone' => 'info'],
    ];

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

$kpiCount = count($primaryKpis);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container-fluid">
    <div class="row">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="ui-dash-header">
                <h2 class="page-title"><i class="ti ti-dashboard me-1"></i><?php echo htmlspecialchars($pageTitle); ?></h2>
                <p class="ui-dash-meta mb-0">Halo, <?php echo $nama; ?> · <?php echo htmlspecialchars($d['tanggal']); ?></p>
            </div>

            <?php if (!$isAdmin): ?>
            <section class="ui-work-queue mb-3" aria-label="Antrian kerja hari ini">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <h3 class="h6 mb-0"><i class="ti ti-list-check me-1"></i>Antrian kerja hari ini</h3>
                    <span class="text-muted small">Tindak lanjut prioritas</span>
                </div>
                <div class="row g-2">
                    <?php foreach ($workQueue as $item): ?>
                        <div class="col-sm-4">
                            <a href="<?php echo htmlspecialchars($item['href']); ?>" class="ui-dash-attention tone-<?php echo htmlspecialchars($item['tone']); ?> text-decoration-none">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="ui-dash-attention-icon"><i class="<?php echo htmlspecialchars($item['icon']); ?>"></i></span>
                                    <div class="min-w-0">
                                        <div class="small text-muted text-truncate"><?php echo htmlspecialchars($item['label']); ?></div>
                                        <div class="fw-semibold"><?php echo number_format((int)$item['value']); ?></div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <div class="ui-stat-grid" data-count="<?php echo (int)$kpiCount; ?>">
                <?php foreach ($primaryKpis as $kpi): ?>
                    <div class="stat-card tone-<?php echo htmlspecialchars($kpi['tone']); ?>">
                        <div class="card-body d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <p class="mb-0 text-muted small text-uppercase"><?php echo htmlspecialchars($kpi['label']); ?></p>
                                <h4 class="mb-0"><?php echo $kpi['value']; ?></h4>
                                <small class="text-muted"><?php echo htmlspecialchars($kpi['hint']); ?></small>
                            </div>
                            <span class="stat-icon"><i class="<?php echo htmlspecialchars($kpi['icon']); ?>"></i></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($isAdmin): ?>
            <div class="row g-2 mb-3">
                <?php foreach ($workQueue as $item): ?>
                    <div class="col-sm-4">
                        <a href="<?php echo htmlspecialchars($item['href']); ?>" class="ui-dash-attention tone-<?php echo htmlspecialchars($item['tone']); ?> text-decoration-none">
                            <div class="d-flex align-items-center gap-2">
                                <span class="ui-dash-attention-icon"><i class="<?php echo htmlspecialchars($item['icon']); ?>"></i></span>
                                <div class="min-w-0">
                                    <div class="small text-muted text-truncate"><?php echo htmlspecialchars($item['label']); ?></div>
                                    <div class="fw-semibold"><?php echo number_format((int)$item['value']); ?></div>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="card ui-dash-panel">
                <div class="card-body py-2">
                    <h3 class="h6 mb-2"><i class="ti ti-bolt me-1"></i>Aksi cepat</h3>
                    <div class="ui-dash-actions">
                        <?php foreach ($quickActions as $i => $act): ?>
                            <a class="btn btn-sm <?php echo $i === 0 ? 'btn-primary' : htmlspecialchars($act['class']); ?>" href="<?php echo htmlspecialchars($act['href']); ?>">
                                <i class="<?php echo htmlspecialchars($act['icon']); ?> me-1"></i><?php echo htmlspecialchars($act['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($d['simpanan_per_jenis']) && $isAdmin): ?>
            <div class="card ui-dash-panel">
                <div class="card-header">Saldo simpanan per jenis</div>
                <div class="table-responsive ui-table-scroll">
                    <table class="table table-vcenter table-sm mb-0">
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
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>Jatuh tempo (7 hari)</span>
                            <a href="angsuran.php" class="small">Lihat semua</a>
                        </div>
                        <div class="table-responsive ui-table-scroll d-none d-md-block">
                            <table class="table table-vcenter table-sm mb-0">
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
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>Pinjaman aktif</span>
                            <a href="pencairan.php" class="small">Pencairan</a>
                        </div>
                        <div class="table-responsive ui-table-scroll d-none d-md-block">
                            <table class="table table-vcenter table-sm mb-0">
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
                        <div class="card-header"><?php echo $isAdmin ? 'Transaksi simpanan' : 'Transaksi saya'; ?></div>
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
                        <div class="card-header">Angsuran terbaru</div>
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
                        <div class="card-header">Waserda terbaru</div>
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
        </main>
    </div>
</div>
</body>
</html>

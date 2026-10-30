<?php
// A missing legacy module must not render an unfinished page or expose SQL errors.
require_once __DIR__.'/shu_helper.php';
$shu_tersedia=shuSiap($db);
if (!$shu_tersedia) {
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($page_title ?? 'SHU') ?> - <?= htmlspecialchars(APP_NAME) ?></title>    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head><body>
<?php include __DIR__ . '/layout/shell_start.php'; ?><div class="page-header"><h1><?= htmlspecialchars($page_title ?? 'Sisa Hasil Usaha') ?></h1></div><div class="card"><div class="card-body py-5 text-center"><div class="mx-auto mb-3 rounded-circle d-flex align-items-center justify-content-center" style="width:64px;height:64px;background:#e7f3f1;color:#087f83"><i class="ti ti-chart-pie fs-2" aria-hidden="true"></i></div><h2 class="h5">Modul SHU belum tersedia</h2><p class="text-muted mx-auto" style="max-width:440px">Struktur data SHU belum tersedia pada database ini. Pengaturan periode dan pembagian SHU perlu disiapkan sebelum modul dapat digunakan.</p><a class="btn btn-primary" href="dashboard.php">Kembali ke dashboard</a><?php if(isAdmin()): ?> <a class="btn btn-outline-primary" href="layanan.php?tab=lunas#bonus-tahunan">Lihat bonus loyalitas</a><?php endif; ?></div></div><?php include __DIR__ . '/layout/shell_end.php'; ?></body></html><?php
    exit;
}

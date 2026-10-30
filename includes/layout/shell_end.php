<?php
/**
 * Penutup shell + JS bersama (sinkron, agar skrip inline halaman bisa memakai bootstrap/jQuery/DataTable).
 * Set $uiCharts = true sebelum include bila halaman memakai ApexCharts.
 */
$uiAssetPrefix = $uiAssetPrefix ?? '../';
$uiRoot = dirname(__DIR__, 2);
$uiP = htmlspecialchars($uiAssetPrefix, ENT_QUOTES, 'UTF-8');
$uiScripts = [
    'assets/vendor/jquery/jquery.min.js',
    'assets/vendor/tabler/tabler.min.js',
    'assets/vendor/datatables/dataTables.min.js',
    'assets/vendor/datatables/dataTables.bootstrap5.min.js',
    'assets/vendor/datatables/dataTables.responsive.min.js',
    'assets/vendor/datatables/responsive.bootstrap5.min.js',
];
if (!empty($uiCharts)) {
    $uiScripts[] = 'assets/vendor/apexcharts/apexcharts.min.js';
}
$uiScripts = array_merge($uiScripts, [
    'assets/js/datatables-init.js',
    'assets/js/pilihan-foto.js',
    'assets/js/brand.js',
    'assets/js/app-ui.js',
    'assets/js/tanda-tangan-layanan.js',
]);
if (empty($GLOBALS['simkeu_ui_js_core'])) {
    $GLOBALS['simkeu_ui_js_core'] = true;
    echo '</main></div>', "\n"; // main, .page-body
    echo '<footer class="footer footer-transparent d-print-none"><div class="container-fluid text-secondary small">&copy; ', date('Y'), ' ', htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'SIMKEU'), '</div></footer>', "\n";
    echo '</div></div>', "\n"; // .page-wrapper, .page
    foreach ($uiScripts as $rel) {
        $f = $uiRoot . '/' . $rel;
        echo '<script src="', $uiP, $rel, '?v=', is_file($f) ? filemtime($f) : 1, '"></script>', "\n";
    }
}

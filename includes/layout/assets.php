<?php
/**
 * CSS bersama (Tabler UI, ikon, DataTables, tema SIMKEU) � semua aset lokal.
 * Aman dipanggil berkali-kali (tanpa dobel link). $uiAssetPrefix: '../' (admin/teller/anggota) atau '' (root).
 */
$uiAssetPrefix = $uiAssetPrefix ?? '../';
if (!empty($GLOBALS['simkeu_ui_css_core'])) {
    return;
}
$GLOBALS['simkeu_ui_css_core'] = true;
$uiRoot = dirname(__DIR__, 2);
$uiV = static function (string $rel) use ($uiRoot): string {
    $f = $uiRoot . '/' . $rel;
    return is_file($f) ? (string)filemtime($f) : '1';
};
$uiP = htmlspecialchars($uiAssetPrefix, ENT_QUOTES, 'UTF-8');
foreach ([
    'assets/vendor/tabler/tabler.min.css',
    'assets/vendor/icons/tabler-icons.min.css',
    'assets/vendor/datatables/dataTables.bootstrap5.min.css',
    'assets/vendor/datatables/responsive.bootstrap5.min.css',
    'assets/css/simkeu-components.css',
    'assets/css/simkeu-theme.css',
    'assets/css/upload-foto.css',
    'assets/css/tanda-tangan.css',
] as $rel): ?>
<link rel="stylesheet" href="<?= $uiP . $rel ?>?v=<?= $uiV($rel) ?>">
<?php endforeach; ?>

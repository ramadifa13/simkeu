<?php
/**
 * Shared front-end assets for app shell (Tabler icons + existing app theme).
 * Aman dipanggil berkali-kali (head halaman + header.php) tanpa dobel script/CSS.
 */
$uiAssetPrefix = $uiAssetPrefix ?? '../';
$uiUseTablerCss = !empty($uiUseTablerCss);
$uiAssetPrefixH = htmlspecialchars($uiAssetPrefix, ENT_QUOTES, 'UTF-8');

if (empty($GLOBALS['simkeu_ui_css_core'])) {
    $GLOBALS['simkeu_ui_css_core'] = true;
    ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.12.0/dist/tabler-icons.min.css">
<link rel="stylesheet" href="<?= $uiAssetPrefixH ?>assets/css/app-ui.css?v=20261029-ux10">
<link rel="stylesheet" href="<?= $uiAssetPrefixH ?>assets/css/upload-foto.css?v=20261021-1">
<link rel="stylesheet" href="<?= $uiAssetPrefixH ?>assets/css/tanda-tangan.css?v=20261029-2">
    <?php
}

if ($uiUseTablerCss && empty($GLOBALS['simkeu_tabler_css'])) {
    $GLOBALS['simkeu_tabler_css'] = true;
    ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js" defer></script>
    <?php
}

if (empty($GLOBALS['simkeu_ui_js_core'])) {
    $GLOBALS['simkeu_ui_js_core'] = true;
    ?>
<script src="<?= $uiAssetPrefixH ?>assets/js/pilihan-foto.js?v=20261023-1" defer></script>
<script src="<?= $uiAssetPrefixH ?>assets/js/brand.js?v=20261019-6" defer></script>
<script src="<?= $uiAssetPrefixH ?>assets/js/app-ui.js?v=20261029-ux10" defer></script>
<script src="<?= $uiAssetPrefixH ?>assets/js/tanda-tangan-layanan.js?v=20261029-2" defer></script>
    <?php
}

<?php
/**
 * Pembuka shell aplikasi (Tabler): sidebar + topbar + area konten.
 * Pakai setelah <body>. Variabel opsional: $pageTitle, $pageSubtitle.
 */
require_once __DIR__ . '/../brand.php';
echo '<a class="visually-hidden-focusable" href="#konten-utama">Lewati ke konten utama</a>', "\n";
echo '<div class="page">', "\n";
include __DIR__ . '/../sidebar.php';
echo '<div class="page-wrapper">', "\n";
include __DIR__ . '/../header.php';
echo '<div class="page-body simkeu-page"><main class="container-fluid simkeu-container" id="konten-utama" tabindex="-1">', "\n";

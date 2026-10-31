<?php
/**
 * Pembuka shell aplikasi (Tabler): sidebar + topbar + area konten.
 * Satu shell untuk semua role (admin / teller / anggota).
 * Pakai setelah <body>. Variabel opsional: $pageTitle, $pageSubtitle.
 */
require_once __DIR__ . '/../brand.php';
$shellRole = preg_replace('/[^a-z]/', '', strtolower((string)($_SESSION['role'] ?? ''))) ?: 'guest';
echo '<a class="visually-hidden-focusable" href="#konten-utama">Lewati ke konten utama</a>', "\n";
echo '<div class="page simkeu-shell" data-role="' . htmlspecialchars($shellRole, ENT_QUOTES, 'UTF-8') . '">', "\n";
include __DIR__ . '/../sidebar.php';
echo '<div class="page-wrapper">', "\n";
include __DIR__ . '/../header.php';
echo '<div class="page-body simkeu-page"><main class="container-fluid simkeu-container" id="konten-utama" tabindex="-1">', "\n";

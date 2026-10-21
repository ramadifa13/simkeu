<?php
require_once __DIR__ . '/../includes/auth.php';
if (!isLoggedIn() || !isTeller()) {
    http_response_code(403);
    exit('Akses ditolak.');
}
$tampilkanMingguBulanan = true;
require __DIR__ . '/../includes/buku_angsuran.php';

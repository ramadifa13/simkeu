<?php
require_once __DIR__ . '/../includes/auth.php';
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    exit('Akses ditolak.');
}
require __DIR__ . '/../includes/buku_angsuran.php';

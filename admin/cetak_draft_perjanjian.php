<?php
require_once '../includes/auth.php';
require_once '../config/config.php';
if (!isAdmin() && !isTeller()) {
    http_response_code(403);
    exit('Akses ditolak.');
}
require __DIR__ . '/../includes/cetak_draft_perjanjian.php';

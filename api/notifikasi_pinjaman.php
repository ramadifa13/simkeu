<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'teller', 'anggota'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi tidak valid']);
    exit;
}

try {
    $jumlah=layananHitungNotifikasi($db,$_SESSION['role'],(int)$_SESSION['user_id']);
    echo json_encode(['ok'=>true]+$jumlah+['server_time'=>date('c')]);
} catch (Exception $e) {
    error_log('API notifikasi pinjaman: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal mengambil notifikasi']);
}

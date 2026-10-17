<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'teller'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi tidak valid']);
    exit;
}

try {
    $jumlah = $db->query("SELECT
        COALESCE(SUM(pp.status='menunggu'),0) AS menunggu,
        COALESCE(SUM(pp.status='disetujui' AND NOT EXISTS
            (SELECT 1 FROM pinjaman p WHERE p.pengajuan_id=pp.id)),0) AS pencairan
        FROM pengajuan_pinjaman pp")->fetch(PDO::FETCH_ASSOC);
    $menunggu = (int)$jumlah['menunggu'];
    $pencairan = (int)$jumlah['pencairan'];
    echo json_encode([
        'ok' => true,
        'pengajuan' => $menunggu,
        'persetujuan' => $menunggu,
        'pencairan' => $pencairan,
        'server_time' => date('c')
    ]);
} catch (Exception $e) {
    error_log('API notifikasi pinjaman: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal mengambil notifikasi']);
}

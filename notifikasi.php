<?php
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
$target = is_string($_GET['target'] ?? null) ? $_GET['target'] : '';
$routes = [
    'admin' => [
        'persetujuan' => 'admin/persetujuan.php',
        'pembatalan' => 'admin/angsuran.php',
        'hapus_denda' => 'admin/layanan.php?tab=macet#perlu-ditinjau',
        'pelunasan_dini' => 'admin/layanan.php?tab=macet#perlu-ditinjau',
        'jaminan_kembali' => 'admin/layanan.php?tab=macet#perlu-ditinjau',
        'prioritas' => 'admin/layanan.php?tab=macet#perlu-ditinjau',
        'laporan_macet' => 'admin/layanan.php?tab=macet#perlu-ditinjau',
        'pembayaran_denda' => 'admin/laporan_denda.php',
    ],
    'teller' => [
        'pencairan' => 'teller/pencairan.php',
        'pembatalan' => 'teller/angsuran.php',
        'pelunasan_disetujui' => 'teller/pelunasan.php',
        'jaminan_disetujui' => 'teller/layanan.php?tab=macet',
        'jatuh_tempo' => 'teller/angsuran.php#daftar-angsuran',
        'terlambat' => 'teller/layanan.php?tab=macet',
        'denda_tertunggak' => 'teller/laporan_denda.php',
    ],
    'anggota' => [
        'anggota_pengajuan' => 'anggota/pinjaman.php',
        'anggota_pencairan' => 'anggota/pinjaman.php',
        'anggota_jatuh_tempo' => 'anggota/pinjaman.php',
        'anggota_terlambat' => 'anggota/pinjaman.php',
        'anggota_denda' => 'anggota/pinjaman.php',
        'anggota_jaminan' => 'anggota/pinjaman.php',
    ],
];

$destination = $routes[$role][$target] ?? ($role . '/dashboard.php');
header('Location: ' . $destination);
exit;

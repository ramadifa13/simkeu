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
        // Semua notifikasi layanan Admin dibuka melalui laporan lunas Admin,
        // bukan halaman pinjaman/layanan milik Teller.
        'hapus_denda' => 'admin/laporan_lunas.php?tab=lunas',
        'pelunasan_dini' => 'admin/laporan_lunas.php?tab=lunas',
        'jaminan_kembali' => 'admin/laporan_lunas.php?tab=lunas',
        'prioritas' => 'admin/laporan_lunas.php?tab=lunas',
        'laporan_macet' => 'admin/laporan_lunas.php?tab=lunas',
        'pembayaran_denda' => 'admin/laporan_denda.php',
    ],
    'teller' => [
        'pencairan' => 'teller/pencairan.php',
        'pembatalan' => 'teller/angsuran.php',
        'pelunasan_disetujui' => 'teller/laporan_lunas.php?tab=lunas#detail',
        'jaminan_disetujui' => 'teller/laporan_lunas.php?tab=lunas#detail',
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

<?php
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
$target = is_string($_GET['target'] ?? null) ? $_GET['target'] : '';
$detailId = max(0, (int)($_GET['id'] ?? 0));
$routes = [
    'admin' => [
        'persetujuan' => 'admin/persetujuan.php',
        'tunda_cair' => 'admin/pencairan.php',
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
        'pengajuan_menunggu' => 'teller/pengajuan.php',
        'pengajuan_disetujui' => 'teller/pengajuan.php',
        'pengajuan_ditolak' => 'teller/pengajuan.php',
        'pengajuan_dicairkan' => 'teller/pengajuan.php',
        'pengajuan_batal_cair' => 'teller/pengajuan.php',
        'tc_ditolak' => 'teller/pencairan.php',
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
        'anggota_batal_cair' => 'anggota/pinjaman.php',
        'anggota_jatuh_tempo' => 'anggota/pinjaman.php',
        'anggota_terlambat' => 'anggota/pinjaman.php',
        'anggota_denda' => 'anggota/pinjaman.php',
        'anggota_jaminan' => 'anggota/pinjaman.php',
    ],
];

$destination = $routes[$role][$target] ?? ($role . '/dashboard.php');
if ($detailId > 0) {
    $param = 'id';
    if ($target === 'pembatalan' || in_array($target, ['jatuh_tempo','terlambat','denda_tertunggak','pembayaran_denda','anggota_jaminan'], true)) $param = 'pinjaman_id';
    if ($target === 'pencairan' || $target === 'tc_ditolak') $param = 'pengajuan_id';
    $destination .= (strpos($destination, '?') === false ? '?' : '&') . $param . '=' . $detailId;
}
header('Location: ' . $destination);
exit;

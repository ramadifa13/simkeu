<?php
require_once __DIR__.'/brand.php';
if (!isset($db)) {
    require_once '../config/config.php';
}

// Ambil data koperasi dari database untuk logo
$koperasi = [];
$logo_path = '';

try {
    require_once '../config/database.php';
    if (!isset($db) || !$db) { $database = new Database(); $db = $database->getConnection(); }
    
    $koperasi_query = "SELECT * FROM koperasi WHERE id = 1";
    $koperasi_stmt = $db->prepare($koperasi_query);
    $koperasi_stmt->execute();
    $koperasi = $koperasi_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$koperasi) {
        $koperasi = [
            'nama' => APP_NAME,
            'logo' => null
        ];
    }
    
    if (!empty($koperasi['logo'])) {
        $logo_path = urlLogoKoperasi($koperasi['logo'], '../');
    }
} catch (Exception $e) {
    error_log("Error mengambil data koperasi di header: " . $e->getMessage());
    $koperasi = [
        'nama' => APP_NAME,
        'logo' => null
    ];
}

$headerNotif = ['total' => 0];
$headerNotifItems = [];
if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'teller', 'anggota'], true) && function_exists('layananHitungNotifikasi')) {
    try {
        $headerNotif = layananHitungNotifikasi($db, $_SESSION['role'], (int)($_SESSION['user_id'] ?? 0));
        if ($_SESSION['role'] === 'admin') {
            $headerNotifItems = [
                ['persetujuan', 'Pengajuan perlu disetujui', '../notifikasi.php?target=persetujuan', 'ti-file-pencil'],
                ['tunda_cair', 'Pengajuan tunda cair (TC) perlu diputuskan', '../notifikasi.php?target=tunda_cair', 'ti-player-pause'],
                ['pembatalan', 'Pembatalan angsuran perlu diputuskan', '../notifikasi.php?target=pembatalan', 'ti-calendar-off'],
                ['hapus_denda', 'Pengajuan hapus denda', '../notifikasi.php?target=hapus_denda', 'ti-eraser'],
                ['pelunasan_dini', 'Pelunasan dipercepat', '../notifikasi.php?target=pelunasan_dini', 'ti-flag-check'],
                ['jaminan_kembali', 'Pengembalian jaminan', '../notifikasi.php?target=jaminan_kembali', 'ti-package'],
                ['prioritas', 'Pengajuan anggota prioritas', '../notifikasi.php?target=prioritas', 'ti-star'],
                ['laporan_macet', 'Laporan pinjaman macet', '../notifikasi.php?target=laporan_macet', 'ti-alert-triangle'],
                ['pembayaran_denda', 'Pembayaran denda perlu ditinjau', '../notifikasi.php?target=pembayaran_denda', 'ti-receipt-2'],
            ];
        } elseif ($_SESSION['role'] === 'teller') {
            $headerNotifItems = [
                ['pengajuan_menunggu', 'Pengajuan menunggu persetujuan Admin', '../notifikasi.php?target=pengajuan_menunggu', 'ti-hourglass'],
                ['pengajuan_disetujui', 'Pengajuan disetujui Admin', '../notifikasi.php?target=pengajuan_disetujui', 'ti-circle-check'],
                ['pengajuan_ditolak', 'Pengajuan ditolak Admin', '../notifikasi.php?target=pengajuan_ditolak', 'ti-circle-x'],
                ['pengajuan_dicairkan', 'Pengajuan sudah dicairkan', '../notifikasi.php?target=pengajuan_dicairkan', 'ti-cash'],
                ['pengajuan_batal_cair', 'TC disetujui Admin — pengajuan Batal Cair', '../notifikasi.php?target=pengajuan_batal_cair', 'ti-ban'],
                ['tc_ditolak', 'TC ditolak Admin — siap dicairkan', '../notifikasi.php?target=tc_ditolak', 'ti-history'],
                ['pencairan', 'Pinjaman siap dicairkan', '../notifikasi.php?target=pencairan', 'ti-cash'],
                ['pembatalan', 'Keputusan pembatalan angsuran', '../notifikasi.php?target=pembatalan', 'ti-calendar-check'],
                ['pelunasan_disetujui', 'Pelunasan dipercepat disetujui', '../notifikasi.php?target=pelunasan_disetujui', 'ti-flag-check'],
                ['jaminan_disetujui', 'Jaminan siap dikembalikan', '../notifikasi.php?target=jaminan_disetujui', 'ti-package'],
                ['jatuh_tempo', 'Angsuran jatuh tempo dalam 7 hari', '../notifikasi.php?target=jatuh_tempo', 'ti-calendar-event'],
                ['terlambat', 'Angsuran melewati jatuh tempo', '../notifikasi.php?target=terlambat', 'ti-clock'],
                ['denda_tertunggak', 'Denda belum dibayar', '../notifikasi.php?target=denda_tertunggak', 'ti-alert-triangle'],
            ];
        } else {
            $headerNotifItems = [
                ['anggota_pengajuan', 'Pengajuan pinjaman sedang diproses', '../notifikasi.php?target=anggota_pengajuan', 'ti-file-text'],
                ['anggota_pencairan', 'Pinjaman telah disetujui', '../notifikasi.php?target=anggota_pencairan', 'ti-circle-check'],
                ['anggota_batal_cair', 'Pengajuan pinjaman batal cair (TC)', '../notifikasi.php?target=anggota_batal_cair', 'ti-ban'],
                ['anggota_jatuh_tempo', 'Angsuran jatuh tempo dalam 7 hari', '../notifikasi.php?target=anggota_jatuh_tempo', 'ti-calendar-event'],
                ['anggota_terlambat', 'Angsuran melewati jatuh tempo', '../notifikasi.php?target=anggota_terlambat', 'ti-clock'],
                ['anggota_denda', 'Tagihan denda belum lunas', '../notifikasi.php?target=anggota_denda', 'ti-alert-triangle'],
                ['anggota_jaminan', 'Status pengembalian barang AGTJ', '../notifikasi.php?target=anggota_jaminan', 'ti-package'],
            ];
        }
    } catch (Exception $e) {
        error_log('Gagal memuat notifikasi navbar: ' . $e->getMessage());
    }
}

// Fungsi getInitials sudah dideklarasikan di config.php

$faToTi = [
    'ti-file-pencil' => 'ti-file-pencil', 'ti-player-pause' => 'ti-player-pause', 'ti-calendar-off' => 'ti-calendar-off',
    'ti-eraser' => 'ti-eraser', 'ti-flag-check' => 'ti-flag-check', 'ti-package' => 'ti-package', 'ti-star' => 'ti-star',
    'ti-alert-triangle' => 'ti-alert-triangle', 'ti-receipt-2' => 'ti-receipt-2', 'ti-hourglass' => 'ti-hourglass',
    'ti-circle-check' => 'ti-circle-check', 'ti-circle-x' => 'ti-circle-x', 'ti-cash' => 'ti-cash',
    'ti-ban' => 'ti-ban', 'ti-history' => 'ti-history', 'ti-calendar-check' => 'ti-calendar-check',
    'ti-calendar-event' => 'ti-calendar-event', 'ti-clock' => 'ti-clock', 'ti-file-text' => 'ti-file-text',
];
$hdrRole = $_SESSION['role'] ?? '';
$hdrProfil = $hdrRole === 'admin' ? 'pengaturan.php' : 'profil.php';
$hdrPassword = $hdrRole === 'anggota' ? 'ganti_password.php' : ($hdrRole === 'teller' ? 'ubah_password.php' : null);
?>
<?php
$hdrNama = (string)($_SESSION['nama_lengkap'] ?? 'Pengguna');
$hdrInitials = getInitials($hdrNama);
$hdrRoleLabel = ucfirst((string)$hdrRole);
?>
<header class="navbar navbar-expand-md d-print-none sticky-top simkeu-topbar" aria-label="Navigasi aplikasi">
    <div class="container-fluid gap-2">
        <div class="simkeu-topbar-brand d-none d-md-flex">
            <span class="simkeu-topbar-brand-mark" aria-hidden="true">
                <?php if (!empty($logo_path)): ?>
                    <img src="<?= htmlspecialchars($logo_path) ?>" alt="" class="simkeu-topbar-logo">
                <?php else: ?>
                    <i class="ti ti-building-bank"></i>
                <?php endif; ?>
            </span>
            <span class="simkeu-topbar-brand-copy">
                <span class="simkeu-topbar-brand-name"><?= htmlspecialchars($koperasi['nama'] ?? APP_NAME) ?></span>
                <span class="simkeu-topbar-brand-sub">Ruang kerja koperasi</span>
            </span>
        </div>
        <div class="navbar-nav flex-row ms-auto align-items-center gap-1 gap-md-2">
            <?php if (in_array($hdrRole, ['admin', 'teller', 'anggota'], true)): ?>
            <div class="nav-item dropdown ui-notifications">
                <a href="#" class="simkeu-topbar-iconbtn nav-link position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" data-bs-display="static" aria-expanded="false" aria-label="Buka notifikasi" title="Notifikasi">
                    <i class="ti ti-bell" aria-hidden="true"></i>
                    <span data-notif-pinjaman="total" class="badge bg-red badge-notification" style="<?= empty($headerNotif['total']) ? 'display:none' : '' ?>"><?= (int)($headerNotif['total'] ?? 0) ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-card simkeu-notif-menu" role="menu" aria-label="Daftar notifikasi">
                    <div class="simkeu-notif-panel">
                        <div class="simkeu-notif-head">
                            <div class="simkeu-notif-head-copy min-w-0">
                                <div class="simkeu-notif-title"><?= $hdrRole === 'anggota' ? 'Status &amp; Tagihan' : 'Pekerjaan Perlu Tindakan' ?></div>
                                <div class="simkeu-notif-sub" data-notif-summary data-notif-role="<?= htmlspecialchars($hdrRole, ENT_QUOTES) ?>"><?= (int)($headerNotif['total'] ?? 0) ?> <?= $hdrRole === 'anggota' ? 'informasi baru' : 'perlu ditindaklanjuti' ?></div>
                            </div>
                            <span class="simkeu-notif-head-icon" aria-hidden="true"><i class="ti ti-bell-ringing"></i></span>
                        </div>
                        <div class="list-group list-group-flush list-group-hoverable simkeu-notif-list">
                            <?php foreach ($headerNotifItems as [$key, $label, $href, $icon]):
                                $count = (int)($headerNotif[$key] ?? 0);
                                $detailId = (int)($headerNotif['detail'][$key] ?? 0);
                                if ($detailId > 0) { $href .= (strpos($href, '?') === false ? '?' : '&') . 'id=' . $detailId; }
                                $tiIcon = $faToTi[$icon] ?? 'ti-bell';
                                ?>
                            <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>" class="list-group-item list-group-item-action simkeu-notif-item" role="menuitem" data-notif-row="<?= htmlspecialchars($key, ENT_QUOTES) ?>" data-notif-key="<?= htmlspecialchars($key, ENT_QUOTES) ?>" style="<?= $count < 1 ? 'display:none' : '' ?>">
                                <span class="simkeu-notif-row">
                                    <span class="avatar avatar-sm simkeu-notif-avatar" aria-hidden="true"><i class="ti <?= htmlspecialchars($tiIcon, ENT_QUOTES) ?>"></i></span>
                                    <span class="simkeu-notif-body">
                                        <span class="simkeu-notif-label"><?= htmlspecialchars($label) ?></span>
                                        <span class="simkeu-notif-meta"><?= $hdrRole === 'anggota' ? 'Lihat informasi status' : 'Perlu ditindaklanjuti' ?></span>
                                    </span>
                                    <span data-notif-pinjaman="<?= htmlspecialchars($key, ENT_QUOTES) ?>" class="badge simkeu-notif-count"><?= $count ?></span>
                                </span>
                            </a>
                            <?php endforeach; ?>
                            <p class="ui-notification-empty simkeu-notif-empty" <?= !empty($headerNotif['total']) ? 'hidden' : '' ?>>
                                <i class="ti ti-bell-off" aria-hidden="true"></i>
                                <span>Belum ada notifikasi baru.</span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="nav-item dropdown">
                <a href="#" class="simkeu-user-chip nav-link" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu pengguna">
                    <span class="simkeu-user-avatar"><?= htmlspecialchars($hdrInitials) ?></span>
                    <span class="simkeu-user-meta d-none d-xl-flex">
                        <span class="simkeu-user-name"><?= htmlspecialchars($hdrNama) ?></span>
                        <span class="simkeu-user-role"><?= htmlspecialchars($hdrRoleLabel) ?></span>
                    </span>
                    <i class="ti ti-chevron-down simkeu-user-caret d-none d-xl-inline" aria-hidden="true"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-end simkeu-user-menu">
                    <div class="simkeu-user-menu-head">
                        <span class="simkeu-user-avatar simkeu-user-avatar-lg"><?= htmlspecialchars($hdrInitials) ?></span>
                        <div class="min-w-0">
                            <div class="simkeu-user-name text-truncate"><?= htmlspecialchars($hdrNama) ?></div>
                            <div class="simkeu-user-role"><?= htmlspecialchars($hdrRoleLabel) ?></div>
                        </div>
                    </div>
                    <div class="simkeu-user-menu-body">
                        <a href="<?= $hdrProfil ?>" class="dropdown-item"><span class="simkeu-user-menu-ico"><i class="ti ti-user" aria-hidden="true"></i></span>Profil</a>
                        <?php if ($hdrPassword): ?>
                        <a href="<?= $hdrPassword ?>" class="dropdown-item"><span class="simkeu-user-menu-ico"><i class="ti ti-lock" aria-hidden="true"></i></span>Ubah password</a>
                        <?php endif; ?>
                    </div>
                    <div class="simkeu-user-menu-foot">
                        <a href="../logout.php" class="dropdown-item text-danger" onclick="return confirm('Apakah Anda yakin ingin logout?')"><span class="simkeu-user-menu-ico text-danger"><i class="ti ti-logout" aria-hidden="true"></i></span>Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
<?php
// Denda otomatis: dihitung ulang paling banyak sekali sehari saat petugas membuka halaman apa pun
if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'teller'], true)
    && function_exists('perbaruiDendaOtomatis')) {
    perbaruiDendaOtomatis($db);
}
if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'teller'], true)
    && function_exists('migrasiRevisiBelumDijalankan') && migrasiRevisiBelumDijalankan($db)): ?>
<div class="alert alert-danger mb-0 rounded-0 shadow position-fixed bottom-0 start-0 end-0" style="z-index:2000;">
    <i class="ti ti-database me-2"></i>
    <strong>Database belum di-update.</strong>
    Jalankan file <code>database/migrations/MIGRASI_UNIVERSAL.sql</code> sekali (lihat README) &mdash;
    tanpa ini fitur bunga flat, angsuran mingguan, dan dokumen anggota tidak berjalan.
</div>
<?php endif; ?>

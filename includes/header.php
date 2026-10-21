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
    
    // Tentukan path logo yang benar
    if (!empty($koperasi['logo'])) {
        // Coba path yang paling mungkin
        $possible_paths = [
            '../admin/uploads/logo/' . $koperasi['logo'],
            'admin/uploads/logo/' . $koperasi['logo'],
            '../uploads/logo/' . $koperasi['logo'],
            'uploads/logo/' . $koperasi['logo'],
            '../assets/uploads/logo/' . $koperasi['logo'],
            'assets/uploads/logo/' . $koperasi['logo']
        ];
        
        foreach ($possible_paths as $path) {
            $full_path = dirname(__FILE__) . '/../' . ltrim(str_replace('../', '', $path), '/');
            if (file_exists($full_path)) {
                $logo_path = $path;
                break;
            }
        }
        
        if (empty($logo_path)) {
            $logo_path = '../admin/uploads/logo/' . $koperasi['logo'];
        }
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
                ['persetujuan', 'Pengajuan perlu disetujui', '../notifikasi.php?target=persetujuan', 'fa-file-signature'],
                ['pembatalan', 'Pembatalan angsuran perlu diputuskan', '../notifikasi.php?target=pembatalan', 'fa-calendar-times'],
                ['hapus_denda', 'Pengajuan hapus denda', '../notifikasi.php?target=hapus_denda', 'fa-eraser'],
                ['pelunasan_dini', 'Pelunasan dipercepat', '../notifikasi.php?target=pelunasan_dini', 'fa-flag-checkered'],
                ['jaminan_kembali', 'Pengembalian jaminan', '../notifikasi.php?target=jaminan_kembali', 'fa-box-open'],
                ['prioritas', 'Pengajuan anggota prioritas', '../notifikasi.php?target=prioritas', 'fa-star'],
                ['laporan_macet', 'Laporan pinjaman macet', '../notifikasi.php?target=laporan_macet', 'fa-triangle-exclamation'],
                ['pembayaran_denda', 'Pembayaran denda perlu ditinjau', '../notifikasi.php?target=pembayaran_denda', 'fa-receipt'],
            ];
        } elseif ($_SESSION['role'] === 'teller') {
            $headerNotifItems = [
                ['pencairan', 'Pinjaman siap dicairkan', '../notifikasi.php?target=pencairan', 'fa-money-bill-wave'],
                ['pembatalan', 'Keputusan pembatalan angsuran', '../notifikasi.php?target=pembatalan', 'fa-calendar-check'],
                ['pelunasan_disetujui', 'Pelunasan dipercepat disetujui', '../notifikasi.php?target=pelunasan_disetujui', 'fa-flag-checkered'],
                ['jaminan_disetujui', 'Jaminan siap dikembalikan', '../notifikasi.php?target=jaminan_disetujui', 'fa-box-open'],
                ['jatuh_tempo', 'Angsuran jatuh tempo dalam 7 hari', '../notifikasi.php?target=jatuh_tempo', 'fa-calendar-day'],
                ['terlambat', 'Angsuran melewati jatuh tempo', '../notifikasi.php?target=terlambat', 'fa-clock'],
                ['denda_tertunggak', 'Denda belum dibayar', '../notifikasi.php?target=denda_tertunggak', 'fa-triangle-exclamation'],
            ];
        } else {
            $headerNotifItems = [
                ['anggota_pengajuan', 'Pengajuan pinjaman sedang diproses', '../notifikasi.php?target=anggota_pengajuan', 'fa-file-alt'],
                ['anggota_pencairan', 'Pinjaman telah disetujui', '../notifikasi.php?target=anggota_pencairan', 'fa-circle-check'],
                ['anggota_jatuh_tempo', 'Angsuran jatuh tempo dalam 7 hari', '../notifikasi.php?target=anggota_jatuh_tempo', 'fa-calendar-day'],
                ['anggota_terlambat', 'Angsuran melewati jatuh tempo', '../notifikasi.php?target=anggota_terlambat', 'fa-clock'],
                ['anggota_denda', 'Tagihan denda belum lunas', '../notifikasi.php?target=anggota_denda', 'fa-triangle-exclamation'],
                ['anggota_jaminan', 'Status pengembalian barang AGTJ', '../notifikasi.php?target=anggota_jaminan', 'fa-box-open'],
            ];
        }
    } catch (Exception $e) {
        error_log('Gagal memuat notifikasi navbar: ' . $e->getMessage());
    }
}

// Fungsi getInitials sudah dideklarasikan di config.php
?>
<a class="ui-skip" href="#konten-utama">Lewati ke konten utama</a>
<nav class="navbar fixed-top ui-topbar" aria-label="Navigasi aplikasi">
    <div class="ui-brand-group">
        <button class="navbar-toggler ui-menu-button" type="button" aria-controls="sidebarMenu" aria-expanded="true" aria-label="Tutup navigasi"><i class="fas fa-bars" aria-hidden="true"></i></button>
        <a class="navbar-brand ui-brand" href="dashboard.php"><?= brandLogo($koperasi['logo'] ?? '', '../') ?><span class="ui-brand-copy"><?php echo htmlspecialchars($koperasi['nama']); ?><small>Ruang kerja koperasi</small></span></a>
    </div>
    <div class="ui-topbar-actions">
        <?php if(in_array($_SESSION['role'] ?? '', ['admin', 'teller', 'anggota'], true)): ?>
        <details class="ui-notifications <?= !empty($headerNotif['total'])?'has-notifications':'' ?>">
            <summary aria-label="Buka notifikasi" title="Notifikasi">
                <i class="fas fa-bell" aria-hidden="true"></i>
                <span class="ui-notification-label">Notifikasi</span>
                <span data-notif-pinjaman="total" class="badge rounded-pill bg-danger ui-notification-count" style="<?= empty($headerNotif['total'])?'display:none':'' ?>"><?= (int)($headerNotif['total'] ?? 0) ?></span>
            </summary>
            <div class="ui-notification-menu">
                <div class="ui-notification-heading"><strong><?= ($_SESSION['role'] ?? '') === 'anggota' ? 'Status & Tagihan' : 'Pekerjaan Perlu Tindakan' ?></strong><span data-notif-summary data-notif-role="<?= htmlspecialchars($_SESSION['role'] ?? '', ENT_QUOTES) ?>"><?= (int)($headerNotif['total'] ?? 0) ?> <?= ($_SESSION['role'] ?? '') === 'anggota' ? 'informasi baru' : 'perlu ditindaklanjuti' ?></span></div>
                <div class="ui-notification-list">
                    <?php foreach($headerNotifItems as [$key,$label,$href,$icon]): $count=(int)($headerNotif[$key] ?? 0); $detailId=(int)($headerNotif['detail'][$key] ?? 0); if($detailId>0) $href .= (strpos($href,'?')===false?'?':'&').'id='.$detailId; ?>
                    <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>" data-notif-row="<?= htmlspecialchars($key, ENT_QUOTES) ?>" data-notif-key="<?= htmlspecialchars($key, ENT_QUOTES) ?>" style="<?= $count<1?'display:none':'' ?>">
                        <span class="ui-notification-icon"><i class="fas <?= htmlspecialchars($icon, ENT_QUOTES) ?>" aria-hidden="true"></i></span><span class="ui-notification-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= ($_SESSION['role'] ?? '') === 'anggota' ? 'Lihat informasi status' : 'Perlu ditindaklanjuti' ?></small></span>
                        <span data-notif-pinjaman="<?= htmlspecialchars($key, ENT_QUOTES) ?>" class="badge rounded-pill bg-warning text-dark"><?= $count ?></span>
                    </a>
                    <?php endforeach; ?>
                    <p class="ui-notification-empty" <?= !empty($headerNotif['total'])?'hidden':'' ?>>Belum ada notifikasi baru.</p>
                </div>
            </div>
        </details>
        <?php endif; ?>
        <div class="ui-account-static"><span class="ui-user-icon" aria-hidden="true"><?= htmlspecialchars(getInitials($_SESSION['nama_lengkap'] ?? 'U')) ?></span><span class="ui-user-copy"><span class="ui-user-name"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Pengguna') ?></span><small><?= htmlspecialchars(ucfirst($_SESSION['role'] ?? '')) ?></small></span></div>
    </div>
</nav>
<?php
// Denda otomatis: dihitung ulang paling banyak sekali sehari saat petugas membuka halaman apa pun
if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'teller'], true)
    && function_exists('perbaruiDendaOtomatis')) {
    perbaruiDendaOtomatis($db);
}
?>
<?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'teller'], true)
          && function_exists('migrasiRevisiBelumDijalankan') && migrasiRevisiBelumDijalankan($db)): ?>
<div class="alert alert-danger mb-0 rounded-0 shadow" style="position:fixed;left:0;right:0;bottom:0;z-index:2000;">
    <i class="fas fa-database me-2"></i>
    <strong>Database belum di-update.</strong>
    Jalankan file <code>database/migrations/MIGRASI_UNIVERSAL.sql</code> sekali (lihat README) &mdash;
    tanpa ini fitur bunga flat, angsuran mingguan, dan dokumen anggota tidak berjalan.
</div>
<?php endif; ?>

<link rel="stylesheet" href="../assets/css/app-ui.css?v=20261022-1">
<script>document.body.classList.add('ui-shell');</script>
<script src="../assets/js/pilihan-foto.js?v=20261021-3" defer></script>
<link rel="stylesheet" href="../assets/css/upload-foto.css?v=20261021-1">
<link rel="stylesheet" href="../assets/css/tanda-tangan.css?v=20261021-1">
<script src="../assets/js/brand.js?v=20261019-6" defer></script>
<script src="../assets/js/app-ui.js?v=20261022-1" defer></script>

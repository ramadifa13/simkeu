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

// Fungsi getInitials sudah dideklarasikan di config.php
?>
<a class="ui-skip" href="#konten-utama">Lewati ke konten utama</a>
<nav class="navbar fixed-top ui-topbar" aria-label="Navigasi aplikasi">
    <div class="ui-brand-group">
        <button class="navbar-toggler ui-menu-button" type="button" aria-controls="sidebarMenu" aria-expanded="true" aria-label="Tutup navigasi"><i class="fas fa-bars" aria-hidden="true"></i></button>
        <a class="navbar-brand ui-brand" href="dashboard.php"><?= brandLogo($koperasi['logo'] ?? '', '../') ?><span class="ui-brand-copy"><?php echo htmlspecialchars($koperasi['nama']); ?><small>Ruang kerja koperasi</small></span></a>
    </div>
    <div class="ui-account-static"><span class="ui-user-icon" aria-hidden="true"><?= htmlspecialchars(getInitials($_SESSION['nama_lengkap'] ?? 'U')) ?></span><span class="ui-user-copy"><span class="ui-user-name"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Pengguna') ?></span><small><?= htmlspecialchars(ucfirst($_SESSION['role'] ?? '')) ?></small></span></div>
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

<link rel="stylesheet" href="../assets/css/app-ui.css?v=20261019-9">
<script>document.body.classList.add('ui-shell');</script>
<script src="../assets/js/pilihan-foto.js?v=1" defer></script>
<script src="../assets/js/brand.js?v=20261019-6" defer></script>
<script src="../assets/js/app-ui.js?v=20261019-9" defer></script>

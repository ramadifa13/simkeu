<?php
if (!isset($db)) {
    require_once '../config/config.php';
}

// Ambil data koperasi dari database untuk logo
$koperasi = [];
$logo_path = '';

try {
    require_once '../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
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
<nav class="navbar navbar-dark fixed-top flex-md-nowrap p-0 shadow" 
     style="background: linear-gradient(135deg, #2c3e50, #34495e) !important; min-height: 60px;">
    
    <!-- Mobile Toggle -->
    <button class="navbar-toggler position-absolute d-md-none collapsed border-0" type="button" 
            data-bs-toggle="collapse" data-bs-target="#sidebarMenu" 
            aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation"
            style="padding: 0.75rem 1rem; left: 0; top: 50%; transform: translateY(-50%);">
        <span class="navbar-toggler-icon"></span>
    </button>
    
    <!-- Brand -->
    <a class="navbar-brand col-md-3 col-lg-2 me-0 px-3 d-flex align-items-center justify-content-center justify-content-md-start" 
       href="dashboard.php"
       style="font-size: 1.1rem; font-weight: 500; letter-spacing: 0.3px;">
        <?php if (!empty($logo_path)): ?>
            <img src="<?php echo $logo_path; ?>" 
                 alt="Logo" 
                 class="rounded-circle me-2"
                 style="width: 32px; height: 32px; object-fit: cover; border: 2px solid rgba(255,255,255,0.2);">
        <?php else: ?>
            <i class="fas fa-hand-holding-usd me-2"></i>
        <?php endif; ?>
        <span class="d-none d-md-inline"><?php echo htmlspecialchars($koperasi['nama']); ?></span>
        <span class="d-md-none">Koperasi</span>
    </a>

    <!-- HAPUS BAGIAN USER PROFILE DI POJOK KANAN -->
    <!-- Tidak ada dropdown user profile -->

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
    Jalankan file <code>database/migrations/2026_10_revisi_koperasi.sql</code> sekali (lihat README) &mdash;
    tanpa ini fitur bunga flat, angsuran mingguan, dan dokumen anggota tidak berjalan.
</div>
<?php endif; ?>

<style>
.navbar {
    min-height: 60px;
    padding: 0;
    z-index: 1030;
    transition: all 0.3s ease;
}

.navbar-brand {
    font-size: 1.1rem;
    font-weight: 500;
    padding: 0.5rem 1rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 280px;
    transition: all 0.3s ease;
    height: 60px;
    line-height: 44px;
}

.navbar-brand:hover {
    opacity: 0.9;
    background: rgba(255,255,255,0.05);
}

@media (max-width: 767.98px) {
    .navbar-brand {
        max-width: calc(100% - 100px);
        margin-left: 50px;
        justify-content: flex-start !important;
        padding-left: 0;
    }
    
    .navbar-brand img {
        margin-left: 0;
    }
}

.navbar-toggler {
    padding: 0.6rem 0.8rem;
    font-size: 1rem;
    border: none;
    outline: none !important;
    box-shadow: none !important;
    z-index: 1031;
}

.navbar-toggler:focus {
    outline: none;
    box-shadow: none;
}

.navbar-toggler-icon {
    width: 1.2em;
    height: 1.2em;
}

/* Untuk mengompensasi fixed navbar */
body {
    padding-top: 60px;
}

/* Sidebar styling untuk mobile */
@media (max-width: 767.98px) {
    #sidebarMenu {
        position: fixed;
        top: 60px;
        bottom: 0;
        left: -280px;
        width: 280px;
        transition: left 0.3s ease;
        z-index: 1020;
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    }
    
    #sidebarMenu.show {
        left: 0;
    }
    
    .sidebar-overlay {
        position: fixed;
        top: 60px;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 1015;
        display: none;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .sidebar-overlay.show {
        display: block;
        opacity: 1;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle sidebar toggle for mobile
    const toggler = document.querySelector('.navbar-toggler');
    const sidebar = document.getElementById('sidebarMenu');
    
    if (toggler && sidebar) {
        // Create overlay if not exists
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);
        }
        
        toggler.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });
        
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }
});
</script>
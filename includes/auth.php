<?php
require_once __DIR__ . '/../config/bootstrap.php';

/**
 * PERBAIKAN: Deteksi path config.php yang benar berdasarkan lokasi file
 */
function getConfigPath() {
    $current_dir = __DIR__; // Directory saat ini (includes/)
    
    // Cek apakah config.php ada di parent directory
    $parent_config = $current_dir . '/../config/config.php';
    if (file_exists($parent_config)) {
        return $parent_config;
    }
    
    // Cek apakah config.php ada di current directory (fallback)
    $current_config = $current_dir . '/config.php';
    if (file_exists($current_config)) {
        return $current_config;
    }
    
    // Default path
    return '../config/config.php';
}

// Include config dengan path yang benar
require_once getConfigPath();

// Include functions
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/permissions.php';

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'admin';
}

/**
 * Check if user is teller
 */
function isTeller() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'teller';
}

/**
 * Check if user is anggota
 */
function isAnggota() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'anggota';
}

/**
 * Redirect user based on role - PERBAIKAN: Gunakan path yang benar
 */
function redirectBasedOnRole() {
    if (isLoggedIn()) {
        $role = $_SESSION['role'];
        // PERBAIKAN: Gunakan path yang benar tanpa duplikasi
        header("Location: " . $role . "/dashboard.php");
        exit();
    } else {
        header("Location: login.php");
        exit();
    }
}

/**
 * Check authentication and redirect if not logged in
 */
function checkAuth() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
}

/**
 * Check role and redirect if not authorized
 */
function checkRole($required_role) {
    checkAuth();
    
    if ($_SESSION['role'] !== $required_role) {
        // PERBAIKAN: Redirect ke dashboard role yang sesuai
        header("Location: " . $_SESSION['role'] . "/dashboard.php");
        exit();
    }
}

/**
 * Check admin access
 */
function checkAdmin() {
    checkRole('admin');
}

/**
 * Check teller access
 */
function checkTeller() {
    checkRole('teller');
}

/**
 * Check anggota access
 */
function checkAnggota() {
    global $db;
    if (isLoggedIn() && isAnggota() && $db) {
        $stmt = $db->prepare("SELECT status FROM users WHERE id = ? AND role = 'anggota'");
        $stmt->execute([$_SESSION['user_id']]);
        if ($stmt->fetchColumn() !== 'aktif') {
            session_unset();
            $_SESSION['error'] = 'Akun anggota nonaktif karena pinjaman telah lunas. Ajukan pinjaman baru melalui Teller untuk mengaktifkan kembali akun.';
            header('Location: ../login.php');
            exit();
        }
    }
    checkRole('anggota');
}

/**
 * Get user dashboard URL based on role - PERBAIKAN: Path yang benar
 */
function getUserDashboardUrl() {
    if (isLoggedIn()) {
        return $_SESSION['role'] . "/dashboard.php";
    }
    return "login.php";
}

// Auto-check authentication for protected pages
// checkAuth();

// Enforce permissions before a protected page can read or mutate application data.
// CLI regression tests call the domain helpers directly and are intentionally separate.
if (PHP_SAPI !== 'cli') {
    $authScript=str_replace('\\','/',realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
    $authRoot=str_replace('\\','/',realpath(__DIR__.'/..'));
    $authArea=null;
    foreach(['admin','teller','anggota','transaksi'] as $area) {
        if(stripos($authScript,$authRoot.'/'.$area.'/')===0){$authArea=$area;break;}
    }
    if($authArea!==null) {
        if(!isLoggedIn()) { header('Location: ../login.php');exit; }
        $authAllowed=$authArea==='admin'?isAdmin():($authArea==='anggota'?isAnggota():(isAdmin()||isTeller()));
        if(!$authAllowed){http_response_code(403);exit('Akses ditolak. Halaman ini tidak tersedia untuk peran Anda.');}
        $authUser=$db->prepare('SELECT username,nama_lengkap,role,status FROM users WHERE id=?');$authUser->execute([$_SESSION['user_id']]);$authAccount=$authUser->fetch(PDO::FETCH_ASSOC);
        if(!$authAccount || $authAccount['status']!=='aktif' || $authAccount['role']!==($_SESSION['role'] ?? '')) {
            $_SESSION=[];session_regenerate_id(true);$_SESSION['error']='Sesi akun tidak aktif. Silakan masuk kembali.';
            header('Location: ../login.php');exit;
        }
        // Nama akun dapat diperbarui oleh Admin/Teller saat anggota sedang login.
        // Segarkan session agar header dan halaman anggota langsung memakai data terbaru.
        $_SESSION['username']=$authAccount['username'];
        $_SESSION['nama_lengkap']=$authAccount['nama_lengkap'];
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
            // Upload > post_max_size: PHP mengosongkan $_POST (termasuk csrf).
            // Tangani dulu agar tidak terlihat seperti "formulir kedaluwarsa".
            if (function_exists('redirectJikaPostMelebihiBatas')) {
                redirectJikaPostMelebihiBatas();
            }
            try { layananValidasiToken(); }
            catch(Exception $e) {
                $_SESSION['error'] = 'Formulir kedaluwarsa atau tidak valid. Muat ulang halaman sebelum mencoba kembali.';
                $back = $_SERVER['HTTP_REFERER'] ?? '';
                if ($back !== '' && stripos($back, ($_SERVER['HTTP_HOST'] ?? '')) !== false) {
                    header('Location: ' . $back);
                    exit;
                }
                http_response_code(403);
                exit('Formulir kedaluwarsa atau tidak valid. Muat ulang halaman sebelum mencoba kembali.');
            }
        }
    }
}
?>

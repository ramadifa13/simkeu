<?php
// Pastikan session sudah started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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
            header('Location: ../login.php?role=anggota');
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
?>

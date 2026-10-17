<?php
// Cek apakah session sudah started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

define('APP_NAME', 'Koperasi App');
define('APP_VERSION', '1.0');

// Seluruh jadwal angsuran dan denda mengikuti waktu operasional Indonesia Barat.
// Hosting sering memakai UTC sehingga angsuran Sabtu baru dianggap telat beberapa jam kemudian.
date_default_timezone_set('Asia/Jakarta');

// Base URL dengan path yang benar
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$script_dir = dirname($_SERVER['SCRIPT_NAME']);

// Pastikan path berakhir dengan slash
$base_path = $script_dir === '/' ? '/' : $script_dir . '/';
$base_url = $protocol . "://" . $host . $base_path;

define('BASE_URL', $base_url);

// Include database
require_once 'database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    // Aplikasi ini dibuat untuk MySQL non-strict. MariaDB/MySQL bawaan XAMPP memakai
    // STRICT_TRANS_TABLES yang membuat sebagian INSERT gagal (tanggal kosong, teks terlalu
    // panjang) - dan karena PDO dalam mode silent, gagalnya tidak terlihat. Samakan mode per koneksi.
    if ($db) {
        $db->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        $db->exec("SET time_zone = '+07:00'");
    }
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Functions - TAMBAHKAN kondisi cek function
if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: " . $url);
        exit();
    }
}

if (!function_exists('formatRupiah')) {
    function formatRupiah($angka) {
        if ($angka == 0) return 'Rp 0';
        return 'Rp ' . number_format($angka, 0, ',', '.');
    }
}

if (!function_exists('getInitials')) {
    function getInitials($name) {
        $words = explode(' ', $name);
        $initials = '';
        foreach ($words as $word) {
            if (!empty(trim($word))) {
                $initials .= strtoupper($word[0]);
            }
        }
        return substr($initials, 0, 2);
    }
}
?>

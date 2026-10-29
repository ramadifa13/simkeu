<?php
require_once __DIR__ . '/bootstrap.php';

define('APP_NAME', 'Koperasi App');
define('APP_VERSION', '1.0');

// Seluruh jadwal angsuran dan denda mengikuti waktu operasional Indonesia Barat.
// Hosting sering memakai UTC sehingga angsuran Sabtu baru dianggap telat beberapa jam kemudian.
date_default_timezone_set('Asia/Jakarta');

// Gunakan APP_URL bila disediakan. HTTP_HOST tidak boleh dipercaya sebagai URL
// aplikasi karena dapat dipengaruhi Host header dari request.
$configuredUrl = trim((string)(getenv('APP_URL') ?: ''));
if ($configuredUrl !== '') {
    $base_url = rtrim($configuredUrl, '/') . '/';
} else {
    $scriptDir = dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $basePath = $scriptDir === DIRECTORY_SEPARATOR || $scriptDir === '.' ? '/' : str_replace('\\', '/', rtrim($scriptDir, '/\\')) . '/';
    $base_url = $basePath;
}

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
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('Layanan sedang tidak tersedia. Silakan coba lagi beberapa saat lagi.');
}

// Functions - TAMBAHKAN kondisi cek function
if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: " . $url);
        exit();
    }
}

// formatRupiah SSOT: config/functions.php (loaded via auth.php / explicit require)

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

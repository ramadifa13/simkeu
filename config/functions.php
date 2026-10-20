<?php
// config/functions.php

require_once __DIR__ . '/../includes/pinjaman_helper.php';
require_once __DIR__ . '/../includes/dokumen_anggota.php';
require_once __DIR__ . '/../includes/anggota_helper.php';
require_once __DIR__ . '/../includes/upload_foto.php';
require_once __DIR__ . '/../includes/akun_helper.php';
require_once __DIR__ . '/../includes/denda_helper.php';
require_once __DIR__ . '/../includes/angsuran_helper.php';
require_once __DIR__ . '/../includes/layanan_helper.php';
require_once __DIR__ . '/../includes/layanan_lanjutan.php';
require_once __DIR__ . '/../includes/perjanjian_helper.php';

// TAMBAHKAN kondisi cek function untuk semua function

if (!function_exists('formatRupiah')) {
    function formatRupiah($angka) {
        if ($angka == 0) return 'Rp 0';
        return 'Rp ' . number_format($angka, 0, ',', '.');
    }
}

if (!function_exists('base_redirect')) {
    function base_redirect($path) {
        header("Location: " . BASE_URL . $path);
        exit();
    }
}

if (!function_exists('sanitize')) {
    function sanitize($data) {
        if (!is_scalar($data)) return '';
        return htmlspecialchars(strip_tags(trim((string)$data)), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('getCurrentDateTime')) {
    function getCurrentDateTime() {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('formatDateIndonesian')) {
    function formatDateIndonesian($date) {
        $months = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
            '04' => 'April', '05' => 'Mei', '06' => 'Juni',
            '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
            '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        
        $date_parts = explode('-', (string)$date);
        if (count($date_parts) === 3 && isset($months[$date_parts[1]]) && checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
            return $date_parts[2] . ' ' . $months[$date_parts[1]] . ' ' . $date_parts[0];
        }
        return $date;
    }
}
?>

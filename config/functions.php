<?php
// config/functions.php

require_once __DIR__ . '/../includes/pinjaman_helper.php';
require_once __DIR__ . '/../includes/upload_foto.php';
require_once __DIR__ . '/../includes/dokumen_anggota.php';
require_once __DIR__ . '/../includes/anggota_helper.php';
require_once __DIR__ . '/../includes/akun_helper.php';
require_once __DIR__ . '/../includes/denda_helper.php';
require_once __DIR__ . '/../includes/angsuran_helper.php';
require_once __DIR__ . '/../includes/layanan_helper.php';
require_once __DIR__ . '/../includes/layanan_lanjutan.php';
require_once __DIR__ . '/../includes/perjanjian_helper.php';
require_once __DIR__ . '/../includes/closing_helper.php';
require_once __DIR__ . '/../includes/schema_helper.php';
require_once __DIR__ . '/../includes/dashboard_helper.php';
require_once __DIR__ . '/../includes/ui/helpers.php';
require_once __DIR__ . '/../includes/ui/riwayat_list.php';
require_once __DIR__ . '/../includes/menu.php';

// TAMBAHKAN kondisi cek function untuk semua function

if (!function_exists('formatRupiah')) {
    /**
     * Format angka ke rupiah (SSOT).
     * Parameter $simple dipertahankan untuk kompatibilitas pemanggilan lama;
     * output selalu memakai prefix "Rp " (perilaku dashboard sebelumnya).
     */
    function formatRupiah($angka, $simple = false) {
        unset($simple);
        if ((float)$angka == 0.0) {
            return 'Rp 0';
        }
        return 'Rp ' . number_format((float)$angka, 0, ',', '.');
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
    /** Format tanggal tampilan: dd MMMM yyyy (contoh 29 Oktober 2026). */
    function formatDateIndonesian($date, bool $short = false) {
        $months = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
            '04' => 'April', '05' => 'Mei', '06' => 'Juni',
            '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
            '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        $monthsShort = [
            '01' => 'Jan', '02' => 'Feb', '03' => 'Mar',
            '04' => 'Apr', '05' => 'Mei', '06' => 'Jun',
            '07' => 'Jul', '08' => 'Agu', '09' => 'Sep',
            '10' => 'Okt', '11' => 'Nov', '12' => 'Des'
        ];
        $raw = trim((string)$date);
        if ($raw === '' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
            return '-';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }
        $ymd = date('Y-m-d', $ts);
        $date_parts = explode('-', $ymd);
        $map = $short ? $monthsShort : $months;
        if (count($date_parts) === 3 && isset($map[$date_parts[1]]) && checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
            return ((int)$date_parts[2]) . ' ' . $map[$date_parts[1]] . ' ' . $date_parts[0];
        }
        return $raw;
    }
}

if (!function_exists('formatTanggalUi')) {
    /**
     * Tanggal UI standar: dd MMMM yyyy; opsional + jam HH:ii; $short = dd MMM yyyy.
     * Untuk layar aplikasi saja (bukan cetak/PDF/export kecuali dipanggil sengaja).
     */
    function formatTanggalUi($date, bool $withTime = false, bool $short = false): string {
        $raw = trim((string)$date);
        if ($raw === '' || $raw === '-' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
            return '-';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }
        $out = formatDateIndonesian(date('Y-m-d', $ts), $short);
        if ($withTime) {
            $out .= ' ' . date('H:i', $ts);
        }
        return $out;
    }
}
?>

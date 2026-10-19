<?php
/**
 * Bootstrap paling awal untuk seluruh request aplikasi.
 * Simpan sesi di dalam aplikasi agar login tidak bergantung pada izin C:\xampp\tmp.
 */
if (!defined('SIMKEU_BOOTSTRAPPED')) {
    define('SIMKEU_BOOTSTRAPPED', true);

    $sessionDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0770, true) && !is_dir($sessionDirectory)) {
        throw new RuntimeException('Folder sesi aplikasi tidak dapat dibuat.');
    }
    if (!is_writable($sessionDirectory)) {
        throw new RuntimeException('Folder sesi aplikasi tidak dapat ditulis.');
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_save_path($sessionDirectory);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_start();
    }
}

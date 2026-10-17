<?php
/**
 * Pengelolaan akun pengguna sendiri (ubah password, profil).
 * Hash password memakai password_hash()/password_verify() (semua data sudah bcrypt).
 */

if (!defined('PASSWORD_MIN_PANJANG')) {
    define('PASSWORD_MIN_PANJANG', 8);
}

if (!function_exists('ubahPasswordPengguna')) {
    /**
     * Ubah password milik $user_id (hanya diri sendiri; panggil dengan $_SESSION['user_id']).
     * @throws Exception dengan pesan untuk pengguna bila tidak valid
     */
    function ubahPasswordPengguna($db, $user_id, $lama, $baru, $konfirmasi) {
        if ($lama === '' || $baru === '' || $konfirmasi === '') {
            throw new Exception("Semua field password wajib diisi");
        }
        if (strlen($baru) < PASSWORD_MIN_PANJANG) {
            throw new Exception("Password baru minimal " . PASSWORD_MIN_PANJANG . " karakter");
        }
        if ($baru !== $konfirmasi) {
            throw new Exception("Konfirmasi password tidak sesuai");
        }
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($lama, $hash)) {
            throw new Exception("Password lama tidak sesuai");
        }
        if ($lama === $baru) {
            throw new Exception("Password baru harus berbeda dari password lama");
        }
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        if (!$stmt->execute([password_hash($baru, PASSWORD_DEFAULT), $user_id])) {
            throw new Exception("Gagal menyimpan password baru");
        }
    }
}

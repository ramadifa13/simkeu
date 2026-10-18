<?php
/**
 * Upload foto tunggal (foto nasabah saat pencairan, foto profil teller).
 * Tipe divalidasi dengan finfo (bukan ekstensi), nama file acak.
 * Folder tujuan: uploads/{subfolder}/ (eksekusi script diblokir .htaccess di folder itu).
 */

if (!function_exists('validasiFotoUpload')) {
    /**
     * @return array|null ['tmp' => path, 'ext' => ekstensi] atau null bila tidak ada file
     * @throws Exception bila tidak valid, atau bila $wajib dan tidak ada file
     */
    function validasiFotoUpload($field, $label, $maks_byte, $wajib = false) {
        $file = isset($_FILES[$field]) ? $_FILES[$field] : null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            if ($wajib) {
                throw new Exception("$label wajib diunggah");
            }
            return null;
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new Exception("$label melebihi batas ukuran server (" . ini_get('upload_max_filesize') . ")");
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception("$label gagal diunggah (kode error " . (int) $file['error'] . ")");
        }
        if ($file['size'] > $maks_byte) {
            throw new Exception("$label melebihi " . round($maks_byte / 1048576) . " MB");
        }
        if (!class_exists('finfo')) {
            throw new Exception("Ekstensi PHP fileinfo belum aktif di server; hubungi administrator");
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $diizinkan = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($diizinkan[$mime])) {
            throw new Exception("$label harus berupa gambar JPG, PNG, atau WEBP");
        }
        return ['tmp' => $file['tmp_name'], 'ext' => $diizinkan[$mime]];
    }
}

if (!function_exists('simpanFotoUpload')) {
    /** Pindahkan file tervalidasi ke uploads/$subfolder/ dan kembalikan path relatif terhadap uploads/. */
    function simpanFotoUpload($info, $subfolder) {
        $root = realpath(__DIR__ . '/..') . '/uploads';
        $dir = $root . '/' . $subfolder;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new Exception("Folder upload tidak dapat dibuat");
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess) && file_exists($root . '/anggota/.htaccess')) {
            @copy($root . '/anggota/.htaccess', $htaccess);
        }
        $nama = bin2hex(random_bytes(16)) . '.' . $info['ext'];
        if (!move_uploaded_file($info['tmp'], $dir . '/' . $nama)) {
            throw new Exception("Gagal menyimpan file foto");
        }
        return $subfolder . '/' . $nama;
    }
}

if (!function_exists('validasiBanyakFotoUpload')) {
    /** Validasi input file multiple dan kembalikan daftar dalam format simpanFotoUpload(). */
    function validasiBanyakFotoUpload($field, $label, $maks_byte, $maks_jumlah = 10) {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) return [];
        $hasil = [];
        $jumlah = count($_FILES[$field]['name']);
        if ($jumlah > $maks_jumlah) throw new Exception("$label maksimal $maks_jumlah halaman");
        for ($i = 0; $i < $jumlah; $i++) {
            if ($_FILES[$field]['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $tmpField = '__multi_' . $field;
            $_FILES[$tmpField] = [
                'name' => $_FILES[$field]['name'][$i], 'type' => $_FILES[$field]['type'][$i],
                'tmp_name' => $_FILES[$field]['tmp_name'][$i], 'error' => $_FILES[$field]['error'][$i],
                'size' => $_FILES[$field]['size'][$i]
            ];
            try { $hasil[] = validasiFotoUpload($tmpField, "$label halaman " . ($i + 1), $maks_byte, false); }
            finally { unset($_FILES[$tmpField]); }
        }
        return $hasil;
    }
}

if (!function_exists('hapusFotoUpload')) {
    function hapusFotoUpload($path) {
        if (empty($path) || strpos($path, '..') !== false) {
            return;
        }
        $file = realpath(realpath(__DIR__ . '/..') . '/uploads/' . $path);
        $root = realpath(__DIR__ . '/../uploads');
        if ($file && $root && strpos($file, $root . DIRECTORY_SEPARATOR) === 0) {
            @unlink($file);
        }
    }
}

if (!function_exists('urlFoto')) {
    /** URL relatif dari folder admin/teller/anggota ke uploads/. Kosong bila tidak ada. */
    function urlFoto($path) {
        return empty($path) ? '' : '../uploads/' . $path;
    }
}

if (!function_exists('fotoNasabahAnggota')) {
    /** Foto pinjaman berjalan pertama; bila semuanya lunas, gunakan pencairan terakhir. */
    function fotoNasabahAnggota($db, $anggota_id) {
        if (!kolomAda($db, 'pinjaman', 'foto_pencairan')) {
            return '';
        }
        // Satu pinjaman berjalan memakai fotonya sendiri. Jika ada dua,
        // pencairan kedua tidak mengganti foto dari pinjaman berjalan pertama.
        $stmt = $db->prepare("SELECT foto_pencairan FROM pinjaman
                              WHERE anggota_id = ? AND status <> 'lunas'
                              ORDER BY tanggal_pencairan ASC, id ASC LIMIT 1");
        $stmt->execute([$anggota_id]);
        $berjalan = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($berjalan !== false) {
            return (string) ($berjalan['foto_pencairan'] ?? '');
        }
        $stmt = $db->prepare("SELECT foto_pencairan FROM pinjaman
                              WHERE anggota_id = ? AND foto_pencairan IS NOT NULL AND foto_pencairan <> ''
                              ORDER BY tanggal_pencairan DESC, id DESC LIMIT 1");
        $stmt->execute([$anggota_id]);
        return (string) $stmt->fetchColumn();
    }
}

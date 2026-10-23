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
        // Kebijakan global: tidak ada gambar yang diterima aplikasi lebih besar dari 1 MB.
        // Parameter lama dipertahankan agar seluruh pemanggil lama tetap kompatibel.
        $maks_byte = min((int) $maks_byte, 1024 * 1024);
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

if (!function_exists('kompresDanSimpanGambar')) {
    /**
     * Kompres dan ubah ukuran gambar otomatis sebelum disimpan.
     * Mengurangi ukuran file 80-90% tanpa mengurangi keterbacaan teks dokumen.
     *
     * @param string $sourcePath Path file sementara (tmp_name)
     * @param string $destPath Path file tujuan lengkap
     * @param string $ext Ekstensi target ('jpg', 'jpeg', 'png', 'webp')
     * @param int $maxDimension Dimensi maksimal lebar/tinggi (default 1280px)
     * @param int $quality Kualitas kompresi JPG/WebP (default 82)
     * @return bool
     */
    function kompresDanSimpanGambar($sourcePath, $destPath, $ext, $maxDimension = 1280, $quality = 82) {
        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            return move_uploaded_file($sourcePath, $destPath);
        }

        $raw = @file_get_contents($sourcePath);
        if ($raw === false) {
            return move_uploaded_file($sourcePath, $destPath);
        }

        $src = @imagecreatefromstring($raw);
        if (!$src) {
            return move_uploaded_file($sourcePath, $destPath);
        }

        // Perbaiki orientasi EXIF untuk kamera HP (JPG)
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath);
            if (!empty($exif['Orientation'])) {
                switch ((int) $exif['Orientation']) {
                    case 3:
                        $rot = imagerotate($src, 180, 0);
                        if ($rot !== false) { imagedestroy($src); $src = $rot; }
                        break;
                    case 6:
                        $rot = imagerotate($src, -90, 0);
                        if ($rot !== false) { imagedestroy($src); $src = $rot; }
                        break;
                    case 8:
                        $rot = imagerotate($src, 90, 0);
                        if ($rot !== false) { imagedestroy($src); $src = $rot; }
                        break;
                }
            }
        }

        $origW = imagesx($src);
        $origH = imagesy($src);

        if ($origW > $maxDimension || $origH > $maxDimension) {
            if ($origW >= $origH) {
                $newW = $maxDimension;
                $newH = (int) max(1, round(($origH / $origW) * $maxDimension));
            } else {
                $newH = $maxDimension;
                $newW = (int) max(1, round(($origW / $origH) * $maxDimension));
            }
        } else {
            $newW = $origW;
            $newH = $origH;
        }

        $dst = imagecreatetruecolor($newW, $newH);
        $targetExt = strtolower($ext);

        if ($targetExt === 'png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        } elseif ($targetExt === 'webp' && function_exists('imagewebp')) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        } else {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        $saved = false;
        if ($targetExt === 'webp' && function_exists('imagewebp')) {
            $saved = @imagewebp($dst, $destPath, $quality);
        } elseif ($targetExt === 'png') {
            $saved = @imagepng($dst, $destPath, 8);
        } else {
            $saved = @imagejpeg($dst, $destPath, $quality);
        }

        imagedestroy($src);
        imagedestroy($dst);

        if ($saved) {
            if (file_exists($sourcePath)) {
                @unlink($sourcePath);
            }
            return true;
        }

        return move_uploaded_file($sourcePath, $destPath);
    }
}

if (!function_exists('simpanFotoUpload')) {
    /** Pindahkan file tervalidasi ke uploads/$subfolder/ dengan kompresi otomatis dan kembalikan path relatif terhadap uploads/. */
    function simpanFotoUpload($info, $subfolder) {
        $root = realpath(__DIR__ . '/..') . '/uploads';
        $dir = $root . '/' . $subfolder;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new Exception("Folder upload tidak dapat dibuat");
        }
        $htaccess = $dir . '/.htaccess';
        if (file_exists($root . '/anggota/.htaccess')) {
            $kontenHt = @file_get_contents($htaccess);
            if (!$kontenHt || (strpos($kontenHt, 'Require all denied') !== false && strpos($kontenHt, 'FilesMatch') === false)) {
                @copy($root . '/anggota/.htaccess', $htaccess);
            }
        }
        $nama = bin2hex(random_bytes(16)) . '.' . $info['ext'];
        $destPath = $dir . '/' . $nama;
        if (!kompresDanSimpanGambar($info['tmp'], $destPath, $info['ext'])) {
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

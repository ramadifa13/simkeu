<?php
/**
 * Upload foto tunggal (foto nasabah saat pencairan, foto profil teller).
 * Tipe divalidasi dengan finfo (bukan ekstensi), nama file acak.
 * Folder tujuan: uploads/{subfolder}/ (eksekusi script diblokir .htaccess di folder itu).
 */

if (!defined('FOTO_HASIL_MAKS_BYTE')) {
    // Hasil akhir yang disimpan ke disk selalu ≤ 1 MB.
    define('FOTO_HASIL_MAKS_BYTE', 1024 * 1024);
}
if (!defined('FOTO_ASAL_MAKS_BYTE')) {
    // File asli dari kamera/galeri boleh lebih besar; akan dikompres sebelum disimpan.
    define('FOTO_ASAL_MAKS_BYTE', 10 * 1024 * 1024);
}

if (!function_exists('pastikanDirektoriUpload')) {
    /**
     * Buat folder tujuan upload bila belum ada (rekursif), pastikan writable,
     * dan salin .htaccess proteksi bila folder berada di bawah uploads/.
     */
    function pastikanDirektoriUpload($dir) {
        $dir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $dir), DIRECTORY_SEPARATOR);
        if ($dir === '' || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $dir)) {
            throw new Exception('Folder upload tidak valid');
        }
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new Exception('Folder upload tidak dapat dibuat');
            }
        }
        if (!is_writable($dir)) {
            throw new Exception('Folder upload tidak dapat ditulis');
        }

        $uploadsRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
        $realUploads = is_dir($uploadsRoot) ? realpath($uploadsRoot) : false;
        $realDir = realpath($dir);
        if ($realUploads && $realDir && strpos($realDir, $realUploads) === 0) {
            $ht = $realDir . DIRECTORY_SEPARATOR . '.htaccess';
            $template = $realUploads . DIRECTORY_SEPARATOR . 'anggota' . DIRECTORY_SEPARATOR . '.htaccess';
            if (!is_file($template)) {
                $template = $realUploads . DIRECTORY_SEPARATOR . '.htaccess';
            }
            if (is_file($template)) {
                $kontenHt = is_file($ht) ? @file_get_contents($ht) : false;
                if ($kontenHt === false || $kontenHt === ''
                    || (strpos($kontenHt, 'Require all denied') !== false && strpos($kontenHt, 'FilesMatch') === false)
                ) {
                    @copy($template, $ht);
                }
            }
        }

        return $realDir ?: $dir;
    }
}

if (!function_exists('uploadsRootPath')) {
    /** Path absolut folder uploads/ (dibuat otomatis bila belum ada). */
    function uploadsRootPath() {
        return pastikanDirektoriUpload(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
    }
}

if (!function_exists('pastikanFolderUpload')) {
    /**
     * Pastikan uploads/{subfolder}/ ada. Subfolder boleh nested (mis. anggota/12).
     * Hanya karakter aman: huruf, angka, underscore, strip.
     */
    function pastikanFolderUpload($subfolder) {
        $sub = trim(str_replace('\\', '/', (string) $subfolder), '/');
        if ($sub === '' || strpos($sub, '..') !== false) {
            throw new Exception('Folder upload tidak valid');
        }
        $parts = array_values(array_filter(explode('/', $sub), 'strlen'));
        foreach ($parts as $p) {
            if ($p === '.' || $p === '..' || !preg_match('/^[A-Za-z0-9_-]+$/', $p)) {
                throw new Exception('Folder upload tidak valid');
            }
        }
        return pastikanDirektoriUpload(
            uploadsRootPath() . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts)
        );
    }
}

if (!function_exists('validasiFotoUpload')) {
    /**
     * Validasi tipe MIME & batas file ASAL (sebelum kompres).
     * Hasil simpan selalu dipaksa ≤ FOTO_HASIL_MAKS_BYTE (1 MB) oleh kompresDanSimpanGambar().
     *
     * @return array|null ['tmp' => path, 'ext' => ekstensi] atau null bila tidak ada file
     * @throws Exception bila tidak valid, atau bila $wajib dan tidak ada file
     */
    function validasiFotoUpload($field, $label, $maks_byte = null, $wajib = false) {
        // $maks_byte tetap ada untuk kompatibilitas pemanggil lama (diabaikan).
        // Batas file asal = 10 MB; hasil simpan selalu ≤ 1 MB via kompresDanSimpanGambar().
        $maks_asal = FOTO_ASAL_MAKS_BYTE;
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
        if ($file['size'] > $maks_asal) {
            throw new Exception("$label melebihi " . round($maks_asal / 1048576) . " MB sebelum dikompres");
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
    function kompresDanSimpanGambar($sourcePath, &$destPath, $ext, $maxDimension = 1280, $quality = 82) {
        $batasByte = defined('FOTO_HASIL_MAKS_BYTE') ? FOTO_HASIL_MAKS_BYTE : (1024 * 1024);
        $parent = dirname($destPath);
        if ($parent !== '' && $parent !== '.' && $parent !== DIRECTORY_SEPARATOR) {
            pastikanDirektoriUpload($parent);
        }

        $pindahJikaSudahKecil = function () use ($sourcePath, $destPath, $batasByte) {
            clearstatcache(true, $sourcePath);
            if (!is_file($sourcePath) || filesize($sourcePath) > $batasByte) {
                return false;
            }
            if (!@move_uploaded_file($sourcePath, $destPath) && !@rename($sourcePath, $destPath)) {
                return @copy($sourcePath, $destPath) && @unlink($sourcePath);
            }
            return true;
        };

        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            return $pindahJikaSudahKecil();
        }

        $raw = @file_get_contents($sourcePath);
        if ($raw === false) {
            return $pindahJikaSudahKecil();
        }

        $src = @imagecreatefromstring($raw);
        if (!$src) {
            return $pindahJikaSudahKecil();
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
        imagedestroy($src);

        // Paksa hasil akhir ≤ 1 MB (turunkan kualitas / dimensi, lalu JPEG).
        $q = (int) $quality;
        $saved = false;
        $work = $dst;
        $curW = $newW;
        $curH = $newH;
        $forceJpeg = false;

        for ($attempt = 0; $attempt < 14; $attempt++) {
            if (!$forceJpeg && $targetExt === 'webp' && function_exists('imagewebp') && $attempt === 0) {
                $saved = @imagewebp($work, $destPath, max(40, $q));
            } elseif (!$forceJpeg && $targetExt === 'png' && $attempt === 0) {
                $saved = @imagepng($work, $destPath, 8);
            } else {
                $forceJpeg = true;
                $jpegPath = preg_replace('/\.(png|webp)$/i', '.jpg', $destPath) ?: $destPath;
                if (!preg_match('/\.jpe?g$/i', $jpegPath)) {
                    $jpegPath .= '.jpg';
                }
                $saved = @imagejpeg($work, $jpegPath, max(32, $q));
                if ($saved && $jpegPath !== $destPath) {
                    if (is_file($destPath)) {
                        @unlink($destPath);
                    }
                    $destPath = $jpegPath;
                }
            }

            if (!$saved || !is_file($destPath)) {
                // Gagal simpan format asli — lanjut paksa JPEG.
                if (!$forceJpeg) {
                    $forceJpeg = true;
                    $targetExt = 'jpg';
                    continue;
                }
                break;
            }
            clearstatcache(true, $destPath);
            if (filesize($destPath) <= $batasByte) {
                imagedestroy($work);
                if (file_exists($sourcePath)) {
                    @unlink($sourcePath);
                }
                return true;
            }

            // Masih > 1 MB: turunkan kualitas, lalu perkecil dimensi.
            $forceJpeg = true;
            $targetExt = 'jpg';
            $q -= 8;
            if ($q < 45 && $curW > 480 && $curH > 480) {
                $q = 72;
                $nextW = (int) max(480, round($curW * 0.75));
                $nextH = (int) max(480, round($curH * 0.75));
                $smaller = imagecreatetruecolor($nextW, $nextH);
                $white = imagecolorallocate($smaller, 255, 255, 255);
                imagefilledrectangle($smaller, 0, 0, $nextW, $nextH, $white);
                imagecopyresampled($smaller, $work, 0, 0, 0, 0, $nextW, $nextH, $curW, $curH);
                imagedestroy($work);
                $work = $smaller;
                $curW = $nextW;
                $curH = $nextH;
            } elseif ($q < 32) {
                break;
            }
            @unlink($destPath);
        }

        imagedestroy($work);
        // Fallback terakhir: jangan simpan file > 1 MB
        if (is_file($destPath)) {
            clearstatcache(true, $destPath);
            if (filesize($destPath) > $batasByte) {
                @unlink($destPath);
                return false;
            }
            if (file_exists($sourcePath)) {
                @unlink($sourcePath);
            }
            return true;
        }

        return false;
    }
}

if (!function_exists('simpanFotoUpload')) {
    /** Pindahkan file tervalidasi ke uploads/$subfolder/ dengan kompresi otomatis dan kembalikan path relatif terhadap uploads/. */
    function simpanFotoUpload($info, $subfolder) {
        $sub = trim(str_replace('\\', '/', (string) $subfolder), '/');
        $dir = pastikanFolderUpload($sub);
        $nama = bin2hex(random_bytes(16)) . '.' . $info['ext'];
        $destPath = $dir . DIRECTORY_SEPARATOR . $nama;
        if (!kompresDanSimpanGambar($info['tmp'], $destPath, $info['ext'])) {
            throw new Exception("Gagal menyimpan file foto (hasil harus maksimal 1 MB)");
        }
        // Extensi bisa berubah ke .jpg saat kompres paksa di bawah 1 MB
        $namaAkhir = basename($destPath);
        return $sub . '/' . $namaAkhir;
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

if (!function_exists('fotoFileAda')) {
    /** True bila path relatif uploads/ mengarah ke file gambar yang benar-benar ada di disk. */
    function fotoFileAda($path) {
        if ($path === null || $path === '' || strpos((string) $path, '..') !== false) {
            return false;
        }
        $root = realpath(__DIR__ . '/../uploads');
        if ($root === false) {
            return false;
        }
        $file = realpath($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $path));
        return $file && strpos($file, $root . DIRECTORY_SEPARATOR) === 0 && is_file($file);
    }
}

if (!function_exists('fotoNasabahAnggota')) {
    /** Foto pinjaman berjalan pertama; bila semuanya lunas, gunakan pencairan terakhir. */
    function fotoNasabahAnggota($db, $anggota_id) {
        $map = fotoNasabahAnggotaMap($db, [(int) $anggota_id]);
        return (string) ($map[(int) $anggota_id] ?? '');
    }
}

if (!function_exists('fotoNasabahAnggotaMap')) {
    /**
     * Peta anggota_id => path foto nasabah saat menerima uang (foto_pencairan).
     * Prefer pinjaman berjalan (tertua); jika semua lunas, pakai pencairan terakhir yang punya foto.
     *
     * @param list<int|string> $anggotaIds
     * @return array<int,string>
     */
    function fotoNasabahAnggotaMap($db, array $anggotaIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $anggotaIds))));
        if ($ids === [] || !kolomAda($db, 'pinjaman', 'foto_pencairan')) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT anggota_id, foto_pencairan, status, tanggal_pencairan, id
            FROM pinjaman
            WHERE anggota_id IN ($ph)
              AND foto_pencairan IS NOT NULL AND foto_pencairan <> ''
            ORDER BY anggota_id ASC,
              CASE WHEN status <> 'lunas' THEN 0 ELSE 1 END ASC,
              CASE WHEN status <> 'lunas' THEN tanggal_pencairan END ASC,
              CASE WHEN status <> 'lunas' THEN id END ASC,
              CASE WHEN status = 'lunas' THEN tanggal_pencairan END DESC,
              CASE WHEN status = 'lunas' THEN id END DESC");
        $stmt->execute($ids);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $aid = (int) $row['anggota_id'];
            if (isset($map[$aid])) {
                continue;
            }
            $path = trim((string) ($row['foto_pencairan'] ?? ''));
            if ($path !== '') {
                $map[$aid] = $path;
            }
        }
        return $map;
    }
}

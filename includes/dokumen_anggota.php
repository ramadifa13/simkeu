<?php
require_once __DIR__ . '/upload_foto.php';

/**
 * Upload dokumen anggota (KTP, KK, KTP suami/istri).
 *
 * - Tipe divalidasi di server dengan finfo (bukan dari ekstensi/nama file).
 * - File asal maksimal 10 MB (jpg/jpeg/png/webp); hasil disimpan maksimal 1 MB (kompres otomatis).
 * - Nama file acak, disimpan di uploads/anggota/{id_anggota}/ (eksekusi script
 *   diblokir oleh uploads/anggota/.htaccess).
 * - File foto_ktp lama (sebelum revisi) tetap dibaca dari assets/uploads/ktp/.
 *
 * Dokumen di DOKUMEN_ANGGOTA_WAJIB wajib saat TAMBAH anggota. Saat edit, dokumen
 * boleh tidak diunggah (file lama tetap dipakai; unggah baru = mengganti).
 */

if (!defined('DOKUMEN_ANGGOTA_WAJIB')) {
    // Dikonfirmasi klien (Okt 2026): ketiga dokumen wajib. Kosongkan array untuk menjadikan opsional.
    define('DOKUMEN_ANGGOTA_WAJIB', ['foto_ktp', 'foto_kk', 'foto_ktp_pasangan']);
}
if (!defined('DOKUMEN_MAKS_BYTE')) {
    define('DOKUMEN_MAKS_BYTE', 10 * 1024 * 1024);
}

if (!function_exists('daftarDokumenAnggota')) {
    function daftarDokumenAnggota() {
        return [
            'foto_ktp' => 'Foto KTP',
            'foto_kk' => 'Foto Kartu Keluarga (KK)',
            'foto_ktp_pasangan' => 'Foto KTP Suami/Istri',
        ];
    }
}

if (!function_exists('dokumenWajib')) {
    function dokumenWajib($field, $khusus_jaminan = false) {
        if ($khusus_jaminan) {
            return $field === 'foto_ktp';
        }
        return in_array($field, DOKUMEN_ANGGOTA_WAJIB, true);
    }
}

if (!function_exists('dirUploadAnggota')) {
    /** Path absolut folder upload dokumen anggota (tanpa slash akhir). */
    function dirUploadAnggota() {
        if (function_exists('pastikanFolderUpload')) {
            return pastikanFolderUpload('anggota');
        }
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'anggota';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new Exception('Folder upload dokumen tidak dapat dibuat');
        }
        return $dir;
    }
}

if (!function_exists('normalisasiPathDokumenAnggota')) {
    /**
     * Normalisasi nilai kolom foto ke path relatif konsisten.
     * Baru: "anggota/{id}/{file}" ; legacy basename → assets/uploads/ktp/{file}.
     */
    function normalisasiPathDokumenAnggota($nilai) {
        $nilai = str_replace('\\', '/', trim((string) $nilai));
        if ($nilai === '' || preg_match('#^(https?:)?//#i', $nilai) || preg_match('#^[a-zA-Z]:/#', $nilai)) {
            return '';
        }
        if (strpos($nilai, 'uploads/') === 0) {
            $nilai = substr($nilai, 8);
        }
        $nilai = ltrim($nilai, '/');
        if ($nilai === '' || strpos($nilai, '..') !== false) {
            return '';
        }
        return $nilai;
    }
}

if (!function_exists('pathAbsDokumenAnggota')) {
    /** Path absolut di disk untuk nilai kolom dokumen; '' bila tidak bisa di-resolve. */
    function pathAbsDokumenAnggota($nilai) {
        $nilai = normalisasiPathDokumenAnggota($nilai);
        if ($nilai === '') {
            return '';
        }
        $root = realpath(__DIR__ . '/..');
        if ($root === false) {
            return '';
        }
        if (strpos($nilai, 'assets/uploads/ktp/') === 0) {
            return $root . '/' . $nilai;
        }
        if (strpos($nilai, '/') === false) {
            return $root . '/assets/uploads/ktp/' . $nilai;
        }
        return $root . '/uploads/' . $nilai;
    }
}

if (!function_exists('dokumenAnggotaAdaDiDisk')) {
    function dokumenAnggotaAdaDiDisk($nilai) {
        $abs = pathAbsDokumenAnggota($nilai);
        return $abs !== '' && is_file($abs);
    }
}

if (!function_exists('urlDokumenAnggota')) {
    /**
     * URL relatif (dari folder admin/ atau teller/) untuk nilai kolom dokumen.
     * Nilai baru: "anggota/{id}/{file}" ; nilai lama (hanya nama file): assets/uploads/ktp/.
     */
    function urlDokumenAnggota($nilai) {
        $nilai = normalisasiPathDokumenAnggota($nilai);
        if ($nilai === '') {
            return '';
        }
        if (strpos($nilai, 'assets/uploads/ktp/') === 0) {
            $base = substr($nilai, strlen('assets/uploads/ktp/'));
            return '../assets/uploads/ktp/' . rawurlencode(basename($base));
        }
        if (strpos($nilai, '/') !== false) {
            return '../uploads/' . $nilai;
        }
        return '../assets/uploads/ktp/' . rawurlencode($nilai);
    }
}

if (!function_exists('cekPostMelebihiBatas')) {
    /**
     * Bila total upload > post_max_size, PHP mengosongkan $_POST dan $_FILES tanpa pesan.
     * Panggil di awal halaman form agar pengguna mendapat pesan yang jelas.
     */
    function cekPostMelebihiBatas() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
            && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0) {
            $_SESSION['error'] = 'Ukuran total file yang diunggah melebihi batas server (post_max_size = '
                . ini_get('post_max_size') . '). Maksimal 10 MB per file.';
            return true;
        }
        return false;
    }
}

if (!function_exists('validasiDokumenAnggota')) {
    /**
     * Validasi semua file dokumen dari $_FILES SEBELUM data disimpan.
     *
     * @param array|null $data_lama baris anggota saat edit, null saat tambah (cek wajib hanya saat tambah)
     * @return array field => ['tmp' => path, 'ext' => ekstensi] untuk file yang diunggah
     * @throws Exception bila ada file tidak valid
     */
    function validasiDokumenAnggota($data_lama = null, $khusus_jaminan = false) {
        $mime_diizinkan = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $hasil = [];

        foreach (daftarDokumenAnggota() as $field => $label) {
            $file = isset($_FILES[$field]) ? $_FILES[$field] : null;
            $ada_file = $file && $file['error'] !== UPLOAD_ERR_NO_FILE;

            if (!$ada_file) {
                if (dokumenWajib($field, $khusus_jaminan) && $data_lama === null) {
                    throw new Exception("$label wajib diunggah");
                }
                continue;
            }

            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new Exception("$label melebihi batas ukuran (maksimal 10 MB; batas server: "
                    . ini_get('upload_max_filesize') . ")");
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new Exception("$label gagal diunggah (kode error " . (int) $file['error'] . ")");
            }
            if ($file['size'] > DOKUMEN_MAKS_BYTE) {
                throw new Exception("$label melebihi 10 MB");
            }
            if (!class_exists('finfo')) {
                throw new Exception("Ekstensi PHP fileinfo belum aktif di server; hubungi administrator");
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!isset($mime_diizinkan[$mime])) {
                throw new Exception("$label harus berupa gambar JPG, PNG, atau WEBP");
            }

            $hasil[$field] = ['tmp' => $file['tmp_name'], 'ext' => $mime_diizinkan[$mime]];
        }

        return $hasil;
    }
}

if (!function_exists('simpanDokumenAnggota')) {
    /**
     * Pindahkan file yang sudah divalidasi ke uploads/anggota/{id}/ dan perbarui kolomnya.
     * File lama yang diganti dihapus. Bila gagal, file yang baru dipindahkan dihapus lagi.
     */
    function simpanDokumenAnggota($db, $anggota_id, $dokumen, $data_lama = null) {
        if (empty($dokumen)) {
            return;
        }
        $anggota_id = (int) $anggota_id;
        if (function_exists('pastikanFolderUpload')) {
            $dir = pastikanFolderUpload('anggota/' . $anggota_id);
        } else {
            $dir = dirUploadAnggota() . DIRECTORY_SEPARATOR . $anggota_id;
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new Exception("Folder upload dokumen tidak dapat dibuat");
            }
        }

        $baru = [];
        try {
            foreach ($dokumen as $field => $info) {
                $nama = bin2hex(random_bytes(16)) . '.' . $info['ext'];
                $destPath = $dir . '/' . $nama;
                if (!kompresDanSimpanGambar($info['tmp'], $destPath, $info['ext'])) {
                    throw new Exception("Gagal menyimpan file dokumen (hasil harus maksimal 1 MB)");
                }
                $baru[$field] = 'anggota/' . $anggota_id . '/' . basename($destPath);
            }

            $set = [];
            foreach (array_keys($baru) as $field) {
                $set[] = "`$field` = ?"; // nama field dari daftarDokumenAnggota(), bukan input pengguna
            }
            $stmt = $db->prepare("UPDATE anggota SET " . implode(', ', $set) . " WHERE id = ?");
            if (!$stmt || !$stmt->execute(array_merge(array_values($baru), [$anggota_id]))) {
                // Jangan gagal diam-diam (mis. kolom belum ada karena migrasi belum dijalankan)
                $info = $stmt ? $stmt->errorInfo() : $db->errorInfo();
                throw new Exception("Dokumen gagal disimpan ke database: " . $info[2]
                    . (migrasiRevisiBelumDijalankan($db) ? " (database belum di-update, jalankan file migrasi)" : ""));
            }
        } catch (Exception $e) {
            foreach ($baru as $path) {
                @unlink(realpath(__DIR__ . '/..') . '/uploads/' . $path);
            }
            throw $e;
        }

        // Hapus file lama yang diganti
        foreach (array_keys($baru) as $field) {
            if (!empty($data_lama[$field])) {
                hapusFileDokumenAnggota($data_lama[$field]);
            }
        }
    }
}

if (!function_exists('hapusFileDokumenAnggota')) {
    function hapusFileDokumenAnggota($nilai) {
        if (empty($nilai)) {
            return;
        }
        $root = realpath(__DIR__ . '/..');
        $path = strpos($nilai, '/') !== false
            ? $root . '/uploads/' . $nilai
            : $root . '/assets/uploads/ktp/' . basename($nilai);
        $real = realpath($path);
        if (!$real) {
            return;
        }
        // Pastikan path tetap di dalam folder upload
        foreach ([$root . '/uploads/anggota', $root . '/assets/uploads/ktp'] as $folder) {
            $folder = realpath($folder);
            if ($folder && strpos($real, $folder . DIRECTORY_SEPARATOR) === 0) {
                @unlink($real);
                return;
            }
        }
    }
}

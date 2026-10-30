<?php

function templatePerjanjianDefault($versi) {
    if ($versi === 'lengkap') {
        return "1. Koperasi menyerahkan pinjaman kepada Anggota sesuai rincian yang tercantum dalam dokumen ini.\n"
             . "2. Anggota mengakui telah menerima dana dan wajib membayar angsuran tepat waktu sampai lunas.\n"
             . "3. Anggota wajib menggunakan dana sesuai tujuan pengajuan dan memberikan data yang benar.\n"
             . "4. Keterlambatan, denda, pelunasan, dan tindakan penagihan mengikuti ketentuan koperasi yang telah disampaikan kepada Anggota.\n"
             . "5. Untuk pinjaman gadai, barang jaminan diserahkan kepada koperasi dan dikembalikan setelah seluruh kewajiban lunas.\n"
             . "6. Perubahan keadaan, alamat, atau kemampuan membayar wajib segera diberitahukan kepada koperasi.\n"
             . "7. Para pihak menjaga kerahasiaan dan menyetujui pemrosesan data untuk administrasi pinjaman.\n"
             . "8. Perselisihan diselesaikan terlebih dahulu melalui musyawarah untuk mufakat.\n"
             . "9. Dokumen elektronik, foto, dan tanda tangan pada dokumen ini merupakan satu kesatuan dengan perjanjian.\n"
             . "10. Perjanjian berlaku sejak ditandatangani sampai seluruh kewajiban dinyatakan lunas.";
    }
    return "1. Anggota mengakui menerima pinjaman sesuai rincian dalam surat ini.\n"
         . "2. Anggota wajib membayar angsuran tepat waktu sampai lunas.\n"
         . "3. Untuk pinjaman gadai, barang jaminan dikembalikan setelah kewajiban lunas.\n"
         . "4. Perselisihan diselesaikan terlebih dahulu secara musyawarah.\n"
         . "5. Para pihak menyetujui dokumen dan tanda tangan elektronik ini.";
}
function pengaturanPerjanjian($db) {
    $row = $db->query("SELECT * FROM pengaturan_perjanjian WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $stmt = $db->prepare("INSERT INTO pengaturan_perjanjian
            (id, jabatan_admin, template_default, isi_ringkas, isi_lengkap)
            VALUES (1, 'Pengurus Koperasi', 'lengkap', ?, ?)");
        $stmt->execute([templatePerjanjianDefault('ringkas'), templatePerjanjianDefault('lengkap')]);
        $row = $db->query("SELECT * FROM pengaturan_perjanjian WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    }
    return $row;
}

/**
 * Kompres TTD ke JPEG data-URL (max 1 MB) agar kolom MEDIUMTEXT tidak membengkak.
 * PNG/WebP lama ikut dikonversi saat disimpan ulang.
 */
function kompresTtdKeJpegDataUrl(string $bin, string $label = 'TTD'): string {
    if (strlen($bin) < 100) {
        throw new Exception("$label tidak valid");
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        if (strlen($bin) > 1024 * 1024) {
            throw new Exception("$label melebihi 1 MB");
        }
        return 'data:image/jpeg;base64,' . base64_encode($bin);
    }
    $img = @imagecreatefromstring($bin);
    if ($img === false) {
        throw new Exception("$label tidak valid");
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $maxW = 700;
    if ($w > $maxW) {
        $nw = $maxW;
        $nh = max(1, (int) round($h * ($maxW / $w)));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    } else {
        // Pastikan latar tidak transparan saat ke JPEG.
        $dst = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $w, $h, $white);
        imagecopy($dst, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        $img = $dst;
    }

    $quality = 82;
    $jpeg = null;
    do {
        ob_start();
        imagejpeg($img, null, $quality);
        $jpeg = ob_get_clean();
        $quality -= 8;
    } while ($jpeg !== false && strlen($jpeg) > 1024 * 1024 && $quality >= 40);

    imagedestroy($img);
    if ($jpeg === false || strlen($jpeg) < 100) {
        throw new Exception("$label gagal dikompres");
    }
    if (strlen($jpeg) > 1024 * 1024) {
        throw new Exception("$label melebihi 1 MB setelah kompresi");
    }
    return 'data:image/jpeg;base64,' . base64_encode($jpeg);
}

function validasiTtdDataUrl($nilai, $label) {
    // TTD dari browser tidak boleh gagal hanya karena terdapat baris baru/spasi
    // saat disimpan oleh PHP atau dipindahkan dari pengaturan lama.
    // Semua format (PNG/JPEG/WebP) dikompres ke JPEG ≤ 1 MB sebelum disimpan.
    $nilai = preg_replace('/\s+/', '', trim((string) $nilai));
    if ($nilai === '') {
        return '';
    }
    if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+/=]+)$#i', $nilai, $m)) {
        throw new Exception("$label wajib ditandatangani langsung pada kotak tanda tangan");
    }
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 100 || strlen($bin) > 1024 * 1024) {
        throw new Exception("$label tidak valid atau terlalu besar (maks. 1 MB)");
    }
    return kompresTtdKeJpegDataUrl($bin, $label);
}

function bacaTtdRequest($field, $label) {
    $upload = $_FILES[$field . '_file'] ?? null;
    if ($upload === null) {
        $raw = trim((string) ($_POST[$field] ?? ''));
        return $raw === '' ? '' : validasiTtdDataUrl($raw, $label);
    }
    if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['size'])
        || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        throw new Exception("Unggah $label gagal. Silakan coba lagi.");
    }
    if ($upload['size'] < 100 || $upload['size'] > 1024 * 1024) {
        throw new Exception("$label tidak valid atau melebihi 1 MB.");
    }
    $gambar = @getimagesize($upload['tmp_name']);
    $okTypes = [IMAGETYPE_PNG, IMAGETYPE_JPEG];
    if (defined('IMAGETYPE_WEBP')) {
        $okTypes[] = IMAGETYPE_WEBP;
    }
    if (!$gambar || !in_array($gambar[2], $okTypes, true)) {
        throw new Exception("$label harus berupa gambar PNG/JPEG.");
    }
    $isi = file_get_contents($upload['tmp_name']);
    if ($isi === false) {
        throw new Exception("$label gagal dibaca.");
    }
    return kompresTtdKeJpegDataUrl($isi, $label);
}

function nomorPerjanjian($pinjaman) {
    $roman = [1=>'I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
    $waktu = strtotime($pinjaman['tanggal_pencairan']);
    return str_pad($pinjaman['id'], 3, '0', STR_PAD_LEFT) . '/SPHP/' . $roman[(int)date('n', $waktu)] . '/' . date('Y', $waktu);
}

function dataUrlKeFileSementara($dataUrl) {
    if (!preg_match('#^data:image/([^;]+);base64,(.+)$#', (string) $dataUrl, $m)) {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'ttd_');
    file_put_contents($tmp, base64_decode($m[2]));
    return $tmp;
}

/** Tipe gambar TCPDF dari data-URL TTD (PNG lama / JPEG baru). */
function dataUrlTipeTcpdf($dataUrl) {
    if (!preg_match('#^data:image/([^;]+);#i', (string) $dataUrl, $m)) {
        return 'JPEG';
    }
    $mime = strtolower($m[1]);
    if ($mime === 'png') {
        return 'PNG';
    }
    if ($mime === 'webp') {
        return 'WEBP';
    }
    return 'JPEG';
}

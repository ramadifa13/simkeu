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

function validasiTtdDataUrl($nilai, $label) {
    $nilai = trim((string) $nilai);
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $nilai, $m)) {
        throw new Exception("$label wajib ditandatangani langsung pada kotak tanda tangan");
    }
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) < 100 || strlen($bin) > 2 * 1024 * 1024) {
        throw new Exception("$label tidak valid atau terlalu besar");
    }
    return $nilai;
}

function nomorPerjanjian($pinjaman) {
    $roman = [1=>'I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
    $waktu = strtotime($pinjaman['tanggal_pencairan']);
    return str_pad($pinjaman['id'], 3, '0', STR_PAD_LEFT) . '/SPHP/' . $roman[(int)date('n', $waktu)] . '/' . date('Y', $waktu);
}

function dataUrlKeFileSementara($dataUrl) {
    if (!preg_match('#^data:image/[^;]+;base64,(.+)$#', $dataUrl, $m)) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'ttd_');
    file_put_contents($tmp, base64_decode($m[1]));
    return $tmp;
}

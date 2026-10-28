<?php
/**
 * Penghapusan anggota yang aman (dipakai admin/anggota.php & teller/anggota.php).
 *
 * Sebelumnya: PDO berjalan dalam mode silent, sehingga DELETE anggota yang masih
 * punya transaksi gagal diam-diam tetapi pesan "berhasil dihapus" tetap muncul,
 * sementara simpanan & file dokumennya sudah terlanjur terhapus.
 */

if (!function_exists('tanggalAtauNull')) {
    /**
     * Tanggal opsional dari form: kosong/tidak valid -> NULL.
     * MySQL/MariaDB strict mode (bawaan XAMPP) menolak '' untuk kolom DATE.
     */
    function tanggalAtauNull($nilai) {
        $nilai = trim((string) $nilai);
        if ($nilai === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai)) {
            return null;
        }
        return $nilai;
    }
}

if (!function_exists('buatKodeAnggota')) {
    /**
     * Kode anggota berikutnya: AGT + nomor urut 4 digit, dari nomor TERBESAR yang ada
     * (bukan jumlah baris, supaya tidak bentrok setelah ada anggota yang dihapus).
     */
    function buatKodeAnggota($db) {
        $max = (int) $db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(kode_anggota, 4) AS UNSIGNED)), 0)
                                 FROM anggota WHERE kode_anggota REGEXP '^AGT[0-9]+$'")->fetchColumn();
        $cek = $db->prepare("SELECT (SELECT COUNT(*) FROM anggota WHERE kode_anggota = :k1)
                                  + (SELECT COUNT(*) FROM users WHERE username = :k2)");
        do {
            $max++;
            $kode = 'AGT' . str_pad($max, 4, '0', STR_PAD_LEFT);
            $cek->execute([':k1' => $kode, ':k2' => $kode]);
        } while ((int) $cek->fetchColumn() > 0);
        return $kode;
    }
}

if (!function_exists('buatKodeAnggotaJaminan')) {
    /** Kode nasabah gadai berikutnya: AGTJ001, AGTJ002, dan seterusnya. */
    function buatKodeAnggotaJaminan($db) {
        $max = (int) $db->query("SELECT GREATEST(
                    COALESCE(MAX(CASE WHEN kode_anggota REGEXP '^AGTJ[0-9]+$' THEN CAST(SUBSTRING(kode_anggota, 5) AS UNSIGNED) END), 0),
                    COALESCE(MAX(CASE WHEN kode_anggota_jaminan REGEXP '^AGTJ[0-9]+$' THEN CAST(SUBSTRING(kode_anggota_jaminan, 5) AS UNSIGNED) END), 0)
                ) FROM anggota")->fetchColumn();
        $cek = $db->prepare("SELECT (SELECT COUNT(*) FROM anggota WHERE kode_anggota = :k1 OR kode_anggota_jaminan = :k2)
                                  + (SELECT COUNT(*) FROM users WHERE username = :k3)");
        do {
            $max++;
            $kode = 'AGTJ' . str_pad($max, 3, '0', STR_PAD_LEFT);
            $cek->execute([':k1' => $kode, ':k2' => $kode, ':k3' => $kode]);
        } while ((int) $cek->fetchColumn() > 0);
        return $kode;
    }
}

if (!function_exists('buatKodeAnggotaSesuaiKategori')) {
    /** Kode akun utama sesuai kategori saat anggota pertama kali didaftarkan. */
    function buatKodeAnggotaSesuaiKategori($db, $kategori) {
        return $kategori === 'jaminan' ? buatKodeAnggotaJaminan($db) : buatKodeAnggota($db);
    }
}

if (!function_exists('kunciKodeAnggota')) {
    /**
     * Kunci pembuatan kode anggota selama proses simpan (sampai dilepas / koneksi berakhir), supaya Admin dan
     * Teller yang menambah anggota bersamaan tidak mendapat kode yang sama. Panggil SEBELUM buatKodeAnggota().
     */
    function kunciKodeAnggota($db) {
        $db->query("SELECT GET_LOCK('kode_anggota_koperasi', 10)")->fetchColumn();
        $db->query("SELECT GET_LOCK('kode_anggota_jaminan_koperasi', 10)")->fetchColumn();
    }
}

if (!function_exists('lepasKodeAnggota')) {
    function lepasKodeAnggota($db) {
        $db->query("SELECT RELEASE_LOCK('kode_anggota_jaminan_koperasi')")->fetchColumn();
        $db->query("SELECT RELEASE_LOCK('kode_anggota_koperasi')")->fetchColumn();
    }
}

if (!function_exists('riwayatAnggota')) {
    /** @return array label => jumlah baris terkait yang mencegah penghapusan */
    function riwayatAnggota($db, $anggota_id) {
        $cek = [
            'transaksi simpanan/penarikan' => "SELECT COUNT(*) FROM transaksi WHERE anggota_id = ?",
            'pengajuan pinjaman' => "SELECT COUNT(*) FROM pengajuan_pinjaman WHERE anggota_id = ?",
            'pinjaman' => "SELECT COUNT(*) FROM pinjaman WHERE anggota_id = ?",
            'transaksi waserda' => "SELECT COUNT(*) FROM waserda_transaksi WHERE anggota_id = ?",
            'pembelian listrik' => "SELECT COUNT(*) FROM pembelian_listrik WHERE anggota_id = ?",
            'pembelian indihome' => "SELECT COUNT(*) FROM pembelian_indihome WHERE anggota_id = ?",
            'simpanan dengan saldo' => "SELECT COUNT(*) FROM simpanan WHERE anggota_id = ? AND saldo <> 0",
        ];
        $hasil = [];
        foreach ($cek as $label => $sql) {
            $stmt = $db->prepare($sql);
            $stmt->execute([$anggota_id]);
            $jumlah = (int) $stmt->fetchColumn();
            if ($jumlah > 0) {
                $hasil[$label] = $jumlah;
            }
        }
        return $hasil;
    }
}

if (!function_exists('hapusAnggota')) {
    /**
     * Hapus permanen anggota nonaktif tanpa kewajiban aktif, termasuk arsip pinjaman,
     * angsuran, kas, pelunasan, dan transaksi miliknya.
     * @throws Exception
     */
    function hapusAnggota($db, $anggota_id) {
        $errmode = $db->getAttribute(PDO::ATTR_ERRMODE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $stmt = $db->prepare("SELECT a.*, u.role AS user_role FROM anggota a
                                  LEFT JOIN users u ON a.user_id = u.id WHERE a.id = ?");
            $stmt->execute([$anggota_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$data) {
                throw new Exception("Data anggota tidak ditemukan");
            }

            if (($data['status'] ?? '') !== 'nonaktif') {
                throw new Exception('Anggota harus berstatus Nonaktif sebelum dihapus permanen.');
            }
            $aktif = $db->prepare("SELECT COUNT(*) FROM pinjaman WHERE anggota_id=? AND status<>'lunas'");
            $aktif->execute([$anggota_id]);
            if ((int) $aktif->fetchColumn() > 0) throw new Exception('Anggota masih memiliki pinjaman aktif atau macet. Lunaskan/selesaikan dahulu.');
            $menunggu = $db->prepare("SELECT COUNT(*) FROM pengajuan_pinjaman WHERE anggota_id=? AND status IN ('menunggu','disetujui')");
            $menunggu->execute([$anggota_id]);
            if ((int) $menunggu->fetchColumn() > 0) throw new Exception('Anggota masih memiliki pengajuan yang belum selesai.');

            $db->beginTransaction();
            $loanStmt = $db->prepare('SELECT id,kode_pinjaman FROM pinjaman WHERE anggota_id=?');
            $loanStmt->execute([$anggota_id]);
            $pinjaman = $loanStmt->fetchAll(PDO::FETCH_ASSOC);
            $loanIds = array_map(function ($row) { return (int) $row['id']; }, $pinjaman);
            $kodePinjaman = array_map(function ($row) { return $row['kode_pinjaman']; }, $pinjaman);
            $pengajuanStmt = $db->prepare('SELECT id FROM pengajuan_pinjaman WHERE anggota_id=?');
            $pengajuanStmt->execute([$anggota_id]);
            $pengajuanIds = array_map('intval', $pengajuanStmt->fetchAll(PDO::FETCH_COLUMN));
            $angsuranIds = hapusAnggotaAmbilId($db, 'angsuran', 'id', 'pinjaman_id', $loanIds);
            $transaksiIds = hapusAnggotaAmbilId($db, 'transaksi', 'id', 'anggota_id', [$anggota_id]);
            $perjanjianIds = hapusAnggotaAmbilId($db, 'perjanjian_pinjaman', 'id', 'pinjaman_id', $loanIds);
            $lelangIds = hapusAnggotaAmbilId($db, 'layanan_lelang', 'id', 'pinjaman_id', $loanIds);
            $hakIds = hapusAnggotaAmbilHakShu($db, $anggota_id, $loanIds);
            $shuAnggotaIds = hapusAnggotaAmbilId($db, 'shu_anggota', 'id', 'anggota_id', [$anggota_id]);
            $kasIds = hapusAnggotaAmbilId($db, 'kas', 'id', 'transaksi_id', $transaksiIds);

            hapusAnggotaHapusBaris($db, 'lampiran_perjanjian', 'perjanjian_id', $perjanjianIds);
            hapusAnggotaHapusBaris($db, 'layanan_alokasi_lelang', 'lelang_id', $lelangIds);
            hapusAnggotaHapusBaris($db, 'layanan_alokasi_lelang', 'angsuran_id', $angsuranIds);
            hapusAnggotaHapusBaris($db, 'layanan_bayar_denda', 'angsuran_id', $angsuranIds);
            hapusAnggotaHapusBaris($db, 'pengajuan_pembatalan_angsuran', 'angsuran_id', $angsuranIds);
            hapusAnggotaHapusBaris($db, 'layanan_pengajuan_hapus_denda', 'angsuran_id', $angsuranIds);
            hapusAnggotaHapusBaris($db, 'wa_pengingat_log', 'angsuran_id', $angsuranIds);
            hapusAnggotaHapusBaris($db, 'shu_hak_audit', 'hak_id', $hakIds);
            hapusAnggotaHapusBaris($db, 'shu_pembayaran', 'shu_anggota_id', $shuAnggotaIds);
            foreach (['layanan_audit','layanan_bayar_denda','layanan_bonus','layanan_jaminan_kembali','layanan_laporan_macet','layanan_lelang','layanan_pelunasan','layanan_pembayaran','layanan_prioritas','layanan_repeat_order','pelunasan_dini','perjanjian_pinjaman','alokasi_dapin','migrasi_pembayaran','shu_hak_pinjaman'] as $tabel) {
                hapusAnggotaHapusBaris($db, $tabel, 'pinjaman_id', $loanIds);
            }
            foreach (['layanan_prioritas','layanan_bonus','shu_hak_pinjaman'] as $tabel) {
                hapusAnggotaHapusBaris($db, $tabel, 'anggota_id', [$anggota_id]);
            }
            hapusAnggotaHapusBaris($db, 'shu_hak_pinjaman', 'sumber_anggota_id', [$anggota_id]);
            hapusAnggotaHapusBaris($db, 'jaminan_pinjaman', 'pengajuan_id', $pengajuanIds);
            hapusAnggotaHapusBaris($db, 'layanan_tunda_cair', 'pengajuan_id', $pengajuanIds);
            hapusAnggotaHapusBaris($db, 'migrasi_pembayaran', 'transaksi_id', $transaksiIds);
            hapusAnggotaHapusBaris($db, 'kas', 'transaksi_id', $transaksiIds);
            foreach ($kodePinjaman as $kode) {
                if (hapusAnggotaTabelAda($db, 'kas')) $db->prepare('DELETE FROM kas WHERE keterangan LIKE ?')->execute(['%' . $kode . '%']);
            }
            if (hapusAnggotaTabelAda($db, 'migrasi_import_item')) {
                $hapusMap = $db->prepare('DELETE FROM migrasi_import_item WHERE (jenis=? AND target_id=?)');
                $hapusMap->execute(['anggota', $anggota_id]);
                foreach ($loanIds as $loanId) $hapusMap->execute(['pinjaman', $loanId]);
                foreach ($transaksiIds as $transaksiId) $hapusMap->execute(['pembayaran', $transaksiId]);
                foreach ($kasIds as $kasId) $hapusMap->execute(['kas', $kasId]);
            }
            hapusAnggotaHapusBaris($db, 'transaksi', 'anggota_id', [$anggota_id]);
            hapusAnggotaHapusBaris($db, 'angsuran', 'pinjaman_id', $loanIds);
            hapusAnggotaHapusBaris($db, 'pinjaman', 'anggota_id', [$anggota_id]);
            hapusAnggotaHapusBaris($db, 'pengajuan_pinjaman', 'anggota_id', [$anggota_id]);
            foreach (['waserda_transaksi','pembelian_listrik','pembelian_indihome','simpanan','migrasi_anggota_dokumen','shu_anggota'] as $tabel) {
                hapusAnggotaHapusBaris($db, $tabel, 'anggota_id', [$anggota_id]);
            }
            hapusAnggotaHitungUlangKas($db);
            $db->prepare("DELETE FROM anggota WHERE id = ?")->execute([$anggota_id]);
            // Hanya hapus akun bila memang akun anggota (data lama bisa tertaut ke akun teller)
            if ($data['user_id'] && $data['user_role'] === 'anggota') {
                $db->prepare("DELETE FROM users WHERE id = ?")->execute([$data['user_id']]);
            }
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $db->setAttribute(PDO::ATTR_ERRMODE, $errmode);
            throw $e;
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, $errmode);

        // File dihapus setelah data benar-benar terhapus
        foreach (array_keys(daftarDokumenAnggota()) as $field) {
            hapusFileDokumenAnggota($data[$field]);
        }
    }
}

if (!function_exists('hapusAnggotaTabelAda')) {
    function hapusAnggotaTabelAda($db, $tabel) {
        static $cache = [];
        if (isset($cache[$tabel])) return $cache[$tabel];
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$tabel]);
        return $cache[$tabel] = ((int) $stmt->fetchColumn() > 0);
    }
    function hapusAnggotaAmbilId($db, $tabel, $kolomId, $kolomRelasi, $ids) {
        if (!$ids || !hapusAnggotaTabelAda($db, $tabel)) return [];
        $sql = 'SELECT ' . $kolomId . ' FROM ' . $tabel . ' WHERE ' . $kolomRelasi . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $stmt = $db->prepare($sql); $stmt->execute($ids);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    function hapusAnggotaHapusBaris($db, $tabel, $kolom, $ids) {
        if (!$ids || !hapusAnggotaTabelAda($db, $tabel)) return;
        $sql = 'DELETE FROM ' . $tabel . ' WHERE ' . $kolom . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $db->prepare($sql)->execute($ids);
    }
    function hapusAnggotaAmbilHakShu($db, $anggotaId, $loanIds) {
        if (!hapusAnggotaTabelAda($db, 'shu_hak_pinjaman')) return [];
        $sql = 'SELECT id FROM shu_hak_pinjaman WHERE anggota_id=? OR sumber_anggota_id=?'; $params = [$anggotaId, $anggotaId];
        if ($loanIds) { $sql .= ' OR pinjaman_id IN (' . implode(',', array_fill(0, count($loanIds), '?')) . ')'; $params = array_merge($params, $loanIds); }
        $stmt = $db->prepare($sql); $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    function hapusAnggotaHitungUlangKas($db) {
        if (!hapusAnggotaTabelAda($db, 'kas')) return;
        $saldo = 0; $rows = $db->query('SELECT id,jenis,jumlah FROM kas ORDER BY tanggal,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $db->prepare('UPDATE kas SET saldo_sebelum=?,saldo_sesudah=? WHERE id=?');
        foreach ($rows as $row) { $sebelum = $saldo; $saldo += $row['jenis'] === 'masuk' ? (float) $row['jumlah'] : -(float) $row['jumlah']; $stmt->execute([$sebelum, $saldo, $row['id']]); }
    }
}

if (!function_exists('migrasiRevisiBelumDijalankan')) {
    /**
     * true bila database belum menjalankan database/migrations/2026_10_revisi_koperasi.sql.
     * Tanpa migrasi, fitur revisi (tarif flat, mingguan, dokumen, pencairan foto, profil) tidak berjalan.
     */
    function migrasiRevisiBelumDijalankan($db) {
        static $hasil = null;
        if ($hasil === null) {
            // (tabel, kolom) yang dibuat migrasi tahap 1, 2026_10b, dan tahap 2
            $wajib = [
                ['jenis_pinjaman', 'frekuensi_diizinkan'], ['pinjaman', 'skema_bunga'],
                ['anggota', 'foto_ktp_pasangan'], ['jenis_pinjaman_tarif', 'tenor_bulan'],
                ['jenis_pinjaman', 'memerlukan_jaminan'], ['jaminan_pinjaman', 'jenis_barang'],
                ['angsuran', 'cara_bayar'], ['pinjaman', 'foto_pencairan'],
                ['pinjaman', 'tanggal_lunas'], ['users', 'foto_profil'],
                ['angsuran', 'denda_manual'], ['denda_tarif', 'denda_per_hari'],
                ['pengajuan_pembatalan_angsuran', 'aktif'], ['pinjaman', 'input_mundur'], ['pengajuan_pembatalan_angsuran', 'dilihat_teller'], ['pengajuan_pinjaman', 'hari_angsuran'],
            ];
            $ada = 0;
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            foreach ($wajib as $kolom) {
                $stmt->execute($kolom);
                $ada += (int) $stmt->fetchColumn();
            }
            $hasil = $ada < count($wajib);
        }
        return $hasil;
    }
}

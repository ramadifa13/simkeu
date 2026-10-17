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
        $max = (int) $db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(kode_anggota_jaminan, 5) AS UNSIGNED)), 0)
                                 FROM anggota WHERE kode_anggota_jaminan REGEXP '^AGTJ[0-9]+$'")->fetchColumn();
        $cek = $db->prepare("SELECT COUNT(*) FROM anggota WHERE kode_anggota_jaminan = ?");
        do {
            $max++;
            $kode = 'AGTJ' . str_pad($max, 3, '0', STR_PAD_LEFT);
            $cek->execute([$kode]);
        } while ((int) $cek->fetchColumn() > 0);
        return $kode;
    }
}

if (!function_exists('kunciKodeAnggota')) {
    /**
     * Kunci pembuatan kode anggota selama proses simpan (sampai dilepas / koneksi berakhir), supaya Admin dan
     * Teller yang menambah anggota bersamaan tidak mendapat kode yang sama. Panggil SEBELUM buatKodeAnggota().
     */
    function kunciKodeAnggota($db) {
        $db->query("SELECT GET_LOCK('kode_anggota_koperasi', 10)")->fetchColumn();
    }
}

if (!function_exists('lepasKodeAnggota')) {
    function lepasKodeAnggota($db) {
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
     * Hapus anggota beserta rekening simpanan (bersaldo 0), akun login anggota, dan
     * file dokumennya. Ditolak bila anggota sudah punya riwayat keuangan.
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

            $riwayat = riwayatAnggota($db, $anggota_id);
            if (!empty($riwayat)) {
                $rincian = [];
                foreach ($riwayat as $label => $jumlah) {
                    $rincian[] = "$jumlah $label";
                }
                throw new Exception("Anggota " . htmlspecialchars($data['nama_lengkap'])
                    . " tidak bisa dihapus karena sudah memiliki " . implode(', ', $rincian)
                    . ". Ubah statusnya menjadi Nonaktif.");
            }

            $db->beginTransaction();
            $db->prepare("DELETE FROM simpanan WHERE anggota_id = ?")->execute([$anggota_id]);
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

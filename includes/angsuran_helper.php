<?php
/**
 * Pembatalan pembayaran angsuran.
 *
 *  - batalkanPembayaranAngsuran(): eksekusi pembatalan (HANYA Admin, dicek di sini).
 *      Membalik pembukuan dengan pola admin/koreksi.php:
 *        transaksi pembayaran jadi Rp 0 + catatan "DIBATALKAN: alasan", saldo simpanan 'ANG' dikurangi,
 *        baris kas jadi Rp 0 + saldo kas sesudahnya disesuaikan, dicatat di log_koreksi,
 *        sisa pokok pinjaman bertambah, pinjaman kembali 'aktif' bila tadinya lunas.
 *      Cicilan yang dibayar lewat Pelunasan tidak punya transaksi kas; hanya statusnya yang dikembalikan.
 *  - Alur Teller (tahap 3): Teller TIDAK membatalkan sendiri, tetapi mengajukan
 *      (ajukanPembatalanAngsuran) dan Admin memutuskan (putuskanPembatalanAngsuran) - menyetujui memanggil
 *      batalkanPembayaranAngsuran() yang sama dalam satu transaksi. Selama menunggu, pembayaran TIDAK dibalik.
 *      "Pengajuan Pembatalan" adalah status tampilan dari tabel pengajuan_pembatalan_angsuran,
 *      bukan nilai baru di angsuran.status (kas/laporan/sisa tagihan tidak berubah sebelum keputusan).
 */

if (!function_exists('tabelPengajuanPembatalanAda')) {
    function tabelPengajuanPembatalanAda($db) {
        static $ada = null;
        if ($ada === null) {
            $ada = kolomAda($db, 'pengajuan_pembatalan_angsuran', 'aktif');
        }
        return $ada;
    }
}

if (!function_exists('batalkanPembayaranAngsuran')) {
    /**
     * @param bool $dalam_transaksi true bila pemanggil sudah memulai transaksi (commit/rollback oleh pemanggil)
     * @param bool $tutup_pengajuan menutup pengajuan "menunggu" milik angsuran ini (nonaktif bila pemanggil
     *             adalah proses persetujuan yang mencatat keputusannya sendiri)
     * @return string ringkasan untuk pesan sukses
     * @throws Exception
     */
    function batalkanPembayaranAngsuran($db, $angsuran_id, $alasan, $user_id, $dalam_transaksi = false, $tutup_pengajuan = true) {
        if (!isAdmin()) {
            throw new Exception("Hanya Admin yang dapat membatalkan pembayaran. Teller mengajukan pembatalan untuk disetujui Admin");
        }
        $alasan = trim($alasan);
        if (strlen($alasan) < 5) {
            throw new Exception("Alasan pembatalan wajib diisi (minimal 5 karakter)");
        }
        if (strlen($alasan) > 250) {
            $alasan = substr($alasan, 0, 250);
        }

        $errmode = $db->getAttribute(PDO::ATTR_ERRMODE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            if (!$dalam_transaksi) {
                $db->beginTransaction();
            }

            $stmt = $db->prepare("SELECT a.*, p.anggota_id, p.kode_pinjaman, p.jumlah_pinjaman
                                  FROM angsuran a JOIN pinjaman p ON a.pinjaman_id = p.id
                                  WHERE a.id = ? FOR UPDATE");
            $stmt->execute([$angsuran_id]);
            $a = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$a || $a['status'] !== 'lunas') {
                throw new Exception("Angsuran tidak ditemukan atau belum dibayar");
            }
            pastikanBulanBelumTerkunci($db, $a['tanggal_bayar']);
            if (function_exists('layananSiap') && layananSiap($db)) {
                $cek=$db->prepare('SELECT COUNT(*) FROM layanan_lelang WHERE pinjaman_id=?');$cek->execute([$a['pinjaman_id']]);
                if($cek->fetchColumn())throw new Exception('Pinjaman dengan penjualan jaminan memerlukan rekonsiliasi menyeluruh; tidak dapat dibatalkan per cicilan.');
                $cek=$db->prepare("SELECT COUNT(*) FROM layanan_pembayaran WHERE pinjaman_id=? AND jenis='pelunasan' AND dibatalkan_at IS NULL");$cek->execute([$a['pinjaman_id']]);
                if ($cek->fetchColumn()) throw new Exception('Pelunasan dipercepat tidak dapat dibatalkan per cicilan. Gunakan rekonsiliasi pelunasan menyeluruh.');
                $cek=$db->prepare("SELECT COUNT(*) FROM layanan_jaminan_kembali WHERE pinjaman_id=? AND status='diserahkan'");$cek->execute([$a['pinjaman_id']]);
                if ($cek->fetchColumn()) throw new Exception('Jaminan sudah diserahkan. Koreksi memerlukan rekonsiliasi jaminan terlebih dahulu.');
            }

            // ---- pembukuan: transaksi pembayaran terakhir untuk cicilan ini
            $ket = 'Pembayaran angsuran ke-' . $a['angsuran_ke'] . ' - ' . $a['kode_pinjaman'];
            $stmt = $db->prepare("SELECT * FROM transaksi
                                  WHERE (keterangan = ? OR keterangan LIKE CONCAT(?, ' |%')) AND jumlah > 0
                                  ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $stmt->execute([$ket, $ket]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);

            $dikembalikan = 0.0;
            if ($t) {
                $dikembalikan = (float) $t['jumlah'];
                $catatan = ' | DIBATALKAN: ' . $alasan;

                $db->prepare("UPDATE transaksi SET jumlah = 0, saldo_sesudah = saldo_sesudah - ?,
                              keterangan = CONCAT(keterangan, ?) WHERE id = ?")
                   ->execute([$dikembalikan, $catatan, $t['id']]);

                $db->prepare("UPDATE simpanan SET saldo = saldo - ? WHERE anggota_id = ? AND jenis_simpanan_id = ?")
                   ->execute([$dikembalikan, $t['anggota_id'], $t['jenis_simpanan_id']]);

                $stmt = $db->prepare("SELECT * FROM kas WHERE transaksi_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$t['id']]);
                $k = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($k) {
                    $db->prepare("UPDATE kas SET jumlah = 0, saldo_sesudah = saldo_sebelum,
                                  keterangan = CONCAT(COALESCE(keterangan, ''), ?) WHERE id = ?")
                       ->execute([$catatan, $k['id']]);
                    // Saldo kas baris-baris sesudahnya ikut turun agar buku kas tetap berkesinambungan
                    $db->prepare("UPDATE kas SET saldo_sebelum = saldo_sebelum - ?, saldo_sesudah = saldo_sesudah - ?
                                  WHERE id > ?")
                       ->execute([(float) $k['jumlah'], (float) $k['jumlah'], $k['id']]);
                }

                $db->prepare("INSERT INTO log_koreksi (transaksi_id, jumlah_lama, jumlah_baru, selisih, keterangan, user_id)
                              VALUES (?, ?, 0, ?, ?, ?)")
                   ->execute([$t['id'], $dikembalikan, -$dikembalikan, 'Pembatalan pembayaran angsuran: ' . $alasan, $user_id]);
            }

            // ---- cicilan kembali belum dibayar (terlambat bila sudah lewat jatuh tempo)
            $hitung = hitungDendaAngsuran($db, $a, $a['jumlah_pinjaman']);
            $telat = $hitung['hari_telat'] > 0;
            $set = "status = ?, tanggal_bayar = NULL, denda = ?, keterlambatan_hari = ?";
            if (kolomAda($db, 'angsuran', 'denda_manual')) {
                $set .= ", denda_manual = 0";
            }
            if (kolomAda($db, 'angsuran', 'cara_bayar')) {
                $set .= ", cara_bayar = 'cicilan'";
            }
            $db->prepare("UPDATE angsuran SET $set WHERE id = ?")
               ->execute([$telat ? 'telat' : 'belum_bayar', $telat ? $hitung['denda'] : 0, $hitung['hari_telat'], $angsuran_id]);

            // ---- pinjaman: sisa pokok bertambah, aktif lagi
            $set = "sisa_pokok = sisa_pokok + ?, status = 'aktif'";
            if (kolomAda($db, 'pinjaman', 'tanggal_lunas')) {
                $set .= ", tanggal_lunas = NULL";
            }
            $db->prepare("UPDATE pinjaman SET $set WHERE id = ?")->execute([(float) $a['pokok'], $a['pinjaman_id']]);
            aktifkanKembaliAnggota($db, $a['anggota_id']);
            if (function_exists('layananSiap') && layananSiap($db)) {
                $db->prepare('UPDATE layanan_pembayaran SET dibatalkan_at=NOW() WHERE angsuran_id=? AND dibatalkan_at IS NULL')->execute([$angsuran_id]);
                layananAudit($db,$a['pinjaman_id'],'batalkan_angsuran','Angsuran '.$angsuran_id.'; '.$alasan);
            }

            // ---- Admin membatalkan langsung: pengajuan Teller yang masih menunggu ikut ditutup
            if ($tutup_pengajuan && tabelPengajuanPembatalanAda($db)) {
                $db->prepare("UPDATE pengajuan_pembatalan_angsuran
                              SET status = 'disetujui', aktif = NULL, admin_id = ?, tanggal_keputusan = NOW(),
                                  catatan_admin = 'Dibatalkan langsung oleh Admin'
                              WHERE angsuran_id = ? AND status = 'menunggu'")
                   ->execute([$user_id, $angsuran_id]);
            }

            if (!$dalam_transaksi) {
                $db->commit();
            }
        } catch (Exception $e) {
            if (!$dalam_transaksi && $db->inTransaction()) {
                $db->rollBack();
            }
            $db->setAttribute(PDO::ATTR_ERRMODE, $errmode);
            throw $e;
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, $errmode);

        return 'Pembayaran angsuran ke-' . $a['angsuran_ke'] . ' (' . $a['kode_pinjaman'] . ') dibatalkan'
            . ($dikembalikan > 0 ? '. Kas dikurangi ' . formatRupiah($dikembalikan) : ' (dibayar lewat pelunasan, tidak ada catatan kas)');
    }
}

if (!function_exists('bisaDiajukanPembatalan')) {
    /** Teller hanya boleh mengajukan pembatalan untuk pembayaran yang dilakukan hari ini. */
    function bisaDiajukanPembatalan($tanggal_bayar) {
        return !empty($tanggal_bayar) && date('Y-m-d', strtotime($tanggal_bayar)) === date('Y-m-d');
    }
}

if (!function_exists('jumlahKeputusanPembatalanTeller')) {
    /** Keputusan Admin yang belum dilihat Teller: ['disetujui' => n, 'ditolak' => n]. */
    function jumlahKeputusanPembatalanTeller($db, $teller_id) {
        $hasil = array('disetujui' => 0, 'ditolak' => 0);
        if (!tabelPengajuanPembatalanAda($db) || !kolomAda($db, 'pengajuan_pembatalan_angsuran', 'dilihat_teller')) {
            return $hasil;
        }
        $stmt = $db->prepare("SELECT status, COUNT(*) FROM pengajuan_pembatalan_angsuran
                              WHERE teller_id = ? AND dilihat_teller = 0 AND status IN ('disetujui', 'ditolak') GROUP BY status");
        $stmt->execute(array($teller_id));
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
            $hasil[$r[0]] = (int) $r[1];
        }
        return $hasil;
    }
}

if (!function_exists('tandaiKeputusanPembatalanDilihat')) {
    function tandaiKeputusanPembatalanDilihat($db, $teller_id) {
        if (!tabelPengajuanPembatalanAda($db) || !kolomAda($db, 'pengajuan_pembatalan_angsuran', 'dilihat_teller')) {
            return;
        }
        $db->prepare("UPDATE pengajuan_pembatalan_angsuran SET dilihat_teller = 1
                      WHERE teller_id = ? AND dilihat_teller = 0 AND status <> 'menunggu'")->execute(array($teller_id));
    }
}

if (!function_exists('ajukanPembatalanAngsuran')) {
    /**
     * Teller mengajukan pembatalan pembayaran. Pembayaran TIDAK dibalik.
     * @throws Exception
     */
    function ajukanPembatalanAngsuran($db, $angsuran_id, $alasan, $teller_id) {
        if (!isTeller()) {
            throw new Exception("Hanya Teller yang dapat mengajukan pembatalan");
        }
        if (!tabelPengajuanPembatalanAda($db)) {
            throw new Exception("Database belum di-update, jalankan file migrasi tahap 3 (lihat README)");
        }
        $alasan = trim($alasan);
        if (strlen($alasan) < 5) {
            throw new Exception("Alasan pembatalan wajib diisi (minimal 5 karakter)");
        }
        if (strlen($alasan) > 250) {
            $alasan = substr($alasan, 0, 250);
        }

        $errmode = $db->getAttribute(PDO::ATTR_ERRMODE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT a.status, a.tanggal_bayar, p.status AS status_pinjaman FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id WHERE a.id = ? FOR UPDATE");
            $stmt->execute([$angsuran_id]);
            $ang = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ang && $ang['status_pinjaman'] === 'lunas') {
                throw new Exception('Histori pinjaman LUNAS terkunci untuk Teller. Hubungi Admin untuk koreksi.');
            }
            if (!$ang || $ang['status'] !== 'lunas') {
                throw new Exception("Angsuran ini belum dibayar, tidak ada pembayaran yang bisa dibatalkan");
            }
            if (!bisaDiajukanPembatalan($ang['tanggal_bayar'])) {
                throw new Exception("Pembatalan hanya bisa diajukan pada hari pembayaran (hari ini). Pembayaran tanggal sebelumnya tidak bisa diajukan pembatalan");
            }
            $stmt = $db->prepare("SELECT COUNT(*) FROM pengajuan_pembatalan_angsuran WHERE angsuran_id = ? AND status = 'menunggu'");
            $stmt->execute([$angsuran_id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new Exception("Angsuran ini sudah memiliki pengajuan pembatalan yang menunggu keputusan Admin");
            }
            $db->prepare("INSERT INTO pengajuan_pembatalan_angsuran (angsuran_id, teller_id, alasan) VALUES (?, ?, ?)")
               ->execute([$angsuran_id, $teller_id, $alasan]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $db->setAttribute(PDO::ATTR_ERRMODE, $errmode);
            // pelanggaran UNIQUE (dua klik bersamaan) -> pesan yang sama
            if ($e instanceof PDOException && $e->getCode() == 23000) {
                throw new Exception("Angsuran ini sudah memiliki pengajuan pembatalan yang menunggu keputusan Admin");
            }
            throw $e;
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, $errmode);
        return "Pengajuan pembatalan dikirim. Menunggu keputusan Admin; pembayaran belum dibatalkan.";
    }
}

if (!function_exists('putuskanPembatalanAngsuran')) {
    /**
     * Admin menyetujui/menolak pengajuan. Setuju -> memakai batalkanPembayaranAngsuran() (satu transaksi).
     * @throws Exception
     */
    function putuskanPembatalanAngsuran($db, $pengajuan_id, $setuju, $catatan, $admin_id) {
        if (!isAdmin()) {
            throw new Exception("Hanya Admin yang dapat memutuskan pengajuan pembatalan");
        }
        $catatan = trim($catatan);
        if (strlen($catatan) > 250) {
            $catatan = substr($catatan, 0, 250);
        }

        $errmode = $db->getAttribute(PDO::ATTR_ERRMODE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT pb.*, u.nama_lengkap AS teller_nama FROM pengajuan_pembatalan_angsuran pb
                                  JOIN users u ON pb.teller_id = u.id WHERE pb.id = ? FOR UPDATE");
            $stmt->execute([$pengajuan_id]);
            $pb = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pb || $pb['status'] !== 'menunggu') {
                throw new Exception("Pengajuan tidak ditemukan atau sudah diputuskan");
            }

            $pesan = 'Pengajuan pembatalan ditolak. Angsuran tetap Lunas, saldo tidak berubah.';
            if ($setuju) {
                $alasan_batal = 'Disetujui pengajuan Teller ' . $pb['teller_nama'] . ': ' . $pb['alasan'];
                $pesan = batalkanPembayaranAngsuran($db, $pb['angsuran_id'], $alasan_batal, $admin_id, true, false);
            }
            $db->prepare("UPDATE pengajuan_pembatalan_angsuran
                          SET status = ?, aktif = NULL, admin_id = ?, catatan_admin = ?, tanggal_keputusan = NOW()
                          WHERE id = ?")
               ->execute([$setuju ? 'disetujui' : 'ditolak', $admin_id, $catatan !== '' ? $catatan : null, $pengajuan_id]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $db->setAttribute(PDO::ATTR_ERRMODE, $errmode);
            throw $e;
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, $errmode);
        return $pesan;
    }
}

if (!function_exists('jumlahPengajuanPembatalan')) {
    function jumlahPengajuanPembatalan($db) {
        if (!tabelPengajuanPembatalanAda($db)) {
            return 0;
        }
        return (int) $db->query("SELECT COUNT(*) FROM pengajuan_pembatalan_angsuran WHERE status = 'menunggu'")->fetchColumn();
    }
}

if (!function_exists('pengajuanPembatalanTerakhir')) {
    /**
     * Pengajuan terbaru per angsuran untuk daftar id angsuran.
     * @return array angsuran_id => baris (status, alasan, teller_nama, admin_nama, catatan_admin, tanggal_*)
     */
    function pengajuanPembatalanTerakhir($db, array $angsuran_ids) {
        if (empty($angsuran_ids) || !tabelPengajuanPembatalanAda($db)) {
            return array();
        }
        $in = implode(',', array_fill(0, count($angsuran_ids), '?'));
        $stmt = $db->prepare("SELECT pb.*, ut.nama_lengkap AS teller_nama, ua.nama_lengkap AS admin_nama
                              FROM pengajuan_pembatalan_angsuran pb
                              JOIN users ut ON pb.teller_id = ut.id
                              LEFT JOIN users ua ON pb.admin_id = ua.id
                              WHERE pb.angsuran_id IN ($in) ORDER BY pb.id ASC");
        $stmt->execute(array_values($angsuran_ids));
        $hasil = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hasil[$row['angsuran_id']] = $row; // urut naik: yang terakhir menimpa
        }
        return $hasil;
    }
}

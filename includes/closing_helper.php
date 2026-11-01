<?php
/** Penguncian dan ringkasan angsuran per bulan. */
if (!function_exists('closingBulanValid')) {
    function closingBulanValid($bulan) {
        return is_string($bulan) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan);
    }
    function closingTabelAda($db) {
        static $ada = null;
        if ($ada !== null) return $ada;
        $q = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='closing_angsuran_bulanan'");
        return $ada = ((int) $q->fetchColumn() > 0);
    }
    function closingBulanDariTanggal($tanggal) { return date('Y-m', strtotime($tanggal)); }
    function closingBulanTerkunci($db, $bulan) {
        if (!closingTabelAda($db) || !closingBulanValid($bulan)) return false;
        $q = $db->prepare("SELECT status FROM closing_angsuran_bulanan WHERE bulan=? AND status='terkunci'");
        $q->execute([$bulan]);
        return (bool) $q->fetchColumn();
    }
    function pastikanBulanBelumTerkunci($db, $tanggal) {
        if (closingBulanTerkunci($db, closingBulanDariTanggal($tanggal))) {
            throw new Exception('Periode angsuran ' . date('m/Y', strtotime($tanggal)) . ' sudah ditutup dan dikunci. Koreksi harus diproses oleh Admin.');
        }
    }

    /**
     * Alur closing angsuran bulanan
     * --------------------------------
     * 1) jumlah = COUNT angsuran lunas dengan tanggal_bayar DAN tanggal_jatuh_tempo
     *    sama-sama di bulan M (hindari catch-up migrasi yang JT-nya bulan lain).
     * 2) total  = SUM kas masuk "Pembayaran angsuran/migrasi" di bulan M
     *    (batch Excel digabung; pelunasan & denda dikecualikan).
     *    Bila kas kosong → fallback migrasi_pembayaran / layanan.
     * 3) Preview di closing_angsuran.php membaca closingHitungBulan().
     * 4) Kunci periode menulis header + detail (nominal dialokasi proporsional ke kas).
     *
     * Oktober 2026 (Excel koperasi): target 58 pembayaran / Rp 9.714.500.
     */
    function closingWhereAngsuranBulan() {
        return "a.status = 'lunas'
            AND a.tanggal_bayar BETWEEN ? AND ?
            AND a.tanggal_jatuh_tempo BETWEEN ? AND ?";
    }

    /**
     * Kas aktual bulan ini dari buku kas: pembayaran angsuran/cicilan migrasi.
     * Batch migrasi bisa >1jt (beberapa cicilan digabung) — tetap dihitung,
     * kecuali pelunasan / denda.
     */
    function closingKasAktualBulan($db, $awal, $akhir) {
        $total = 0.0;
        try {
            $q = $db->prepare(
                "SELECT COALESCE(SUM(jumlah),0) FROM kas
                 WHERE jenis = 'masuk'
                   AND DATE(tanggal) BETWEEN ? AND ?
                   AND jumlah > 0
                   AND (
                        keterangan LIKE 'Pembayaran angsuran%'
                     OR keterangan LIKE 'Pembayaran migrasi%'
                   )
                   AND keterangan NOT LIKE '%PELUNASAN%'
                   AND keterangan NOT LIKE '%-PEL %'
                   AND keterangan NOT LIKE '%-PEL-%'
                   AND keterangan NOT LIKE '%(denda)%'
                   AND keterangan NOT LIKE '%[DIBATALKAN:%'"
            );
            $q->execute([$awal, $akhir]);
            $total = (float) $q->fetchColumn();
        } catch (Exception $e) {
            $total = 0.0;
        }

        // Fallback bila kas kosong: migrasi_pembayaran cicilan + layanan.
        if ($total < 0.009) {
            try {
                $q = $db->prepare(
                    "SELECT COALESCE(SUM(total_diterima),0) FROM migrasi_pembayaran
                     WHERE tanggal_bayar BETWEEN ? AND ?
                       AND jenis_pembayaran = 'cicilan'
                       AND total_diterima > 0"
                );
                $q->execute([$awal, $akhir]);
                $total += (float) $q->fetchColumn();
            } catch (Exception $e) {
                // optional
            }
            try {
                $cek = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='layanan_pembayaran'");
                if ((int) $cek->fetchColumn() > 0) {
                    $q = $db->prepare(
                        "SELECT COALESCE(SUM(jumlah),0) FROM layanan_pembayaran
                         WHERE dibatalkan_at IS NULL
                           AND DATE(created_at) BETWEEN ? AND ?
                           AND jenis IN ('cicilan','angsuran','bayar')
                           AND jumlah > 0"
                    );
                    $q->execute([$awal, $akhir]);
                    $total += (float) $q->fetchColumn();
                }
            } catch (Exception $e) {
                // optional
            }
        }
        return $total;
    }

    function closingDaftarAngsuranBulan($db, $awal, $akhir) {
        $q = $db->prepare(
            "SELECT a.id, a.tanggal_bayar, a.jumlah_angsuran + COALESCE(a.denda,0) AS nominal
             FROM angsuran a
             WHERE " . closingWhereAngsuranBulan() . "
             ORDER BY a.tanggal_bayar, a.id"
        );
        $q->execute([$awal, $akhir, $awal, $akhir]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    function closingHitungDariDaftar(array $rows, $kasAktual = 0.0) {
        $jumlah = count($rows);
        $jadwal = 0.0;
        foreach ($rows as $r) {
            $jadwal += (float) $r['nominal'];
        }
        // Utamakan kas aktual (sesuai buku/Excel); fallback ke jumlah jadwal.
        $total = $kasAktual > 0.009 ? (float) $kasAktual : $jadwal;
        return ['jumlah' => $jumlah, 'total' => $total, 'jadwal' => $jadwal];
    }

    /** Ringkasan preview per bulan (belum dikunci). */
    function closingPreviewMap($db) {
        $bulanRows = $db->query(
            "SELECT DISTINCT DATE_FORMAT(tanggal_bayar,'%Y-%m') bulan
             FROM angsuran
             WHERE status = 'lunas' AND tanggal_bayar IS NOT NULL
             ORDER BY bulan DESC"
        )->fetchAll(PDO::FETCH_COLUMN);
        $map = [];
        foreach ($bulanRows as $bulan) {
            if (!closingBulanValid($bulan)) {
                continue;
            }
            $map[$bulan] = closingHitungBulan($db, $bulan);
        }
        return $map;
    }

    function closingHitungBulan($db, $bulan) {
        if (!closingBulanValid($bulan)) {
            return ['jumlah' => 0, 'total' => 0.0];
        }
        $awal = $bulan . '-01';
        $akhir = date('Y-m-t', strtotime($awal));
        $rows = closingDaftarAngsuranBulan($db, $awal, $akhir);
        $hit = closingHitungDariDaftar($rows, closingKasAktualBulan($db, $awal, $akhir));
        return ['jumlah' => $hit['jumlah'], 'total' => $hit['total']];
    }

    function tutupClosingAngsuran($db, $bulan, $userId, $catatan = '') {
        if (!closingBulanValid($bulan)) throw new Exception('Bulan closing tidak valid.');
        if (closingBulanTerkunci($db, $bulan)) throw new Exception('Periode tersebut sudah terkunci.');
        $awal = $bulan . '-01'; $akhir = date('Y-m-t', strtotime($awal));
        $rows = closingDaftarAngsuranBulan($db, $awal, $akhir);
        $hit = closingHitungDariDaftar($rows, closingKasAktualBulan($db, $awal, $akhir));
        $faktor = ($hit['jadwal'] > 0.009 && $hit['total'] > 0.009)
            ? ($hit['total'] / $hit['jadwal'])
            : 1.0;

        $db->beginTransaction();
        try {
            $cek = $db->prepare("SELECT id, status FROM closing_angsuran_bulanan WHERE bulan=? FOR UPDATE");
            $cek->execute([$bulan]);
            $existing = $cek->fetch(PDO::FETCH_ASSOC);
            if ($existing && ($existing['status'] ?? '') === 'terkunci') {
                throw new Exception('Periode tersebut sudah terkunci.');
            }

            $now = date('Y-m-d H:i:s');
            $catatanVal = $catatan !== '' ? $catatan : null;
            if ($existing) {
                // Periode pernah dibuka — kunci ulang pada baris yang sama.
                $closingId = (int) $existing['id'];
                $db->prepare("DELETE FROM closing_angsuran_detail WHERE closing_id=?")->execute([$closingId]);
                $db->prepare("UPDATE closing_angsuran_bulanan
                              SET status='terkunci', ditutup_at=?, ditutup_oleh=?, dibuka_at=NULL, dibuka_oleh=NULL,
                                  catatan=?, total_pembayaran=0, jumlah_pembayaran=0
                              WHERE id=?")
                   ->execute([$now, $userId, $catatanVal, $closingId]);
            } else {
                $q = $db->prepare("INSERT INTO closing_angsuran_bulanan(bulan,status,ditutup_at,ditutup_oleh,catatan,total_pembayaran,jumlah_pembayaran) VALUES(?,?,?,?,?,0,0)");
                $q->execute([$bulan, 'terkunci', $now, $userId, $catatanVal]);
                $closingId = (int) $db->lastInsertId();
            }

            $ins = $db->prepare(
                "INSERT INTO closing_angsuran_detail(closing_id,angsuran_id,nominal,tanggal_bayar) VALUES (?,?,?,?)"
            );
            $sumNominal = 0.0;
            $lastIdx = count($rows) - 1;
            foreach ($rows as $i => $r) {
                if ($i === $lastIdx && $hit['total'] > 0.009) {
                    $nominal = round($hit['total'] - $sumNominal, 2);
                } else {
                    $nominal = round((float) $r['nominal'] * $faktor, 2);
                }
                $sumNominal += $nominal;
                $ins->execute([$closingId, (int) $r['id'], $nominal, $r['tanggal_bayar']]);
            }
            $db->prepare("UPDATE closing_angsuran_bulanan SET jumlah_pembayaran=?,total_pembayaran=? WHERE id=?")
               ->execute([$hit['jumlah'], $sumNominal, $closingId]);
            $db->commit();
            return ['id'=>$closingId, 'jumlah_pembayaran'=>$hit['jumlah'], 'total_pembayaran'=>$sumNominal];
        } catch (Exception $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    /**
     * Buka kunci periode closing (Admin). Setelah dibuka, backdate/koreksi di bulan itu diizinkan lagi.
     * Snapshot detail dihapus; kunci ulang akan menghitung ulang dari data terkini.
     */
    function bukaClosingAngsuran($db, $bulan, $userId, $catatan = '') {
        if (!closingBulanValid($bulan)) throw new Exception('Bulan closing tidak valid.');
        if (!closingTabelAda($db)) throw new Exception('Tabel closing belum tersedia.');

        $db->beginTransaction();
        try {
            $q = $db->prepare("SELECT id, catatan FROM closing_angsuran_bulanan WHERE bulan=? AND status='terkunci' FOR UPDATE");
            $q->execute([$bulan]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Periode tersebut tidak terkunci atau tidak ditemukan.');
            }

            $catatanBaru = trim((string) $catatan);
            $catatanGabung = (string) ($row['catatan'] ?? '');
            if ($catatanBaru !== '') {
                $catatanGabung = ($catatanGabung !== '' ? $catatanGabung . "\n" : '')
                    . '[Dibuka ' . date('d/m/Y H:i') . '] ' . $catatanBaru;
            }

            $db->prepare("UPDATE closing_angsuran_bulanan
                          SET status='dibuka', dibuka_at=?, dibuka_oleh=?, catatan=?
                          WHERE id=?")
               ->execute([date('Y-m-d H:i:s'), $userId, $catatanGabung !== '' ? $catatanGabung : null, (int) $row['id']]);
            $db->prepare("DELETE FROM closing_angsuran_detail WHERE closing_id=?")->execute([(int) $row['id']]);
            $db->commit();
            return ['id' => (int) $row['id'], 'bulan' => $bulan];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}

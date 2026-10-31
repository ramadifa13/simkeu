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
     * Cicilan masuk closing bulan M bila dibayar di M dan jatuh tempo di M.
     * Menghindari cicilan lama/catch-up migrasi yang ikut distempel tanggal bayar.
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
            $q = $db->prepare("INSERT INTO closing_angsuran_bulanan(bulan,status,ditutup_at,ditutup_oleh,catatan,total_pembayaran,jumlah_pembayaran) VALUES(?,?,?,?,?,0,0)");
            $q->execute([$bulan, 'terkunci', date('Y-m-d H:i:s'), $userId, $catatan !== '' ? $catatan : null]);
            $closingId = (int) $db->lastInsertId();
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
}

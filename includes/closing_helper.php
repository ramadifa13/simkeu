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
    function tutupClosingAngsuran($db, $bulan, $userId, $catatan = '') {
        if (!closingBulanValid($bulan)) throw new Exception('Bulan closing tidak valid.');
        if (closingBulanTerkunci($db, $bulan)) throw new Exception('Periode tersebut sudah terkunci.');
        $awal = $bulan . '-01'; $akhir = date('Y-m-t', strtotime($awal));
        $db->beginTransaction();
        try {
            $q = $db->prepare("INSERT INTO closing_angsuran_bulanan(bulan,status,ditutup_at,ditutup_oleh,catatan,total_pembayaran,jumlah_pembayaran) VALUES(?,?,?,?,?,0,0)");
            $q->execute([$bulan, 'terkunci', date('Y-m-d H:i:s'), $userId, $catatan !== '' ? $catatan : null]);
            $closingId = (int) $db->lastInsertId();
            $ins = $db->prepare("INSERT INTO closing_angsuran_detail(closing_id,angsuran_id,nominal,tanggal_bayar) SELECT ?,a.id,a.jumlah_angsuran+COALESCE(a.denda,0),a.tanggal_bayar FROM angsuran a WHERE a.status='lunas' AND a.tanggal_bayar BETWEEN ? AND ?");
            $ins->execute([$closingId, $awal, $akhir]);
            $sum = $db->prepare("SELECT COUNT(*),COALESCE(SUM(nominal),0) FROM closing_angsuran_detail WHERE closing_id=?"); $sum->execute([$closingId]);
            $hasil = $sum->fetch(PDO::FETCH_NUM);
            $db->prepare("UPDATE closing_angsuran_bulanan SET jumlah_pembayaran=?,total_pembayaran=? WHERE id=?")->execute([$hasil[0], $hasil[1], $closingId]);
            $db->commit();
            return ['id'=>$closingId, 'jumlah_pembayaran'=>(int)$hasil[0], 'total_pembayaran'=>(float)$hasil[1]];
        } catch (Exception $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
}

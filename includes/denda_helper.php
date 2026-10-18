<?php
/**
 * Denda keterlambatan angsuran.
 *
 * Aturan (klien, Okt 2026):
 *  - Dengan jaminan: Rp 5.000/hari per Rp 1 juta pinjaman (kelipatan juta penuh).
 *  - Tanpa jaminan: denda nominal tetap PER HARI menurut tabel denda_tarif:
 *    Rp 1 jt = 1.500/hari, Rp 2 jt = 2.500/hari, Rp 3 jt = 3.500/hari.
 *  - Hari keterlambatan dihitung mulai hari SETELAH jatuh tempo, sampai jatuh tempo cicilan
 *    berikutnya (setelah itu cicilan berikutnya yang berjalan dendanya sendiri).
 *    Cicilan terakhir tidak punya "jatuh tempo berikutnya": dendanya terus berjalan.
 *  - Otomatis (perbaruiDendaOtomatis, sekali sehari + saat bayar), tetapi petugas boleh mengisi
 *    manual; denda manual (angsuran.denda_manual = 1) tidak ditimpa hitungan otomatis.
 *
 * Besar pinjaman di luar tabel memakai tarif tingkat terdekat di bawahnya (di bawah tarif
 * terkecil memakai tarif terkecil).
 */

if (!function_exists('dendaDariParameter')) {
    /** Fungsi murni: denda = tarif per hari x hari yang dihitung (maksimal $batas_hari bila ada). */
    function dendaDariParameter($per_hari, $hari_telat, $batas_hari = null) {
        $hari = max(0, (int) $hari_telat);
        if ($batas_hari !== null) {
            $hari = min($hari, max(0, (int) $batas_hari));
        }
        return array('hari_dihitung' => $hari, 'denda' => (float) $per_hari * $hari);
    }
}

if (!function_exists('dendaPerHari')) {
    function dendaPerHari($db, $jumlah_pinjaman, $memerlukan_jaminan = false) {
        if ($memerlukan_jaminan) {
            // Pengajuan gadai hanya menerima kelipatan Rp 1 juta. Data lama di luar
            // kelipatan mengikuti tingkat juta di bawahnya, minimal satu tingkat.
            return max(1, floor((float) $jumlah_pinjaman / 1000000)) * 5000.0;
        }
        static $tarif = null;
        if ($tarif === null) {
            $tarif = array();
            $stmt = $db->query("SELECT jumlah_pinjaman, denda_per_hari FROM denda_tarif ORDER BY jumlah_pinjaman");
            if ($stmt) {
                $tarif = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        if (empty($tarif)) {
            return 0.0;
        }
        $dipakai = $tarif[0];
        foreach ($tarif as $t) {
            if ((float) $t['jumlah_pinjaman'] <= (float) $jumlah_pinjaman + 0.001) {
                $dipakai = $t;
            }
        }
        return (float) $dipakai['denda_per_hari'];
    }
}

if (!function_exists('hitungDendaAngsuran')) {
    /**
     * @param array $a baris angsuran: pinjaman_id, angsuran_ke, tanggal_jatuh_tempo
     * @return array hari_telat, hari_dihitung, per_hari, denda
     */
    function hitungDendaAngsuran($db, $a, $jumlah_pinjaman, $acuan = null) {
        $acuan = new DateTime($acuan ? $acuan : date('Y-m-d'));
        $jatuh_tempo = new DateTime($a['tanggal_jatuh_tempo']);
        $hari_telat = (int) $jatuh_tempo->diff($acuan)->format('%r%a');
        if (!array_key_exists('memerlukan_jaminan', $a)) {
            $jenis = $db->prepare("SELECT jp.memerlukan_jaminan FROM pinjaman p
                                  JOIN jenis_pinjaman jp ON jp.id = p.jenis_pinjaman_id
                                  WHERE p.id = ?");
            $jenis->execute(array($a['pinjaman_id']));
            $a['memerlukan_jaminan'] = $jenis->fetchColumn();
        }
        $per_hari = dendaPerHari($db, $jumlah_pinjaman, !empty($a['memerlukan_jaminan']));

        $batas = null;
        $stmt = $db->prepare("SELECT MIN(tanggal_jatuh_tempo) FROM angsuran WHERE pinjaman_id = ? AND angsuran_ke > ?");
        $stmt->execute(array($a['pinjaman_id'], $a['angsuran_ke']));
        $berikutnya = $stmt->fetchColumn();
        if ($berikutnya) {
            $batas = (int) $jatuh_tempo->diff(new DateTime($berikutnya))->format('%a');
        }

        $hasil = dendaDariParameter($per_hari, $hari_telat, $batas);
        return array(
            'hari_telat' => max(0, $hari_telat),
            'hari_dihitung' => $hasil['hari_dihitung'],
            'per_hari' => $per_hari,
            'denda' => $hasil['denda'],
        );
    }
}

if (!function_exists('perbaruiDendaOtomatis')) {
    /**
     * Hitung ulang denda semua cicilan yang terlambat (kecuali yang diinput manual) dan tandai 'telat'.
     * Berjalan paling banyak sekali sehari (settings.denda_dihitung_tanggal) kecuali $paksa.
     * $pinjaman_id membatasi pembaruan ke satu pinjaman tanpa menandai pembaruan harian selesai.
     * @return int jumlah cicilan yang diperbarui (0 bila sudah dihitung hari ini / migrasi belum jalan)
     */
    function perbaruiDendaOtomatis($db, $paksa = false, $pinjaman_id = null) {
        if (!kolomAda($db, 'angsuran', 'denda_manual') || !kolomAda($db, 'denda_tarif', 'denda_per_hari')) {
            return 0;
        }
        $hari_ini = date('Y-m-d');
        if (!$paksa && $pinjaman_id === null) {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'denda_dihitung_tanggal'");
            $stmt->execute();
            if ($stmt->fetchColumn() === $hari_ini) {
                return 0;
            }
        }

        $query = "SELECT a.id, a.pinjaman_id, a.angsuran_ke, a.tanggal_jatuh_tempo, a.denda, a.status,
                                     a.keterlambatan_hari, p.jumlah_pinjaman, jp.memerlukan_jaminan
                              FROM angsuran a JOIN pinjaman p ON a.pinjaman_id = p.id
                              JOIN jenis_pinjaman jp ON jp.id = p.jenis_pinjaman_id
                              WHERE a.status IN ('belum_bayar', 'telat') AND a.denda_manual = 0
                                AND a.tanggal_jatuh_tempo < ?";
        $params = array($hari_ini);
        if ($pinjaman_id !== null) {
            $query .= " AND a.pinjaman_id = ?";
            $params[] = (int) $pinjaman_id;
        }
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $update = $db->prepare("UPDATE angsuran SET denda = ?, keterlambatan_hari = ?, status = 'telat' WHERE id = ?");
        $diperbarui = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $d = hitungDendaAngsuran($db, $a, $a['jumlah_pinjaman'], $hari_ini);
            if (abs((float) $a['denda'] - $d['denda']) > 0.005 || $a['status'] !== 'telat'
                || (int) $a['keterlambatan_hari'] !== $d['hari_telat']) {
                $update->execute(array($d['denda'], $d['hari_telat'], $a['id']));
                $diperbarui++;
            }
        }

        // Perhitungan satu pinjaman tidak menggantikan pembaruan harian seluruh pinjaman.
        if ($pinjaman_id === null) {
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('denda_dihitung_tanggal', ?)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute(array($hari_ini));
        }
        return $diperbarui;
    }
}

<?php
/**
 * Data dashboard staf (Admin & Teller) — Single Source of Truth untuk query KPI.
 */

if (!function_exists('dashboardFetchScalar')) {
    function dashboardFetchScalar(PDO $db, string $sql, array $params = [])
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}

if (!function_exists('dashboardFetchRow')) {
    function dashboardFetchRow(PDO $db, string $sql, array $params = []): array
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }
}

if (!function_exists('dashboardFetchAll')) {
    function dashboardFetchAll(PDO $db, string $sql, array $params = []): array
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('dashboardLoadStaffDataDefaults')) {
    /** @return array<string,mixed> */
    function dashboardLoadStaffDataDefaults(string $role): array
    {
        return [
            'role' => $role,
            'tanggal' => function_exists('formatTanggalUi') ? formatTanggalUi(date('Y-m-d')) : date('Y-m-d'),
            'stat_anggota' => ['total' => 0, 'aktif' => 0, 'nonaktif' => 0, 'daftar_hari_ini' => 0],
            'simpanan_per_jenis' => [],
            'total_simpanan' => 0,
            'stat_kas' => ['total_masuk' => 0, 'total_keluar' => 0, 'saldo' => 0, 'total_setoran' => 0, 'total_angsuran' => 0, 'total_waserda' => 0, 'total_penarikan' => 0, 'total_pencairan' => 0],
            'waserda_stats' => ['total_transaksi' => 0, 'total_nominal' => 0],
            'waserda_hari_ini' => ['total' => 0, 'total_nominal' => 0],
            'waserda_terbaru' => [],
            'pinjaman_stats' => ['total_pinjaman' => 0, 'aktif' => 0, 'lunas' => 0, 'macet' => 0, 'total_nominal' => 0, 'total_sisa' => 0],
            'angsuran_stats' => ['total_transaksi' => 0, 'total_nominal' => 0, 'lunas' => 0, 'belum_bayar' => 0, 'telat' => 0],
            'jatuh_tempo' => [],
            'pengajuan_stats' => ['total' => 0, 'menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0],
            'siap_cair' => 0,
            'pinjaman_aktif' => [],
            'transaksi_terbaru' => [],
            'angsuran_terbaru' => [],
            'transaksi_hari_ini' => ['total_transaksi' => 0],
            'setoran_hari_ini' => ['total_transaksi' => 0, 'total_setoran' => 0],
        ];
    }
}

if (!function_exists('dashboardLoadStaffData')) {
    /**
     * @return array<string,mixed>
     */
    function dashboardLoadStaffData(PDO $db, string $role): array
    {
        $today = date('Y-m-d');
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $isAdmin = ($role === 'admin');

        $statAnggota = dashboardFetchRow($db, "SELECT
            COUNT(*) AS total,
            COUNT(CASE WHEN status = 'aktif' THEN 1 END) AS aktif,
            COUNT(CASE WHEN status = 'nonaktif' THEN 1 END) AS nonaktif,
            COUNT(CASE WHEN DATE(tanggal_daftar) = CURDATE() THEN 1 END) AS daftar_hari_ini
            FROM anggota");
        if (!$statAnggota) {
            $statAnggota = ['total' => 0, 'aktif' => 0, 'nonaktif' => 0, 'daftar_hari_ini' => 0];
        }

        $simpananPerJenis = dashboardFetchAll($db, "SELECT
            j.kode_jenis, j.nama_jenis,
            COALESCE((
                SELECT SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END)
                FROM transaksi t WHERE t.jenis_simpanan_id = j.id
            ), 0) AS total_saldo,
            COUNT(DISTINCT t.anggota_id) AS jumlah_anggota
            FROM jenis_simpanan j
            LEFT JOIN transaksi t ON t.jenis_simpanan_id = j.id
            WHERE j.status = 'aktif' AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
            GROUP BY j.id, j.kode_jenis, j.nama_jenis
            ORDER BY j.id");

        $totalSimpanan = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(
            CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END
        ), 0)
            FROM transaksi t
            JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
            WHERE j.kode_jenis NOT IN ('ANG','BONUS','SHU')");

        // Kas: saldo = masuk (setoran+angsuran+waserda) - keluar (sudah termasuk pencairan bila tercatat di kas)
        $totalSetoran = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(jumlah),0) FROM kas
            WHERE jenis = 'masuk' AND (keterangan = '' OR (keterangan NOT LIKE '%angsuran%' AND keterangan NOT LIKE '%Waserda%'))");
        $totalAngsuranKas = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(jumlah),0) FROM kas
            WHERE jenis = 'masuk' AND keterangan LIKE '%angsuran%'");
        $totalWaserdaKas = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(jumlah),0) FROM kas
            WHERE jenis = 'masuk' AND keterangan LIKE '%Waserda%'");
        $totalKeluar = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(jumlah),0) FROM kas WHERE jenis = 'keluar'");
        $totalPencairan = (float)dashboardFetchScalar($db, 'SELECT COALESCE(SUM(jumlah_pinjaman),0) FROM pinjaman');
        $totalMasuk = $totalSetoran + $totalAngsuranKas + $totalWaserdaKas;
        $statKas = [
            'total_masuk' => $totalMasuk,
            'total_keluar' => $totalKeluar,
            'saldo' => $totalMasuk - $totalKeluar,
            'total_setoran' => $totalSetoran,
            'total_angsuran' => $totalAngsuranKas,
            'total_waserda' => $totalWaserdaKas,
            'total_penarikan' => $totalKeluar,
            'total_pencairan' => $totalPencairan,
        ];

        schemaPastikanWaserda($db);
        $waserdaStats = dashboardFetchRow($db, 'SELECT COUNT(*) AS total_transaksi, COALESCE(SUM(total_bayar),0) AS total_nominal FROM waserda_transaksi')
            ?: ['total_transaksi' => 0, 'total_nominal' => 0];
        $waserdaHariIni = dashboardFetchRow($db, 'SELECT COUNT(*) AS total, COALESCE(SUM(total_bayar),0) AS total_nominal FROM waserda_transaksi WHERE DATE(created_at) = ?', [$today])
            ?: ['total' => 0, 'total_nominal' => 0];
        $waserdaTerbaru = dashboardFetchAll($db, "SELECT w.*, a.nama_lengkap, a.kode_anggota, u.nama_lengkap AS petugas
            FROM waserda_transaksi w
            JOIN anggota a ON w.anggota_id = a.id
            JOIN users u ON w.user_id = u.id
            ORDER BY w.created_at DESC LIMIT 5");
        foreach ($waserdaTerbaru as &$w) {
            $items = json_decode($w['items'] ?? '[]', true);
            $w['jml_item'] = is_array($items) ? count($items) : 1;
            $w['nama_barang'] = (is_array($items) && isset($items[0]['nama'])) ? $items[0]['nama'] : 'barang';
        }
        unset($w);

        $sisaPokok = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(sisa_pokok),0) FROM pinjaman WHERE status = 'aktif' AND sisa_pokok > 0");
        $sisaBunga = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(bunga),0) FROM angsuran WHERE status != 'lunas'");
        $pinjamanStats = dashboardFetchRow($db, "SELECT
            COUNT(*) AS total_pinjaman,
            COUNT(CASE WHEN status = 'aktif' THEN 1 END) AS aktif,
            COUNT(CASE WHEN status = 'lunas' THEN 1 END) AS lunas,
            COUNT(CASE WHEN status = 'macet' THEN 1 END) AS macet,
            COALESCE(SUM(jumlah_pinjaman),0) AS total_nominal
            FROM pinjaman") ?: [
            'total_pinjaman' => 0, 'aktif' => 0, 'lunas' => 0, 'macet' => 0, 'total_nominal' => 0,
        ];
        $pinjamanStats['total_sisa'] = $sisaPokok + $sisaBunga;

        $angsuranStats = dashboardFetchRow($db, "SELECT
            COUNT(*) AS total_transaksi,
            COALESCE(SUM(jumlah_angsuran + COALESCE(denda,0)),0) AS total_nominal,
            COUNT(CASE WHEN status = 'lunas' THEN 1 END) AS lunas,
            COUNT(CASE WHEN status = 'belum_bayar' THEN 1 END) AS belum_bayar,
            COUNT(CASE WHEN status = 'telat' THEN 1 END) AS telat
            FROM angsuran") ?: [
            'total_transaksi' => 0, 'total_nominal' => 0, 'lunas' => 0, 'belum_bayar' => 0, 'telat' => 0,
        ];

        $jatuhTempo = dashboardFetchAll($db, "SELECT a.*, ang.nama_lengkap, ang.kode_anggota, p.kode_pinjaman, p.jumlah_pinjaman, p.sisa_pokok
            FROM angsuran a
            JOIN pinjaman p ON a.pinjaman_id = p.id
            JOIN anggota ang ON p.anggota_id = ang.id
            WHERE a.status IN ('belum_bayar','telat')
              AND a.tanggal_jatuh_tempo BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
            ORDER BY a.tanggal_jatuh_tempo ASC
            LIMIT 8", [$today, $today]);

        $pengajuanStats = dashboardFetchRow($db, "SELECT
            COUNT(*) AS total,
            COUNT(CASE WHEN status = 'menunggu' THEN 1 END) AS menunggu,
            COUNT(CASE WHEN status = 'disetujui' THEN 1 END) AS disetujui,
            COUNT(CASE WHEN status = 'ditolak' THEN 1 END) AS ditolak
            FROM pengajuan_pinjaman") ?: ['total' => 0, 'menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0];

        $siapCair = (int)dashboardFetchScalar($db, "SELECT COUNT(*) FROM pengajuan_pinjaman pp
            WHERE pp.status = 'disetujui'
              AND NOT EXISTS (SELECT 1 FROM pinjaman p WHERE p.pengajuan_id = pp.id)");

        $pinjamanAktif = dashboardFetchAll($db, "SELECT p.*, a.nama_lengkap, a.kode_anggota, jp.nama_jenis,
            (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') AS angsuran_lunas,
            (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id) AS total_angsuran,
            (SELECT COALESCE(SUM(bunga),0) FROM angsuran WHERE pinjaman_id = p.id AND status != 'lunas') AS sisa_bunga
            FROM pinjaman p
            JOIN anggota a ON p.anggota_id = a.id
            JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
            WHERE p.status = 'aktif'
            ORDER BY p.tanggal_pencairan DESC
            LIMIT 5");

        if ($isAdmin) {
            $transaksiTerbaru = dashboardFetchAll($db, "SELECT t.*, a.nama_lengkap, a.kode_anggota, j.kode_jenis, j.nama_jenis, u.nama_lengkap AS teller
                FROM transaksi t
                JOIN anggota a ON t.anggota_id = a.id
                JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                JOIN users u ON t.user_id = u.id
                WHERE j.kode_jenis NOT IN ('ANG','BONUS','SHU')
                ORDER BY t.tanggal_transaksi DESC LIMIT 6");
        } else {
            $transaksiTerbaru = dashboardFetchAll($db, "SELECT t.*, a.nama_lengkap, a.kode_anggota, j.kode_jenis, j.nama_jenis
                FROM transaksi t
                JOIN anggota a ON t.anggota_id = a.id
                JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                WHERE t.user_id = ? AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
                ORDER BY t.tanggal_transaksi DESC LIMIT 6", [$userId]);
        }

        $angsuranTerbaru = dashboardFetchAll($db, "SELECT a.*, ang.nama_lengkap, ang.kode_anggota, p.kode_pinjaman, jp.nama_jenis AS jenis_pinjaman
            FROM angsuran a
            JOIN pinjaman p ON a.pinjaman_id = p.id
            JOIN anggota ang ON p.anggota_id = ang.id
            JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
            WHERE a.tanggal_bayar IS NOT NULL
            ORDER BY a.tanggal_bayar DESC, a.created_at DESC
            LIMIT 6");

        $transaksiHariIni = [
            'total_transaksi' => (int)dashboardFetchScalar($db, 'SELECT COUNT(*) FROM kas WHERE DATE(tanggal) = ?', [$today]),
        ];
        $setoranHariIni = dashboardFetchRow($db, "SELECT COUNT(*) AS total_transaksi, COALESCE(SUM(jumlah),0) AS total_setoran
            FROM kas WHERE jenis = 'masuk' AND DATE(tanggal) = ?
            AND (keterangan = '' OR (keterangan NOT LIKE '%angsuran%' AND keterangan NOT LIKE '%Waserda%'))", [$today])
            ?: ['total_transaksi' => 0, 'total_setoran' => 0];

        return [
            'role' => $role,
            'tanggal' => formatTanggalUi($today),
            'stat_anggota' => $statAnggota,
            'simpanan_per_jenis' => $simpananPerJenis,
            'total_simpanan' => $totalSimpanan,
            'stat_kas' => $statKas,
            'waserda_stats' => $waserdaStats,
            'waserda_hari_ini' => $waserdaHariIni,
            'waserda_terbaru' => $waserdaTerbaru,
            'pinjaman_stats' => $pinjamanStats,
            'angsuran_stats' => $angsuranStats,
            'jatuh_tempo' => $jatuhTempo,
            'pengajuan_stats' => $pengajuanStats,
            'siap_cair' => $siapCair,
            'pinjaman_aktif' => $pinjamanAktif,
            'transaksi_terbaru' => $transaksiTerbaru,
            'angsuran_terbaru' => $angsuranTerbaru,
            'transaksi_hari_ini' => $transaksiHariIni,
            'setoran_hari_ini' => $setoranHariIni,
        ];
    }
}

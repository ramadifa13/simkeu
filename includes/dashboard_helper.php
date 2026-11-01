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
            'setoran_hari_ini' => ['total_transaksi' => 0, 'total_setoran' => 0, 'total_nasabah' => 0],
            'pertumbuhan_laba' => [
                'bulan' => date('Y-m'),
                'bulan_label' => date('Y-m'),
                'terkunci' => false,
                'total_angsuran' => 0.0,
                'total_bunga' => 0.0,
                'total_pokok' => 0.0,
                'persen' => 0.0,
                'jumlah_trx' => 0,
            ],
            'pertumbuhan_series' => [],
            'tren' => [
                'noa' => [],
                'telat' => [],
                'denda' => [],
                'macet' => [],
            ],
        ];
    }
}

if (!function_exists('dashboardLabelBulan')) {
    function dashboardLabelBulan(string $ym): string
    {
        static $nama = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
            return $ym;
        }
        $n = (int)$m[2];
        return ($nama[$n] ?? $m[2]) . ' ' . $m[1];
    }
}

if (!function_exists('dashboardLoadPertumbuhanLaba')) {
    /**
     * Persentase pertumbuhan laba = bunga / total angsuran periode.
     * Prioritas: closing terkunci terakhir; jika belum ada → bulan berjalan (preview).
     *
     * @return array{bulan:string,bulan_label:string,terkunci:bool,total_angsuran:float,total_bunga:float,total_pokok:float,persen:float,jumlah_trx:int}
     */
    function dashboardLoadPertumbuhanLaba(PDO $db): array
    {
        $bulan = date('Y-m');
        $terkunci = false;
        if (function_exists('closingTabelAda') && closingTabelAda($db)) {
            $locked = dashboardFetchScalar(
                $db,
                "SELECT bulan FROM closing_angsuran_bulanan WHERE status='terkunci' ORDER BY bulan DESC LIMIT 1"
            );
            if (is_string($locked) && preg_match('/^\d{4}-\d{2}$/', $locked)) {
                $bulan = $locked;
                $terkunci = true;
            }
        }

        $awal = $bulan . '-01';
        $akhir = date('Y-m-t', strtotime($awal));

        if ($terkunci) {
            $row = dashboardFetchRow($db, "SELECT
                    COUNT(*) AS jumlah_trx,
                    COALESCE(SUM(a.jumlah_angsuran + COALESCE(a.denda,0)),0) AS total_angsuran,
                    COALESCE(SUM(a.bunga),0) AS total_bunga,
                    COALESCE(SUM(a.pokok),0) AS total_pokok
                FROM closing_angsuran_detail d
                JOIN angsuran a ON a.id = d.angsuran_id
                JOIN closing_angsuran_bulanan c ON c.id = d.closing_id
                WHERE c.bulan = ? AND c.status = 'terkunci'", [$bulan]);
        } else {
            $row = dashboardFetchRow($db, "SELECT
                    COUNT(*) AS jumlah_trx,
                    COALESCE(SUM(jumlah_angsuran + COALESCE(denda,0)),0) AS total_angsuran,
                    COALESCE(SUM(bunga),0) AS total_bunga,
                    COALESCE(SUM(pokok),0) AS total_pokok
                FROM angsuran
                WHERE status = 'lunas'
                  AND tanggal_bayar BETWEEN ? AND ?
                  AND tanggal_jatuh_tempo BETWEEN ? AND ?", [$awal, $akhir, $awal, $akhir]);
        }

        $totalAngsuran = (float)($row['total_angsuran'] ?? 0);
        $totalBunga = (float)($row['total_bunga'] ?? 0);
        $totalPokok = (float)($row['total_pokok'] ?? 0);
        $persen = $totalAngsuran > 0 ? round(($totalBunga / $totalAngsuran) * 100, 1) : 0.0;

        return [
            'bulan' => $bulan,
            'bulan_label' => dashboardLabelBulan($bulan),
            'terkunci' => $terkunci,
            'total_angsuran' => $totalAngsuran,
            'total_bunga' => $totalBunga,
            'total_pokok' => $totalPokok,
            'persen' => $persen,
            'jumlah_trx' => (int)($row['jumlah_trx'] ?? 0),
        ];
    }
}

if (!function_exists('dashboardBulanKeys')) {
    /**
     * @return list<string> YYYY-MM tertua → terbaru
     */
    function dashboardBulanKeys(int $bulanKeBelakang = 6): array
    {
        $bulanKeBelakang = max(3, min(12, $bulanKeBelakang));
        $out = [];
        for ($i = $bulanKeBelakang - 1; $i >= 0; $i--) {
            $out[] = date('Y-m', strtotime("first day of -{$i} month"));
        }
        return $out;
    }
}

if (!function_exists('dashboardDeltaPersen')) {
    function dashboardDeltaPersen(float $sekarang, float $sebelum): ?float
    {
        if ($sebelum == 0.0) {
            return $sekarang > 0 ? 100.0 : ($sekarang < 0 ? -100.0 : null);
        }
        return round((($sekarang - $sebelum) / abs($sebelum)) * 100, 1);
    }
}

if (!function_exists('dashboardMapByBulan')) {
    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    function dashboardMapByBulan(array $rows, string $key = 'bulan'): array
    {
        $map = [];
        foreach ($rows as $row) {
            $b = (string)($row[$key] ?? '');
            if ($b !== '') {
                $map[$b] = $row;
            }
        }
        return $map;
    }
}

if (!function_exists('dashboardLoadNoaSeries')) {
    /**
     * Pertumbuhan anggota/NOA: anggota baru per bulan + kumulatif.
     * @return list<array{bulan:string,label:string,baru:int,kumulatif:int,delta:float|null}>
     */
    function dashboardLoadNoaSeries(PDO $db, int $bulanKeBelakang = 6): array
    {
        $keys = dashboardBulanKeys($bulanKeBelakang);
        $awal = $keys[0] . '-01';
        $rows = dashboardFetchAll($db, "SELECT DATE_FORMAT(tanggal_daftar, '%Y-%m') AS bulan, COUNT(*) AS baru
            FROM anggota
            WHERE tanggal_daftar IS NOT NULL AND tanggal_daftar >= ?
            GROUP BY DATE_FORMAT(tanggal_daftar, '%Y-%m')", [$awal]);
        $map = dashboardMapByBulan($rows);
        $sebelumAwal = (int)dashboardFetchScalar(
            $db,
            'SELECT COUNT(*) FROM anggota WHERE tanggal_daftar IS NOT NULL AND tanggal_daftar < ?',
            [$awal]
        );
        $kumulatif = $sebelumAwal;
        $out = [];
        $prevBaru = null;
        foreach ($keys as $bulan) {
            $baru = (int)($map[$bulan]['baru'] ?? 0);
            $kumulatif += $baru;
            $out[] = [
                'bulan' => $bulan,
                'label' => dashboardLabelBulan($bulan),
                'baru' => $baru,
                'kumulatif' => $kumulatif,
                'delta' => $prevBaru === null ? null : dashboardDeltaPersen((float)$baru, (float)$prevBaru),
            ];
            $prevBaru = $baru;
        }
        return $out;
    }
}

if (!function_exists('dashboardLoadTelatSeries')) {
    /**
     * Telat angsuran per bulan jatuh tempo (masih telat / pernah bayar terlambat).
     * @return list<array{bulan:string,label:string,jumlah:int,nominal:float,delta:float|null}>
     */
    function dashboardLoadTelatSeries(PDO $db, int $bulanKeBelakang = 6): array
    {
        $keys = dashboardBulanKeys($bulanKeBelakang);
        $awal = $keys[0] . '-01';
        $rows = dashboardFetchAll($db, "SELECT DATE_FORMAT(tanggal_jatuh_tempo, '%Y-%m') AS bulan,
                COUNT(*) AS jumlah,
                COALESCE(SUM(jumlah_angsuran + COALESCE(denda,0)),0) AS nominal
            FROM angsuran
            WHERE tanggal_jatuh_tempo >= ?
              AND (
                    status = 'telat'
                 OR COALESCE(keterlambatan_hari,0) > 0
                 OR (status = 'lunas' AND tanggal_bayar IS NOT NULL AND tanggal_bayar > tanggal_jatuh_tempo)
              )
            GROUP BY DATE_FORMAT(tanggal_jatuh_tempo, '%Y-%m')", [$awal]);
        $map = dashboardMapByBulan($rows);
        $out = [];
        $prev = null;
        foreach ($keys as $bulan) {
            $jumlah = (int)($map[$bulan]['jumlah'] ?? 0);
            $nominal = (float)($map[$bulan]['nominal'] ?? 0);
            $out[] = [
                'bulan' => $bulan,
                'label' => dashboardLabelBulan($bulan),
                'jumlah' => $jumlah,
                'nominal' => $nominal,
                'delta' => $prev === null ? null : dashboardDeltaPersen((float)$jumlah, (float)$prev),
            ];
            $prev = $jumlah;
        }
        return $out;
    }
}

if (!function_exists('dashboardLoadDendaSeries')) {
    /**
     * Denda per bulan: terbentuk (pada cicilan telat) vs tertagih.
     * @return list<array{bulan:string,label:string,terbentuk:float,tertagih:float,delta:float|null}>
     */
    function dashboardLoadDendaSeries(PDO $db, int $bulanKeBelakang = 6): array
    {
        $keys = dashboardBulanKeys($bulanKeBelakang);
        $awal = $keys[0] . '-01';

        $terbentukRows = dashboardFetchAll($db, "SELECT DATE_FORMAT(tanggal_jatuh_tempo, '%Y-%m') AS bulan,
                COALESCE(SUM(COALESCE(denda,0)),0) AS terbentuk
            FROM angsuran
            WHERE tanggal_jatuh_tempo >= ?
              AND COALESCE(denda,0) > 0
            GROUP BY DATE_FORMAT(tanggal_jatuh_tempo, '%Y-%m')", [$awal]);
        $terbentukMap = dashboardMapByBulan($terbentukRows);

        $tertagihMap = [];
        $punyaLedgerDenda = false;
        try {
            $bayarRows = dashboardFetchAll($db, "SELECT DATE_FORMAT(created_at, '%Y-%m') AS bulan,
                    COALESCE(SUM(jumlah),0) AS tertagih
                FROM layanan_bayar_denda
                WHERE created_at >= ?
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')", [$awal . ' 00:00:00']);
            $tertagihMap = dashboardMapByBulan($bayarRows);
            $punyaLedgerDenda = true;
        } catch (Throwable $e) {
            // tabel layanan belum ada di sebagian DB lama
        }

        // Legacy: denda ikut lunas di angsuran tanpa ledger layanan_bayar_denda
        if ($punyaLedgerDenda) {
            $lunasRows = dashboardFetchAll($db, "SELECT DATE_FORMAT(a.tanggal_bayar, '%Y-%m') AS bulan,
                    COALESCE(SUM(COALESCE(a.denda,0)),0) AS tertagih
                FROM angsuran a
                WHERE a.status = 'lunas'
                  AND a.tanggal_bayar IS NOT NULL
                  AND a.tanggal_bayar >= ?
                  AND COALESCE(a.denda,0) > 0
                  AND NOT EXISTS (SELECT 1 FROM layanan_bayar_denda b WHERE b.angsuran_id = a.id)
                GROUP BY DATE_FORMAT(a.tanggal_bayar, '%Y-%m')", [$awal]);
        } else {
            $lunasRows = dashboardFetchAll($db, "SELECT DATE_FORMAT(tanggal_bayar, '%Y-%m') AS bulan,
                    COALESCE(SUM(COALESCE(denda,0)),0) AS tertagih
                FROM angsuran
                WHERE status = 'lunas'
                  AND tanggal_bayar IS NOT NULL
                  AND tanggal_bayar >= ?
                  AND COALESCE(denda,0) > 0
                GROUP BY DATE_FORMAT(tanggal_bayar, '%Y-%m')", [$awal]);
        }
        foreach ($lunasRows as $row) {
            $b = (string)$row['bulan'];
            $tertagihMap[$b]['tertagih'] = (float)($tertagihMap[$b]['tertagih'] ?? 0) + (float)$row['tertagih'];
        }

        $out = [];
        $prev = null;
        foreach ($keys as $bulan) {
            $terbentuk = (float)($terbentukMap[$bulan]['terbentuk'] ?? 0);
            $tertagih = (float)($tertagihMap[$bulan]['tertagih'] ?? 0);
            $out[] = [
                'bulan' => $bulan,
                'label' => dashboardLabelBulan($bulan),
                'terbentuk' => $terbentuk,
                'tertagih' => $tertagih,
                'delta' => $prev === null ? null : dashboardDeltaPersen($terbentuk, (float)$prev),
            ];
            $prev = $terbentuk;
        }
        return $out;
    }
}

if (!function_exists('dashboardLoadMacetSeries')) {
    /**
     * Macet (≥7 hari) diakhir bulan: jumlah pinjaman + nominal sisa pokok.
     * Rekonstruksi point-in-time dari jadwal angsuran (tanpa snapshot historis).
     * @return list<array{bulan:string,label:string,jumlah:int,nominal:float,delta:float|null}>
     */
    function dashboardLoadMacetSeries(PDO $db, int $bulanKeBelakang = 6): array
    {
        $keys = dashboardBulanKeys($bulanKeBelakang);
        $punyaTanggalLunas = function_exists('kolomAda') && kolomAda($db, 'pinjaman', 'tanggal_lunas');
        $aktifSql = $punyaTanggalLunas
            ? "(p.status IN ('aktif','macet') OR (p.status = 'lunas' AND (p.tanggal_lunas IS NULL OR p.tanggal_lunas > ?)))"
            : "(p.status IN ('aktif','macet') OR p.status = 'lunas')";
        $out = [];
        $prev = null;
        $hariIni = date('Y-m-d');
        foreach ($keys as $bulan) {
            $akhirBulan = date('Y-m-t', strtotime($bulan . '-01'));
            $acuan = $akhirBulan > $hariIni ? $hariIni : $akhirBulan;
            $paramsAktif = $punyaTanggalLunas ? [$acuan, $acuan] : [$acuan];
            $jumlah = (int)dashboardFetchScalar($db, "SELECT COUNT(*)
                FROM pinjaman p
                WHERE p.tanggal_pencairan <= ?
                  AND {$aktifSql}
                  AND EXISTS (
                    SELECT 1 FROM angsuran a
                    WHERE a.pinjaman_id = p.id
                      AND a.tanggal_jatuh_tempo <= ?
                      AND DATEDIFF(?, a.tanggal_jatuh_tempo) >= 7
                      AND (
                            a.status IN ('belum_bayar','telat')
                         OR (a.status = 'lunas' AND a.tanggal_bayar IS NOT NULL AND a.tanggal_bayar > ?)
                      )
                  )", array_merge($paramsAktif, [$acuan, $acuan, $acuan]));
            $nominal = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(a.jumlah_angsuran + COALESCE(a.denda,0)),0)
                FROM angsuran a
                JOIN pinjaman p ON p.id = a.pinjaman_id
                WHERE p.tanggal_pencairan <= ?
                  AND {$aktifSql}
                  AND EXISTS (
                    SELECT 1 FROM angsuran x
                    WHERE x.pinjaman_id = p.id
                      AND x.tanggal_jatuh_tempo <= ?
                      AND DATEDIFF(?, x.tanggal_jatuh_tempo) >= 7
                      AND (
                            x.status IN ('belum_bayar','telat')
                         OR (x.status = 'lunas' AND x.tanggal_bayar IS NOT NULL AND x.tanggal_bayar > ?)
                      )
                  )
                  AND a.tanggal_jatuh_tempo <= ?
                  AND (
                        a.status IN ('belum_bayar','telat')
                     OR (a.status = 'lunas' AND a.tanggal_bayar IS NOT NULL AND a.tanggal_bayar > ?)
                  )", array_merge($paramsAktif, [$acuan, $acuan, $acuan, $acuan, $acuan]));

            $out[] = [
                'bulan' => $bulan,
                'label' => dashboardLabelBulan($bulan),
                'jumlah' => $jumlah,
                'nominal' => $nominal,
                'delta' => $prev === null ? null : dashboardDeltaPersen((float)$jumlah, (float)$prev),
            ];
            $prev = $jumlah;
        }
        return $out;
    }
}

if (!function_exists('dashboardLoadTrenOperasional')) {
    /**
     * @return array{noa:list,telat:list,denda:list,macet:list}
     */
    function dashboardLoadTrenOperasional(PDO $db, int $bulanKeBelakang = 6): array
    {
        return [
            'noa' => dashboardLoadNoaSeries($db, $bulanKeBelakang),
            'telat' => dashboardLoadTelatSeries($db, $bulanKeBelakang),
            'denda' => dashboardLoadDendaSeries($db, $bulanKeBelakang),
            'macet' => dashboardLoadMacetSeries($db, $bulanKeBelakang),
        ];
    }
}

if (!function_exists('dashboardLoadPertumbuhanLabaSeries')) {
    /**
     * Deret bulanan untuk grafik: bunga, total angsuran, dan % laba (6 bulan terakhir).
     * @return list<array{bulan:string,label:string,bunga:float,angsuran:float,persen:float}>
     */
    function dashboardLoadPertumbuhanLabaSeries(PDO $db, int $bulanKeBelakang = 6): array
    {
        $out = [];
        $bulanKeBelakang = max(3, min(12, $bulanKeBelakang));
        for ($i = $bulanKeBelakang - 1; $i >= 0; $i--) {
            $bulan = date('Y-m', strtotime("first day of -{$i} month"));
            $awal = $bulan . '-01';
            $akhir = date('Y-m-t', strtotime($awal));
            $terkunci = function_exists('closingBulanTerkunci') && closingBulanTerkunci($db, $bulan);
            if ($terkunci) {
                $row = dashboardFetchRow($db, "SELECT
                        COALESCE(SUM(a.jumlah_angsuran + COALESCE(a.denda,0)),0) AS total_angsuran,
                        COALESCE(SUM(a.bunga),0) AS total_bunga
                    FROM closing_angsuran_detail d
                    JOIN angsuran a ON a.id = d.angsuran_id
                    JOIN closing_angsuran_bulanan c ON c.id = d.closing_id
                    WHERE c.bulan = ? AND c.status = 'terkunci'", [$bulan]);
            } else {
                $row = dashboardFetchRow($db, "SELECT
                        COALESCE(SUM(jumlah_angsuran + COALESCE(denda,0)),0) AS total_angsuran,
                        COALESCE(SUM(bunga),0) AS total_bunga
                    FROM angsuran
                    WHERE status = 'lunas'
                      AND tanggal_bayar BETWEEN ? AND ?
                      AND tanggal_jatuh_tempo BETWEEN ? AND ?", [$awal, $akhir, $awal, $akhir]);
            }
            $angsuran = (float)($row['total_angsuran'] ?? 0);
            $bunga = (float)($row['total_bunga'] ?? 0);
            $persen = $angsuran > 0 ? round(($bunga / $angsuran) * 100, 1) : 0.0;
            $out[] = [
                'bulan' => $bulan,
                'label' => dashboardLabelBulan($bulan),
                'bunga' => $bunga,
                'angsuran' => $angsuran,
                'persen' => $persen,
            ];
        }
        return $out;
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

        // Kas: pecahan masuk untuk kartu; saldo pakai ledger terakhir (saldo_sesudah)
        // agar selaras setelah migrasiRekalkulasiKas / koreksi jumlah baris kas.
        $totalSetoran = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(jumlah),0) FROM kas
            WHERE jenis = 'masuk' AND jumlah > 0
              AND (keterangan = '' OR (keterangan NOT LIKE '%angsuran%' AND keterangan NOT LIKE '%migrasi%' AND keterangan NOT LIKE '%Waserda%'))");
        $totalAngsuranKas = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(jumlah),0) FROM kas
            WHERE jenis = 'masuk' AND jumlah > 0
              AND (keterangan LIKE '%angsuran%' OR keterangan LIKE 'Pembayaran migrasi%')");
        $totalWaserdaKas = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(jumlah),0) FROM kas
            WHERE jenis = 'masuk' AND jumlah > 0 AND keterangan LIKE '%Waserda%'");
        $totalKeluar = (float)dashboardFetchScalar($db, "SELECT COALESCE(SUM(jumlah),0) FROM kas
            WHERE jenis = 'keluar' AND jumlah > 0 AND keterangan NOT LIKE 'Pencairan migrasi%'");
        $totalPencairan = (float)dashboardFetchScalar($db, 'SELECT COALESCE(SUM(jumlah_pinjaman),0) FROM pinjaman');
        $totalMasuk = $totalSetoran + $totalAngsuranKas + $totalWaserdaKas;
        $saldoLedger = (float)dashboardFetchScalar($db, 'SELECT COALESCE(saldo_sesudah,0) FROM kas ORDER BY id DESC LIMIT 1');
        $statKas = [
            'total_masuk' => $totalMasuk,
            'total_keluar' => $totalKeluar,
            'saldo' => $saldoLedger,
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

        // Teller: hanya transaksi yang diinput dirinya. Admin: gabungan semua input teller/admin.
        $kasHariIniSql = 'SELECT COUNT(*) FROM kas k
            LEFT JOIN transaksi t ON t.id = k.transaksi_id
            WHERE DATE(k.tanggal) = ?';
        $kasHariIniParams = [$today];
        if (!$isAdmin) {
            $kasHariIniSql .= ' AND t.user_id = ?';
            $kasHariIniParams[] = $userId;
        }
        $transaksiHariIni = [
            'total_transaksi' => (int)dashboardFetchScalar($db, $kasHariIniSql, $kasHariIniParams),
        ];

        // Setoran hari ini = kas masuk operasional (simpanan + angsuran). Waserda dikecualikan.
        $setoranSql = "SELECT
                COUNT(*) AS total_transaksi,
                COALESCE(SUM(k.jumlah), 0) AS total_setoran,
                COUNT(DISTINCT t.anggota_id) AS total_nasabah
            FROM kas k
            INNER JOIN transaksi t ON t.id = k.transaksi_id
            WHERE k.jenis = 'masuk' AND DATE(k.tanggal) = ?
              AND (k.keterangan IS NULL OR k.keterangan = '' OR k.keterangan NOT LIKE '%Waserda%')";
        $setoranParams = [$today];
        if (!$isAdmin) {
            $setoranSql .= ' AND t.user_id = ?';
            $setoranParams[] = $userId;
        }
        $setoranHariIni = dashboardFetchRow($db, $setoranSql, $setoranParams)
            ?: ['total_transaksi' => 0, 'total_setoran' => 0, 'total_nasabah' => 0];

        $pertumbuhanLaba = $isAdmin
            ? dashboardLoadPertumbuhanLaba($db)
            : dashboardLoadStaffDataDefaults($role)['pertumbuhan_laba'];
        $pertumbuhanSeries = $isAdmin
            ? dashboardLoadPertumbuhanLabaSeries($db, 6)
            : [];
        $tren = $isAdmin
            ? dashboardLoadTrenOperasional($db, 6)
            : dashboardLoadStaffDataDefaults($role)['tren'];

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
            'pertumbuhan_laba' => $pertumbuhanLaba,
            'pertumbuhan_series' => $pertumbuhanSeries,
            'tren' => $tren,
        ];
    }
}

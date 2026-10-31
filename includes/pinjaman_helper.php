<?php
require_once __DIR__ . '/shu_pinjaman_helper.php';
/**
 * Helper perhitungan pinjaman & jadwal angsuran.
 *
 * SATU-SATUNYA tempat rumus bunga/angsuran. Bila klien mengirim tabel angsuran
 * resmi, cukup sesuaikan hitungPinjaman() di sini dan hitungPinjaman() di
 * assets/js/pinjaman.js (preview form) agar hasilnya tetap sama.
 *
 * Skema bunga:
 *   'flat'    : total = pokok + (pokok x bunga%)  -> bunga untuk keseluruhan tenor
 *   'tahunan' : rumus lama (pinjaman sebelum revisi Okt 2026), tidak boleh diubah
 *               supaya nilai pinjaman lama tetap sama.
 */

if (!defined('BUNGA_MAKS')) {
    define('BUNGA_MAKS', 1000); // batas wajar bunga (%) agar tidak salah ketik
}

if (!function_exists('parseRupiah')) {
    /**
     * Validasi nominal rupiah penuh (boleh tidak bulat ribuan, mis. 112500 / 1112575).
     * @return string|false angka tanpa desimal, atau false bila tidak valid
     */
    function parseRupiah($nilai) {
        $nilai = trim((string) $nilai);
        if (!preg_match('/^\d{1,13}(\.0+)?$/', $nilai)) {
            return false;
        }
        return ltrim(preg_replace('/\.0+$/', '', $nilai), '0') ?: '0';
    }
}

if (!function_exists('hitungJumlahCicilan')) {
    function hitungJumlahCicilan($tenor_bulan, $frekuensi) {
        $tenor_bulan = (int) $tenor_bulan;
        if ($frekuensi === 'mingguan') return $tenor_bulan * 4;
        if ($frekuensi === 'dua_mingguan') return $tenor_bulan * 2;
        return $tenor_bulan;
    }
}

if (!function_exists('biayaMingguanGadai')) {
    /** Biaya tambahan gadai per minggu menurut nominal yang disepakati. */
    function biayaMingguanGadai($pokok) {
        $pokok = (float) $pokok;
        if ($pokok < 1000000 || fmod($pokok, 1000000.0) != 0.0) return null;
        // Pola tarif user: 1 jt=10 rb, 2 jt=15 rb, 3 jt=20 rb, dst. +5 rb/juta.
        return 5000 + ((int) ($pokok / 1000000) * 5000);
    }
}

if (!function_exists('hitungPinjamanGadai')) {
    /**
     * Gadai: bunga dasar flat (umumnya 15%) + biaya mingguan x (tenor bulan x 4).
     * Hasil tetap memakai hitungPinjaman agar pembulatan jadwal konsisten.
     */
    function hitungPinjamanGadai($pokok, $bunga_dasar, $tenor_bulan, $frekuensi) {
        $biaya_mingguan = biayaMingguanGadai($pokok);
        if ($biaya_mingguan === null) {
            throw new InvalidArgumentException('Nominal pinjaman gadai harus kelipatan Rp 1 juta');
        }
        $tambahan = $biaya_mingguan * ((int) $tenor_bulan * 4);
        $bunga_dasar_nominal = round((float) $pokok * (float) $bunga_dasar / 100);
        $bunga_efektif = (($bunga_dasar_nominal + $tambahan) / (float) $pokok) * 100;
        $hasil = hitungPinjaman($pokok, $bunga_efektif, $tenor_bulan, $frekuensi, 'flat');
        $hasil['bunga_dasar_persen'] = (float) $bunga_dasar;
        $hasil['biaya_mingguan_gadai'] = $biaya_mingguan;
        $hasil['tambahan_gadai'] = $tambahan;
        return $hasil;
    }
}

if (!function_exists('hitungPinjaman')) {
    /**
     * @return array [
     *   pokok, bunga_persen, skema, frekuensi, tenor, jumlah_cicilan,
     *   total_bunga, total, angsuran (nominal cicilan reguler),
     *   rincian => [ ['ke'=>1,'pokok'=>..,'bunga'=>..,'jumlah'=>..], ... ]
     * ]
     */
    function hitungPinjaman($pokok, $bunga_persen, $tenor_bulan, $frekuensi = 'bulanan', $skema = 'flat') {
        $pokok = (float) $pokok;
        $bunga_persen = (float) $bunga_persen;
        $tenor_bulan = (int) $tenor_bulan;
        $frekuensi = in_array($frekuensi, ['bulanan', 'mingguan', 'dua_mingguan'], true)
            ? $frekuensi : 'bulanan';
        $n = max(1, hitungJumlahCicilan($tenor_bulan, $frekuensi));
        $rincian = [];

        if ($skema === 'tahunan') {
            // Rumus lama (admin/pencairan.php sebelum revisi): bunga per tahun, proporsional tenor
            $total_bunga = $pokok * ($bunga_persen / 100) * ($tenor_bulan / 12);
            $total = $pokok + $total_bunga;
            for ($i = 1; $i <= $n; $i++) {
                $rincian[] = [
                    'ke' => $i,
                    'pokok' => $pokok / $n,
                    'bunga' => $total_bunga / $n,
                    'jumlah' => $total / $n,
                ];
            }
        } else {
            // Flat per tenor, dibulatkan ke rupiah penuh
            $total_bunga = round($pokok * $bunga_persen / 100);
            $total = $pokok + $total_bunga;

            // Cicilan dibulatkan; selisih pembulatan dibebankan ke cicilan terakhir
            $cicilan = round($total / $n);
            $pokok_cicilan = round($pokok / $n);
            $sum_jumlah = 0;
            $sum_pokok = 0;
            for ($i = 1; $i <= $n; $i++) {
                if ($i < $n) {
                    $jumlah = $cicilan;
                    $pokok_i = $pokok_cicilan;
                } else {
                    $jumlah = $total - $sum_jumlah;
                    $pokok_i = $pokok - $sum_pokok;
                }
                $sum_jumlah += $jumlah;
                $sum_pokok += $pokok_i;
                $rincian[] = [
                    'ke' => $i,
                    'pokok' => $pokok_i,
                    'bunga' => $jumlah - $pokok_i,
                    'jumlah' => $jumlah,
                ];
            }
        }

        return [
            'pokok' => $pokok,
            'bunga_persen' => $bunga_persen,
            'skema' => $skema === 'tahunan' ? 'tahunan' : 'flat',
            'frekuensi' => $frekuensi,
            'tenor' => $tenor_bulan,
            'jumlah_cicilan' => $n,
            'total_bunga' => $total_bunga,
            'total' => $total,
            'angsuran' => $rincian[0]['jumlah'],
            'rincian' => $rincian,
        ];
    }
}

if (!function_exists('hitungJatuhTempo')) {
    /**
     * Tanggal jatuh tempo cicilan ke-$ke dari $tanggal_mulai (Y-m-d).
     * Mingguan: +7 hari per cicilan. Bulanan: +1 bulan per cicilan; bila tanggal
     * tidak ada di bulan tujuan dipakai akhir bulan (sama dengan DATE_ADD MySQL).
     */
    function hitungJatuhTempo($tanggal_mulai, $ke, $frekuensi) {
        $mulai = new DateTime($tanggal_mulai);
        if ($frekuensi === 'mingguan' || $frekuensi === 'dua_mingguan') {
            $hari_per_cicilan = $frekuensi === 'dua_mingguan' ? 14 : 7;
            $mulai->modify('+' . ($hari_per_cicilan * (int) $ke) . ' days');
            return $mulai->format('Y-m-d');
        }
        $hari = (int) $mulai->format('d');
        $target = new DateTime($mulai->format('Y-m-01'));
        $target->modify('+' . (int) $ke . ' months');
        $hari = min($hari, (int) $target->format('t'));
        return $target->format('Y-m-') . str_pad($hari, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('labelFrekuensi')) {
    function labelFrekuensi($frekuensi) {
        if ($frekuensi === 'mingguan') return 'Mingguan';
        if ($frekuensi === 'dua_mingguan') return '2 Minggu Sekali';
        return 'Bulanan';
    }
}

if (!function_exists('labelPeriode')) {
    /** "Minggu" / "Bulan" untuk label seperti "Angsuran/Minggu". */
    function labelPeriode($frekuensi) {
        if ($frekuensi === 'mingguan') return 'Minggu';
        if ($frekuensi === 'dua_mingguan') return '2 Minggu';
        return 'Bulan';
    }
}

if (!function_exists('labelBunga')) {
    /** Teks bunga sesuai skema, mis. "35% flat" atau "12% / tahun". */
    function labelBunga($bunga, $skema) {
        $angka = rtrim(rtrim(number_format((float) $bunga, 2, ',', '.'), '0'), ',');
        return $skema === 'tahunan' ? $angka . '% / tahun' : $angka . '% flat';
    }
}

if (!function_exists('labelBungaRow')) {
    /** labelBunga() untuk baris pinjaman/pengajuan; baris tanpa skema dianggap skema lama. */
    function labelBungaRow($row) {
        return labelBunga($row['bunga'], isset($row['skema_bunga']) ? $row['skema_bunga'] : 'tahunan');
    }
}

if (!function_exists('labelTenorRow')) {
    /** Contoh: "3 bulan · Mingguan 12×" atau "3 bulan · Bulanan". */
    function labelTenorRow($row) {
        $frekuensi = isset($row['frekuensi']) ? $row['frekuensi'] : 'bulanan';
        $teks = $row['tenor'] . ' bulan · ' . labelFrekuensi($frekuensi);
        if ($frekuensi === 'mingguan' || $frekuensi === 'dua_mingguan') {
            $teks .= ' ' . hitungJumlahCicilan($row['tenor'], $frekuensi) . '×';
        }
        return $teks;
    }
}

if (!function_exists('ringkasanAngsuran')) {
    /** Contoh: "Mingguan — 12× Rp 112.500" */
    function ringkasanAngsuran($hitung) {
        return labelFrekuensi($hitung['frekuensi']) . ' — ' . $hitung['jumlah_cicilan'] . '× Rp '
            . number_format(round($hitung['angsuran']), 0, ',', '.');
    }
}

if (!function_exists('getTarifJenisPinjaman')) {
    /** @return array tenor_bulan => bunga (%) */
    function getTarifJenisPinjaman($db, $jenis_pinjaman_id) {
        $stmt = $db->prepare("SELECT tenor_bulan, bunga FROM jenis_pinjaman_tarif
                              WHERE jenis_pinjaman_id = ? ORDER BY tenor_bulan");
        $stmt->execute([(int) $jenis_pinjaman_id]);
        $tarif = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tarif[(int) $row['tenor_bulan']] = (float) $row['bunga'];
        }
        return $tarif;
    }
}

if (!function_exists('hitungDariPinjaman')) {
    /** Hitung ulang dari baris tabel pinjaman / pengajuan_pinjaman (membaca skema & frekuensi). */
    function hitungDariPinjaman($row) {
        return hitungPinjaman(
            $row['jumlah_pinjaman'],
            $row['bunga'],
            $row['tenor'],
            isset($row['frekuensi']) ? $row['frekuensi'] : 'bulanan',
            isset($row['skema_bunga']) ? $row['skema_bunga'] : 'tahunan'
        );
    }
}

if (!defined('JADWAL_DARI_PENGAJUAN')) {
    // Jatuh tempo mengikuti tanggal pengajuan yang disetujui, meskipun dana
    // baru dicairkan kemudian. Contoh: ajukan 20, mingguan pertama 27.
    define('JADWAL_DARI_PENGAJUAN', true);
}

if (!function_exists('tanggalMulaiJadwal')) {
    /** Tanggal acuan jatuh tempo cicilan pertama (Y-m-d) untuk sebuah pengajuan. */
    function tanggalMulaiJadwal($pengajuan) {
        if (JADWAL_DARI_PENGAJUAN && !empty($pengajuan['tanggal_pengajuan'])) {
            $t = strtotime($pengajuan['tanggal_pengajuan']);
            if ($t) {
                return date('Y-m-d', $t);
            }
        }
        return date('Y-m-d');
    }
}

if (!function_exists('buatPinjamanDanJadwal')) {
    /**
     * Membuat record pinjaman + jadwal angsuran dari pengajuan (dipanggil di dalam
     * transaksi oleh halaman persetujuan / pencairan). Tidak membuat ganda bila
     * pinjaman untuk pengajuan ini sudah ada.
     *
     * @return int|null id pinjaman yang dibuat, null bila sudah ada
     */
    function buatPinjamanDanJadwal($db, $pengajuan, $tanggal_mulai = null, $tanggal_pencairan = null) {
        $cek = $db->prepare("SELECT id FROM pinjaman WHERE pengajuan_id = ?");
        $cek->execute([$pengajuan['id']]);
        if ($cek->fetch()) {
            return null;
        }

        if ($tanggal_mulai === null) {
            $tanggal_mulai = date('Y-m-d');
        }
        // Tanggal pencairan (uang diserahkan) bisa berbeda dari tanggal acuan jatuh tempo
        if ($tanggal_pencairan === null) {
            $tanggal_pencairan = $tanggal_mulai;
        }
        $hitung = hitungDariPinjaman($pengajuan);
        $n = $hitung['jumlah_cicilan'];
        $jatuh_tempo_akhir = hitungJatuhTempo($tanggal_mulai, $n, $hitung['frekuensi']);
        // Placeholder unik dulu; diganti ke kode berbasis pinjaman_id agar tidak bentrok
        // dengan nomor perjanjian (dulu suffix = pengajuan_id → mudah salah cetak PDF).
        $kode_sementara = 'PJN-' . date('Ymd') . '-TMP-' . bin2hex(random_bytes(4));

        $stmt = $db->prepare("INSERT INTO pinjaman
            (pengajuan_id, kode_pinjaman, anggota_id, jenis_pinjaman_id, jumlah_pinjaman, sisa_pokok,
             tenor, bunga, skema_bunga, frekuensi, jumlah_cicilan, total_pinjaman, angsuran_per_bulan,
             tanggal_pencairan, jatuh_tempo, kategori_shu, ketua_kelompok_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aktif')");
        $stmt->execute([
            $pengajuan['id'], $kode_sementara, $pengajuan['anggota_id'], $pengajuan['jenis_pinjaman_id'],
            $hitung['pokok'], $hitung['pokok'], $hitung['tenor'], $hitung['bunga_persen'],
            $hitung['skema'], $hitung['frekuensi'], $n, $hitung['total'], $hitung['angsuran'],
            $tanggal_pencairan, $jatuh_tempo_akhir,
            shuKategoriAnggota($pengajuan['kategori_shu'] ?? 'anggota'), $pengajuan['ketua_kelompok_id'] ?: null,
        ]);
        $pinjaman_id = (int)$db->lastInsertId();
        $kode_pinjaman = 'PJN-' . date('Ymd') . '-' . str_pad((string)$pinjaman_id, 4, '0', STR_PAD_LEFT);
        $db->prepare('UPDATE pinjaman SET kode_pinjaman = ? WHERE id = ?')->execute([$kode_pinjaman, $pinjaman_id]);

        // Hubungkan data barang gadai dengan pinjaman yang baru dicairkan.
        // Pemeriksaan tabel menjaga kompatibilitas instalasi lama sebelum migrasi dijalankan.
        try {
            $db->prepare("UPDATE jaminan_pinjaman SET pinjaman_id = ? WHERE pengajuan_id = ?")
               ->execute([$pinjaman_id, $pengajuan['id']]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;
        }

        $stmt_angsuran = $db->prepare("INSERT INTO angsuran
            (pinjaman_id, angsuran_ke, jumlah_angsuran, pokok, bunga, tanggal_jatuh_tempo, status)
            VALUES (?, ?, ?, ?, ?, ?, 'belum_bayar')");
        foreach ($hitung['rincian'] as $r) {
            $stmt_angsuran->execute([
                $pinjaman_id, $r['ke'], $r['jumlah'], $r['pokok'], $r['bunga'],
                hitungJatuhTempo($tanggal_mulai, $r['ke'], $hitung['frekuensi']),
            ]);
        }

        return $pinjaman_id;
    }
}

if (!function_exists('daftarHariAngsuran')) {
    /** 1 = Senin ... 7 = Minggu (kategori hari angsuran pada pengajuan). */
    function daftarHariAngsuran() {
        return array(1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu');
    }
}

if (!function_exists('labelHariAngsuran')) {
    function labelHariAngsuran($hari) {
        $d = daftarHariAngsuran();
        return isset($d[(int) $hari]) ? $d[(int) $hari] : '-';
    }
}

if (!function_exists('tandaiCicilanDimuka')) {
    /**
     * Data lama: cicilan 1..$sampai_ke ditandai sudah dibayar sebelum memakai aplikasi.
     * Tanggal bayar = jatuh tempo; TIDAK ada transaksi/kas (uangnya sudah diterima dan dicatat di Excel lama).
     * Sisa pokok dikurangi. Dipanggil di dalam transaksi.
     */
    function tandaiCicilanDimuka($db, $pinjaman_id, $sampai_ke) {
        $sampai_ke = (int) $sampai_ke;
        if ($sampai_ke <= 0) {
            return;
        }
        $stmt = $db->prepare("SELECT COALESCE(SUM(pokok), 0) FROM angsuran WHERE pinjaman_id = ? AND angsuran_ke <= ?");
        $stmt->execute([$pinjaman_id, $sampai_ke]);
        $pokok = (float) $stmt->fetchColumn();

        $set = "status = 'lunas', tanggal_bayar = tanggal_jatuh_tempo, denda = 0, keterlambatan_hari = 0";
        if (kolomAda($db, 'angsuran', 'denda_manual')) {
            $set .= ", denda_manual = 0";
        }
        $db->prepare("UPDATE angsuran SET $set WHERE pinjaman_id = ? AND angsuran_ke <= ?")
           ->execute([$pinjaman_id, $sampai_ke]);
        $db->prepare("UPDATE pinjaman SET sisa_pokok = GREATEST(sisa_pokok - ?, 0), cicilan_dimuka = ? WHERE id = ?")
           ->execute([$pokok, $sampai_ke, $pinjaman_id]);
        tandaiLunasBilaSelesai($db, $pinjaman_id);
    }
}

if (!function_exists('terbilang')) {
    /** Angka -> kata, mis. 2550000 => "dua juta lima ratus lima puluh ribu". */
    function terbilang($x) {
        $x = (int) abs(round($x));
        $satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($x < 12) return $satuan[$x];
        if ($x < 20) return terbilang($x - 10) . ' belas';
        if ($x < 100) return terbilang(intdiv($x, 10)) . ' puluh' . ($x % 10 ? ' ' . terbilang($x % 10) : '');
        if ($x < 200) return 'seratus' . ($x - 100 ? ' ' . terbilang($x - 100) : '');
        if ($x < 1000) return terbilang(intdiv($x, 100)) . ' ratus' . ($x % 100 ? ' ' . terbilang($x % 100) : '');
        if ($x < 2000) return 'seribu' . ($x - 1000 ? ' ' . terbilang($x - 1000) : '');
        if ($x < 1000000) return terbilang(intdiv($x, 1000)) . ' ribu' . ($x % 1000 ? ' ' . terbilang($x % 1000) : '');
        if ($x < 1000000000) return terbilang(intdiv($x, 1000000)) . ' juta' . ($x % 1000000 ? ' ' . terbilang($x % 1000000) : '');
        if ($x < 1000000000000) return terbilang(intdiv($x, 1000000000)) . ' miliar' . ($x % 1000000000 ? ' ' . terbilang($x % 1000000000) : '');
        return terbilang(intdiv($x, 1000000000000)) . ' triliun' . ($x % 1000000000000 ? ' ' . terbilang($x % 1000000000000) : '');
    }
}

if (!function_exists('terbilangRupiah')) {
    function terbilangRupiah($x) {
        $kata = (int) round($x) === 0 ? 'nol' : terbilang($x);
        return ucfirst($kata) . ' rupiah';
    }
}

if (!function_exists('kolomAda')) {
    function kolomAda($db, $tabel, $kolom) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$tabel, $kolom]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

if (!function_exists('tandaiLunasBilaSelesai')) {
    /**
     * Setelah pembayaran: bila SEMUA cicilan pinjaman sudah lunas, tandai pinjaman lunas
     * (status, sisa_pokok = 0, tanggal_lunas = hari ini). Mengatasi sisa pembulatan 0,01 pada
     * pinjaman skema lama yang sebelumnya membuat status tidak pernah berubah jadi lunas.
     * @return bool true bila pinjaman sudah lunas
     */
    function tandaiLunasBilaSelesai($db, $pinjaman_id) {
        $stmt = $db->prepare("SELECT COUNT(*) AS total, SUM(status <> 'lunas') AS belum
                              FROM angsuran WHERE pinjaman_id = ?");
        $stmt->execute([$pinjaman_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ((int) $r['total'] === 0 || (int) $r['belum'] > 0) {
            return false;
        }
        $set = "status = 'lunas', sisa_pokok = 0";
        if (kolomAda($db, 'pinjaman', 'tanggal_lunas')) {
            $set .= ", tanggal_lunas = COALESCE(tanggal_lunas, CURDATE())";
        }
        $stmt_pinjaman = $db->prepare("SELECT anggota_id FROM pinjaman WHERE id = ?");
        $stmt_pinjaman->execute([$pinjaman_id]);
        $anggota_id = (int) $stmt_pinjaman->fetchColumn();
        $db->prepare("UPDATE pinjaman SET $set WHERE id = ?")->execute([$pinjaman_id]);
        shuCatatHakPinjaman($db, $pinjaman_id);
        if ($anggota_id > 0) {
            nonaktifkanAnggotaBilaSemuaPinjamanLunas($db, $anggota_id);
        }
        return true;
    }
}

if (!defined('ANGGOTA_GRACE_NONAKTIF_HARI')) {
    /** Masa aktif setelah semua pinjaman lunas — nasabah masih bisa cek SHU. */
    define('ANGGOTA_GRACE_NONAKTIF_HARI', 7);
}

if (!function_exists('pastikanKolomNonaktifJadwal')) {
    function pastikanKolomNonaktifJadwal($db) {
        if (!kolomAda($db, 'anggota', 'nonaktif_jadwal')) {
            $db->exec("ALTER TABLE `anggota` ADD COLUMN `nonaktif_jadwal` DATE NULL DEFAULT NULL AFTER `status`");
        }
    }
}

if (!function_exists('anggotaMasihPunyaPinjamanAtauPengajuan')) {
    function anggotaMasihPunyaPinjamanAtauPengajuan($db, $anggota_id) {
        $stmt = $db->prepare("SELECT
            (SELECT COUNT(*) FROM pinjaman WHERE anggota_id = ? AND status <> 'lunas') +
            (SELECT COUNT(*) FROM pengajuan_pinjaman WHERE anggota_id = ? AND status IN ('menunggu','disetujui'))");
        $stmt->execute([$anggota_id, $anggota_id]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

if (!function_exists('nonaktifkanAnggotaBilaSemuaPinjamanLunas')) {
    /**
     * Setelah semua pinjaman lunas: jangan langsung nonaktif.
     * Jadwalkan nonaktif +7 hari agar nasabah masih bisa login dan cek SHU.
     * Jika dalam masa tenggang ada pengajuan ulang, jadwal dibatalkan.
     */
    function nonaktifkanAnggotaBilaSemuaPinjamanLunas($db, $anggota_id) {
        if (anggotaMasihPunyaPinjamanAtauPengajuan($db, $anggota_id)) {
            return false;
        }
        pastikanKolomNonaktifJadwal($db);
        $jadwal = date('Y-m-d', strtotime('+' . ANGGOTA_GRACE_NONAKTIF_HARI . ' days'));
        $db->prepare("UPDATE anggota SET status = 'aktif', nonaktif_jadwal = ? WHERE id = ?")
           ->execute([$jadwal, $anggota_id]);
        $db->prepare("UPDATE users u JOIN anggota a ON a.user_id = u.id
                      SET u.status = 'aktif' WHERE a.id = ? AND u.role = 'anggota'")
           ->execute([$anggota_id]);
        return true;
    }
}

if (!function_exists('aktifkanKembaliAnggota')) {
    /** Aktifkan akun lama saat pengajuan baru berhasil dibuat; batalkan jadwal nonaktif. */
    function aktifkanKembaliAnggota($db, $anggota_id) {
        pastikanKolomNonaktifJadwal($db);
        $db->prepare("UPDATE anggota SET status = 'aktif', nonaktif_jadwal = NULL WHERE id = ?")
           ->execute([$anggota_id]);
        $db->prepare("UPDATE users u JOIN anggota a ON a.user_id = u.id
                      SET u.status = 'aktif' WHERE a.id = ? AND u.role = 'anggota'")
           ->execute([$anggota_id]);
    }
}

if (!function_exists('prosesJadwalNonaktifAnggota')) {
    /**
     * Nonaktifkan anggota yang masa tenggangnya habis dan tidak ada pengajuan ulang.
     * Dipanggil lazy saat anggota login / halaman anggota dibuka.
     */
    function prosesJadwalNonaktifAnggota($db, $anggota_id = null) {
        pastikanKolomNonaktifJadwal($db);
        if ($anggota_id !== null) {
            $stmt = $db->prepare("SELECT id FROM anggota
                WHERE id = ? AND nonaktif_jadwal IS NOT NULL AND nonaktif_jadwal <= CURDATE()");
            $stmt->execute([(int) $anggota_id]);
        } else {
            $stmt = $db->query("SELECT id FROM anggota
                WHERE nonaktif_jadwal IS NOT NULL AND nonaktif_jadwal <= CURDATE()");
        }
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $id = (int) $id;
            if (anggotaMasihPunyaPinjamanAtauPengajuan($db, $id)) {
                $db->prepare("UPDATE anggota SET nonaktif_jadwal = NULL, status = 'aktif' WHERE id = ?")
                   ->execute([$id]);
                $db->prepare("UPDATE users u JOIN anggota a ON a.user_id = u.id
                              SET u.status = 'aktif' WHERE a.id = ? AND u.role = 'anggota'")
                   ->execute([$id]);
                continue;
            }
            $db->prepare("UPDATE anggota SET status = 'nonaktif', nonaktif_jadwal = NULL WHERE id = ?")
               ->execute([$id]);
            $db->prepare("UPDATE users u JOIN anggota a ON a.user_id = u.id
                          SET u.status = 'nonaktif' WHERE a.id = ? AND u.role = 'anggota'")
               ->execute([$id]);
        }
        return count($ids);
    }
}

if (!function_exists('pulihkanGraceNonaktifTerbaru')) {
    /**
     * Pulihkan anggota yang baru saja dinonaktifkan karena lunas (masih dalam jendela grace)
     * supaya bisa cek SHU — dijalankan sekali saat skema nonaktif_jadwal tersedia.
     */
    function pulihkanGraceNonaktifTerbaru($db) {
        pastikanKolomNonaktifJadwal($db);
        if (!kolomAda($db, 'pinjaman', 'tanggal_lunas')) {
            return 0;
        }
        $hari = (int) ANGGOTA_GRACE_NONAKTIF_HARI;
        // Transisi: anggota yang sudah nonaktif karena lunas dapat 1x masa tenggang
        // dari hari ini agar masih sempat cek SHU setelah fitur ini aktif.
        $sql = "UPDATE anggota a
                SET a.status = 'aktif',
                    a.nonaktif_jadwal = DATE_ADD(CURDATE(), INTERVAL {$hari} DAY)
                WHERE a.status = 'nonaktif'
                  AND a.nonaktif_jadwal IS NULL
                  AND EXISTS (SELECT 1 FROM pinjaman p WHERE p.anggota_id = a.id)
                  AND NOT EXISTS (
                      SELECT 1 FROM pinjaman px
                      WHERE px.anggota_id = a.id AND px.status <> 'lunas'
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM pengajuan_pinjaman pp
                      WHERE pp.anggota_id = a.id AND pp.status IN ('menunggu','disetujui')
                  )";
        $db->exec($sql);
        $db->exec("UPDATE users u
                   JOIN anggota a ON a.user_id = u.id
                   SET u.status = 'aktif'
                   WHERE u.role = 'anggota' AND a.status = 'aktif' AND a.nonaktif_jadwal IS NOT NULL");
        return 1;
    }
}

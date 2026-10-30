<?php
/**
 * Lengkapi data demo untuk username anggota_1 agar semua menu portal anggota terisi:
 * simpanan, riwayat, pinjaman (aktif + lunas + gadai), waserda, SHU, profil.
 *
 * Idempoten (kode AGT1-*). Jalankan:
 *   C:\xampp\php\php.exe database/fixtures/SEED_ANGGOTA1_LENGKAP.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Jalankan via CLI saja.\n");
    exit(1);
}

require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

$urow = $db->query(
    "SELECT u.id AS user_id, a.id AS anggota_id, a.kode_anggota, a.nama_lengkap
     FROM users u
     JOIN anggota a ON a.user_id = u.id
     WHERE u.username = 'anggota_1'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$urow) {
    fwrite(STDERR, "User anggota_1 / data anggota tidak ditemukan.\n");
    exit(1);
}

$anggotaId = (int) $urow['anggota_id'];
$userId = (int) $urow['user_id'];
$tellerId = (int) $db->query("SELECT id FROM users WHERE role='teller' AND status='aktif' ORDER BY id LIMIT 1")->fetchColumn();
$adminId = (int) $db->query("SELECT id FROM users WHERE role='admin' AND status='aktif' ORDER BY id LIMIT 1")->fetchColumn();
$jenisReguler = (int) $db->query("SELECT id FROM jenis_pinjaman WHERE memerlukan_jaminan=0 AND status='aktif' ORDER BY id LIMIT 1")->fetchColumn();
$jenisGadai = (int) $db->query("SELECT id FROM jenis_pinjaman WHERE memerlukan_jaminan=1 AND status='aktif' ORDER BY id LIMIT 1")->fetchColumn();

if (!$tellerId || !$jenisReguler) {
    fwrite(STDERR, "Butuh teller aktif dan jenis pinjaman tanpa jaminan.\n");
    exit(1);
}

$db->beginTransaction();
try {
    // Profil lebih jelas
    $db->prepare(
        "UPDATE anggota
         SET nama_lengkap = ?, alamat = ?, telepon = ?, status = 'aktif', kategori_shu = 'ketua_kelompok'
         WHERE id = ?"
    )->execute([
        'Anggota Satu (Demo Lengkap)',
        'Jl. Merdeka No. 1, Mojokerto',
        '081200000001',
        $anggotaId,
    ]);
    $db->prepare("UPDATE users SET status='aktif', nama_lengkap=? WHERE id=?")->execute([
        'Anggota Satu (Demo Lengkap)',
        $userId,
    ]);

    // --- Simpanan + mutasi ---
    $saldoTarget = [
        1 => 100000,   // POKOK
        2 => 250000,   // WAJIB
        3 => 1500000,  // SUKARELA
    ];
    foreach ($saldoTarget as $jenisId => $saldo) {
        $cek = $db->prepare('SELECT id FROM simpanan WHERE anggota_id=? AND jenis_simpanan_id=?');
        $cek->execute([$anggotaId, $jenisId]);
        $sid = (int) $cek->fetchColumn();
        if ($sid) {
            $db->prepare('UPDATE simpanan SET saldo=? WHERE id=?')->execute([$saldo, $sid]);
        } else {
            $db->prepare('INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo) VALUES (?,?,?)')
                ->execute([$anggotaId, $jenisId, $saldo]);
        }
    }

    $mutasi = [
        ['AGT1-SET-POKOK', 1, 'setoran', 100000, 0, 100000, '-30 days', 'Setoran simpanan pokok (seed)'],
        ['AGT1-SET-WAJIB1', 2, 'setoran', 150000, 0, 150000, '-25 days', 'Setoran wajib awal (seed)'],
        ['AGT1-SET-WAJIB2', 2, 'setoran', 100000, 150000, 250000, '-18 days', 'Setoran wajib lanjutan (seed)'],
        ['AGT1-SET-SUK1', 3, 'setoran', 1000000, 0, 1000000, '-20 days', 'Setoran sukarela (seed)'],
        ['AGT1-SET-SUK2', 3, 'setoran', 600000, 1000000, 1600000, '-10 days', 'Setoran sukarela tambahan (seed)'],
        ['AGT1-TAR-SUK', 3, 'penarikan', 100000, 1600000, 1500000, '-3 days', 'Penarikan sebagian sukarela (seed)'],
    ];
    $insTrx = $db->prepare(
        "INSERT INTO transaksi
         (kode_transaksi, anggota_id, jenis_simpanan_id, jenis_transaksi, jumlah, saldo_sebelum, saldo_sesudah, keterangan, user_id, tanggal_transaksi)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ($mutasi as $m) {
        $exists = $db->prepare('SELECT id FROM transaksi WHERE kode_transaksi=?');
        $exists->execute([$m[0]]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $tgl = date('Y-m-d H:i:s', strtotime($m[6]));
        $insTrx->execute([$m[0], $anggotaId, $m[1], $m[2], $m[3], $m[4], $m[5], $m[7], $tellerId, $tgl]);
    }

    // --- Waserda ---
    $waserda = [
        [
            'kode' => 'AGT1-WSD-01',
            'items' => [['nama' => 'Beras 5kg', 'harga' => 75000, 'jumlah' => 1], ['nama' => 'Gula 1kg', 'harga' => 18000, 'jumlah' => 2]],
            'total' => 111000,
            'metode' => 'tunai',
            'when' => '-12 days',
        ],
        [
            'kode' => 'AGT1-WSD-02',
            'items' => [['nama' => 'Minyak 2L', 'harga' => 42000, 'jumlah' => 1], ['nama' => 'Sabun cuci', 'harga' => 15000, 'jumlah' => 3]],
            'total' => 87000,
            'metode' => 'transfer',
            'when' => '-4 days',
        ],
    ];
    $insWas = $db->prepare(
        'INSERT INTO waserda_transaksi (kode_transaksi, anggota_id, items, total_bayar, metode_bayar, user_id, created_at)
         VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($waserda as $w) {
        $exists = $db->prepare('SELECT id FROM waserda_transaksi WHERE kode_transaksi=?');
        $exists->execute([$w['kode']]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $insWas->execute([
            $w['kode'],
            $anggotaId,
            json_encode($w['items'], JSON_UNESCAPED_UNICODE),
            $w['total'],
            $w['metode'],
            $tellerId,
            date('Y-m-d H:i:s', strtotime($w['when'])),
        ]);
    }

    // --- Pinjaman aktif tanpa jaminan ---
    $kodeAktif = 'AGT1-PJN-AKTIF';
    $cekAktif = $db->prepare('SELECT id FROM pinjaman WHERE kode_pinjaman=?');
    $cekAktif->execute([$kodeAktif]);
    $pinAktifId = (int) $cekAktif->fetchColumn();
    if (!$pinAktifId) {
        $pokok = 3000000;
        $tenor = 6;
        $bunga = 10;
        $total = (int) round($pokok * (1 + $bunga / 100));
        $cicilan = (int) round($total / $tenor);
        $tglCair = date('Y-m-d', strtotime('-60 days'));
        $db->prepare(
            "INSERT INTO pengajuan_pinjaman
             (kode_pengajuan, anggota_id, jenis_pinjaman_id, jumlah_pinjaman, tenor, bunga, skema_bunga, frekuensi,
              tujuan_pinjaman, status, catatan_admin, admin_id, user_id, tanggal_pengajuan, tanggal_persetujuan, kategori_shu)
             VALUES ('AGT1-PJG-AKTIF', ?, ?, ?, ?, ?, 'flat', 'bulanan', 'Modal usaha (seed)', 'dicairkan', 'Seed AGT1', ?, ?, DATE_SUB(NOW(), INTERVAL 65 DAY), DATE_SUB(NOW(), INTERVAL 62 DAY), 'ketua_kelompok')"
        )->execute([$anggotaId, $jenisReguler, $pokok, $tenor, $bunga, $adminId ?: $tellerId, $tellerId]);
        $pengajuanAktif = (int) $db->lastInsertId();

        $db->prepare(
            "INSERT INTO pinjaman
             (pengajuan_id, kode_pinjaman, anggota_id, jenis_pinjaman_id, jumlah_pinjaman, sisa_pokok, tenor, bunga,
              skema_bunga, frekuensi, jumlah_cicilan, total_pinjaman, angsuran_per_bulan, tanggal_pencairan, jatuh_tempo,
              foto_pencairan, dicairkan_oleh, status, kategori_shu)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'flat', 'bulanan', ?, ?, ?, ?, DATE_ADD(?, INTERVAL 180 DAY),
                     'pencairan/demo-nasabah.jpeg', ?, 'aktif', 'ketua_kelompok')"
        )->execute([
            $pengajuanAktif, $kodeAktif, $anggotaId, $jenisReguler, $pokok,
            (int) round($pokok * 4 / 6), $tenor, $bunga, $tenor, $total, $cicilan, $tglCair, $tglCair, $tellerId,
        ]);
        $pinAktifId = (int) $db->lastInsertId();

        $pokokPer = (int) round($pokok / $tenor);
        $bungaPer = $cicilan - $pokokPer;
        $insAng = $db->prepare(
            "INSERT INTO angsuran
             (pinjaman_id, angsuran_ke, jumlah_angsuran, pokok, bunga, denda, tanggal_jatuh_tempo, tanggal_bayar, status, keterlambatan_hari)
             VALUES (?,?,?,?,?,0,?,?,?,?)"
        );
        for ($n = 1; $n <= $tenor; $n++) {
            $jt = date('Y-m-d', strtotime($tglCair . " +{$n} month"));
            if ($n <= 2) {
                $insAng->execute([$pinAktifId, $n, $cicilan, $pokokPer, $bungaPer, $jt, $jt, 'lunas', 0]);
            } elseif ($n === 3) {
                $insAng->execute([$pinAktifId, $n, $cicilan, $pokokPer, $bungaPer, date('Y-m-d', strtotime('-10 days')), null, 'telat', 10]);
                $db->prepare('UPDATE angsuran SET denda=25000 WHERE pinjaman_id=? AND angsuran_ke=3')->execute([$pinAktifId]);
            } else {
                $insAng->execute([$pinAktifId, $n, $cicilan, $pokokPer, $bungaPer, $jt, null, 'belum_bayar', 0]);
            }
        }
        echo "Created pinjaman aktif {$kodeAktif}\n";
    } else {
        echo "Reuse pinjaman aktif {$kodeAktif} (#{$pinAktifId})\n";
    }

    // --- Pinjaman gadai aktif (jika jenis tersedia) ---
    if ($jenisGadai) {
        $kodeGadai = 'AGT1-PJN-GADAI';
        $cekGadai = $db->prepare('SELECT id FROM pinjaman WHERE kode_pinjaman=?');
        $cekGadai->execute([$kodeGadai]);
        $pinGadaiId = (int) $cekGadai->fetchColumn();
        if (!$pinGadaiId) {
            $pokok = 2000000;
            $tenor = 3;
            $bunga = 12;
            $total = (int) round($pokok * (1 + $bunga / 100));
            $cicilan = (int) round($total / $tenor);
            $tglCair = date('Y-m-d', strtotime('-30 days'));
            $db->prepare(
                "INSERT INTO pengajuan_pinjaman
                 (kode_pengajuan, anggota_id, jenis_pinjaman_id, jumlah_pinjaman, tenor, bunga, skema_bunga, frekuensi,
                  tujuan_pinjaman, status, catatan_admin, admin_id, user_id, tanggal_pengajuan, tanggal_persetujuan, kategori_shu)
                 VALUES ('AGT1-PJG-GADAI', ?, ?, ?, ?, ?, 'flat', 'bulanan', 'Gadai (seed)', 'dicairkan', 'Seed AGT1', ?, ?, DATE_SUB(NOW(), INTERVAL 35 DAY), DATE_SUB(NOW(), INTERVAL 32 DAY), 'ketua_kelompok')"
            )->execute([$anggotaId, $jenisGadai, $pokok, $tenor, $bunga, $adminId ?: $tellerId, $tellerId]);
            $pengajuanGadai = (int) $db->lastInsertId();

            $db->prepare(
                "INSERT INTO pinjaman
                 (pengajuan_id, kode_pinjaman, anggota_id, jenis_pinjaman_id, jumlah_pinjaman, sisa_pokok, tenor, bunga,
                  skema_bunga, frekuensi, jumlah_cicilan, total_pinjaman, angsuran_per_bulan, tanggal_pencairan, jatuh_tempo,
                  foto_pencairan, dicairkan_oleh, status, kategori_shu)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'flat', 'bulanan', ?, ?, ?, ?, DATE_ADD(?, INTERVAL 90 DAY),
                         'pencairan/demo-nasabah.jpeg', ?, 'aktif', 'ketua_kelompok')"
            )->execute([
                $pengajuanGadai, $kodeGadai, $anggotaId, $jenisGadai, $pokok, $pokok, $tenor, $bunga,
                $tenor, $total, $cicilan, $tglCair, $tglCair, $tellerId,
            ]);
            $pinGadaiId = (int) $db->lastInsertId();

            $db->prepare(
                "INSERT INTO jaminan_pinjaman
                 (pengajuan_id, pinjaman_id, jenis_barang, identitas_barang, deskripsi_barang, nilai_taksiran, foto_barang, status_jaminan)
                 VALUES (?, ?, 'Sepeda Motor', 'Nopol L 1234 AB', 'Honda Beat 2020 — seed demo', 4500000, 'jaminan/demo-barang.jpeg', 'dititipkan')"
            )->execute([$pengajuanGadai, $pinGadaiId]);

            $pokokPer = (int) round($pokok / $tenor);
            $bungaPer = $cicilan - $pokokPer;
            $insAng = $db->prepare(
                "INSERT INTO angsuran
                 (pinjaman_id, angsuran_ke, jumlah_angsuran, pokok, bunga, denda, tanggal_jatuh_tempo, tanggal_bayar, status, keterlambatan_hari)
                 VALUES (?,?,?,?,?,0,?,?, 'belum_bayar', 0)"
            );
            for ($n = 1; $n <= $tenor; $n++) {
                $jt = date('Y-m-d', strtotime($tglCair . " +{$n} month"));
                $insAng->execute([$pinGadaiId, $n, $cicilan, $pokokPer, $bungaPer, $jt, null]);
            }
            echo "Created pinjaman gadai {$kodeGadai}\n";
        } else {
            echo "Reuse pinjaman gadai {$kodeGadai} (#{$pinGadaiId})\n";
        }
    }

    // Pengajuan menunggu agar daftar pengajuan terlihat
    $cekMenunggu = $db->prepare("SELECT id FROM pengajuan_pinjaman WHERE kode_pengajuan='AGT1-PJG-WAIT'");
    $cekMenunggu->execute();
    if (!$cekMenunggu->fetchColumn()) {
        $db->prepare(
            "INSERT INTO pengajuan_pinjaman
             (kode_pengajuan, anggota_id, jenis_pinjaman_id, jumlah_pinjaman, tenor, bunga, skema_bunga, frekuensi,
              tujuan_pinjaman, status, user_id, tanggal_pengajuan, kategori_shu)
             VALUES ('AGT1-PJG-WAIT', ?, ?, 1500000, 3, 10, 'flat', 'bulanan', 'Pengajuan menunggu (seed)', 'menunggu', ?, NOW(), 'ketua_kelompok')"
        )->execute([$anggotaId, $jenisReguler, $tellerId]);
        echo "Created pengajuan menunggu AGT1-PJG-WAIT\n";
    }

    $db->commit();
    echo "OK — data lengkap untuk anggota_1 (id={$anggotaId})\n";
    echo "Login: anggota_1 / password demo yang dipakai sebelumnya\n";
    echo "Cek: simpanan, riwayat, pinjaman, waserda, shu_saya, profil\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'SEED GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}

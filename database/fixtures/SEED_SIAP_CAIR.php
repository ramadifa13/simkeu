<?php
/**
 * Seed pengajuan berstatus "Siap cair" (disetujui, belum ada pinjaman).
 * Variasi: reguler mingguan / 2 minggu, gadai HP / motor / emas, nominal & tenor berbeda.
 *
 * Jalankan: php database/fixtures/SEED_SIAP_CAIR.php
 * Idempoten: kode SC-* yang sama tidak digandakan.
 */
declare(strict_types=1);

require __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$adminId = (int) $db->query("SELECT id FROM users WHERE role='admin' AND status='aktif' ORDER BY id LIMIT 1")->fetchColumn();
$tellerId = (int) $db->query("SELECT id FROM users WHERE role='teller' AND status='aktif' ORDER BY id LIMIT 1")->fetchColumn();
if ($adminId < 1 || $tellerId < 1) {
    fwrite(STDERR, "Butuh minimal 1 admin dan 1 teller aktif.\n");
    exit(1);
}

$jenisRegulerMingguan = (int) $db->query("SELECT id FROM jenis_pinjaman WHERE memerlukan_jaminan=0 AND (nama_jenis LIKE '%MINGGU%' OR frekuensi_diizinkan LIKE '%mingguan%') ORDER BY id LIMIT 1")->fetchColumn();
$jenisReguler2Minggu = (int) $db->query("SELECT id FROM jenis_pinjaman WHERE memerlukan_jaminan=0 AND (nama_jenis LIKE '%2 MINGGU%' OR frekuensi_diizinkan LIKE '%dua_mingguan%') ORDER BY id DESC LIMIT 1")->fetchColumn();
$jenisGadai = (int) $db->query("SELECT id FROM jenis_pinjaman WHERE memerlukan_jaminan=1 ORDER BY id DESC LIMIT 1")->fetchColumn();

if ($jenisRegulerMingguan < 1) {
    $jenisRegulerMingguan = (int) $db->query("SELECT id FROM jenis_pinjaman WHERE memerlukan_jaminan=0 ORDER BY id LIMIT 1")->fetchColumn();
}
if ($jenisReguler2Minggu < 1) {
    $jenisReguler2Minggu = $jenisRegulerMingguan;
}
if ($jenisGadai < 1 || $jenisRegulerMingguan < 1) {
    fwrite(STDERR, "Jenis pinjaman reguler/gadai belum ada.\n");
    exit(1);
}

/** @var list<array<string,mixed>> $scenarios */
$scenarios = [
    [
        'kode_anggota' => 'SC001',
        'nama' => 'Siti Siap Cair Mingguan',
        'telepon' => '081211100001',
        'ktp' => '3515010101900001',
        'kode_pengajuan' => 'SC-PJG-001',
        'jenis_id' => $jenisRegulerMingguan,
        'jumlah' => 1500000,
        'tenor' => 3,
        'bunga' => 10,
        'frekuensi' => 'mingguan',
        'hari' => 1, // Senin
        'tujuan' => 'Modal warung sembako',
        'gadai' => null,
        'catatan' => 'Seed siap cair — reguler mingguan kecil',
    ],
    [
        'kode_anggota' => 'SC002',
        'nama' => 'Budi Siap Cair 2 Mingguan',
        'telepon' => '081211100002',
        'ktp' => '3515010101900002',
        'kode_pengajuan' => 'SC-PJG-002',
        'jenis_id' => $jenisReguler2Minggu,
        'jumlah' => 3000000,
        'tenor' => 6,
        'bunga' => 12,
        'frekuensi' => 'dua_mingguan',
        'hari' => 3, // Rabu
        'tujuan' => 'Tambah stok toko kelontong',
        'gadai' => null,
        'catatan' => 'Seed siap cair — reguler 2 mingguan',
    ],
    [
        'kode_anggota' => 'SC003',
        'nama' => 'Rina Siap Cair Prioritas',
        'telepon' => '081211100003',
        'ktp' => '3515010101900003',
        'kode_pengajuan' => 'SC-PJG-003',
        'jenis_id' => $jenisRegulerMingguan,
        'jumlah' => 2000000,
        'tenor' => 4,
        'bunga' => 10,
        'frekuensi' => 'mingguan',
        'hari' => 5, // Jumat
        'tujuan' => 'Biaya sekolah anak',
        'gadai' => null,
        'prioritas' => 1,
        'catatan' => 'Seed siap cair — fasilitas prioritas',
    ],
    [
        'kode_anggota' => 'SCJG01',
        'kode_jaminan' => 'SCJG01',
        'nama' => 'Dewi Gadai HP Siap Cair',
        'telepon' => '081211100004',
        'ktp' => '3515010101900004',
        'kode_pengajuan' => 'SC-PJG-004',
        'jenis_id' => $jenisGadai,
        'jumlah' => 1000000,
        'tenor' => 2,
        'bunga' => 8,
        'frekuensi' => 'bulanan',
        'hari' => null,
        'tujuan' => 'Pinjaman gadai HP',
        'gadai' => [
            'jenis_barang' => 'HP - Android',
            'identitas_barang' => 'IMEI: 356938035643809',
            'deskripsi_barang' => "Merk/tipe: Samsung A14\nWarna: Hitam\nNomor seri: R58T12ABC\nNomor model: SM-A145F\nKelengkapan: HP + charger\nKondisi: Baik",
            'nilai_taksiran' => 1800000,
        ],
        'catatan' => 'Seed siap cair — gadai HP',
    ],
    [
        'kode_anggota' => 'SCJG02',
        'kode_jaminan' => 'SCJG02',
        'nama' => 'Agus Gadai Motor Siap Cair',
        'telepon' => '081211100005',
        'ktp' => '3515010101900005',
        'kode_pengajuan' => 'SC-PJG-005',
        'jenis_id' => $jenisGadai,
        'jumlah' => 2500000,
        'tenor' => 3,
        'bunga' => 9,
        'frekuensi' => 'bulanan',
        'hari' => null,
        'tujuan' => 'Pinjaman gadai sepeda motor',
        'gadai' => [
            'jenis_barang' => 'Sepeda Motor - Bebek',
            'identitas_barang' => 'Nopol: S 4521 YK; BPKB: BPKB-SC-005',
            'deskripsi_barang' => "Merk/tipe: Honda Revo\nTahun: 2019\nNo. rangka: MH1SC1234567890\nNo. mesin: JFZ1E1234567\nWarna: Merah\nAtas nama BPKB: Agus Gadai Motor Siap Cair\nKondisi: Baik",
            'nilai_taksiran' => 4500000,
        ],
        'catatan' => 'Seed siap cair — gadai motor',
    ],
    [
        'kode_anggota' => 'SCJG03',
        'kode_jaminan' => 'SCJG03',
        'nama' => 'Lestari Gadai Emas Siap Cair',
        'telepon' => '081211100006',
        'ktp' => '3515010101900006',
        'kode_pengajuan' => 'SC-PJG-006',
        'jenis_id' => $jenisGadai,
        'jumlah' => 1800000,
        'tenor' => 1,
        'bunga' => 7,
        'frekuensi' => 'bulanan',
        'hari' => null,
        'tujuan' => 'Pinjaman gadai emas',
        'gadai' => [
            'jenis_barang' => 'Emas - Kalung',
            'identitas_barang' => 'Surat/nota: NOTA-EMAS-SC-006',
            'deskripsi_barang' => "Berat: 5 gram\nKadar: 22K\nBentuk/ciri: Kalung rantai kecil",
            'nilai_taksiran' => 3200000,
        ],
        'catatan' => 'Seed siap cair — gadai emas',
    ],
    [
        'kode_anggota' => 'SC007',
        'nama' => 'Hendra Siap Cair Nominal Besar',
        'telepon' => '081211100007',
        'ktp' => '3515010101900007',
        'kode_pengajuan' => 'SC-PJG-007',
        'jenis_id' => $jenisReguler2Minggu,
        'jumlah' => 5000000,
        'tenor' => 6,
        'bunga' => 12,
        'frekuensi' => 'dua_mingguan',
        'hari' => 2, // Selasa
        'tujuan' => 'Renovasi rumah kontrakan',
        'gadai' => null,
        'catatan' => 'Seed siap cair — nominal besar 2 mingguan',
    ],
    [
        'kode_anggota' => 'SC008',
        'nama' => 'Maya Siap Cair Input Mundur',
        'telepon' => '081211100008',
        'ktp' => '3515010101900008',
        'kode_pengajuan' => 'SC-PJG-008',
        'jenis_id' => $jenisRegulerMingguan,
        'jumlah' => 1200000,
        'tenor' => 3,
        'bunga' => 10,
        'frekuensi' => 'mingguan',
        'hari' => 4, // Kamis
        'tujuan' => 'Bayar kebutuhan dapur',
        'gadai' => null,
        'tanggal_pengajuan' => date('Y-m-d H:i:s', strtotime('-14 days')),
        'tanggal_persetujuan' => date('Y-m-d H:i:s', strtotime('-10 days')),
        'catatan' => 'Seed siap cair — pengajuan lama (uji tanggal pencairan mundur)',
    ],
];

$created = 0;
$skipped = 0;

try {
    $db->beginTransaction();

    $hasKodeJaminan = (bool) $db->query("SHOW COLUMNS FROM anggota LIKE 'kode_anggota_jaminan'")->fetch();
    $hasPrioritas = (bool) $db->query("SHOW COLUMNS FROM pengajuan_pinjaman LIKE 'fasilitas_prioritas'")->fetch();
    $hasHari = (bool) $db->query("SHOW COLUMNS FROM pengajuan_pinjaman LIKE 'hari_angsuran'")->fetch();
    $hasKategoriShu = (bool) $db->query("SHOW COLUMNS FROM pengajuan_pinjaman LIKE 'kategori_shu'")->fetch();

    foreach ($scenarios as $s) {
        $cek = $db->prepare('SELECT id FROM pengajuan_pinjaman WHERE kode_pengajuan = ?');
        $cek->execute([$s['kode_pengajuan']]);
        if ($cek->fetchColumn()) {
            echo "SKIP {$s['kode_pengajuan']} (sudah ada)\n";
            $skipped++;
            continue;
        }

        $stmtAng = $db->prepare('SELECT id FROM anggota WHERE kode_anggota = ? OR ktp = ?');
        $stmtAng->execute([$s['kode_anggota'], $s['ktp']]);
        $anggotaId = (int) $stmtAng->fetchColumn();

        if ($anggotaId < 1) {
            if ($hasKodeJaminan) {
                $db->prepare(
                    "INSERT INTO anggota (kode_anggota, kode_anggota_jaminan, nama_lengkap, alamat, telepon, ktp, status, tanggal_daftar)
                     VALUES (?, ?, ?, 'Mojokerto — seed siap cair', ?, ?, 'aktif', CURDATE())"
                )->execute([
                    $s['kode_anggota'],
                    $s['kode_jaminan'] ?? ('SCX' . substr($s['kode_anggota'], -3)),
                    $s['nama'],
                    $s['telepon'],
                    $s['ktp'],
                ]);
            } else {
                $db->prepare(
                    "INSERT INTO anggota (kode_anggota, nama_lengkap, alamat, telepon, ktp, status, tanggal_daftar)
                     VALUES (?, ?, 'Mojokerto — seed siap cair', ?, ?, 'aktif', CURDATE())"
                )->execute([$s['kode_anggota'], $s['nama'], $s['telepon'], $s['ktp']]);
            }
            $anggotaId = (int) $db->lastInsertId();
        } elseif (!empty($s['kode_jaminan']) && $hasKodeJaminan) {
            $db->prepare('UPDATE anggota SET kode_anggota_jaminan = COALESCE(NULLIF(kode_anggota_jaminan, \'\'), ?) WHERE id = ?')
               ->execute([$s['kode_jaminan'], $anggotaId]);
        }

        $tglAjuan = $s['tanggal_pengajuan'] ?? date('Y-m-d H:i:s', strtotime('-3 days'));
        $tglSetuju = $s['tanggal_persetujuan'] ?? date('Y-m-d H:i:s', strtotime('-1 day'));
        $prioritas = !empty($s['prioritas']) ? 1 : 0;
        $hari = $s['hari'] ?? null;

        $cols = 'kode_pengajuan, anggota_id, jenis_pinjaman_id, jumlah_pinjaman, tenor, bunga, skema_bunga, frekuensi, tujuan_pinjaman, status, catatan_admin, admin_id, user_id, tanggal_pengajuan, tanggal_persetujuan';
        $vals = '?, ?, ?, ?, ?, ?, \'flat\', ?, ?, \'disetujui\', ?, ?, ?, ?, ?';
        $params = [
            $s['kode_pengajuan'],
            $anggotaId,
            $s['jenis_id'],
            $s['jumlah'],
            $s['tenor'],
            $s['bunga'],
            $s['frekuensi'],
            $s['tujuan'],
            $s['catatan'],
            $adminId,
            $tellerId,
            $tglAjuan,
            $tglSetuju,
        ];

        if ($hasHari) {
            $cols .= ', hari_angsuran';
            $vals .= ', ?';
            $params[] = $hari;
        }
        if ($hasPrioritas) {
            $cols .= ', fasilitas_prioritas';
            $vals .= ', ?';
            $params[] = $prioritas;
        }
        if ($hasKategoriShu) {
            $cols .= ', kategori_shu';
            $vals .= ', \'anggota\'';
        }

        $db->prepare("INSERT INTO pengajuan_pinjaman ($cols) VALUES ($vals)")->execute($params);
        $pengajuanId = (int) $db->lastInsertId();

        if (!empty($s['gadai'])) {
            $g = $s['gadai'];
            $db->prepare(
                "INSERT INTO jaminan_pinjaman
                 (pengajuan_id, jenis_barang, identitas_barang, deskripsi_barang, nilai_taksiran, status_jaminan)
                 VALUES (?, ?, ?, ?, ?, 'dititipkan')"
            )->execute([
                $pengajuanId,
                $g['jenis_barang'],
                $g['identitas_barang'],
                $g['deskripsi_barang'],
                $g['nilai_taksiran'],
            ]);
        }

        echo "OK {$s['kode_pengajuan']} — {$s['nama']} · Rp " . number_format((float) $s['jumlah'], 0, ',', '.') . " · {$s['frekuensi']}\n";
        $created++;
    }

    $db->commit();
    echo "\nSelesai: {$created} dibuat, {$skipped} dilewati.\n";
    echo "Buka menu Pencairan → filter status Siap cair.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Gagal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

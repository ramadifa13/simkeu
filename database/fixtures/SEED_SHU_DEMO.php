<?php
/**
 * Seed demo hak SHU pinjaman (tanpa jaminan) agar Dashboard / Pembayaran / Laporan terisi.
 * Idempoten: aman dijalankan ulang (kode DEMO-SHU-* / DEMO-SHUJ-*).
 *
 * Jalankan: php database/fixtures/SEED_SHU_DEMO.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Jalankan via CLI saja.\n");
    exit(1);
}

require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../includes/pinjaman_helper.php';
require_once __DIR__ . '/../../includes/shu_pinjaman_helper.php';

if (!shuPinjamanSiap($db)) {
    fwrite(STDERR, "Modul SHU pinjaman belum siap (migrasi belum lengkap).\n");
    exit(1);
}

$adminId = (int)$db->query("SELECT id FROM users WHERE role='admin' AND status='aktif' ORDER BY id LIMIT 1")->fetchColumn();
$tellerId = (int)$db->query("SELECT id FROM users WHERE role='teller' AND status='aktif' ORDER BY id LIMIT 1")->fetchColumn();
$jenisId = (int)$db->query('SELECT id FROM jenis_pinjaman WHERE memerlukan_jaminan=0 ORDER BY id LIMIT 1')->fetchColumn();

if (!$adminId || !$tellerId || !$jenisId) {
    fwrite(STDERR, "Butuh admin, teller, dan jenis pinjaman tanpa jaminan.\n");
    exit(1);
}

$anggota = $db->query(
    "SELECT id, kode_anggota, nama_lengkap, kategori_shu
     FROM anggota WHERE status='aktif' ORDER BY id LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);

if (count($anggota) < 4) {
    fwrite(STDERR, "Butuh minimal 4 anggota aktif untuk seed SHU.\n");
    exit(1);
}

$ketua = $anggota[0];
$anggotaA = $anggota[1];
$anggotaB = $anggota[2];
$anggotaC = $anggota[3];

$db->beginTransaction();
try {
    $db->prepare("UPDATE anggota SET kategori_shu='ketua_kelompok' WHERE id=?")->execute([(int)$ketua['id']]);
    $db->prepare("UPDATE anggota SET kategori_shu='anggota' WHERE id IN (?,?,?)")->execute([
        (int)$anggotaA['id'],
        (int)$anggotaB['id'],
        (int)$anggotaC['id'],
    ]);

    $scenarios = [
        // Ketua lunas pribadi → hak 2%
        [
            'kode' => 'DEMO-SHU-KETUA',
            'anggota' => $ketua,
            'kategori' => 'ketua_kelompok',
            'ketua_id' => null,
            'pokok' => 5000000,
            'lancar' => true,
            'bayar_pelunasan' => true,
        ],
        // Anggota di bawah ketua, lancar → hak 1% + referensi 0,25% ke ketua
        [
            'kode' => 'DEMO-SHU-AGT-A',
            'anggota' => $anggotaA,
            'kategori' => 'anggota',
            'ketua_id' => (int)$ketua['id'],
            'pokok' => 4000000,
            'lancar' => true,
            'bayar_pelunasan' => false,
        ],
        // Anggota di bawah ketua, ada telat → hak 1% saja (tanpa referensi)
        [
            'kode' => 'DEMO-SHU-AGT-B',
            'anggota' => $anggotaB,
            'kategori' => 'anggota',
            'ketua_id' => (int)$ketua['id'],
            'pokok' => 3000000,
            'lancar' => false,
            'bayar_pelunasan' => true,
        ],
        // Anggota tanpa ketua → hak 1% belum bayar
        [
            'kode' => 'DEMO-SHU-AGT-C',
            'anggota' => $anggotaC,
            'kategori' => 'anggota',
            'ketua_id' => null,
            'pokok' => 2500000,
            'lancar' => true,
            'bayar_pelunasan' => false,
        ],
    ];

    $createdHak = 0;
    $paidHak = 0;

    foreach ($scenarios as $sc) {
        $kodePjg = str_replace('DEMO-SHU-', 'DEMO-SHUJ-', $sc['kode']);
        $kodePjn = $sc['kode'];
        $angId = (int)$sc['anggota']['id'];
        $pokok = (int)$sc['pokok'];
        $tenor = 3;
        $bunga = 10;
        $total = (int)round($pokok * (1 + $bunga / 100));
        $cicilan = (int)round($total / $tenor);
        $tglCair = date('Y-m-d', strtotime('-90 days'));
        $tglLunas = date('Y-m-d', strtotime('-5 days'));

        $existing = $db->prepare('SELECT id FROM pinjaman WHERE kode_pinjaman=?');
        $existing->execute([$kodePjn]);
        $pinjamanId = (int)$existing->fetchColumn();

        if (!$pinjamanId) {
            $db->prepare(
                "INSERT INTO pengajuan_pinjaman
                (kode_pengajuan,anggota_id,jenis_pinjaman_id,jumlah_pinjaman,tenor,bunga,skema_bunga,frekuensi,
                 tujuan_pinjaman,status,catatan_admin,admin_id,user_id,tanggal_pengajuan,tanggal_persetujuan,
                 kategori_shu,ketua_kelompok_id)
                 VALUES (?,?,?,?,?,?, 'flat','bulanan', 'Seed demo SHU','dicairkan','Seed SHU',?,?,NOW(),NOW(),?,?)"
            )->execute([
                $kodePjg,
                $angId,
                $jenisId,
                $pokok,
                $tenor,
                $bunga,
                $adminId,
                $tellerId,
                $sc['kategori'],
                $sc['ketua_id'],
            ]);
            $pengajuanId = (int)$db->lastInsertId();

            $db->prepare(
                "INSERT INTO pinjaman
                (pengajuan_id,kode_pinjaman,anggota_id,jenis_pinjaman_id,jumlah_pinjaman,sisa_pokok,tenor,bunga,
                 skema_bunga,frekuensi,jumlah_cicilan,total_pinjaman,angsuran_per_bulan,tanggal_pencairan,jatuh_tempo,
                 foto_pencairan,dicairkan_oleh,tanggal_lunas,status,kategori_shu,ketua_kelompok_id)
                 VALUES (?,?,?,?,?,0,?,?,'flat','bulanan',?,?,?,?,DATE_ADD(?, INTERVAL 90 DAY),'pencairan/demo-nasabah.jpeg',?,?, 'lunas',?,?)"
            )->execute([
                $pengajuanId,
                $kodePjn,
                $angId,
                $jenisId,
                $pokok,
                $tenor,
                $bunga,
                $tenor,
                $total,
                $cicilan,
                $tglCair,
                $tglCair,
                $tellerId,
                $tglLunas,
                $sc['kategori'],
                $sc['ketua_id'],
            ]);
            $pinjamanId = (int)$db->lastInsertId();

            $insAng = $db->prepare(
                "INSERT INTO angsuran
                (pinjaman_id,angsuran_ke,jumlah_angsuran,pokok,bunga,denda,tanggal_jatuh_tempo,tanggal_bayar,status,keterlambatan_hari)
                 VALUES (?,?,?,?,?,0,?,?, 'lunas',?)"
            );
            $pokokPer = (int)round($pokok / $tenor);
            $bungaPer = $cicilan - $pokokPer;
            for ($n = 1; $n <= $tenor; $n++) {
                $jt = date('Y-m-d', strtotime($tglCair . " +{$n} month"));
                if ($sc['lancar']) {
                    $bayar = $jt;
                    $telat = 0;
                } else {
                    // Cicilan ke-2 terlambat → tidak dapat bonus referensi
                    if ($n === 2) {
                        $bayar = date('Y-m-d', strtotime($jt . ' +10 days'));
                        $telat = 10;
                    } else {
                        $bayar = $jt;
                        $telat = 0;
                    }
                }
                $insAng->execute([$pinjamanId, $n, $cicilan, $pokokPer, $bungaPer, $jt, $bayar, $telat]);
            }
            echo "Created pinjaman {$kodePjn}\n";
        } else {
            $db->prepare(
                "UPDATE pinjaman SET status='lunas', sisa_pokok=0, tanggal_lunas=?, kategori_shu=?, ketua_kelompok_id=? WHERE id=?"
            )->execute([$tglLunas, $sc['kategori'], $sc['ketua_id'], $pinjamanId]);
            echo "Reuse pinjaman {$kodePjn} (#{$pinjamanId})\n";
        }

        shuCatatHakPinjaman($db, $pinjamanId);

        if (!empty($sc['bayar_pelunasan'])) {
            $h = $db->prepare(
                "SELECT id FROM shu_hak_pinjaman WHERE pinjaman_id=? AND jenis='pelunasan' AND status_bayar='belum'"
            );
            $h->execute([$pinjamanId]);
            $hakId = (int)$h->fetchColumn();
            if ($hakId) {
                $db->prepare(
                    "UPDATE shu_hak_pinjaman
                     SET status_bayar='sudah', metode='cash', bukti=NULL, referensi=?, dibayar_oleh=?, dibayar_at=NOW(), transaksi_id=NULL
                     WHERE id=?"
                )->execute(['Seed demo SHU dibayar', $adminId, $hakId]);
                $db->prepare(
                    'INSERT INTO shu_hak_audit(hak_id,user_id,aksi,detail) VALUES (?,?,?,?)'
                )->execute([
                    $hakId,
                    $adminId,
                    'bayar',
                    json_encode(['seed' => true, 'metode' => 'cash'], JSON_UNESCAPED_UNICODE),
                ]);
                $paidHak++;
            }
        }
    }

    // Hitung ulang
    $createdHak = (int)$db->query(
        "SELECT COUNT(*) FROM shu_hak_pinjaman h
         JOIN pinjaman p ON p.id=h.pinjaman_id
         WHERE p.kode_pinjaman LIKE 'DEMO-SHU-%'"
    )->fetchColumn();

    $db->commit();

    echo "OK — hak SHU demo: {$createdHak} catatan (baru dibayar di seed: {$paidHak})\n";
    echo "Ketua: {$ketua['nama_lengkap']} (#{$ketua['id']})\n";
    echo "Buka: admin/shu.php\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'SEED GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}

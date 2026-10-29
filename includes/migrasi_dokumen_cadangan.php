<?php
/**
 * Cadangan dokumen agar reset+import tidak menghilangkan foto/TTD.
 * Tabel ini tidak dihapus saat Reset Total; diisi ulang sebelum reset / saat backup.
 */
require_once __DIR__ . '/pinjaman_helper.php';

function migrasiPastikanCadanganDokumen(PDO $db) {
    // MySQL: CREATE TABLE (meski IF NOT EXISTS) = DDL = implicit commit.
    // Jangan jalankan DDL di dalam transaksi import, atau commit akhir gagal
    // dengan "There is no active transaction".
    static $siap = false;
    if ($siap) {
        return;
    }
    $cek = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrasi_dokumen_cadangan'"
    );
    if ($cek && (int) $cek->fetchColumn() > 0) {
        $siap = true;
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException(
            'Tabel migrasi_dokumen_cadangan belum ada. Muat ulang halaman Import (setup di luar transaksi) atau jalankan MIGRASI_UNIVERSAL.sql.'
        );
    }
    $db->exec(
        "CREATE TABLE migrasi_dokumen_cadangan (
            id INT NOT NULL AUTO_INCREMENT,
            jenis ENUM('anggota','pinjaman') NOT NULL,
            source_key VARCHAR(100) NOT NULL,
            kode_anggota VARCHAR(50) NULL,
            foto_ktp VARCHAR(255) NULL,
            foto_kk VARCHAR(255) NULL,
            foto_ktp_pasangan VARCHAR(255) NULL,
            ttd_anggota MEDIUMTEXT NULL,
            foto_pencairan VARCHAR(255) NULL,
            foto_barang VARCHAR(255) NULL,
            foto_nasabah_barang VARCHAR(255) NULL,
            perjanjian_json MEDIUMTEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_migrasi_cadangan (jenis, source_key),
            KEY idx_migrasi_cadangan_anggota (kode_anggota)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $siap = true;
}

function migrasiCadanganUpsert(PDO $db, $jenis, $sourceKey, array $fields) {
    $sourceKey = trim((string) $sourceKey);
    if ($sourceKey === '') {
        return;
    }
    $cols = ['jenis', 'source_key'];
    $vals = [$jenis, $sourceKey];
    $updates = [];
    foreach ([
        'kode_anggota', 'foto_ktp', 'foto_kk', 'foto_ktp_pasangan', 'ttd_anggota',
        'foto_pencairan', 'foto_barang', 'foto_nasabah_barang', 'perjanjian_json',
    ] as $col) {
        if (!array_key_exists($col, $fields)) {
            continue;
        }
        $cols[] = $col;
        $vals[] = $fields[$col];
        // Jangan timpa nilai lama dengan kosong.
        $updates[] = "$col=COALESCE(NULLIF(VALUES($col),''), $col)";
    }
    if ($updates === []) {
        return;
    }
    $sql = 'INSERT INTO migrasi_dokumen_cadangan (' . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE '
        . implode(', ', $updates);
    $db->prepare($sql)->execute($vals);
}

/**
 * Salin semua path foto + TTD perjanjian ke tabel cadangan (aman diulang).
 * @return array{anggota:int,pinjaman:int}
 */
function migrasiSimpanCadanganDokumen(PDO $db) {
    migrasiPastikanCadanganDokumen($db);
    $nAnggota = 0;
    $nPinjaman = 0;

    $cols = 'id, kode_anggota, foto_ktp';
    if (kolomAda($db, 'anggota', 'foto_kk')) {
        $cols .= ', foto_kk';
    }
    if (kolomAda($db, 'anggota', 'foto_ktp_pasangan')) {
        $cols .= ', foto_ktp_pasangan';
    }
    $anggota = $db->query("SELECT $cols FROM anggota")->fetchAll(PDO::FETCH_ASSOC);
    $dokumen = [];
    try {
        foreach ($db->query('SELECT anggota_id, foto, ttd FROM migrasi_anggota_dokumen') as $d) {
            $dokumen[(int) $d['anggota_id']] = $d;
        }
    } catch (Throwable $e) {
        // tabel belum ada
    }
    foreach ($anggota as $a) {
        $doc = $dokumen[(int) $a['id']] ?? [];
        $ttd = (string) ($doc['ttd'] ?? '');
        migrasiCadanganUpsert($db, 'anggota', $a['kode_anggota'], [
            'kode_anggota' => $a['kode_anggota'],
            'foto_ktp' => $a['foto_ktp'] ?: ($doc['foto'] ?? null),
            'foto_kk' => $a['foto_kk'] ?? null,
            'foto_ktp_pasangan' => $a['foto_ktp_pasangan'] ?? null,
            'ttd_anggota' => $ttd !== '' ? $ttd : null,
        ]);
        $nAnggota++;
    }

    if (!kolomAda($db, 'pinjaman', 'foto_pencairan')) {
        return ['anggota' => $nAnggota, 'pinjaman' => 0];
    }

    $sql = "SELECT p.id, p.kode_pinjaman, p.anggota_id, a.kode_anggota, p.foto_pencairan,
                   j.foto_barang, j.foto_nasabah_barang,
                   pr.nomor_perjanjian, pr.versi, pr.snapshot_json, pr.nama_saksi_1, pr.nama_saksi_2,
                   pr.ttd_nasabah, pr.ttd_teller, pr.ttd_admin, pr.ttd_saksi_1, pr.ttd_saksi_2,
                   pr.hash_dokumen, pr.ditandatangani_at
            FROM pinjaman p
            JOIN anggota a ON a.id = p.anggota_id
            LEFT JOIN jaminan_pinjaman j ON j.pinjaman_id = p.id
            LEFT JOIN perjanjian_pinjaman pr ON pr.pinjaman_id = p.id";
    try {
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = $db->query(
            "SELECT p.id, p.kode_pinjaman, p.anggota_id, a.kode_anggota, p.foto_pencairan
             FROM pinjaman p JOIN anggota a ON a.id = p.anggota_id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rows as $p) {
        $perjanjian = null;
        if (!empty($p['ttd_nasabah']) || !empty($p['nomor_perjanjian'])) {
            $perjanjian = json_encode([
                'nomor_perjanjian' => $p['nomor_perjanjian'] ?? null,
                'versi' => $p['versi'] ?? 'lengkap',
                'snapshot_json' => $p['snapshot_json'] ?? '{}',
                'nama_saksi_1' => $p['nama_saksi_1'] ?? '-',
                'nama_saksi_2' => $p['nama_saksi_2'] ?? '-',
                'ttd_nasabah' => $p['ttd_nasabah'] ?? '',
                'ttd_teller' => $p['ttd_teller'] ?? '',
                'ttd_admin' => $p['ttd_admin'] ?? '',
                'ttd_saksi_1' => $p['ttd_saksi_1'] ?? '',
                'ttd_saksi_2' => $p['ttd_saksi_2'] ?? '',
                'hash_dokumen' => $p['hash_dokumen'] ?? '',
                'ditandatangani_at' => $p['ditandatangani_at'] ?? null,
            ], JSON_UNESCAPED_UNICODE);
        }
        migrasiCadanganUpsert($db, 'pinjaman', $p['kode_pinjaman'], [
            'kode_anggota' => $p['kode_anggota'],
            'foto_pencairan' => $p['foto_pencairan'] ?? null,
            'foto_barang' => $p['foto_barang'] ?? null,
            'foto_nasabah_barang' => $p['foto_nasabah_barang'] ?? null,
            'perjanjian_json' => $perjanjian,
        ]);
        $nPinjaman++;
    }

    return ['anggota' => $nAnggota, 'pinjaman' => $nPinjaman];
}

function migrasiAmbilCadangan(PDO $db, $jenis, $sourceKey) {
    migrasiPastikanCadanganDokumen($db);
    $q = $db->prepare('SELECT * FROM migrasi_dokumen_cadangan WHERE jenis=? AND source_key=?');
    $q->execute([$jenis, trim((string) $sourceKey)]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Pulihkan foto KTP/KK/pasangan/TTD ke anggota bila masih kosong. */
function migrasiPulihkanDokumenAnggota(PDO $db, $anggotaId, $kodeAnggota) {
    $cad = migrasiAmbilCadangan($db, 'anggota', $kodeAnggota);
    if (!$cad) {
        return 0;
    }
    if (!function_exists('dokumenAnggotaAdaDiDisk')) {
        require_once __DIR__ . '/dokumen_anggota.php';
    }
    $set = [];
    $params = [];
    $pulih = 0;
    $hilang = 0;
    foreach (['foto_ktp' => 'foto_ktp', 'foto_kk' => 'foto_kk', 'foto_ktp_pasangan' => 'foto_ktp_pasangan'] as $col => $cadCol) {
        if (!kolomAda($db, 'anggota', $col)) {
            continue;
        }
        $val = trim((string) ($cad[$cadCol] ?? ''));
        if ($val === '') {
            continue;
        }
        if (function_exists('dokumenAnggotaAdaDiDisk') && !dokumenAnggotaAdaDiDisk($val)) {
            $hilang++;
            continue;
        }
        $set[] = "$col=COALESCE(NULLIF($col,''), ?)";
        $params[] = $val;
        $pulih++;
    }
    if ($set) {
        $params[] = (int) $anggotaId;
        $db->prepare('UPDATE anggota SET ' . implode(', ', $set) . ' WHERE id=?')->execute($params);
    }
    $ttd = (string) ($cad['ttd_anggota'] ?? '');
    $foto = trim((string) ($cad['foto_ktp'] ?? ''));
    if ($foto !== '' && function_exists('dokumenAnggotaAdaDiDisk') && !dokumenAnggotaAdaDiDisk($foto)) {
        $foto = '';
    }
    try {
        $db->prepare(
            'INSERT INTO migrasi_anggota_dokumen(anggota_id,foto,ttd) VALUES(?,?,?)
             ON DUPLICATE KEY UPDATE
               foto=COALESCE(NULLIF(VALUES(foto),\'\'),foto),
               ttd=COALESCE(NULLIF(VALUES(ttd),\'\'),ttd)'
        )->execute([(int) $anggotaId, $foto !== '' ? $foto : null, $ttd !== '' ? $ttd : null]);
    } catch (Throwable $e) {
        // ignore bila tabel belum ada
    }
    return $pulih > 0 ? 1 : ($hilang > 0 ? 0 : 0);
}

/** Pulihkan foto pencairan, jaminan, dan perjanjian pinjaman dari cadangan. */
function migrasiPulihkanDokumenPinjaman(PDO $db, $pinjamanId, $kodePinjaman) {
    $cad = migrasiAmbilCadangan($db, 'pinjaman', $kodePinjaman);
    if (!$cad) {
        return 0;
    }
    $pinjamanId = (int) $pinjamanId;
    $fotoCair = trim((string) ($cad['foto_pencairan'] ?? ''));
    if ($fotoCair !== '' && kolomAda($db, 'pinjaman', 'foto_pencairan')) {
        $db->prepare('UPDATE pinjaman SET foto_pencairan=COALESCE(NULLIF(foto_pencairan,\'\'), ?) WHERE id=?')
            ->execute([$fotoCair, $pinjamanId]);
    }

    $fotoBarang = trim((string) ($cad['foto_barang'] ?? ''));
    $fotoNasabahBarang = trim((string) ($cad['foto_nasabah_barang'] ?? ''));
    if ($fotoBarang !== '' || $fotoNasabahBarang !== '') {
        $p = $db->prepare('SELECT pengajuan_id FROM pinjaman WHERE id=?');
        $p->execute([$pinjamanId]);
        $pengajuanId = (int) $p->fetchColumn();
        $j = $db->prepare('SELECT id FROM jaminan_pinjaman WHERE pinjaman_id=? OR pengajuan_id=? LIMIT 1');
        $j->execute([$pinjamanId, $pengajuanId]);
        $jaminanId = (int) $j->fetchColumn();
        if ($jaminanId > 0) {
            $fields = [];
            $vals = [];
            if ($fotoBarang !== '') {
                $fields[] = 'foto_barang=COALESCE(NULLIF(foto_barang,\'\'), ?)';
                $vals[] = $fotoBarang;
            }
            if ($fotoNasabahBarang !== '') {
                $fields[] = 'foto_nasabah_barang=COALESCE(NULLIF(foto_nasabah_barang,\'\'), ?)';
                $vals[] = $fotoNasabahBarang;
            }
            $vals[] = $jaminanId;
            $db->prepare('UPDATE jaminan_pinjaman SET ' . implode(',', $fields) . ' WHERE id=?')->execute($vals);
        } elseif ($pengajuanId > 0) {
            $db->prepare(
                "INSERT INTO jaminan_pinjaman
                 (pengajuan_id, pinjaman_id, jenis_barang, identitas_barang, deskripsi_barang, nilai_taksiran, foto_barang, foto_nasabah_barang, status_jaminan)
                 VALUES (?, ?, 'Cadangan migrasi', '-', 'Dipulihkan dari cadangan dokumen', 0, ?, ?, 'dititipkan')"
            )->execute([
                $pengajuanId,
                $pinjamanId,
                $fotoBarang !== '' ? $fotoBarang : null,
                $fotoNasabahBarang !== '' ? $fotoNasabahBarang : null,
            ]);
        }
    }

    $json = trim((string) ($cad['perjanjian_json'] ?? ''));
    if ($json !== '') {
        $data = json_decode($json, true);
        if (is_array($data) && !empty($data['ttd_nasabah'])) {
            $ada = $db->prepare('SELECT id FROM perjanjian_pinjaman WHERE pinjaman_id=?');
            $ada->execute([$pinjamanId]);
            if (!$ada->fetchColumn()) {
                $nomor = $data['nomor_perjanjian'] ?: ('MIG-' . $kodePinjaman);
                $versi = in_array(($data['versi'] ?? ''), ['ringkas', 'lengkap'], true) ? $data['versi'] : 'lengkap';
                $hash = $data['hash_dokumen'] ?: hash('sha256', $json);
                $tgl = $data['ditandatangani_at'] ?: date('Y-m-d H:i:s');
                $db->prepare(
                    "INSERT INTO perjanjian_pinjaman
                     (pinjaman_id, nomor_perjanjian, versi, snapshot_json, nama_saksi_1, nama_saksi_2,
                      ttd_nasabah, ttd_teller, ttd_admin, ttd_saksi_1, ttd_saksi_2, hash_dokumen, ditandatangani_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $pinjamanId,
                    substr((string) $nomor, 0, 60),
                    $versi,
                    $data['snapshot_json'] ?? '{}',
                    $data['nama_saksi_1'] ?? '-',
                    $data['nama_saksi_2'] ?? '-',
                    $data['ttd_nasabah'] ?? '',
                    $data['ttd_teller'] ?? '',
                    $data['ttd_admin'] ?? '',
                    $data['ttd_saksi_1'] ?? '',
                    $data['ttd_saksi_2'] ?? '',
                    substr((string) $hash, 0, 64),
                    $tgl,
                ]);
            }
        }
    }
    return 1;
}

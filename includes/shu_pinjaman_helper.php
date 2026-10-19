<?php
/** Aturan SHU pinjaman sesuai AD/ART: dicatat sekali ketika pinjaman lunas. */
function shuPinjamanSiap($db) {
    return function_exists('kolomAda')
        && kolomAda($db, 'anggota', 'kategori_shu')
        && kolomAda($db, 'pengajuan_pinjaman', 'ketua_kelompok_id')
        && kolomAda($db, 'pinjaman', 'kategori_shu')
        && kolomAda($db, 'shu_hak_pinjaman', 'status_bayar');
}

function shuKategoriAnggota($value) {
    return $value === 'ketua_kelompok' ? 'ketua_kelompok' : 'anggota';
}

function shuValidasiProfil($db, $anggotaId, $kategori, $ketuaId) {
    $anggotaId = (int)$anggotaId;
    $kategori = shuKategoriAnggota($kategori);
    $ketuaId = $kategori === 'anggota' ? (int)$ketuaId : 0;
    if ($kategori === 'anggota' && $ketuaId > 0) {
        if ($ketuaId === $anggotaId) throw new Exception('Anggota tidak dapat memilih dirinya sebagai ketua kelompok.');
        $s = $db->prepare("SELECT COUNT(*) FROM anggota WHERE id=? AND kategori_shu='ketua_kelompok'");
        $s->execute([$ketuaId]);
        if (!(int)$s->fetchColumn()) throw new Exception('Ketua kelompok tidak ditemukan atau kategorinya belum Ketua Kelompok.');
    }
    return [$kategori, $ketuaId ?: null];
}

function shuPinjamanLancar($db, $pinjamanId) {
    $s = $db->prepare("SELECT COUNT(*) total, SUM(status<>'lunas' OR tanggal_bayar IS NULL OR DATE(tanggal_bayar)>tanggal_jatuh_tempo) bermasalah FROM angsuran WHERE pinjaman_id=?");
    $s->execute([(int)$pinjamanId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return (int)$r['total'] > 0 && (int)$r['bermasalah'] === 0;
}

function shuCatatHakPinjaman($db, $pinjamanId) {
    if (!shuPinjamanSiap($db)) return;
    $s = $db->prepare("SELECT p.*,jp.memerlukan_jaminan FROM pinjaman p JOIN jenis_pinjaman jp ON jp.id=p.jenis_pinjaman_id WHERE p.id=? FOR UPDATE");
    $s->execute([(int)$pinjamanId]);
    $p = $s->fetch(PDO::FETCH_ASSOC);
    if (!$p || $p['status'] !== 'lunas' || !empty($p['memerlukan_jaminan'])) return;

    $kategori = shuKategoriAnggota($p['kategori_shu'] ?? 'anggota');
    $persen = $kategori === 'ketua_kelompok' ? 2.0 : 1.0;
    $jumlah = (int)round((float)$p['jumlah_pinjaman'] * $persen / 100, 0, PHP_ROUND_HALF_UP);
    if ($jumlah > 0) {
        $q = $db->prepare("INSERT IGNORE INTO shu_hak_pinjaman
            (pinjaman_id,anggota_id,sumber_anggota_id,jenis,kategori_snapshot,dasar_pinjaman,persen,jumlah,pembayaran_lancar)
            VALUES (?,?,?,'pelunasan',?,?,?,?,?)");
        $q->execute([$p['id'],$p['anggota_id'],$p['anggota_id'],$kategori,$p['jumlah_pinjaman'],$persen,$jumlah,shuPinjamanLancar($db,$p['id'])?1:0]);
    }

    $ketuaId = (int)($p['ketua_kelompok_id'] ?? 0);
    $lancar = shuPinjamanLancar($db, $p['id']);
    if ($kategori === 'anggota' && $ketuaId > 0 && $lancar) {
        $s = $db->prepare("SELECT COUNT(*) FROM anggota WHERE id=? AND kategori_shu='ketua_kelompok'");
        $s->execute([$ketuaId]);
        if ((int)$s->fetchColumn()) {
            $refPersen = 0.25; // 2% x 12,5% = Rp2.500 per Rp1 juta.
            $refJumlah = (int)round((float)$p['jumlah_pinjaman'] * $refPersen / 100, 0, PHP_ROUND_HALF_UP);
            if ($refJumlah > 0) {
                $q = $db->prepare("INSERT IGNORE INTO shu_hak_pinjaman
                    (pinjaman_id,anggota_id,sumber_anggota_id,jenis,kategori_snapshot,dasar_pinjaman,persen,jumlah,pembayaran_lancar)
                    VALUES (?,?,?,'referensi','ketua_kelompok',?,?,?,1)");
                $q->execute([$p['id'],$ketuaId,$p['anggota_id'],$p['jumlah_pinjaman'],$refPersen,$refJumlah]);
            }
        }
    }
}

function shuSinkronkanHakLama($db) {
    if (!shuPinjamanSiap($db)) return 0;
    $ids = $db->query("SELECT p.id FROM pinjaman p JOIN jenis_pinjaman jp ON jp.id=p.jenis_pinjaman_id WHERE p.status='lunas' AND jp.memerlukan_jaminan=0 ORDER BY p.id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) shuCatatHakPinjaman($db, $id);
    return count($ids);
}

function shuBayarHakPinjaman($db, $hakId, $metode, $referensi, $foto, &$files) {
    if (!isAdmin()) throw new Exception('Pembayaran SHU hanya dapat dilakukan Admin.');
    if (!in_array($metode, ['cash','transfer'], true)) throw new Exception('Metode pembayaran tidak valid.');
    $referensi = trim((string)$referensi);
    if (strlen($referensi) < 3 || strlen($referensi) > 100) throw new Exception('Referensi pembayaran wajib 3–100 karakter.');
    $s = $db->prepare('SELECT h.*,a.nama_lengkap,a.kode_anggota FROM shu_hak_pinjaman h JOIN anggota a ON a.id=h.anggota_id WHERE h.id=? FOR UPDATE');
    $s->execute([(int)$hakId]);
    $h = $s->fetch(PDO::FETCH_ASSOC);
    if (!$h || $h['status_bayar'] !== 'belum') throw new Exception('Hak SHU tidak ditemukan atau sudah dibayar.');
    $path = layananSimpanFoto($foto, 'pembayaran'); $files[] = $path;
    $tid = shuCatatKas($db, $h, (int)$h['jumlah'], 'keluar', 'Pembayaran SHU pinjaman '.$h['pinjaman_id'].' - '.$h['jenis']);
    $u = $db->prepare("UPDATE shu_hak_pinjaman SET status_bayar='sudah',metode=?,bukti=?,referensi=?,dibayar_oleh=?,dibayar_at=NOW(),transaksi_id=? WHERE id=? AND status_bayar='belum'");
    $u->execute([$metode,$path,$referensi,$_SESSION['user_id'],$tid,$h['id']]);
    if (!$u->rowCount()) throw new Exception('Hak SHU sudah diproses oleh pengguna lain.');
    $db->prepare('INSERT INTO shu_hak_audit(hak_id,user_id,aksi,detail) VALUES (?,?,?,?)')->execute([$h['id'],$_SESSION['user_id'],'bayar',json_encode(['jumlah'=>(int)$h['jumlah'],'metode'=>$metode,'transaksi'=>$tid],JSON_UNESCAPED_UNICODE)]);
}

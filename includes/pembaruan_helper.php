<?php

/**
 * Aturan pembaruan pinjaman, aging/KOL, kalender libur, dan alokasi pembayaran.
 * Fungsi bisnis berada di sini agar halaman Admin, Teller dan Anggota memakai aturan sama.
 */

function statusNasabahLanjutan(PDO $db, int $anggotaId): bool
{
    $q = $db->prepare("SELECT 1 FROM pinjaman WHERE anggota_id=? AND status='lunas' LIMIT 1");
    $q->execute([$anggotaId]);
    return (bool)$q->fetchColumn();
}

function batasNominalPinjaman(PDO $db, int $anggotaId, float $batasJenis): array
{
    $q = $db->prepare("SELECT jumlah_pinjaman FROM pinjaman
        WHERE anggota_id=? AND status='lunas'
        ORDER BY COALESCE(tanggal_lunas, tanggal_pencairan) DESC, id DESC LIMIT 1");
    $q->execute([$anggotaId]);
    $terakhir = $q->fetchColumn();
    $lanjutan = $terakhir !== false;
    $minimum = $lanjutan ? 500000.0 : 1000000.0;
    $maksimumKebijakan = $lanjutan ? ((float)$terakhir + 2000000.0) : 2000000.0;
    $batasAdmin = null;
    $limit = $db->prepare('SELECT batas_maksimum FROM pengajuan_limit_pinjaman WHERE anggota_id=? AND aktif=1 ORDER BY dibuat_pada DESC,id DESC LIMIT 1');
    $limit->execute([$anggotaId]);
    $batasAdmin = $limit->fetchColumn();
    $maksimum = min(max(0.0, $batasJenis), $maksimumKebijakan, $batasAdmin === false ? INF : (float)$batasAdmin);
    return [
        'lanjutan' => $lanjutan,
        'minimum' => $minimum,
        'maksimum' => $maksimum,
        'pinjaman_terakhir_lunas' => $lanjutan ? (float)$terakhir : null,
    ];
}

function validasiNominalPinjaman(float $nominal, array $batas): void
{
    if ($nominal < (float)$batas['minimum']) {
        throw new InvalidArgumentException('Minimum pinjaman untuk nasabah ' . (!empty($batas['lanjutan']) ? 'lanjutan' : 'baru') . ' adalah ' . formatRupiah($batas['minimum']) . '.');
    }
    if (fmod($nominal, 500000.0) !== 0.0) {
        throw new InvalidArgumentException('Nominal pinjaman harus kelipatan Rp500.000.');
    }
    if ($nominal > (float)$batas['maksimum']) {
        throw new InvalidArgumentException('Pengajuan ditolak: batas maksimum untuk nasabah ini ' . formatRupiah($batas['maksimum']) . '. Pilih nominal yang lebih rendah atau minta Admin/Teller meninjau limit produk.');
    }
}

function simpanKalenderLibur(PDO $db, string $tanggal, string $nama, string $sumber, int $userId): void
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $tanggal);
    if (!$dt || $dt->format('Y-m-d') !== $tanggal) throw new InvalidArgumentException('Tanggal libur tidak valid.');
    $nama = trim($nama);
    if ($nama === '' || mb_strlen($nama) > 160) throw new InvalidArgumentException('Nama libur wajib diisi (maksimal 160 karakter).');
    if (!in_array($sumber, ['idulfitri','iduladha','eksternal'], true)) throw new InvalidArgumentException('Jenis libur tidak valid.');
    $q = $db->prepare('INSERT INTO kalender_libur_angsuran(tanggal,nama,sumber,aktif,dibuat_oleh) VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE nama=VALUES(nama),sumber=VALUES(sumber),aktif=1,dibuat_oleh=VALUES(dibuat_oleh)');
    $q->execute([$tanggal,$nama,$sumber,$userId]);
}

function ajukanMutasiHariAngsuran(PDO $db,int $anggotaId,int $pinjamanId,int $hariBaru,int $userId,string $alasan):int
{
    if($hariBaru<1||$hariBaru>7)throw new InvalidArgumentException('Pilih hari Senin sampai Minggu.');
    $alasan=trim($alasan);if(mb_strlen($alasan)<5||mb_strlen($alasan)>2000)throw new InvalidArgumentException('Alasan wajib diisi (5–2.000 karakter).');
    if(!$db->inTransaction())throw new LogicException('Permohonan harus disimpan dalam transaksi.');
    $q=$db->prepare("SELECT p.id,pp.hari_angsuran FROM pinjaman p JOIN pengajuan_pinjaman pp ON pp.id=p.pengajuan_id WHERE p.id=? AND p.anggota_id=? AND p.status IN ('aktif','macet') FOR UPDATE");$q->execute([$pinjamanId,$anggotaId]);$p=$q->fetch(PDO::FETCH_ASSOC);
    if(!$p)throw new RuntimeException('Pinjaman aktif milik anggota tidak ditemukan.');
    $due=$db->prepare("SELECT COUNT(*) FROM angsuran WHERE pinjaman_id=? AND tanggal_jatuh_tempo<=CURDATE() AND status<>'lunas' AND jumlah_terbayar<jumlah_angsuran-0.005");$due->execute([$pinjamanId]);
    if((int)$due->fetchColumn()>0)throw new RuntimeException('Lunasi seluruh angsuran yang sudah jatuh tempo sebelum mengajukan pindah hari.');
    $pending=$db->prepare("SELECT COUNT(*) FROM pengajuan_mutasi_angsuran WHERE pinjaman_id=? AND status='menunggu'");$pending->execute([$pinjamanId]);if((int)$pending->fetchColumn())throw new RuntimeException('Sudah ada permohonan pindah hari yang menunggu keputusan.');
    if((int)$p['hari_angsuran']===$hariBaru)throw new RuntimeException('Hari angsuran pinjaman ini sudah sesuai.');
    $db->prepare("INSERT INTO pengajuan_mutasi_angsuran(anggota_id,pinjaman_id,jenis,hari_lama,hari_baru,alasan,status,diajukan_oleh) VALUES(?,?,'hari',?,?,?,'menunggu',?)")->execute([$anggotaId,$pinjamanId,$p['hari_angsuran'],$hariBaru,$alasan,$userId]);
    return (int)$db->lastInsertId();
}

function ajukanMutasiKelompokAngsuran(PDO $db,int $anggotaId,int $pinjamanId,int $kelompokBaruId,int $userId,string $alasan):int
{
    $alasan=trim($alasan);if(mb_strlen($alasan)<5||mb_strlen($alasan)>2000)throw new InvalidArgumentException('Alasan wajib diisi (5–2.000 karakter).');
    if(!$db->inTransaction())throw new LogicException('Permohonan harus disimpan dalam transaksi.');
    $q=$db->prepare("SELECT id,kelompok_angsuran_id FROM pinjaman WHERE id=? AND anggota_id=? AND status IN ('aktif','macet') FOR UPDATE");$q->execute([$pinjamanId,$anggotaId]);$p=$q->fetch(PDO::FETCH_ASSOC);
    if(!$p)throw new RuntimeException('Pinjaman aktif milik anggota tidak ditemukan.');
    $due=$db->prepare("SELECT COUNT(*) FROM angsuran WHERE pinjaman_id=? AND tanggal_jatuh_tempo<=CURDATE() AND status<>'lunas' AND jumlah_terbayar<jumlah_angsuran-0.005");$due->execute([$pinjamanId]);
    if((int)$due->fetchColumn()>0)throw new RuntimeException('Lunasi seluruh angsuran yang sudah jatuh tempo sebelum mengajukan pindah kelompok.');
    $group=$db->prepare("SELECT id FROM kelompok_angsuran WHERE id=? AND status='aktif'");$group->execute([$kelompokBaruId]);if(!$group->fetchColumn())throw new RuntimeException('Kelompok tujuan tidak aktif atau tidak ditemukan.');
    if((int)($p['kelompok_angsuran_id']??0)===$kelompokBaruId)throw new RuntimeException('Pinjaman ini sudah berada di kelompok tersebut.');
    $pending=$db->prepare("SELECT COUNT(*) FROM pengajuan_mutasi_angsuran WHERE pinjaman_id=? AND status='menunggu'");$pending->execute([$pinjamanId]);if((int)$pending->fetchColumn())throw new RuntimeException('Sudah ada permohonan mutasi yang menunggu keputusan.');
    $db->prepare("INSERT INTO pengajuan_mutasi_angsuran(anggota_id,pinjaman_id,jenis,kelompok_lama_id,kelompok_baru_id,alasan,status,diajukan_oleh) VALUES(?,?,'kelompok',?,?,?,'menunggu',?)")
        ->execute([$anggotaId,$pinjamanId,$p['kelompok_angsuran_id'],$kelompokBaruId,$alasan,$userId]);
    return (int)$db->lastInsertId();
}

function putuskanMutasiHariAngsuran(PDO $db,int $permohonanId,int $adminId,bool $setuju,string $catatan=''):?string
{
    if(!function_exists('isAdmin')||!isAdmin())throw new RuntimeException('Hanya Admin yang dapat memutuskan permohonan.');
    if(!$db->inTransaction())throw new LogicException('Keputusan harus disimpan dalam transaksi.');
    $q=$db->prepare("SELECT m.*,p.frekuensi,p.status pinjaman_status,p.kelompok_angsuran_id,pp.hari_angsuran FROM pengajuan_mutasi_angsuran m JOIN pinjaman p ON p.id=m.pinjaman_id JOIN pengajuan_pinjaman pp ON pp.id=p.pengajuan_id WHERE m.id=? AND m.status='menunggu' FOR UPDATE");$q->execute([$permohonanId]);$m=$q->fetch(PDO::FETCH_ASSOC);if(!$m)throw new RuntimeException('Permohonan tidak ditemukan atau sudah diputuskan.');
    $tanggalEfektif=null;
    if($m['jenis']==='kelompok'){
        if($setuju){
            if(!in_array($m['pinjaman_status'],['aktif','macet'],true))throw new RuntimeException('Pinjaman sudah tidak aktif.');
            $due=$db->prepare("SELECT COUNT(*) FROM angsuran WHERE pinjaman_id=? AND tanggal_jatuh_tempo<=CURDATE() AND status<>'lunas' AND jumlah_terbayar<jumlah_angsuran-0.005");$due->execute([$m['pinjaman_id']]);if((int)$due->fetchColumn())throw new RuntimeException('Keputusan ditunda: masih ada angsuran jatuh tempo yang belum lunas.');
            $group=$db->prepare("SELECT id FROM kelompok_angsuran WHERE id=? AND status='aktif'");$group->execute([(int)$m['kelompok_baru_id']]);if(!$group->fetchColumn())throw new RuntimeException('Kelompok tujuan sudah tidak aktif atau tidak ditemukan.');
            $db->prepare('UPDATE pinjaman SET kelompok_angsuran_id=? WHERE id=?')->execute([(int)$m['kelompok_baru_id'],(int)$m['pinjaman_id']]);
            $tanggalEfektif=date('Y-m-d');
        }
        $status=$setuju?'disetujui':'ditolak';$catatan=trim($catatan);
        $db->prepare('UPDATE pengajuan_mutasi_angsuran SET status=?,diputuskan_oleh=?,catatan_admin=?,tanggal_efektif=?,diputuskan_pada=NOW() WHERE id=? AND status=\'menunggu\'')->execute([$status,$adminId,$catatan?:null,$tanggalEfektif,$permohonanId]);
        layananAudit($db,(int)$m['pinjaman_id'],'mutasi_kelompok_'.$status,'Kelompok '.($m['kelompok_lama_id']?:'tanpa kelompok').' ke '.$m['kelompok_baru_id'].($tanggalEfektif?' efektif '.$tanggalEfektif:'').($catatan?' · '.$catatan:''));
        return $tanggalEfektif;
    }
    if($m['jenis']!=='hari')throw new RuntimeException('Jenis mutasi tidak valid.');
    if($setuju){
        if(!in_array($m['pinjaman_status'],['aktif','macet'],true))throw new RuntimeException('Pinjaman sudah tidak aktif.');
        $due=$db->prepare("SELECT COUNT(*) FROM angsuran WHERE pinjaman_id=? AND tanggal_jatuh_tempo<=CURDATE() AND status<>'lunas' AND jumlah_terbayar<jumlah_angsuran-0.005");$due->execute([$m['pinjaman_id']]);if((int)$due->fetchColumn())throw new RuntimeException('Keputusan ditunda: masih ada angsuran jatuh tempo yang belum lunas.');
        $hariTarget=(int)$m['hari_baru'];$mula=(new DateTimeImmutable('today'))->modify('+7 days');$offset=($hariTarget-(int)$mula->format('N')+7)%7;$pertama=$mula->modify('+'.$offset.' days');
        $jadwal=$db->prepare("SELECT id,angsuran_ke,tanggal_jatuh_tempo FROM angsuran WHERE pinjaman_id=? AND status<>'lunas' ORDER BY angsuran_ke FOR UPDATE");$jadwal->execute([$m['pinjaman_id']]);$rows=$jadwal->fetchAll(PDO::FETCH_ASSOC);if(!$rows)throw new RuntimeException('Tidak ada jadwal angsuran yang dapat dipindahkan.');
        $update=$db->prepare('UPDATE angsuran SET tanggal_jatuh_tempo_asal=COALESCE(tanggal_jatuh_tempo_asal,tanggal_jatuh_tempo),tanggal_jatuh_tempo=? WHERE id=?');$last=null;
        foreach($rows as $i=>$row){
            if($i===0)$tgl=$pertama;
            elseif($m['frekuensi']==='mingguan')$tgl=$pertama->modify('+'.($i*7).' days');
            elseif($m['frekuensi']==='dua_mingguan')$tgl=$pertama->modify('+'.($i*14).' days');
            else{$tgl=$pertama->modify('+'.$i.' months');$offset=($hariTarget-(int)$tgl->format('N')+7)%7;$tgl=$tgl->modify('+'.$offset.' days');}
            $str=$tgl->format('Y-m-d');$update->execute([$str,(int)$row['id']]);$last=$str;
        }
        $db->prepare('UPDATE pengajuan_pinjaman SET hari_angsuran=? WHERE id=(SELECT pengajuan_id FROM pinjaman WHERE id=?)')->execute([$hariTarget,$m['pinjaman_id']]);
        $db->prepare('UPDATE pinjaman SET jatuh_tempo=? WHERE id=?')->execute([$last,$m['pinjaman_id']]);
        $tanggalEfektif=$pertama->format('Y-m-d');
    }
    $status=$setuju?'disetujui':'ditolak';$catatan=trim($catatan);
    $db->prepare('UPDATE pengajuan_mutasi_angsuran SET status=?,diputuskan_oleh=?,catatan_admin=?,tanggal_efektif=?,diputuskan_pada=NOW() WHERE id=? AND status=\'menunggu\'')->execute([$status,$adminId,$catatan?:null,$tanggalEfektif,$permohonanId]);
    layananAudit($db,(int)$m['pinjaman_id'],'mutasi_hari_'.$status,'Hari '.$m['hari_lama'].' ke '.$m['hari_baru'].($tanggalEfektif?' efektif '.$tanggalEfektif:'').($catatan?' · '.$catatan:''));
    return $tanggalEfektif;
}

function perbaruiJadwalHariLibur(PDO $db, ?int $pinjamanId = null): int
{
    $sql = "SELECT a.id,a.pinjaman_id,a.angsuran_ke,a.tanggal_jatuh_tempo,p.frekuensi
        FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id
        WHERE a.status<>'lunas' AND p.status IN ('aktif','macet') AND a.tanggal_jatuh_tempo>=CURDATE()";
    $params = [];
    if ($pinjamanId !== null) {
        $sql .= ' AND a.pinjaman_id=?';
        $params[] = $pinjamanId;
    }
    $sql .= ' ORDER BY a.pinjaman_id,a.angsuran_ke';
    $q = $db->prepare($sql);
    $q->execute($params);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;

    $liburRows = $db->query('SELECT tanggal FROM kalender_libur_angsuran WHERE aktif=1')->fetchAll(PDO::FETCH_COLUMN);
    $libur = array_fill_keys($liburRows, true);
    $update = $db->prepare('UPDATE angsuran SET tanggal_jatuh_tempo=? WHERE id=? AND status<>\'lunas\'');
    $ubah = 0;$pinjamanBerubah=[];
    $perPinjaman = [];
    foreach ($rows as $row) $perPinjaman[(int)$row['pinjaman_id']][] = $row;
    foreach ($perPinjaman as $jadwal) {
        $sebelumnya = null;
        $frekuensi = (string)($jadwal[0]['frekuensi'] ?? 'mingguan');
        $jarak = $frekuensi === 'dua_mingguan' ? 14 : ($frekuensi === 'mingguan' ? 7 : 0);
        foreach ($jadwal as $row) {
            $tanggal = new DateTimeImmutable($row['tanggal_jatuh_tempo']);
            while (isset($libur[$tanggal->format('Y-m-d')]) || ($sebelumnya !== null && $tanggal <= $sebelumnya)) {
                $tanggal = $jarak > 0
                    ? $tanggal->modify('+' . $jarak . ' days')
                    : new DateTimeImmutable(hitungJatuhTempo($tanggal->format('Y-m-d'),1,'bulanan'));
            }
            $baru = $tanggal->format('Y-m-d');
            if ($baru !== $row['tanggal_jatuh_tempo']) {
                $update->execute([$baru, (int)$row['id']]);
                $ubah += $update->rowCount();
                if($update->rowCount())$pinjamanBerubah[(int)$row['pinjaman_id']]=true;
            }
            $sebelumnya = $tanggal;
        }
    }
    if($pinjamanBerubah){$upd=$db->prepare('UPDATE pinjaman SET jatuh_tempo=(SELECT MAX(a.tanggal_jatuh_tempo) FROM angsuran a WHERE a.pinjaman_id=pinjaman.id) WHERE id=?');foreach(array_keys($pinjamanBerubah) as $pid)$upd->execute([$pid]);}
    return $ubah;
}

function hitungDPDPinjaman(PDO $db, int $pinjamanId, ?string $acuan = null): array
{
    $acuan = $acuan ?: date('Y-m-d');
    $q = $db->prepare("SELECT p.tanggal_mulai_tunggakan, p.reschedule_parent_id,
        MIN(CASE WHEN a.status<>'lunas' AND a.jumlah_terbayar < a.jumlah_angsuran AND a.tanggal_jatuh_tempo < ? THEN a.tanggal_jatuh_tempo END) AS jatuh_tempo_tertua
        FROM pinjaman p LEFT JOIN angsuran a ON a.pinjaman_id=p.id
        WHERE p.id=? GROUP BY p.id");
    $q->execute([$acuan,$pinjamanId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['dpd'=>0,'kol'=>1,'tanggal_tunggakan'=>null];
    $tanggal = $row['jatuh_tempo_tertua'];
    if (!empty($row['tanggal_mulai_tunggakan']) && $row['tanggal_mulai_tunggakan'] < $acuan
        && (!$tanggal || $row['tanggal_mulai_tunggakan'] < $tanggal)) $tanggal=$row['tanggal_mulai_tunggakan'];
    if (!empty($row['reschedule_parent_id'])) {
        $asal = $db->prepare('SELECT tanggal_mulai_tunggakan FROM pinjaman WHERE id=?');
        $asal->execute([(int)$row['reschedule_parent_id']]);
        $asalTanggal = $asal->fetchColumn() ?: null;
        if ($asalTanggal && (!$tanggal || $asalTanggal < $tanggal)) $tanggal = $asalTanggal;
    }
    if (!$tanggal || $tanggal >= $acuan) return ['dpd'=>0,'kol'=>1,'tanggal_tunggakan'=>$tanggal ?: null];
    $dpd = (int)(new DateTimeImmutable($tanggal))->diff(new DateTimeImmutable($acuan))->format('%a');
    $kol = $dpd <= 0 ? 1 : ($dpd <= 90 ? 2 : ($dpd <= 120 ? 3 : ($dpd <= 180 ? 4 : 5)));
    return ['dpd'=>$dpd,'kol'=>$kol,'tanggal_tunggakan'=>$tanggal];
}

function ringkasanKOLAnggota(PDO $db, int $anggotaId, ?string $acuan = null): array
{
    $q = $db->prepare("SELECT id,sisa_pokok FROM pinjaman WHERE anggota_id=? AND status IN ('aktif','macet') ORDER BY id");
    $q->execute([$anggotaId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $hasil = ['kol'=>1,'dpd'=>0,'sisa_pokok'=>0.0,'pinjaman'=>[]];
    foreach ($rows as $p) {
        $k = hitungDPDPinjaman($db,(int)$p['id'],$acuan);
        $k['pinjaman_id'] = (int)$p['id'];
        $k['sisa_pokok'] = (float)$p['sisa_pokok'];
        $hasil['pinjaman'][] = $k;
        $hasil['sisa_pokok'] += (float)$p['sisa_pokok'];
        if ($k['kol'] > $hasil['kol']) $hasil['kol'] = $k['kol'];
        if ($k['dpd'] > $hasil['dpd']) $hasil['dpd'] = $k['dpd'];
    }
    return $hasil;
}

function alokasiPembayaranFIFO(PDO $db, int $pinjamanId, float $jumlah): array
{
    if ($jumlah <= 0) throw new InvalidArgumentException('Nominal pembayaran harus lebih dari nol.');
    $q = $db->prepare("SELECT a.id,a.angsuran_ke,a.jumlah_angsuran,a.pokok,a.bunga,a.jumlah_terbayar,a.tanggal_jatuh_tempo,a.status,
        COALESCE((SELECT SUM(pa.pokok) FROM pembayaran_angsuran_alokasi pa WHERE pa.angsuran_id=a.id),0) AS pokok_teralokasi
        FROM angsuran a WHERE a.pinjaman_id=? AND a.status<>'lunas'
        ORDER BY a.tanggal_jatuh_tempo,a.angsuran_ke FOR UPDATE");
    $q->execute([$pinjamanId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $sisa = round($jumlah,2);
    $alokasi = [];
    foreach ($rows as $row) {
        if ($sisa <= 0.005) break;
        $tunggakan = max(0.0,(float)$row['jumlah_angsuran']-(float)$row['jumlah_terbayar']);
        if ($tunggakan <= 0.005) continue;
        $nilai = min($sisa,$tunggakan);
        $proporsiPokok = (float)$row['jumlah_angsuran'] > 0 ? (float)$row['pokok']/(float)$row['jumlah_angsuran'] : 0;
        $pokokSisa=max(0.0,(float)$row['pokok']-(float)$row['pokok_teralokasi']);
        $pokok = $nilai >= $tunggakan-0.005 ? $pokokSisa : min($pokokSisa, round($nilai*$proporsiPokok,2));
        $bunga = round($nilai-$pokok,2);
        $alokasi[] = ['angsuran_id'=>(int)$row['id'],'angsuran_ke'=>(int)$row['angsuran_ke'],'jumlah'=>$nilai,'pokok'=>$pokok,'bunga'=>$bunga,'jumlah_terbayar_sebelum'=>(float)$row['jumlah_terbayar'],'jumlah_angsuran'=>(float)$row['jumlah_angsuran'],'tanggal_jatuh_tempo'=>$row['tanggal_jatuh_tempo']];
        $sisa = round($sisa-$nilai,2);
    }
    if ($sisa > 0.005) throw new InvalidArgumentException('Pembayaran melebihi sisa total angsuran pinjaman.');
    if (!$alokasi) throw new InvalidArgumentException('Tidak ada angsuran yang masih memiliki tagihan.');
    return $alokasi;
}

/** Catat setoran pokok+bunga secara FIFO dalam transaksi yang dibuka pemanggil. */
function terapkanPembayaranAngsuran(PDO $db, int $pinjamanId, float $jumlah, int $userId, string $metode, string $jenis, ?int $pembayaranId = null, ?string $idempotencyKey = null): array
{
    if (!$db->inTransaction()) throw new LogicException('Pembayaran harus dicatat di dalam transaksi.');
    if ($jumlah <= 0 || !in_array($metode,['cash','transfer'],true) || !in_array($jenis,['petugas','anggota'],true)) throw new InvalidArgumentException('Data pembayaran tidak valid.');
    $q=$db->prepare("SELECT p.*,a.nama_lengkap FROM pinjaman p JOIN anggota a ON a.id=p.anggota_id WHERE p.id=? FOR UPDATE");
    $q->execute([$pinjamanId]); $p=$q->fetch(PDO::FETCH_ASSOC);
    if (!$p || !in_array($p['status'],['aktif','macet'],true)) throw new RuntimeException('Pinjaman tidak aktif.');
    $alokasi=alokasiPembayaranFIFO($db,$pinjamanId,$jumlah);
    $kode='ANG-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
    if ($pembayaranId===null) {
        $q=$db->prepare("INSERT INTO pembayaran_angsuran(kode_pembayaran,anggota_id,pinjaman_id,jumlah,metode,status,jenis,user_id,diverifikasi_oleh,diverifikasi_pada,idempotency_key) VALUES(?,?,?,?,?,'diterima',?,?,?,NOW(),?)");
        $q->execute([$kode,$p['anggota_id'],$pinjamanId,$jumlah,$metode,$jenis,$userId,$userId,$idempotencyKey]);
        $pembayaranId=(int)$db->lastInsertId();
    } else {
        $q=$db->prepare("SELECT id FROM pembayaran_angsuran WHERE id=? AND pinjaman_id=? AND status='menunggu' FOR UPDATE");
        $q->execute([$pembayaranId,$pinjamanId]);
        if (!$q->fetchColumn()) throw new RuntimeException('Pengajuan transfer sudah diproses atau tidak cocok.');
        $db->prepare("UPDATE pembayaran_angsuran SET status='diterima',diverifikasi_oleh=?,diverifikasi_pada=NOW() WHERE id=?")->execute([$userId,$pembayaranId]);
    }
    $jenisSimpanan=$db->query("SELECT id FROM jenis_simpanan WHERE kode_jenis='ANG' LIMIT 1")->fetchColumn();
    if (!$jenisSimpanan) { $db->exec("INSERT INTO jenis_simpanan(kode_jenis,nama_jenis,deskripsi,status) VALUES('ANG','Pembayaran Angsuran','Pembayaran angsuran pinjaman','aktif')"); $jenisSimpanan=(int)$db->lastInsertId(); }
    $ket='Pembayaran angsuran FIFO '.$p['kode_pinjaman'].' ('.$kode.')';
    $db->prepare("INSERT INTO transaksi(kode_transaksi,anggota_id,jenis_simpanan_id,jenis_transaksi,jumlah,saldo_sebelum,saldo_sesudah,keterangan,user_id,tanggal_transaksi) VALUES(?,?,?,'setoran',?,0,?,?,?,NOW())")
        ->execute([$kode,$p['anggota_id'],$jenisSimpanan,$jumlah,$jumlah,$ket,$userId]);
    $transaksiId=(int)$db->lastInsertId();
    $db->query('SELECT id FROM koperasi WHERE id=1 FOR UPDATE')->fetchColumn();
    $saldo=(float)$db->query('SELECT saldo_sesudah FROM kas ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
    $db->prepare("INSERT INTO kas(transaksi_id,jenis,jumlah,saldo_sebelum,saldo_sesudah,keterangan,tanggal) VALUES(?,'masuk',?,?,?,?,NOW())")
        ->execute([$transaksiId,$jumlah,$saldo,$saldo+$jumlah,$ket]);
    $q=$db->prepare('SELECT id,saldo FROM simpanan WHERE anggota_id=? AND jenis_simpanan_id=? FOR UPDATE'); $q->execute([$p['anggota_id'],$jenisSimpanan]); $s=$q->fetch(PDO::FETCH_ASSOC);
    if ($s) $db->prepare('UPDATE simpanan SET saldo=saldo+? WHERE id=?')->execute([$jumlah,$s['id']]);
    else $db->prepare('INSERT INTO simpanan(anggota_id,jenis_simpanan_id,saldo) VALUES(?,?,?)')->execute([$p['anggota_id'],$jenisSimpanan,$jumlah]);
    $db->prepare('UPDATE pembayaran_angsuran SET transaksi_id=? WHERE id=?')->execute([$transaksiId,$pembayaranId]);
    $insert=$db->prepare('INSERT INTO pembayaran_angsuran_alokasi(pembayaran_id,angsuran_id,jumlah,pokok,bunga) VALUES(?,?,?,?,?)');
    $update=$db->prepare('UPDATE angsuran SET jumlah_terbayar=jumlah_terbayar+?,status=?,tanggal_bayar=? WHERE id=?');
    $pokokTotal=0.0;
    foreach($alokasi as $x){
        $insert->execute([$pembayaranId,$x['angsuran_id'],$x['jumlah'],$x['pokok'],$x['bunga']]);
        $lunas=($x['jumlah_terbayar_sebelum']+$x['jumlah']) >= $x['jumlah_angsuran']-0.005;
        $status=$lunas?'lunas':($x['tanggal_jatuh_tempo']<date('Y-m-d')?'telat':'belum_bayar');
        $update->execute([$x['jumlah'],$status,$lunas?date('Y-m-d'):null,$x['angsuran_id']]);
        $pokokTotal+=(float)$x['pokok'];
        $db->prepare("INSERT INTO layanan_pembayaran(angsuran_id,pinjaman_id,user_id,jenis,jumlah,metode,bukti,pembayaran_angsuran_id) VALUES(?,?,?,'angsuran',?,?, '',?)")->execute([$x['angsuran_id'],$pinjamanId,$userId,$x['jumlah'],$metode,$pembayaranId]);
    }
    $db->prepare("UPDATE pinjaman SET sisa_pokok=GREATEST(0,sisa_pokok-?),status='aktif' WHERE id=?")->execute([$pokokTotal,$pinjamanId]);
    if (function_exists('tandaiLunasBilaSelesai')) tandaiLunasBilaSelesai($db,$pinjamanId);
    layananAudit($db,$pinjamanId,'bayar_angsuran_fifo','Pembayaran '.$kode.'; Rp '.number_format($jumlah,2,'.','').' alokasi FIFO; '.$jenis);
    return ['pembayaran_id'=>$pembayaranId,'transaksi_id'=>$transaksiId,'alokasi'=>$alokasi,'total'=>$jumlah];
}

function batalkanPembayaranFIFO(PDO $db,int $pembayaranId,int $adminId,string $alasan):void
{
    if(!function_exists('isAdmin')||!isAdmin())throw new RuntimeException('Hanya Admin yang dapat membatalkan pembayaran FIFO.');
    if(!$db->inTransaction())throw new LogicException('Pembatalan harus dicatat di dalam transaksi.');
    $alasan=trim($alasan);if(mb_strlen($alasan)<5)throw new InvalidArgumentException('Alasan minimal 5 karakter.');
    $q=$db->prepare("SELECT * FROM pembayaran_angsuran WHERE id=? AND status='diterima' FOR UPDATE");$q->execute([$pembayaranId]);$b=$q->fetch(PDO::FETCH_ASSOC);
    if(!$b||empty($b['transaksi_id']))throw new RuntimeException('Pembayaran FIFO tidak ditemukan atau tidak dapat dibatalkan.');
    $t=$db->prepare('SELECT * FROM transaksi WHERE id=? FOR UPDATE');$t->execute([(int)$b['transaksi_id']]);$transaksi=$t->fetch(PDO::FETCH_ASSOC);
    if(!$transaksi||(float)$transaksi['jumlah']<=0)throw new RuntimeException('Transaksi kas sudah dibatalkan atau tidak ditemukan.');
    if(function_exists('pastikanBulanBelumTerkunci'))pastikanBulanBelumTerkunci($db,$transaksi['tanggal_transaksi']);
    $a=$db->prepare('SELECT pa.angsuran_id,pa.pokok,pa.jumlah,x.jumlah_terbayar,x.jumlah_angsuran,x.tanggal_jatuh_tempo FROM pembayaran_angsuran_alokasi pa JOIN angsuran x ON x.id=pa.angsuran_id WHERE pa.pembayaran_id=? FOR UPDATE');$a->execute([$pembayaranId]);$alokasi=$a->fetchAll(PDO::FETCH_ASSOC);
    if(!$alokasi)throw new RuntimeException('Rincian alokasi pembayaran tidak ditemukan.');
    $pokokPulih=0.0;$reverse=$db->prepare('UPDATE angsuran SET jumlah_terbayar=?,status=?,tanggal_bayar=? WHERE id=?');
    foreach($alokasi as $x){$paid=max(0,(float)$x['jumlah_terbayar']-(float)$x['jumlah']);$lunas=$paid>=(float)$x['jumlah_angsuran']-0.005;$status=$lunas?'lunas':($x['tanggal_jatuh_tempo']<date('Y-m-d')?'telat':'belum_bayar');$reverse->execute([$paid,$status,$lunas?date('Y-m-d'):null,$x['angsuran_id']]);$pokokPulih+=(float)$x['pokok'];}
    $jumlah=(float)$transaksi['jumlah'];$catatan=' | DIBATALKAN: '.$alasan;
    $db->prepare("UPDATE transaksi SET jumlah=0,saldo_sesudah=saldo_sesudah-?,keterangan=CONCAT(keterangan,?) WHERE id=?")->execute([$jumlah,$catatan,$transaksi['id']]);
    $k=$db->prepare('SELECT * FROM kas WHERE transaksi_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$k->execute([$transaksi['id']]);$kas=$k->fetch(PDO::FETCH_ASSOC);
    if($kas){$db->prepare("UPDATE kas SET jumlah=0,saldo_sesudah=saldo_sebelum,keterangan=CONCAT(COALESCE(keterangan,''),?) WHERE id=?")->execute([$catatan,$kas['id']]);$db->prepare('UPDATE kas SET saldo_sebelum=saldo_sebelum-?,saldo_sesudah=saldo_sesudah-? WHERE id>?')->execute([(float)$kas['jumlah'],(float)$kas['jumlah'],$kas['id']]);}
    $db->prepare('UPDATE simpanan SET saldo=saldo-? WHERE anggota_id=? AND jenis_simpanan_id=?')->execute([$jumlah,$transaksi['anggota_id'],$transaksi['jenis_simpanan_id']]);
    $db->prepare("UPDATE layanan_pembayaran SET dibatalkan_at=NOW() WHERE pembayaran_angsuran_id=? AND dibatalkan_at IS NULL")->execute([$pembayaranId]);
    $db->prepare("UPDATE pinjaman SET sisa_pokok=sisa_pokok+?,status='aktif',tanggal_lunas=NULL WHERE id=?")->execute([$pokokPulih,$b['pinjaman_id']]);
    $db->prepare("UPDATE pembayaran_angsuran SET status='ditolak',diverifikasi_oleh=?,diverifikasi_pada=NOW(),catatan=? WHERE id=?")->execute([$adminId,'Dibatalkan Admin: '.$alasan,$pembayaranId]);
    $db->prepare('INSERT INTO log_koreksi(transaksi_id,jumlah_lama,jumlah_baru,selisih,keterangan,user_id) VALUES(?,?,0,?,?,?)')->execute([$transaksi['id'],$jumlah,-$jumlah,'Pembatalan pembayaran FIFO '.$pembayaranId.': '.$alasan,$adminId]);
    layananAudit($db,(int)$b['pinjaman_id'],'batalkan_pembayaran_fifo','Pembayaran '.$pembayaranId.' dibatalkan: '.$alasan);
}

function prosesReschedulingPinjaman(PDO $db,int $pinjamanId,int $adminId,string $alasan,?string $tanggalAksi=null):int
{
    if(!function_exists('isAdmin')||!isAdmin())throw new RuntimeException('Rescheduling hanya dapat diproses Admin.');
    if(!$db->inTransaction())throw new LogicException('Rescheduling harus dilakukan dalam transaksi.');
    $alasan=trim($alasan);if(mb_strlen($alasan)<5)throw new InvalidArgumentException('Alasan rescheduling minimal 5 karakter.');
    $tanggalAksi=$tanggalAksi?:date('Y-m-d');
    $q=$db->prepare('SELECT p.*,pp.hari_angsuran,pp.kategori_shu,pp.ketua_kelompok_id,pp.kelompok_angsuran_id AS kelompok_pengajuan_id FROM pinjaman p JOIN pengajuan_pinjaman pp ON pp.id=p.pengajuan_id WHERE p.id=? FOR UPDATE');$q->execute([$pinjamanId]);$p=$q->fetch(PDO::FETCH_ASSOC);
    if(!$p||!in_array($p['status'],['aktif','macet'],true))throw new RuntimeException('Pinjaman aktif tidak ditemukan.');
    $child=$db->prepare('SELECT id FROM pinjaman WHERE reschedule_parent_id=? LIMIT 1 FOR UPDATE');$child->execute([$pinjamanId]);if($child->fetchColumn())throw new RuntimeException('Pinjaman ini sudah pernah di-reschedule.');
    $aging=hitungDPDPinjaman($db,$pinjamanId,$tanggalAksi);if(!in_array((int)$aging['kol'],[2,3,4],true))throw new RuntimeException('Rescheduling hanya tersedia untuk KOL 2 sampai KOL 4.');
    $pending=$db->prepare("SELECT COUNT(*) FROM pembayaran_angsuran WHERE pinjaman_id=? AND status='menunggu'");$pending->execute([$pinjamanId]);if((int)$pending->fetchColumn()>0)throw new RuntimeException('Selesaikan verifikasi setoran yang masih menunggu sebelum rescheduling.');
    $pokok=max(0.0,(float)$p['sisa_pokok']);if($pokok<=0.005)throw new RuntimeException('Sisa pokok tidak tersedia untuk rescheduling.');
    $profitQ=$db->prepare("SELECT COALESCE(SUM(GREATEST(0,a.bunga-COALESCE(x.bunga_dibayar,0))),0) FROM angsuran a LEFT JOIN (SELECT angsuran_id,SUM(bunga) bunga_dibayar FROM pembayaran_angsuran_alokasi GROUP BY angsuran_id) x ON x.angsuran_id=a.id WHERE a.pinjaman_id=? AND a.status<>'lunas'");$profitQ->execute([$pinjamanId]);$keuntunganTersisa=(float)$profitQ->fetchColumn();
    $rate=(float)$p['bunga'];$profitBaru=min(round($pokok*$rate/100,2),round($keuntunganTersisa,2));$tenorBaru=max(1,(int)$p['tenor']*2);$hitung=hitungPinjaman($pokok,$pokok>0?$profitBaru/$pokok*100:0,$tenorBaru,$p['frekuensi'],'flat');
    $jumlahCicilan=(int)$hitung['jumlah_cicilan'];$tglPertama=(new DateTimeImmutable($tanggalAksi))->modify('+7 days');$tanggalJatuhTempo=[];
    for($i=1;$i<=$jumlahCicilan;$i++){
        if($i===1)$d=$tglPertama;
        elseif($p['frekuensi']==='mingguan')$d=$tglPertama->modify('+'.(($i-1)*7).' days');
        elseif($p['frekuensi']==='dua_mingguan')$d=$tglPertama->modify('+'.(($i-1)*14).' days');
        else{$d=$tglPertama->modify('+'.($i-1).' months');}
        $tanggalJatuhTempo[]=$d->format('Y-m-d');
    }
    $oldDpdDate=$aging['tanggal_tunggakan'];
    $kodePengajuan='RSJ-'.date('ymdHis').'-'.str_pad((string)random_int(1,999),3,'0',STR_PAD_LEFT);
    $db->prepare("INSERT INTO pengajuan_pinjaman(kode_pengajuan,anggota_id,jenis_pinjaman_id,jumlah_pinjaman,tenor,bunga,skema_bunga,frekuensi,tujuan_pinjaman,status,admin_id,user_id,tanggal_pengajuan,tanggal_persetujuan,hari_angsuran,fasilitas_prioritas,kategori_shu,ketua_kelompok_id,kelompok_angsuran_id,batas_pinjaman_snapshot,nasabah_lanjutan) VALUES(?,?,?,?,?,?,'flat',?,?,'dicairkan',?,?,NOW(),NOW(),?,0,?,?,?,?,1)")
        ->execute([$kodePengajuan,$p['anggota_id'],$p['jenis_pinjaman_id'],$pokok,$tenorBaru,$profitBaru/$pokok*100,$p['frekuensi'],'Rescheduling '.$p['kode_pinjaman'].': '.$alasan,$adminId,$adminId,$p['hari_angsuran'],$p['kategori_shu'],$p['ketua_kelompok_id'],$p['kelompok_angsuran_id'],$pokok]);
    $pengajuanBaru=(int)$db->lastInsertId();$kodePinjaman='RSJ-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
    $db->prepare("INSERT INTO pinjaman(pengajuan_id,kode_pinjaman,anggota_id,jenis_pinjaman_id,jumlah_pinjaman,sisa_pokok,tenor,bunga,skema_bunga,frekuensi,jumlah_cicilan,total_pinjaman,angsuran_per_bulan,tanggal_pencairan,jatuh_tempo,dicairkan_oleh,status,kategori_shu,ketua_kelompok_id,kelompok_angsuran_id,reschedule_parent_id,is_rescheduling,tanggal_mulai_tunggakan) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'aktif',?,?,?,?,?,?)")
        ->execute([$pengajuanBaru,$kodePinjaman,$p['anggota_id'],$p['jenis_pinjaman_id'],$pokok,$pokok,$tenorBaru,$profitBaru/$pokok*100,'flat',$p['frekuensi'],$jumlahCicilan,$hitung['total'],$hitung['angsuran'],$tanggalAksi,end($tanggalJatuhTempo),$adminId,$p['kategori_shu'],$p['ketua_kelompok_id'],$p['kelompok_angsuran_id'],$pinjamanId,1,$oldDpdDate]);
    $newId=(int)$db->lastInsertId();$ins=$db->prepare("INSERT INTO angsuran(pinjaman_id,angsuran_ke,jumlah_angsuran,pokok,bunga,tanggal_jatuh_tempo,status,jumlah_terbayar,tanggal_jatuh_tempo_asal) VALUES(?,?,?,?,?,?,'belum_bayar',0,?)");
    foreach($hitung['rincian'] as $i=>$x)$ins->execute([$newId,$x['ke'],$x['jumlah'],$x['pokok'],$x['bunga'],$tanggalJatuhTempo[$i],$tanggalJatuhTempo[$i]]);
    perbaruiJadwalHariLibur($db,$newId);
    $finePaid=$db->prepare('SELECT a.id,a.denda,COALESCE(SUM(b.jumlah),0) paid FROM angsuran a LEFT JOIN layanan_bayar_denda b ON b.angsuran_id=a.id WHERE a.pinjaman_id=? AND a.status<>\'lunas\' GROUP BY a.id,a.denda');$finePaid->execute([$pinjamanId]);$fineRows=$finePaid->fetchAll(PDO::FETCH_ASSOC);$dendaDibebaskan=0.0;$updFine=$db->prepare('UPDATE angsuran SET denda=?,denda_manual=1 WHERE id=?');foreach($fineRows as $f){$dendaDibebaskan+=max(0,(float)$f['denda']-(float)$f['paid']);$updFine->execute([$f['paid'],$f['id']]);}
    $snapshot=['asal_id'=>$pinjamanId,'asal_kode'=>$p['kode_pinjaman'],'pokok_awal'=>(float)$p['jumlah_pinjaman'],'sisa_pokok'=>$pokok,'bunga_lama'=>$rate,'keuntungan_tersisa_belum_lunas'=>$keuntunganTersisa,'keuntungan_baru'=>$profitBaru,'tenor_lama'=>(int)$p['tenor'],'tenor_baru'=>$tenorBaru,'frekuensi'=>$p['frekuensi'],'jumlah_cicilan_baru'=>$jumlahCicilan,'denda_dibebaskan'=>$dendaDibebaskan,'dpd_awal'=>$aging['dpd'],'tanggal_tunggakan'=>$oldDpdDate,'alasan'=>$alasan,'tanggal_aksi'=>$tanggalAksi];
    $db->prepare('INSERT INTO audit_rescheduling(pinjaman_asal_id,pinjaman_baru_id,admin_id,snapshot_json) VALUES(?,?,?,?)')->execute([$pinjamanId,$newId,$adminId,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $db->prepare("UPDATE pinjaman SET status='reschedule',sisa_pokok=0 WHERE id=?")->execute([$pinjamanId]);
    layananAudit($db,$pinjamanId,'reschedule','Kontrak '.$kodePinjaman.' dibuat; pokok '.$pokok.'; keuntungan '.$profitBaru.'; denda dibebaskan. '.$alasan);
    return $newId;
}


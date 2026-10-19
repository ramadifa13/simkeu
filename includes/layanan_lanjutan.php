<?php
function layananKreditLelang($db,$id) {
    $s=$db->prepare('SELECT kredit FROM layanan_lelang WHERE pinjaman_id=?');$s->execute([$id]);
    return (float)$s->fetchColumn();
}
function layananPakaiKredit($db,$id,$angsuran,$jumlah) {
    $s=$db->prepare('SELECT * FROM layanan_lelang WHERE pinjaman_id=? FOR UPDATE');$s->execute([$id]);$l=$s->fetch(PDO::FETCH_ASSOC);
    $pakai=$l?round(min((float)$l['kredit'],max(0,$jumlah)),2):0;
    if($pakai>0) {
        $db->prepare('UPDATE layanan_lelang SET kredit=kredit-?,terpakai=terpakai+? WHERE id=?')->execute([$pakai,$pakai,$l['id']]);
        $db->prepare('INSERT INTO layanan_alokasi_lelang (lelang_id,angsuran_id,jumlah) VALUES (?,?,?)')->execute([$l['id'],$angsuran,$pakai]);
    }
    return $pakai;
}
function layananSegarkanDenda($db,$p) {
    $s=$db->prepare("SELECT * FROM angsuran WHERE pinjaman_id=? AND status<>'lunas' FOR UPDATE");$s->execute([$p['id']]);
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $a) if(!$a['denda_manual']) {
        $a['memerlukan_jaminan']=$p['memerlukan_jaminan'];$d=hitungDendaAngsuran($db,$a,$p['jumlah_pinjaman']);
        $db->prepare("UPDATE angsuran SET denda=?,keterlambatan_hari=?,status=? WHERE id=?")->execute([$d['denda'],$d['hari_telat'],$d['hari_telat']?'telat':'belum_bayar',$a['id']]);
    }
}
function layananBungaDiterima($db,$id) {
    $s=$db->prepare("SELECT COALESCE(SUM(bunga),0) FROM angsuran WHERE pinjaman_id=? AND status='lunas'");$s->execute([$id]);$b=(float)$s->fetchColumn();
    $s=$db->prepare("SELECT COALESCE(SUM(bunga_diskon),0) FROM layanan_pembayaran WHERE pinjaman_id=? AND dibatalkan_at IS NULL");$s->execute([$id]);$diskon=(float)$s->fetchColumn();
    return max(0,round($b-$diskon,2));
}
function layananCatatKeluar($db,$p,$jumlah,$ket) {
    if($jumlah<=0)throw new Exception('Nominal pengeluaran harus positif.');
    $db->query('SELECT id FROM koperasi WHERE id=1 FOR UPDATE')->fetchColumn();
    $saldo=(float)$db->query('SELECT saldo_sesudah FROM kas ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
    if($jumlah>$saldo)throw new Exception('Saldo kas tidak cukup untuk pengeluaran.');
    $jenis=$db->query("SELECT id FROM jenis_simpanan WHERE kode_jenis='BONUS'")->fetchColumn();
    if(!$jenis){$db->exec("INSERT INTO jenis_simpanan (kode_jenis,nama_jenis,deskripsi,status) VALUES ('BONUS','Bonus dan pengembalian','Pengeluaran layanan nasabah','aktif')");$jenis=$db->lastInsertId();}
    $db->prepare("INSERT INTO transaksi (kode_transaksi,anggota_id,jenis_simpanan_id,jenis_transaksi,jumlah,saldo_sebelum,saldo_sesudah,keterangan,user_id,tanggal_transaksi) VALUES (?,?,?,'penarikan',?,0,0,?,?,NOW())")->execute(['OUT-'.bin2hex(random_bytes(8)),$p['anggota_id'],$jenis,$jumlah,$ket,$_SESSION['user_id']]);
    $tid=$db->lastInsertId();
    $db->prepare("INSERT INTO kas (transaksi_id,jenis,jumlah,saldo_sebelum,saldo_sesudah,keterangan,tanggal) VALUES (?,'keluar',?,?,?,?,NOW())")->execute([$tid,$jumlah,$saldo,$saldo-$jumlah,$ket]);return $tid;
}
function layananValidasiPrioritas($db,$anggota,$jumlah,$jaminan,$diminta) {
    if(!$diminta)return;
    if($jaminan || $jumlah>1000000)throw new Exception('Fasilitas prioritas hanya tanpa jaminan sampai Rp 1 juta.');
    $s=$db->prepare("SELECT COUNT(*) FROM layanan_prioritas WHERE anggota_id=? AND status='disetujui'");$s->execute([$anggota]);
    if(!$s->fetchColumn() || !layananPrioritasLayak($db,$anggota))throw new Exception('Fasilitas prioritas belum disetujui atau dua pelunasan terakhir tidak lancar.');
}
function prosesLayananLanjutan($db,$p,$aksi,&$files) {
    $id=$p['id'];$uid=$_SESSION['user_id'];$catatan=trim($_POST['catatan'] ?? '');
    if($aksi==='ajukan_hapus_denda') {
        if($p['status']==='lunas' || strlen($catatan)<5)throw new Exception('Pengajuan memerlukan pinjaman belum lunas dan alasan minimal 5 karakter.');
        $s=$db->prepare("SELECT id FROM angsuran WHERE id=? AND pinjaman_id=? AND status<>'lunas' AND denda>0");$s->execute([(int)($_POST['angsuran_id']??0),$id]);
        if(!$s->fetchColumn())throw new Exception('Angsuran berdenda tidak ditemukan.');
        $db->prepare('INSERT INTO layanan_pengajuan_hapus_denda (pinjaman_id,angsuran_id,user_id,alasan) VALUES (?,?,?,?)')->execute([$id,(int)$_POST['angsuran_id'],$uid,$catatan]);
    } elseif($aksi==='putuskan_hapus_denda') {
        layananAdmin();
        $s=$db->prepare("SELECT * FROM layanan_pengajuan_hapus_denda WHERE id=? AND pinjaman_id=? AND status='menunggu' FOR UPDATE");$s->execute([(int)($_POST['pengajuan_id']??0),$id]);$r=$s->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new Exception('Pengajuan sudah diproses atau tidak ditemukan.');
        $setuju=($_POST['keputusan']??'')==='setuju';
        if(!in_array($_POST['keputusan']??'', ['setuju','tolak'],true))throw new Exception('Keputusan tidak valid.');
        if($setuju){
            if(!$db->query('SELECT pemutihan FROM layanan_pengaturan WHERE id=1')->fetchColumn())throw new Exception('Pemutihan sedang dinonaktifkan.');
            $s=$db->prepare("SELECT * FROM angsuran WHERE id=? AND status<>'lunas'");$s->execute([$r['angsuran_id']]);$a=$s->fetch(PDO::FETCH_ASSOC);
            if(!$a)throw new Exception('Angsuran sudah lunas; pengajuan hanya dapat ditolak.');
            $s=$db->prepare('SELECT COALESCE(SUM(jumlah),0) FROM layanan_bayar_denda WHERE angsuran_id=?');$s->execute([$r['angsuran_id']]);$paid=$s->fetchColumn();
            $db->prepare('UPDATE angsuran SET denda=?,denda_manual=1 WHERE id=?')->execute([$paid,$r['angsuran_id']]);
            $catatan='Denda awal '.$a['denda'].'; diputihkan '.($a['denda']-$paid).'; '.$r['alasan'];
        }
        $db->prepare('UPDATE layanan_pengajuan_hapus_denda SET status=?,aktif=NULL,admin_id=?,diputuskan_at=NOW() WHERE id=?')->execute([$setuju?'disetujui':'ditolak',$uid,$r['id']]);
    } elseif($aksi==='jual_jaminan') {
        layananAdmin();
        if($p['status']==='lunas' || !$p['memerlukan_jaminan'] || strlen($catatan)<5)throw new Exception('Penjualan memerlukan pinjaman belum lunas dengan jaminan dan catatan.');
        $s=$db->prepare("SELECT id FROM jaminan_pinjaman WHERE pinjaman_id=? AND status_jaminan='dilelang' FOR UPDATE");$s->execute([$id]);if(!$s->fetchColumn())throw new Exception('Eksekusi jaminan harus diputuskan terlebih dahulu.');
        $s=$db->prepare('SELECT id FROM layanan_lelang WHERE pinjaman_id=?');$s->execute([$id]);if($s->fetchColumn())throw new Exception('Penjualan sudah dicatat.');
        $harga=layananNominal($_POST['jumlah']??'');$metode=$_POST['metode']??'';
        if(!in_array($metode,['cash','transfer'],true))throw new Exception('Metode tidak valid.');
        $foto=validasiFotoUpload('bukti','Bukti penjualan jaminan',5*1024*1024,true);
        $sisa=layananSisa($db,$id);$kredit=min($harga,$sisa['total']);$lebih=round($harga-$kredit,2);
        $path=layananSimpanFoto($foto,'pembayaran');$files[]=$path;
        $tid=layananCatatKas($db,$p,$harga,'Hasil penjualan jaminan - '.$p['kode_pinjaman']);
        $db->prepare('INSERT INTO layanan_lelang (pinjaman_id,user_id,harga_jual,kredit,kelebihan,metode,bukti,transaksi_id,catatan) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$id,$uid,$harga,$kredit,$lebih,$metode,$path,$tid,$catatan]);
        $s=$db->prepare("SELECT a.*,COALESCE((SELECT SUM(jumlah) FROM layanan_bayar_denda WHERE angsuran_id=a.id),0) denda_paid FROM angsuran a WHERE a.pinjaman_id=? AND a.status<>'lunas' ORDER BY a.angsuran_ke FOR UPDATE");$s->execute([$id]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $a){
            $due=round($a['jumlah_angsuran']+max(0,$a['denda']-$a['denda_paid']),2);
            if(layananKreditLelang($db,$id)+0.001<$due)break;
            layananPakaiKredit($db,$id,$a['id'],$due);
            $db->prepare("UPDATE angsuran SET status='lunas',tanggal_bayar=CURDATE() WHERE id=?")->execute([$a['id']]);
            $db->prepare('UPDATE pinjaman SET sisa_pokok=GREATEST(0,sisa_pokok-?) WHERE id=?')->execute([$a['pokok'],$id]);
        }
        tandaiLunasBilaSelesai($db,$id);
        $catatan='Harga jual '.$harga.'; kelebihan milik nasabah '.$lebih.'; '.$catatan;
    } elseif($aksi==='kembalikan_lebih') {
        layananAdmin();
        $s=$db->prepare('SELECT * FROM layanan_lelang WHERE pinjaman_id=? FOR UPDATE');$s->execute([$id]);$l=$s->fetch(PDO::FETCH_ASSOC);
        if(!$l || $l['kelebihan']<=$l['dikembalikan'])throw new Exception('Tidak ada kelebihan hasil penjualan untuk dikembalikan.');
        $jumlah=round($l['kelebihan']-$l['dikembalikan'],2);
        $foto=validasiFotoUpload('bukti','Bukti pengembalian kelebihan',5*1024*1024,true);$path=layananSimpanFoto($foto,'pembayaran');$files[]=$path;
        $tid=layananCatatKeluar($db,$p,$jumlah,'Pengembalian kelebihan penjualan - '.$p['kode_pinjaman']);
        $db->prepare('UPDATE layanan_lelang SET dikembalikan=kelebihan WHERE id=?')->execute([$l['id']]);
        $db->prepare("UPDATE simpanan s JOIN jenis_simpanan j ON j.id=s.jenis_simpanan_id SET s.saldo=GREATEST(0,s.saldo-?) WHERE s.anggota_id=? AND j.kode_jenis='ANG'")->execute([$jumlah,$p['anggota_id']]);
        $db->prepare("INSERT INTO layanan_pembayaran (pinjaman_id,user_id,jenis,jumlah,metode,bukti) VALUES (?,?,'kembali_lelang',?,?,?)")->execute([$id,$uid,$jumlah,$l['metode'],$path]);
        $catatan='Kelebihan dikembalikan Rp '.$jumlah.'; transaksi '.$tid;
    } elseif($aksi==='bayar_bonus') {
        layananAdmin();
        if($p['status']!=='lunas' || $p['memerlukan_jaminan'])throw new Exception('Bonus hanya untuk pinjaman lunas tanpa jaminan.');
        $persen=(float)$db->query('SELECT bonus_persen FROM layanan_pengaturan WHERE id=1')->fetchColumn();
        $bunga=layananBungaDiterima($db,$id);$nominal=round($bunga*$persen/100,2);
        if($persen<=0 || $nominal<=0)throw new Exception('Atur persentase bonus terlebih dahulu; bunga yang diterima harus positif.');
        $s=$db->prepare('SELECT id FROM layanan_bonus WHERE pinjaman_id=?');$s->execute([$id]);if($s->fetchColumn())throw new Exception('Bonus pinjaman ini sudah dibagikan.');
        $jumlah=layananNominal($_POST['jumlah']??'');if(abs($jumlah-$nominal)>0.001)throw new Exception('Nominal bonus harus sesuai perhitungan terbaru.');
        $metode=$_POST['metode']??'';if(!in_array($metode,['cash','transfer'],true))throw new Exception('Metode tidak valid.');
        $foto=validasiFotoUpload('bukti','Bukti pembagian bonus',5*1024*1024,true);$path=layananSimpanFoto($foto,'pembayaran');$files[]=$path;
        $tid=layananCatatKeluar($db,$p,$jumlah,'Bonus loyalitas '.date('Y').' - '.$p['kode_pinjaman']);
        $db->prepare('INSERT INTO layanan_bonus (pinjaman_id,anggota_id,tahun,bunga_dasar,persen,jumlah,metode,bukti,user_id,transaksi_id) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$p['anggota_id'],date('Y'),$bunga,$persen,$jumlah,$metode,$path,$uid,$tid]);
        $catatan='Bonus '.$persen.'% x bunga '.$bunga.' = '.$jumlah;
    } elseif($aksi==='repeat_order') {
        layananAdmin();if($p['status']!=='lunas' || strlen($catatan)<5)throw new Exception('Isi catatan tindak lanjut nasabah lunas.');
        $db->prepare('INSERT INTO layanan_repeat_order (pinjaman_id,user_id,catatan) VALUES (?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),catatan=VALUES(catatan),created_at=NOW()')->execute([$id,$uid,$catatan]);
    } else throw new Exception('Tindakan tidak dikenal.');
    layananAudit($db,$id,$aksi,$catatan);
}

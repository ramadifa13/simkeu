<?php
require_once __DIR__.'/layanan_helper.php';
function shuKategori(){return ['simpanan'=>'Jasa simpanan','pinjaman'=>'Jasa pinjaman','transaksi'=>'Jasa transaksi Waserda','cadangan'=>'Dana cadangan','pengurus'=>'Pengurus','pengawas'=>'Pengawas','sosial'=>'Dana sosial','pendidikan'=>'Pendidikan'];}
function shuSiap($db){return kolomAda($db,'shu_periode','kebijakan_json') && kolomAda($db,'shu_anggota','pembayaran_id') && kolomAda($db,'shu_pembayaran','nonce') && kolomAda($db,'shu_audit','aksi');}
function shuRupiah($value,$zero=false){
    if(!is_scalar($value) || !preg_match('/^\d{1,13}$/D',(string)$value) || (int)$value>1000000000000 || (!$zero && (int)$value===0))throw new Exception('Nominal harus dalam rupiah bulat, positif, maksimal Rp 1 triliun.');
    return (int)$value;
}
function shuPersen($value){
    if(!is_scalar($value) || !preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D',(string)$value))throw new Exception('Persentase tidak valid. Gunakan angka 0–100 dengan maksimal dua desimal.');
    $v=(int)round((float)$value*100);if($v>10000)throw new Exception('Persentase harus antara 0 dan 100.');return $v;
}
/** Largest-remainder apportionment, deterministic on input key, exact whole-rupiah total. */
function shuBagi($pool,$weights){
    $result=array_fill_keys(array_keys($weights),0);$sum=array_sum($weights);if(!$pool)return $result;
    if($sum<=0)throw new Exception('Alokasi positif tidak memiliki kontribusi. Ubah kebijakan atau periksa transaksi sumber.');
    $fractions=[];$used=0;
    foreach($weights as $key=>$weight){if($weight<0)throw new Exception('Kontribusi negatif tidak dapat digunakan.');$raw=$pool*($weight/$sum);$result[$key]=(int)floor($raw);$used+=$result[$key];$fractions[$key]=$raw-$result[$key];}
    arsort($fractions,SORT_NUMERIC);$left=$pool-$used;
    if($left<0 || $left>count($weights))throw new Exception('Pembulatan alokasi tidak konsisten.');
    foreach($fractions as $key=>$unused){if($left--<=0)break;$result[$key]++;}return $result;
}
function shuAudit($db,$id,$action,$detail){$db->prepare('INSERT INTO shu_audit(periode_id,user_id,aksi,detail) VALUES (?,?,?,?)')->execute([$id,$_SESSION['user_id'],$action,json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);}
function shuAdmin(){if(!isAdmin())throw new Exception('Tindakan SHU hanya untuk Admin.');}
function shuPeriodeLock($db,$id){$s=$db->prepare('SELECT * FROM shu_periode WHERE id=? FOR UPDATE');$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new Exception('Periode SHU tidak ditemukan.');return $r;}
function shuKebijakan($post){
    $bp=[];foreach(shuKategori() as $key=>$label)$bp[$key]=shuPersen($post['persen_'.$key] ?? '');
    if(array_sum($bp)!==10000)throw new Exception('Total persentase alokasi wajib tepat 100%.');
    $basis=$post['basis_simpanan'] ?? '';if(!in_array($basis,['rata_harian','saldo_akhir'],true))throw new Exception('Pilih dasar jasa simpanan.');
    return ['versi'=>1,'persen_bp'=>$bp,'basis_simpanan'=>$basis,'basis_pinjaman'=>'bunga_dibayar_setelah_diskon','basis_transaksi'=>'belanja_waserda','anggota'=>'terdaftar_sampai_akhir_periode'];
}
/** Time-weighted end-of-day balance, deposits before period retained. Transactions are cents. */
function shuSaldoDasar($events,$start,$end,$basis){
    $start=new DateTimeImmutable($start);$end=new DateTimeImmutable($end);$after=$end->modify('+1 day');$balance=0;$weighted=0;$cursor=$start;
    foreach($events as $date=>$cents){$day=new DateTimeImmutable($date);if($day>$end)break;if($day<$start){$balance+=$cents;continue;}
        $weighted+=max(0,$balance)*(int)$cursor->diff($day)->format('%a');$balance+=$cents;$cursor=$day;
    }
    $weighted+=max(0,$balance)*(int)$cursor->diff($after)->format('%a');
    if($basis==='saldo_akhir')return max(0,$balance)/100;
    return round($weighted/(int)$start->diff($after)->format('%a')/100,2);
}
function shuKontribusi($db,$period){
    $policy=json_decode($period['kebijakan_json'],true,512,JSON_THROW_ON_ERROR);$start=$period['tanggal_mulai'];$end=$period['tanggal_selesai'];
    $s=$db->prepare('SELECT id,nama_lengkap,kode_anggota FROM anggota WHERE tanggal_daftar<=? ORDER BY id');$s->execute([$end]);$members=$s->fetchAll(PDO::FETCH_ASSOC);$rows=[];
    // Deliberately exclude loan/bonus/SHU accounting entries from savings contributions.
    $events=$db->prepare("SELECT DATE(t.tanggal_transaksi) hari,SUM(CASE WHEN t.jenis_transaksi='setoran' THEN t.jumlah ELSE -t.jumlah END) jumlah FROM transaksi t JOIN jenis_simpanan j ON j.id=t.jenis_simpanan_id WHERE t.anggota_id=? AND t.tanggal_transaksi<DATE_ADD(?,INTERVAL 1 DAY) AND j.kode_jenis NOT IN ('ANG','BONUS','SHU') GROUP BY DATE(t.tanggal_transaksi) ORDER BY hari");
    $interest=$db->prepare("SELECT COALESCE(SUM(a.bunga),0) FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id WHERE p.anggota_id=? AND a.status='lunas' AND a.tanggal_bayar BETWEEN ? AND ?");
    $discount=$db->prepare("SELECT COALESCE(SUM(b.bunga_diskon),0) FROM layanan_pembayaran b JOIN pinjaman p ON p.id=b.pinjaman_id WHERE p.anggota_id=? AND b.dibatalkan_at IS NULL AND b.created_at>=? AND b.created_at<DATE_ADD(?,INTERVAL 1 DAY)");
    $shop=$db->prepare('SELECT COALESCE(SUM(total_bayar),0) FROM waserda_transaksi WHERE anggota_id=? AND created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY)');
    foreach($members as $member){$id=$member['id'];$events->execute([$id,$end]);$days=[];foreach($events->fetchAll(PDO::FETCH_ASSOC) as $e)$days[$e['hari']]=(int)round($e['jumlah']*100);
        $interest->execute([$id,$start,$end]);$paid=(float)$interest->fetchColumn();$discount->execute([$id,$start,$end]);$paid=max(0,round($paid-(float)$discount->fetchColumn(),2));$shop->execute([$id,$start,$end]);
        $rows[$id]=['anggota_id'=>(int)$id,'nama_snapshot'=>$member['nama_lengkap'],'kode_snapshot'=>$member['kode_anggota'],'total_simpanan'=>shuSaldoDasar($days,$start,$end,$policy['basis_simpanan']),'total_pinjaman'=>$paid,'total_transaksi'=>max(0,(float)$shop->fetchColumn())];
    }return $rows;
}
function shuSimulasi($db,$period){
    $rows=shuKontribusi($db,$period);$policy=json_decode($period['kebijakan_json'],true,512,JSON_THROW_ON_ERROR);$alloc=shuBagi((int)$period['total_shu'],$policy['persen_bp']);
    foreach(['simpanan','pinjaman','transaksi'] as $key){$weights=[];foreach($rows as $id=>$r)$weights[$id]=(int)round($r['total_'.$key]*100);$shares=shuBagi($alloc[$key],$weights);foreach($rows as $id=>&$r)$r['jasa_'.$key]=$shares[$id];unset($r);}
    foreach($rows as &$r)$r['total_shu']=$r['jasa_simpanan']+$r['jasa_pinjaman']+$r['jasa_transaksi'];unset($r);
    return ['rows'=>$rows,'alokasi'=>$alloc,'hash'=>hash('sha256',json_encode([$period['total_shu'],$policy,$rows],JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR))];
}
function shuProses($db,$action,$post,&$files){
    shuAdmin();if(!$db->inTransaction())throw new Exception('Transaksi database diperlukan.');
    if($action==='buat'){
        $year=filter_var($post['tahun'] ?? '',FILTER_VALIDATE_INT);if(!$year || $year<2000 || $year>(int)date('Y'))throw new Exception('Tahun harus antara 2000 dan tahun berjalan.');
        $pool=shuRupiah($post['total_shu'] ?? '');$policy=shuKebijakan($post);$name='SHU Tahun '.$year;
        $db->prepare("INSERT INTO shu_periode(tahun,nama_periode,tanggal_mulai,tanggal_selesai,total_shu,kebijakan_json,dibuat_oleh) VALUES (?,?,?,?,?,?,?)")->execute([$year,$name,$year.'-01-01',$year.'-12-31',$pool,json_encode($policy,JSON_THROW_ON_ERROR),$_SESSION['user_id']]);$id=(int)$db->lastInsertId();shuAudit($db,$id,'buat',['pool'=>$pool,'kebijakan'=>$policy]);return $id;
    }
    $id=(int)($post['periode_id'] ?? 0);$p=shuPeriodeLock($db,$id);
    if(in_array($action,['ubah','hitung','sahkan'],true) && $p['status']==='final')throw new Exception('Periode final terkunci. Riwayat pembagian tidak dapat diubah.');
    if($action==='ubah'){
        $policy=shuKebijakan($post);$pool=shuRupiah($post['total_shu'] ?? '');
        $db->prepare('DELETE FROM shu_anggota WHERE shu_periode_id=?')->execute([$id]);
        $db->prepare("UPDATE shu_periode SET total_shu=?,kebijakan_json=?,status='draft',snapshot_hash=NULL,alokasi_json=NULL,dihitung_at=NULL WHERE id=?")->execute([$pool,json_encode($policy,JSON_THROW_ON_ERROR),$id]);shuAudit($db,$id,'ubah',['pool'=>$pool,'kebijakan'=>$policy]);
    }elseif($action==='hitung'){
        $sim=shuSimulasi($db,$p);$db->prepare('DELETE FROM shu_anggota WHERE shu_periode_id=?')->execute([$id]);
        $insert=$db->prepare('INSERT INTO shu_anggota(shu_periode_id,anggota_id,nama_snapshot,kode_snapshot,total_simpanan,total_pinjaman,total_transaksi,jasa_simpanan,jasa_pinjaman,jasa_transaksi,total_shu) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach($sim['rows'] as $r)$insert->execute([$id,$r['anggota_id'],$r['nama_snapshot'],$r['kode_snapshot'],$r['total_simpanan'],$r['total_pinjaman'],$r['total_transaksi'],$r['jasa_simpanan'],$r['jasa_pinjaman'],$r['jasa_transaksi'],$r['total_shu']]);
        $db->prepare("UPDATE shu_periode SET status='dihitung',snapshot_hash=?,alokasi_json=?,dihitung_at=NOW() WHERE id=?")->execute([$sim['hash'],json_encode($sim['alokasi'],JSON_THROW_ON_ERROR),$id]);shuAudit($db,$id,'hitung',['hash'=>$sim['hash'],'alokasi'=>$sim['alokasi']]);
    }elseif($action==='sahkan'){
        if($p['status']!=='dihitung')throw new Exception('Hitung simulasi sebelum pengesahan.');
        if($p['tanggal_selesai']>=date('Y-m-d'))throw new Exception('Tahun buku belum berakhir. Pengesahan hanya setelah periode selesai.');
        $ref=trim($post['referensi_rat'] ?? '');if(strlen($ref)<5 || strlen($ref)>200 || empty($post['konfirmasi_rat']))throw new Exception('Referensi keputusan RAT dan konfirmasi pemeriksaan wajib diisi.');
        $sim=shuSimulasi($db,$p);if(!hash_equals($p['snapshot_hash'],$sim['hash']))throw new Exception('Kontribusi sumber berubah. Hitung ulang simulasi dan tinjau sebelum mengesahkan.');
        $db->prepare("UPDATE shu_periode SET status='final',referensi_rat=?,disahkan_oleh=?,disahkan_at=NOW() WHERE id=?")->execute([$ref,$_SESSION['user_id'],$id]);shuAudit($db,$id,'sahkan',['referensi_rat'=>$ref,'hash'=>$sim['hash']]);
    }elseif($action==='bayar'){
        if($p['status']!=='final')throw new Exception('Pembayaran hanya untuk periode final yang telah disahkan.');
        $s=$db->prepare('SELECT * FROM shu_anggota WHERE id=? AND shu_periode_id=? FOR UPDATE');$s->execute([(int)($post['shu_anggota_id'] ?? 0),$id]);$r=$s->fetch(PDO::FETCH_ASSOC);
        if(!$r || $r['status_bayar']!=='belum' || $r['total_shu']<=0)throw new Exception('Penerima tidak ditemukan, sudah dibayar, atau hak SHU nol.');
        $method=$post['metode'] ?? '';if(!in_array($method,['cash','transfer'],true))throw new Exception('Pilih metode Cash atau Transfer.');
        $ref=trim($post['referensi'] ?? '');if(strlen($ref)<3 || strlen($ref)>100)throw new Exception('Referensi pembayaran atau penerima wajib diisi (3–100 karakter).');
        $nonce=$post['payment_nonce'] ?? '';if(!is_string($nonce)||!preg_match('/^[a-f0-9]{32}$/D',$nonce))throw new Exception('Permintaan pembayaran tidak valid. Muat ulang halaman.');
        $foto=validasiFotoUpload('bukti','Bukti pembayaran SHU',5*1024*1024,true);$path=layananSimpanFoto($foto,'pembayaran');$files[]=$path;
        $tid=shuCatatKas($db,$r,(int)$r['total_shu'],'keluar','Pembayaran SHU '.$p['tahun']);
        $db->prepare('INSERT INTO shu_pembayaran(shu_anggota_id,jumlah,metode,bukti,referensi,nonce,user_id,transaksi_id) VALUES (?,?,?,?,?,?,?,?)')->execute([$r['id'],$r['total_shu'],$method,$path,$ref,$nonce,$_SESSION['user_id'],$tid]);$pay=(int)$db->lastInsertId();
        $db->prepare("UPDATE shu_anggota SET status_bayar='sudah',tanggal_bayar=NOW(),pembayaran_id=? WHERE id=?")->execute([$pay,$r['id']]);shuAudit($db,$id,'bayar',['pembayaran'=>$pay,'anggota'=>$r['anggota_id'],'jumlah'=>$r['total_shu'],'metode'=>$method,'transaksi'=>$tid]);
    }elseif($action==='balik'){
        $reason=trim($post['alasan'] ?? '');if(strlen($reason)<10 || strlen($reason)>500)throw new Exception('Alasan koreksi wajib 10–500 karakter.');
        $s=$db->prepare('SELECT b.*,a.anggota_id,a.pembayaran_id FROM shu_pembayaran b JOIN shu_anggota a ON a.id=b.shu_anggota_id WHERE b.id=? AND a.shu_periode_id=? FOR UPDATE');$s->execute([(int)($post['pembayaran_id'] ?? 0),$id]);$b=$s->fetch(PDO::FETCH_ASSOC);
        if(!$b || $b['dibatalkan_at'] || (int)$b['pembayaran_id']!==(int)$b['id'])throw new Exception('Pembayaran sudah dikoreksi atau tidak ditemukan.');
        if(empty($post['uang_kembali']))throw new Exception('Konfirmasi dana benar-benar kembali ke koperasi sebelum membalik pembayaran.');
        $tid=shuCatatKas($db,$b,(int)$b['jumlah'],'masuk','Pengembalian SHU '.$p['tahun'].' pembayaran #'.$b['id']);
        $db->prepare('UPDATE shu_pembayaran SET dibatalkan_at=NOW(),dibatalkan_oleh=?,alasan_batal=?,transaksi_balik_id=? WHERE id=?')->execute([$_SESSION['user_id'],$reason,$tid,$b['id']]);
        $db->prepare("UPDATE shu_anggota SET status_bayar='belum',tanggal_bayar=NULL,pembayaran_id=NULL WHERE id=?")->execute([$b['shu_anggota_id']]);shuAudit($db,$id,'balik',['pembayaran'=>$b['id'],'alasan'=>$reason,'transaksi'=>$tid]);
    }else throw new Exception('Tindakan SHU tidak dikenal.');return $id;
}
function shuCatatKas($db,$member,$amount,$direction,$description){
    $db->query('SELECT id FROM koperasi WHERE id=1 FOR UPDATE')->fetchColumn();
    $balance=(float)$db->query('SELECT saldo_sesudah FROM kas ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
    if($direction==='keluar' && $amount>$balance)throw new Exception('Saldo kas tidak mencukupi. Pembayaran SHU belum dicatat.');
    $kind=$db->query("SELECT id FROM jenis_simpanan WHERE kode_jenis='SHU'")->fetchColumn();
    if(!$kind){$db->exec("INSERT INTO jenis_simpanan(kode_jenis,nama_jenis,deskripsi,status) VALUES ('SHU','Pembagian SHU','Jurnal pembagian SHU; bukan rekening simpanan anggota','aktif')");$kind=$db->lastInsertId();}
    $db->prepare('INSERT INTO transaksi(kode_transaksi,anggota_id,jenis_simpanan_id,jenis_transaksi,jumlah,saldo_sebelum,saldo_sesudah,keterangan,user_id) VALUES (?,?,?,?,?,0,0,?,?)')->execute(['SHU-'.bin2hex(random_bytes(8)),$member['anggota_id'],$kind,$direction==='keluar'?'penarikan':'setoran',$amount,$description,$_SESSION['user_id']]);$tid=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO kas(transaksi_id,jenis,jumlah,saldo_sebelum,saldo_sesudah,keterangan) VALUES (?,?,?,?,?,?)')->execute([$tid,$direction,$amount,$balance,$balance+($direction==='masuk'?$amount:-$amount),$description]);return $tid;
}

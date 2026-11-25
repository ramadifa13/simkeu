<?php
function dataLaporanKasPinjaman(PDO $db,string $awal,string $akhir,string $granularitas):array
{
    if(!in_array($granularitas,['hari','bulan','tahun'],true))throw new InvalidArgumentException('Granularitas laporan tidak valid.');
    $from=DateTimeImmutable::createFromFormat('!Y-m-d',$awal);$to=DateTimeImmutable::createFromFormat('!Y-m-d',$akhir);
    if(!$from||!$to||$from->format('Y-m-d')!==$awal||$to->format('Y-m-d')!==$akhir||$from>$to)throw new InvalidArgumentException('Rentang tanggal tidak valid.');
    if($from->diff($to)->days>1825)throw new InvalidArgumentException('Rentang laporan maksimal lima tahun.');
    $bucket=function(string $date)use($granularitas):string{return $granularitas==='hari'?substr($date,0,10):($granularitas==='bulan'?substr($date,0,7):substr($date,0,4));};
    $series=[];$cursor=$granularitas==='hari'?$from:($granularitas==='bulan'?$from->modify('first day of this month'):$from->modify('first day of January'));
    $stop=$granularitas==='hari'?$to:($granularitas==='bulan'?$to->modify('first day of this month'):$to->modify('first day of January'));
    while($cursor<=$stop){$key=$bucket($cursor->format('Y-m-d'));$series[$key]=['label'=>$key,'pokok'=>0.0,'bunga'=>0.0,'denda'=>0.0,'collection_rescheduling'=>0.0,'pencairan'=>0.0,'denda_dibebaskan'=>0.0,'pelunasan_lain'=>0.0];$cursor=$granularitas==='hari'?$cursor->modify('+1 day'):($granularitas==='bulan'?$cursor->modify('+1 month'):$cursor->modify('+1 year'));}
    $add=function(string $date,string $field,float $value)use(&$series,$bucket):void{$k=$bucket($date);if(isset($series[$k]))$series[$k][$field]+=$value;};
    $q=$db->prepare("SELECT DATE(COALESCE(b.diverifikasi_pada,b.dibuat_pada)) tgl,pa.jumlah,pa.pokok,pa.bunga,p.is_rescheduling
        FROM pembayaran_angsuran b JOIN pembayaran_angsuran_alokasi pa ON pa.pembayaran_id=b.id JOIN pinjaman p ON p.id=b.pinjaman_id
        WHERE b.status='diterima' AND DATE(COALESCE(b.diverifikasi_pada,b.dibuat_pada)) BETWEEN ? AND ?");$q->execute([$awal,$akhir]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$add($r['tgl'],'pokok',(float)$r['pokok']);$add($r['tgl'],'bunga',(float)$r['bunga']);if(!empty($r['is_rescheduling']))$add($r['tgl'],'collection_rescheduling',(float)$r['jumlah']);}
    // Pembayaran legacy sebelum pencatatan alokasi: pokok/bunga dialokasikan proporsional sesuai jadwal cicilan.
    $q=$db->prepare("SELECT DATE(l.created_at) tgl,l.jenis,l.jumlah,p.is_rescheduling,a.jumlah_angsuran,a.pokok,a.bunga
        FROM layanan_pembayaran l JOIN pinjaman p ON p.id=l.pinjaman_id LEFT JOIN angsuran a ON a.id=l.angsuran_id
        WHERE l.dibatalkan_at IS NULL AND l.pembayaran_angsuran_id IS NULL AND l.jenis IN ('angsuran','pelunasan') AND DATE(l.created_at) BETWEEN ? AND ?");$q->execute([$awal,$akhir]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$amount=(float)$r['jumlah'];if($r['jenis']==='pelunasan'){$add($r['tgl'],'pelunasan_lain',$amount);}elseif((float)$r['jumlah_angsuran']>0){$scheduled=min($amount,(float)$r['jumlah_angsuran']);$ratio=(float)$r['pokok']/(float)$r['jumlah_angsuran'];$add($r['tgl'],'pokok',$scheduled*$ratio);$add($r['tgl'],'bunga',$scheduled*(1-$ratio));$add($r['tgl'],'denda',max(0,$amount-$scheduled));}if(!empty($r['is_rescheduling']))$add($r['tgl'],'collection_rescheduling',$amount);}
    $q=$db->prepare('SELECT DATE(created_at) tgl,jumlah FROM layanan_bayar_denda WHERE created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY)');$q->execute([$awal,$akhir]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$add($r['tgl'],'denda',(float)$r['jumlah']);
    $q=$db->prepare("SELECT DATE(tanggal_pencairan) tgl,jumlah_pinjaman FROM pinjaman WHERE COALESCE(is_rescheduling,0)=0 AND DATE(tanggal_pencairan) BETWEEN ? AND ?");$q->execute([$awal,$akhir]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$add($r['tgl'],'pencairan',(float)$r['jumlah_pinjaman']);
    $q=$db->prepare('SELECT snapshot_json,DATE(dibuat_pada) tgl FROM audit_rescheduling WHERE DATE(dibuat_pada) BETWEEN ? AND ?');$q->execute([$awal,$akhir]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$snapshot=json_decode((string)$r['snapshot_json'],true);if(is_array($snapshot))$add($r['tgl'],'denda_dibebaskan',(float)($snapshot['denda_dibebaskan']??0));}
    $totals=array_fill_keys(['pokok','bunga','denda','collection_rescheduling','pencairan','denda_dibebaskan','pelunasan_lain'],0.0);foreach($series as $row)foreach($totals as $key=>$_)$totals[$key]+=$row[$key];
    return ['series'=>array_values($series),'totals'=>$totals];
}

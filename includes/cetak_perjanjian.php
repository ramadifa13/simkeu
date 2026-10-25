<?php
require_once __DIR__ . '/../tcpdf/tcpdf.php';

$stmt = $db->prepare("SELECT pr.* FROM perjanjian_pinjaman pr WHERE pr.pinjaman_id = ?");
$stmt->execute([(int)$id]);
$surat = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$surat) die('Surat perjanjian belum tersedia untuk pinjaman ini.');
$data = json_decode($surat['snapshot_json'], true);
if (!is_array($data)) die('Arsip perjanjian tidak valid.');
$e = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$rp = function($v){ return 'Rp ' . number_format((float)$v, 0, ',', '.'); };

$pdf = new TCPDF('P','mm','A4',true,'UTF-8',false);
$pdf->SetCreator('SIMKEU'); $pdf->SetAuthor($data['koperasi']['nama'] ?? 'Koperasi');
$pdf->SetTitle('Surat Perjanjian ' . $surat['nomor_perjanjian']);
$pdf->SetMargins(18,16,18); $pdf->SetAutoPageBreak(true,16); $pdf->setPrintHeader(false);
$pdf->SetFooterMargin(8); $pdf->AddPage(); $pdf->SetFont('dejavusans','',9.5);
$p=$data['pinjaman']; $a=$data['anggota']; $k=$data['koperasi']; $pp=$data['pihak_pertama'] ?? ['nama'=>$k['nama'],'nik'=>'','alamat'=>$k['alamat'],'telepon'=>$k['telepon']];
$html='<div style="text-align:center"><b style="font-size:15px">SURAT PERJANJIAN PINJAMAN</b><br>'.$e($k['nama'] ?? '').'<br><small>No. '.$e($surat['nomor_perjanjian']).' · Versi '.ucfirst($e($surat['versi'])).'</small></div><hr>';
$html.='<h4>PIHAK PERTAMA — PEMBERI PINJAMAN</h4><table cellpadding="2"><tr><td width="25%">Nama / NIK</td><td>: '.$e($pp['nama']).' / '.$e($pp['nik']).'</td></tr><tr><td>Alamat / No. HP</td><td>: '.$e($pp['alamat']).' / '.$e($pp['telepon']).'</td></tr></table>';
$lahir=trim(($a['tempat_lahir'] ?? '').(!empty($a['tanggal_lahir'])?', '.date('d/m/Y',strtotime($a['tanggal_lahir'])):''));
$html.='<h4>PIHAK KEDUA — PENERIMA PINJAMAN</h4><table cellpadding="2"><tr><td width="25%">Nama / Kode</td><td>: <b>'.$e($a['nama']).'</b> / '.$e($a['kode']).'</td></tr><tr><td>NIK / Tempat, Tgl Lahir</td><td>: '.$e($a['nik']).' / '.$e($lahir).'</td></tr><tr><td>Alamat / No. HP</td><td>: '.$e($a['alamat']).' / '.$e($a['telepon']).'</td></tr></table>';
$html.='<h4>RINCIAN PINJAMAN</h4><table cellpadding="2"><tr><td width="30%">Jenis / Nilai Pinjaman</td><td width="70%">: '.$e($p['jenis']).' / <b>'.$rp($p['jumlah']).'</b></td></tr><tr><td>Tujuan Pinjaman</td><td>: '.$e($p['tujuan'] ?? '-').'</td></tr><tr><td>Tenor / Frekuensi</td><td>: '.$e($p['tenor']).' bulan / '.$e($p['frekuensi']).' ('.$e($p['jumlah_cicilan'] ?? '').' kali)</td></tr><tr><td>Bunga / Total / Angsuran</td><td>: '.$e($p['bunga']).'% / '.$rp($p['total']).' / '.$rp($p['angsuran']).'</td></tr><tr><td>Pencairan / Jatuh Tempo</td><td>: '.$e(date('d/m/Y',strtotime($p['tanggal_pencairan']))).' / <b>'.$e(date('d/m/Y',strtotime($p['jatuh_tempo']))).'</b></td></tr></table>';
if (!empty($data['jaminan']['ada'])) {
    $html.='<h4>PASAL 3 - JAMINAN</h4><p>Sebagai jaminan atas hutang tersebut, PIHAK KEDUA menyerahkan jaminan berupa:<br><b>Jenis:</b> '.$e($data['jaminan']['jenis']).'<br>'.nl2br($e($data['jaminan']['detail'])).'<br>Jaminan dikembalikan sepenuhnya setelah seluruh hutang dan bunga lunas 100%.</p>';
    $html.='<h4>PASAL 4 - WANPRESTASI</h4><p>Apabila PIHAK KEDUA tidak melunasi pada tanggal jatuh tempo, PIHAK PERTAMA berhak menjual atau mengalihkan jaminan untuk pelunasan hutang. Kelebihan hasil penjualan dikembalikan kepada PIHAK KEDUA; kekurangannya tetap menjadi kewajiban PIHAK KEDUA.</p>';
    $html.='<h4>PASAL 5 - PENYELESAIAN SENGKETA</h4><p>Perselisihan diselesaikan terlebih dahulu secara musyawarah mufakat. Jika tidak tercapai, diselesaikan melalui jalur hukum yang berlaku.</p>';
} else {
    $html.='<h4>Ketentuan Perjanjian</h4><div style="line-height:1.5">'.nl2br($e($data['isi_perjanjian'])).'</div>';
}
$html.='<p>Dengan membubuhkan tanda tangan di bawah ini, para pihak menyatakan data telah dibaca dan disetujui.</p>';
$tanggalSurat=!empty($p['tanggal_pencairan']) ? date('d/m/Y',strtotime($p['tanggal_pencairan'])) : date('d/m/Y');
$html.='<p style="text-align:right">Dibuat di Mojokerto<br>Pada tanggal: '.$e($tanggalSurat).'</p>';
$pdf->writeHTML($html,true,false,true,false,'');

$ttds=['ttd_nasabah'=>'Nasabah','ttd_teller'=>'Teller','ttd_admin'=>$data['penandatangan']['jabatan_admin'],'ttd_saksi_1'=>'Saksi 1','ttd_saksi_2'=>'Saksi 2'];
$names=['ttd_nasabah'=>$data['penandatangan']['nasabah'],'ttd_teller'=>$data['penandatangan']['teller'],'ttd_admin'=>$data['penandatangan']['admin'],'ttd_saksi_1'=>$surat['nama_saksi_1'],'ttd_saksi_2'=>$surat['nama_saksi_2']];
$x=[18,78,138,48,108]; $y=$pdf->GetY()+7; if($y>170){$pdf->AddPage();$y=25;}
$temps=[];
foreach(array_keys($ttds) as $i=>$field){ if($i===3){$y+=50;} $tx=$x[$i]; $pdf->SetXY($tx,$y);$pdf->Cell(55,5,$ttds[$field],0,0,'C');if($field==='ttd_nasabah'){$pdf->SetFont('dejavusans','',6);$pdf->SetXY($tx,$y+22);$pdf->Cell(55,3,'Meterai Rp10.000',0,0,'C');$pdf->SetFont('dejavusans','',9.5);}$tmp=dataUrlKeFileSementara($surat[$field]);if($tmp){$temps[]=$tmp;$pdf->Image($tmp,$tx+10,$y+8,35,17,'PNG');}$pdf->SetXY($tx,$y+50);$pdf->Cell(55,5,$names[$field],0,0,'C'); }
$pdf->SetY($y+63); $pdf->SetFont('dejavusans','',7); $pdf->MultiCell(0,4,'Arsip ditandatangani: '.date('d/m/Y H:i',strtotime($surat['ditandatangani_at'])).' WIB | SHA-256: '.$surat['hash_dokumen'],0,'L');

if (!empty($data['jadwal_angsuran'])) {
    $pdf->AddPage(); $pdf->SetFont('dejavusans','B',12); $pdf->Cell(0,8,'LAMPIRAN JADWAL ANGSURAN',0,1,'C');
    $pdf->SetFont('dejavusans','',8);
    $t='<table border="1" cellpadding="4"><tr style="font-weight:bold;background-color:#eeeeee"><th width="7%">Ke</th><th width="19%">Jatuh Tempo</th><th width="18%">Pokok</th><th width="17%">Bunga</th><th width="20%">Total Bayar</th><th width="19%">Sisa</th></tr>';
    $sisa=(float)$p['total'];
    foreach($data['jadwal_angsuran'] as $j){$sisa=max(0,$sisa-(float)$j['jumlah_angsuran']);$t.='<tr><td>'.$e($j['angsuran_ke']).'</td><td>'.$e(date('d/m/Y',strtotime($j['tanggal_jatuh_tempo']))).'</td><td>'.$rp($j['pokok']).'</td><td>'.$rp($j['bunga']).'</td><td>'.$rp($j['jumlah_angsuran']).'</td><td>'.($sisa>0?$rp($sisa):'LUNAS').'</td></tr>';}
    $t.='</table>'; $pdf->writeHTML($t,true,false,true,false,'');
}

$lampiran=[];
foreach(['pencairan','barang','nasabah_barang'] as $label){$path=$data['foto'][$label] ?? '';if($path)$lampiran[]=['path'=>$path,'judul'=>ucwords(str_replace('_',' ',$label))];}
$st=$db->prepare("SELECT file_path, urutan FROM lampiran_perjanjian WHERE perjanjian_id=? ORDER BY urutan");$st->execute([$surat['id']]);
foreach($st->fetchAll(PDO::FETCH_ASSOC) as $l)$lampiran[]=['path'=>$l['file_path'],'judul'=>'Lampiran Surat Halaman '.$l['urutan']];
foreach($lampiran as $l){$file=realpath(__DIR__.'/../uploads/'.$l['path']);$root=realpath(__DIR__.'/../uploads');if(!$file||strpos($file,$root.DIRECTORY_SEPARATOR)!==0)continue;$size=@getimagesize($file);if(!$size)continue;$pdf->AddPage();$pdf->SetFont('dejavusans','B',11);$pdf->Cell(0,8,$l['judul'],0,1,'C');$maxW=174;$maxH=245;$ratio=min($maxW/$size[0],$maxH/$size[1]);$w=$size[0]*$ratio;$h=$size[1]*$ratio;$pdf->Image($file,(210-$w)/2,28,$w,$h);}
foreach($temps as $tmp) @unlink($tmp);
$namaFile='perjanjian-'.preg_replace('/[^A-Za-z0-9_-]+/','-',$surat['nomor_perjanjian']).'.pdf';
$pdf->Output($namaFile,'I');
exit;

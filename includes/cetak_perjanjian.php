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
$p=$data['pinjaman']; $a=$data['anggota']; $k=$data['koperasi'];
$html='<div style="text-align:center"><b style="font-size:15px">SURAT PERJANJIAN PINJAMAN</b><br>'.$e($k['nama'] ?? '').'<br><small>No. '.$e($surat['nomor_perjanjian']).' · Versi '.ucfirst($e($surat['versi'])).'</small></div><hr>';
$html.='<table cellpadding="3"><tr><td width="30%">Nama Anggota</td><td width="70%">: <b>'.$e($a['nama']).'</b> ('.$e($a['kode']).')</td></tr><tr><td>NIK / Alamat</td><td>: '.$e($a['nik']).' / '.$e($a['alamat']).'</td></tr><tr><td>Jenis / Nilai Pinjaman</td><td>: '.$e($p['jenis']).' / <b>'.$rp($p['jumlah']).'</b></td></tr><tr><td>Tenor / Frekuensi</td><td>: '.$e($p['tenor']).' bulan / '.$e($p['frekuensi']).'</td></tr><tr><td>Total / Angsuran</td><td>: '.$rp($p['total']).' / '.$rp($p['angsuran']).'</td></tr><tr><td>Tanggal Pencairan</td><td>: '.$e(date('d/m/Y',strtotime($p['tanggal_pencairan']))).'</td></tr></table>';
if (!empty($data['jaminan']['ada'])) $html.='<p><b>Jaminan:</b> '.$e($data['jaminan']['jenis']).'<br>'.$e($data['jaminan']['detail']).'</p>';
$html.='<h4>Ketentuan Perjanjian</h4><div style="line-height:1.5">'.nl2br($e($data['isi_perjanjian'])).'</div><p>Dengan membubuhkan tanda tangan di bawah ini, para pihak menyatakan data telah dibaca dan disetujui.</p>';
$pdf->writeHTML($html,true,false,true,false,'');

$ttds=['ttd_nasabah'=>'Nasabah','ttd_teller'=>'Teller','ttd_admin'=>$data['penandatangan']['jabatan_admin'],'ttd_saksi_1'=>'Saksi 1','ttd_saksi_2'=>'Saksi 2'];
$names=['ttd_nasabah'=>$data['penandatangan']['nasabah'],'ttd_teller'=>$data['penandatangan']['teller'],'ttd_admin'=>$data['penandatangan']['admin'],'ttd_saksi_1'=>$surat['nama_saksi_1'],'ttd_saksi_2'=>$surat['nama_saksi_2']];
$x=[18,78,138,48,108]; $y=$pdf->GetY()+7; if($y>225){$pdf->AddPage();$y=25;}
$temps=[];
foreach(array_keys($ttds) as $i=>$field){ if($i===3){$y+=39;} $tx=$x[$i]; $pdf->SetXY($tx,$y);$pdf->Cell(55,5,$ttds[$field],0,0,'C');$tmp=dataUrlKeFileSementara($surat[$field]);if($tmp){$temps[]=$tmp;$pdf->Image($tmp,$tx+10,$y+5,35,20,'PNG');}$pdf->SetXY($tx,$y+27);$pdf->Cell(55,5,$names[$field],0,0,'C'); }
$pdf->SetY($y+40); $pdf->SetFont('dejavusans','',7); $pdf->MultiCell(0,4,'Arsip ditandatangani: '.date('d/m/Y H:i',strtotime($surat['ditandatangani_at'])).' WIB | SHA-256: '.$surat['hash_dokumen'],0,'L');

$lampiran=[];
foreach(['pencairan','barang','nasabah_barang'] as $label){$path=$data['foto'][$label] ?? '';if($path)$lampiran[]=['path'=>$path,'judul'=>ucwords(str_replace('_',' ',$label))];}
$st=$db->prepare("SELECT file_path, urutan FROM lampiran_perjanjian WHERE perjanjian_id=? ORDER BY urutan");$st->execute([$surat['id']]);
foreach($st->fetchAll(PDO::FETCH_ASSOC) as $l)$lampiran[]=['path'=>$l['file_path'],'judul'=>'Lampiran Surat Halaman '.$l['urutan']];
foreach($lampiran as $l){$file=realpath(__DIR__.'/../uploads/'.$l['path']);$root=realpath(__DIR__.'/../uploads');if(!$file||strpos($file,$root.DIRECTORY_SEPARATOR)!==0)continue;$size=@getimagesize($file);if(!$size)continue;$pdf->AddPage();$pdf->SetFont('dejavusans','B',11);$pdf->Cell(0,8,$l['judul'],0,1,'C');$maxW=174;$maxH=245;$ratio=min($maxW/$size[0],$maxH/$size[1]);$w=$size[0]*$ratio;$h=$size[1]*$ratio;$pdf->Image($file,(210-$w)/2,28,$w,$h);}
foreach($temps as $tmp) @unlink($tmp);
$pdf->Output('perjanjian-'.$surat['nomor_perjanjian'].'.pdf','I');
exit;

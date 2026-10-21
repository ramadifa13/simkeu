<?php
require_once '../includes/auth.php';
require_once '../config/config.php';
if (!isTeller()) { http_response_code(403); exit('Akses ditolak.'); }

$id=(int)($_GET['id'] ?? 0);
$stmt=$db->prepare("SELECT pp.*,jp.nama_jenis,jp.memerlukan_jaminan,a.nama_lengkap,a.ktp,a.tempat_lahir,a.tanggal_lahir,a.alamat,a.telepon
 FROM pengajuan_pinjaman pp JOIN jenis_pinjaman jp ON jp.id=pp.jenis_pinjaman_id JOIN anggota a ON a.id=pp.anggota_id
 WHERE pp.id=? AND pp.status='disetujui'");
$stmt->execute([$id]);$p=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$p){http_response_code(404);exit('Pengajuan tidak ditemukan atau belum disetujui.');}
$k=$db->query('SELECT * FROM koperasi WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: [];
$aturan=pengaturanPerjanjian($db);$h=hitungDariPinjaman($p);$tgl=date('Y-m-d');
$akhir=hitungJatuhTempo($tgl,$h['jumlah_cicilan'],$h['frekuensi']);
$denda=dendaPerHari($db,$h['pokok'],!empty($p['memerlukan_jaminan']));
$persenDenda=$h['pokok']>0?$denda/$h['pokok']*100:0;
$e=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$rp=function($v){return 'Rp'.number_format((float)$v,0,',','.').',-';};
$nomor=str_pad($id,3,'0',STR_PAD_LEFT).'/SPHP/'.[1=>'I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'][(int)date('n')].'/'.date('Y');
$lahir=trim(($p['tempat_lahir']??'').(!empty($p['tanggal_lahir'])?', '.date('d/m/Y',strtotime($p['tanggal_lahir'])):''));
$hari=(int)($p['hari_angsuran']??0);$namaHari=[1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];
?><!doctype html><html lang="id"><head><meta charset="utf-8"><title>SPHP <?= $e($p['nama_lengkap']) ?></title><style>
@page{size:A4;margin:17mm}*{box-sizing:border-box}body{font-family:"Times New Roman",serif;color:#000;margin:0;background:#eee;font-size:12pt;line-height:1.35}.bar{position:sticky;top:0;text-align:center;padding:10px;background:#fff;border-bottom:1px solid #ccc}.bar button{padding:9px 18px}.page{width:210mm;min-height:297mm;margin:15px auto;background:#fff;padding:17mm}.center{text-align:center}.title{font-size:16pt;font-weight:bold;text-decoration:underline;margin:0}.sub{margin:2px 0 20px}.party{margin:8px 0 14px}.party table,.schedule{width:100%;border-collapse:collapse}.party td:first-child{width:34mm}.article{font-weight:bold;margin:14px 0 4px}.sign{width:100%;margin-top:28px;text-align:center}.sign td{width:50%;height:85px;vertical-align:top}.schedule th,.schedule td{border:1px solid #000;padding:4px;text-align:center}.schedule{font-size:10pt}.page-break{break-before:page}.muted{font-size:9pt}@media print{body{background:#fff}.bar{display:none}.page{width:auto;min-height:0;margin:0;padding:0}}
</style></head><body><div class="bar"><button onclick="window.print()">Cetak / Simpan PDF</button></div><main class="page">
<div class="center"><h1 class="title">SURAT PERJANJIAN HUTANG PIUTANG</h1><p class="sub">Nomor: <?= $e($nomor) ?></p></div>
<p>Yang bertanda tangan di bawah ini:</p><div class="party"><strong>PIHAK PERTAMA - PIHAK YANG MEMBERI PINJAMAN</strong><table><tr><td>Nama</td><td>: <?= $e($aturan['nama_pihak_pertama'] ?: ($k['nama']??'')) ?></td></tr><tr><td>NIK</td><td>: <?= $e($aturan['nik_pihak_pertama']??'') ?></td></tr><tr><td>Tempat/Tgl Lahir</td><td>: </td></tr><tr><td>Alamat</td><td>: <?= $e($aturan['alamat_pihak_pertama'] ?: ($k['alamat']??'')) ?></td></tr><tr><td>No. HP</td><td>: <?= $e($aturan['telepon_pihak_pertama'] ?: ($k['telepon']??'')) ?></td></tr></table></div>
<div class="party"><strong>PIHAK KEDUA - PIHAK YANG MENERIMA PINJAMAN</strong><table><tr><td>Nama</td><td>: <?= $e($p['nama_lengkap']) ?></td></tr><tr><td>NIK</td><td>: <?= $e($p['ktp']) ?></td></tr><tr><td>Tempat/Tgl Lahir</td><td>: <?= $e($lahir) ?></td></tr><tr><td>Alamat</td><td>: <?= $e($p['alamat']) ?></td></tr><tr><td>No. HP</td><td>: <?= $e($p['telepon']) ?></td></tr></table></div>
<p>Para pihak dengan ini sepakat mengikatkan diri dalam Perjanjian Hutang Piutang dengan syarat-syarat sebagai berikut:</p>
<div class="article">PASAL 1: JUMLAH PINJAMAN</div><div>1. PIHAK PERTAMA setuju memberikan pinjaman kepada PIHAK KEDUA sebesar <?= $rp($h['pokok']) ?> (<?= $e(terbilangRupiah($h['pokok'])) ?>).</div><div>2. PIHAK KEDUA menerima pinjaman tersebut penuh tanpa potongan atau 100%.</div>
<div class="article">PASAL 2: TUJUAN PINJAMAN</div><div>Pinjaman tersebut digunakan oleh PIHAK KEDUA untuk keperluan: <?= $e($p['tujuan_pinjaman'] ?: '-') ?></div>
<div class="article">PASAL 3: BUNGA DAN JANGKA WAKTU</div><div>1. Jangka waktu pinjaman: <?= (int)$h['tenor'] ?> bulan terhitung sejak <?= date('d/m/Y',strtotime($tgl)) ?> s/d <?= date('d/m/Y',strtotime($akhir)) ?></div><div>2. Suku bunga menggunakan sistem bunga flat.</div><div>3. Total bunga <?= $e(rtrim(rtrim(number_format($h['bunga_persen'],2,',','.'),'0'),',')) ?>% selama tenor: <?= $rp($h['total_bunga']) ?></div><div>4. Total yang harus dibayar: <?= $rp($h['total']) ?></div>
<div class="article">PASAL 4: CARA PEMBAYARAN</div><div>1. Pembayaran dilakukan secara <?= $e(labelFrekuensi($h['frekuensi'])) ?>, <?= (int)$h['jumlah_cicilan'] ?> kali.</div><div>2. Besar cicilan: <?= $rp($h['angsuran']) ?> per <?= strtolower($e(labelPeriode($h['frekuensi']))) ?>/lihat Tabel Angsuran di Lampiran.</div><div>3. Pembayaran dilakukan setiap hari: <?= $e($namaHari[$hari]??'-') ?></div>
<div class="article">PASAL 5: DENDA KETERLAMBATAN</div><div>Apabila PIHAK KEDUA terlambat membayar, dikenakan denda <?= $e(rtrim(rtrim(number_format($persenDenda,2,',','.'),'0'),',')) ?>% atau <?= $rp($denda) ?> per hari.</div>
<div class="article">PASAL 6: JAMINAN</div><div><?= !empty($p['memerlukan_jaminan'])?'PIHAK KEDUA menyerahkan barang jaminan sebagaimana tercatat dalam pengajuan.':'PIHAK KEDUA menyerahkan fotokopi KK dan KTP suami/istri yang telah diketahui oleh pasangan.' ?></div>
<div class="article">PASAL 7: WANPRESTASI</div><div>Apabila PIHAK KEDUA tidak membayar selama 2 kali cicilan berturut-turut, PIHAK PERTAMA berhak menagih seluruh sisa hutang sekaligus.</div>
<div class="article">PASAL 8: PENYELESAIAN SENGKETA</div><div>Perselisihan diselesaikan secara musyawarah. Jika tidak tercapai, diselesaikan di Pengadilan Negeri.</div>
<p>Demikian surat perjanjian hutang piutang ini dibuat dengan sebenar-benarnya dalam keadaan sadar tanpa paksaan dari pihak manapun.</p><p>Dibuat di Mojokerto<br>Pada tanggal: <?= date('d/m/Y') ?></p>
<table class="sign"><tr><td>PIHAK PERTAMA<br><br><br><br>(<?= $e($aturan['nama_pihak_pertama'] ?: ($k['nama']??'...............................')) ?>)</td><td>PIHAK KEDUA<br>Meterai Rp10.000<br><br><br>(<?= $e($p['nama_lengkap']) ?>)</td></tr><tr><td>SAKSI 1<br><br><br>(...............................)</td><td>SAKSI 2<br><br><br>(...............................)</td></tr></table>
<section class="page-break"><h2 class="center">LAMPIRAN: TABEL ANGSURAN <?= (int)$h['jumlah_cicilan'] ?> <?= strtoupper($e(labelPeriode($h['frekuensi']))) ?></h2><table class="schedule"><thead><tr><th>Ke</th><th>Jatuh Tempo</th><th>Pokok</th><th>Bunga</th><th>Total Bayar</th><th>Sisa</th></tr></thead><tbody><?php $sisa=$h['total'];foreach($h['rincian'] as $r):$sisa=max(0,$sisa-$r['jumlah']);?><tr><td><?= (int)$r['ke'] ?></td><td><?= date('d/m/Y',strtotime(hitungJatuhTempo($tgl,$r['ke'],$h['frekuensi']))) ?></td><td><?= $rp($r['pokok']) ?></td><td><?= $rp($r['bunga']) ?></td><td><?= $rp($r['jumlah']) ?></td><td><?= $sisa>0?$rp($sisa):'LUNAS' ?></td></tr><?php endforeach;?></tbody></table><p class="muted">Tanggal dihitung berdasarkan rencana pencairan <?= date('d/m/Y',strtotime($tgl)) ?>. Jika tanggal pencairan aktual berbeda, jadwal final mengikuti tanggal pencairan yang diproses Teller.</p></section>
</main></body></html>

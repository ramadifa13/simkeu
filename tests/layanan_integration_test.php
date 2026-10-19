<?php
// DB_NAME wajib berisi "test". Semua perubahan di-rollback.
$name=getenv('DB_NAME');
if (!$name || stripos($name,'test')===false) { fwrite(STDERR,"Gunakan database salinan dengan nama mengandung test.\n");exit(1); }
date_default_timezone_set('Asia/Jakarta');
$db=new PDO('mysql:host='.(getenv('DB_HOST') ?: '127.0.0.1').';dbname='.$name.';charset=utf8mb4',getenv('DB_USER') ?: 'root',getenv('DB_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require __DIR__.'/../config/functions.php';
require __DIR__.'/../includes/layanan_actions.php';
function isAdmin(){return $_SESSION['role']==='admin';}
function isTeller(){return $_SESSION['role']==='teller';}
$count=0;
function checkLayanan($ok,$pesan){global $count;if(!$ok)throw new Exception('FAIL: '.$pesan);$count++;echo 'PASS '.$pesan.PHP_EOL;}
function ditolakLayanan($fn,$teks){try{$fn();}catch(Exception $e){checkLayanan(stripos($e->getMessage(),$teks)!==false,'Ditolak: '.$teks);return;}throw new Exception('Seharusnya ditolak: '.$teks);}
$files=[];
try {
    checkLayanan(nomorWhatsApp('0812-3456-7890')==='6281234567890','Normalisasi nomor WhatsApp');
    checkLayanan(nomorWhatsApp('+62 812 3456 7890')==='6281234567890','Nomor internasional WhatsApp');
    checkLayanan(nomorWhatsApp('abc')==='','Nomor tidak valid tidak membuat tautan');
    foreach([0=>'Lancar',3=>'Lancar',4=>'Terlambat',6=>'Terlambat',7=>'Macet Ringan',14=>'Macet Ringan',29=>'Macet Ringan',30=>'Macet Berat',90=>'Macet Berat',91=>'Gagal Bayar'] as $h=>$status)checkLayanan(statusKolektibilitas($h)[0]===$status,'Batas status '.$h.' hari');
    ditolakLayanan(function(){layananNominal('-1');},'Nominal');
    ditolakLayanan(function(){layananNominal('1e6');},'Nominal');
    $_SESSION=['role'=>'teller','user_id'=>0,'layanan_csrf'=>'abc'];$_POST=['csrf'=>'salah'];
    ditolakLayanan(function(){layananValidasiToken();},'Sesi formulir');
    $teller=$db->query("SELECT id FROM users WHERE role='teller' LIMIT 1")->fetchColumn();
    $admin=$db->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
    $id=$db->query("SELECT p.id FROM pinjaman p WHERE p.status<>'lunas' AND EXISTS(SELECT 1 FROM angsuran a WHERE a.pinjaman_id=p.id AND a.status<>'lunas') LIMIT 1")->fetchColumn();
    if(!$id||!$teller||!$admin)throw new Exception('Fixture memerlukan Admin, Teller, dan pinjaman dengan cicilan belum lunas.');
    $_SESSION['user_id']=$teller;
    $db->beginTransaction();
    foreach(['jual_jaminan','kembalikan_lebih','bayar_bonus','repeat_order','putuskan_hapus_denda'] as $aksi)ditolakLayanan(function()use($db,$id,$aksi,&$files){prosesLayanan($db,$aksi,$id,$files);},'hanya untuk Admin');
    ditolakLayanan(function()use($db,$id){layananValidasiPrioritas($db,0,1000001,false,true);},'sampai Rp 1 juta');
    ditolakLayanan(function()use($db){layananValidasiPrioritas($db,0,1000000,false,true);},'belum disetujui');
    $db->prepare("UPDATE angsuran SET tanggal_jatuh_tempo=DATE_SUB(CURDATE(),INTERVAL 14 DAY),denda=50000,denda_manual=1 WHERE pinjaman_id=? AND status<>'lunas'")->execute([$id]);
    $_POST=['catatan'=>'Nasabah belum membayar'];prosesLayanan($db,'lapor_macet',$id,$files);
    $stmt=$db->prepare('SELECT COUNT(*) FROM layanan_laporan_macet WHERE pinjaman_id=?');$stmt->execute([$id]);checkLayanan($stmt->fetchColumn()>0,'Teller membuat laporan macet');
    ditolakLayanan(function()use($db,$id,&$files){prosesLayanan($db,'lapor_macet',$id,$files);},'Masih ada laporan');
    foreach(['reschedule','eksekusi','diskon_denda','approve_denda','approve_lunas','approve_jaminan','approve_prioritas'] as $aksi)ditolakLayanan(function()use($db,$id,$aksi,&$files){prosesLayanan($db,$aksi,$id,$files);},'hanya untuk Admin');
    ditolakLayanan(function()use($db,$id,&$files){prosesLayanan($db,'proses_lunas',$id,$files);},'disetujui Admin');
    $_POST=['catatan'=>'Ingin melunasi lebih awal'];prosesLayanan($db,'ajukan_lunas',$id,$files);
    $_SESSION=['role'=>'admin','user_id'=>$admin];$_POST=['diskon'=>'10','catatan'=>'Diskon disetujui'];prosesLayanan($db,'approve_lunas',$id,$files);
    $s=layananSisa($db,$id,10);checkLayanan(abs($s['total']-($s['pokok']+$s['bunga']*0.9+$s['denda']))<0.02,'Pelunasan menghitung diskon bunga di server');
    $_SESSION=['role'=>'teller','user_id'=>$teller];$_POST=['jumlah'=>'1','metode'=>'cash'];
    ditolakLayanan(function()use($db,$id,&$files){prosesLayanan($db,'proses_lunas',$id,$files);},'Nominal harus sama');
    $_POST=['jumlah'=>(string)$s['total'],'metode'=>'cash','cek_fisik'=>1];
    ditolakLayanan(function()use($db,$id,&$files){prosesLayanan($db,'proses_lunas',$id,$files);},'Bukti pelunasan wajib');
    $a=$db->query("SELECT id FROM angsuran WHERE pinjaman_id=".(int)$id." AND status<>'lunas' LIMIT 1")->fetchColumn();
    $_POST=['angsuran_id'=>$a,'jumlah'=>'50001','metode'=>'cash'];ditolakLayanan(function()use($db,$id,&$files){prosesLayanan($db,'bayar_denda',$id,$files);},'melebihi sisa denda');
    $_POST=['angsuran_id'=>$a,'jumlah'=>'1000','metode'=>'cash'];ditolakLayanan(function()use($db,$id,&$files){prosesLayanan($db,'bayar_denda',$id,$files);},'Bukti pembayaran denda wajib');
    // Ledger denda: bayar terpisah harus mengurangi sisa tagihan sekali saja.
    $p=layananPinjaman($db,$id);$tid=layananCatatKas($db,$p,1000,'TEST DENDA');
    $db->prepare("INSERT INTO layanan_bayar_denda (angsuran_id,pinjaman_id,user_id,jumlah,metode,bukti,transaksi_id) VALUES (?,?,?,1000,'cash','test.jpg',?)")->execute([$a,$id,$teller,$tid]);
    $sesudah=layananSisa($db,$id,10);checkLayanan(abs($s['total']-$sesudah['total']-1000)<0.01,'Denda terpisah tidak ditagih ulang');
    $_SESSION=['role'=>'admin','user_id'=>$admin];$_POST=['angsuran_id'=>$a,'jumlah'=>'0','catatan'=>'Pemutihan penuh'];ditolakLayanan(function()use($db,$id,&$files){prosesLayanan($db,'diskon_denda',$id,$files);},'antara jumlah yang sudah dibayar');
    $_POST=['tanggal'=>date('Y-m-d',strtotime('+7 days')),'catatan'=>'Perpanjangan disetujui'];prosesLayanan($db,'reschedule',$id,$files);
    checkLayanan(abs(layananSisa($db,$id,10)['total']-$sesudah['total'])<0.01,'Reschedule tidak menghapus denda terutang');
    $db->prepare("UPDATE pinjaman SET status='lunas' WHERE id=?")->execute([$id]);
    $_SESSION=['role'=>'teller','user_id'=>$teller];$_POST=['angsuran_id'=>$a,'jumlah'=>'1000','metode'=>'cash'];ditolakLayanan(function()use($db,$id,&$files){prosesLayanan($db,'bayar_denda',$id,$files);},'terkunci');
    $db->rollBack();echo "Semua $count pemeriksaan lulus; perubahan di-rollback.\n";
} catch(Exception $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

<?php
$dbName=getenv('DB_NAME');
if(!$dbName||stripos($dbName,'test')===false){fwrite(STDERR,"Gunakan DB_NAME database test.\n");exit(1);}
require __DIR__.'/../config/config.php';
require __DIR__.'/../config/functions.php';
if(!function_exists('isAdmin')){function isAdmin(){return ($_SESSION['role']??'')==='admin';}}
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$admin=(int)$db->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
$p=$db->query("SELECT p.id,p.anggota_id,a.user_id FROM pinjaman p JOIN anggota a ON a.id=p.anggota_id
    WHERE p.status IN ('aktif','macet') AND a.user_id IS NOT NULL
      AND EXISTS(SELECT 1 FROM angsuran x WHERE x.pinjaman_id=p.id AND x.status<>'lunas')
      AND NOT EXISTS(SELECT 1 FROM angsuran x WHERE x.pinjaman_id=p.id AND x.status<>'lunas' AND x.tanggal_jatuh_tempo<=CURDATE() AND x.jumlah_terbayar<x.jumlah_angsuran-0.005)
      AND NOT EXISTS(SELECT 1 FROM pengajuan_mutasi_angsuran m WHERE m.pinjaman_id=p.id AND m.status='menunggu') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$admin||!$p)throw new RuntimeException('Fixture Admin dan pinjaman anggota tanpa tunggakan wajib tersedia.');
$_SESSION=['user_id'=>$admin,'role'=>'admin'];$db->beginTransaction();
try{
    $kode='T'.strtoupper(bin2hex(random_bytes(4)));
    $db->prepare("INSERT INTO kelompok_angsuran(kode,nama,status,dibuat_oleh) VALUES(?,?,'aktif',?)")->execute([$kode,'Tes '.$kode,$admin]);
    $kelompokId=(int)$db->lastInsertId();
    $id=ajukanMutasiKelompokAngsuran($db,(int)$p['anggota_id'],(int)$p['id'],$kelompokId,(int)$p['user_id'],'Uji perpindahan kelompok');
    $tanggal=putuskanMutasiHariAngsuran($db,$id,$admin,true,'Disetujui dalam pengujian');
    $q=$db->prepare('SELECT kelompok_angsuran_id FROM pinjaman WHERE id=?');$q->execute([(int)$p['id']]);
    if((int)$q->fetchColumn()!==$kelompokId)throw new RuntimeException('Kelompok pinjaman tidak diperbarui.');
    $q=$db->prepare('SELECT jenis,status,tanggal_efektif FROM pengajuan_mutasi_angsuran WHERE id=?');$q->execute([$id]);$mutasi=$q->fetch(PDO::FETCH_ASSOC);
    if($mutasi['jenis']!=='kelompok'||$mutasi['status']!=='disetujui'||$mutasi['tanggal_efektif']!==date('Y-m-d'))throw new RuntimeException('Status atau tanggal efektif mutasi salah.');
    $jenis=(int)$db->query('SELECT id FROM jenis_pinjaman ORDER BY id LIMIT 1')->fetchColumn();
    $kode='TEST-GRP-'.strtoupper(bin2hex(random_bytes(3)));
    $db->prepare("INSERT INTO pengajuan_pinjaman(kode_pengajuan,anggota_id,jenis_pinjaman_id,jumlah_pinjaman,tenor,bunga,skema_bunga,frekuensi,tujuan_pinjaman,status,admin_id,user_id,hari_angsuran,kelompok_angsuran_id) VALUES(?,?,?,1000000,1,15,'flat','mingguan','Uji pewarisan kelompok','dicairkan',?,?,3,?)")
        ->execute([$kode,(int)$p['anggota_id'],$jenis,$admin,$admin,$kelompokId]);
    $pengajuanId=(int)$db->lastInsertId();$q=$db->prepare('SELECT * FROM pengajuan_pinjaman WHERE id=?');$q->execute([$pengajuanId]);
    $pinjamanBaru=buatPinjamanDanJadwal($db,$q->fetch(PDO::FETCH_ASSOC),date('Y-m-d'),date('Y-m-d'));
    $q=$db->prepare('SELECT kelompok_angsuran_id FROM pinjaman WHERE id=?');$q->execute([$pinjamanBaru]);
    if((int)$q->fetchColumn()!==$kelompokId)throw new RuntimeException('Kelompok dari pengajuan baru tidak tersimpan pada pinjaman.');
    echo "Mutasi kelompok lulus; pinjaman={$p['id']}, tanggal efektif=$tanggal.\n";
    $db->rollBack();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

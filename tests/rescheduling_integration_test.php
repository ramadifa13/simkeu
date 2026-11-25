<?php
$dbName=getenv('DB_NAME');
if(!$dbName||stripos($dbName,'test')===false){fwrite(STDERR,"Gunakan DB_NAME database test.\n");exit(1);}
require __DIR__.'/../config/config.php';
require __DIR__.'/../config/functions.php';
if(!function_exists('isAdmin')){function isAdmin(){return ($_SESSION['role']??'')==='admin';}}
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$admin=(int)$db->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
$p=$db->query("SELECT p.id,p.tenor,p.frekuensi,p.sisa_pokok,p.jumlah_pinjaman,p.kelompok_angsuran_id
    FROM pinjaman p WHERE p.status IN ('aktif','macet') AND p.sisa_pokok>0
      AND NOT EXISTS(SELECT 1 FROM pembayaran_angsuran b WHERE b.pinjaman_id=p.id AND b.status='menunggu')
      AND NOT EXISTS(SELECT 1 FROM pinjaman c WHERE c.reschedule_parent_id=p.id) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$admin||!$p)throw new RuntimeException('Fixture Admin dan pinjaman aktif wajib tersedia.');
$_SESSION=['user_id'=>$admin,'role'=>'admin'];$db->beginTransaction();
try{
    $kode='R'.strtoupper(bin2hex(random_bytes(4)));
    $db->prepare("INSERT INTO kelompok_angsuran(kode,nama,status,dibuat_oleh) VALUES(?,?,'aktif',?)")->execute([$kode,'Tes '.$kode,$admin]);
    $kelompokId=(int)$db->lastInsertId();
    $db->prepare("UPDATE pinjaman SET kelompok_angsuran_id=? WHERE id=?")->execute([$kelompokId,(int)$p['id']]);
    $db->prepare("UPDATE angsuran SET tanggal_jatuh_tempo=DATE_SUB(CURDATE(),INTERVAL 30 DAY),denda=10000,denda_manual=0 WHERE pinjaman_id=? AND status<>'lunas' AND angsuran_ke=(SELECT min_ke FROM (SELECT MIN(angsuran_ke) min_ke FROM angsuran WHERE pinjaman_id=? AND status<>'lunas') x)")->execute([(int)$p['id'],(int)$p['id']]);
    $db->prepare('UPDATE pinjaman SET tanggal_mulai_tunggakan=NULL WHERE id=?')->execute([(int)$p['id']]);
    $newId=prosesReschedulingPinjaman($db,(int)$p['id'],$admin,'Uji rescheduling dan kelompok');
    $q=$db->prepare('SELECT tenor,jumlah_cicilan,kelompok_angsuran_id,sisa_pokok FROM pinjaman WHERE id=?');$q->execute([$newId]);$child=$q->fetch(PDO::FETCH_ASSOC);
    $expectedCount=hitungJumlahCicilan((int)$p['tenor']*2,$p['frekuensi']);
    if((int)$child['tenor']!==(int)$p['tenor']*2||(int)$child['jumlah_cicilan']!==$expectedCount)throw new RuntimeException('Tenor/jumlah cicilan reschedule salah.');
    if((int)$child['kelompok_angsuran_id']!==$kelompokId)throw new RuntimeException('Kelompok pinjaman tidak diwariskan ke kontrak reschedule.');
    $q=$db->prepare('SELECT COUNT(*) FROM angsuran WHERE pinjaman_id=?');$q->execute([$newId]);if((int)$q->fetchColumn()!==$expectedCount)throw new RuntimeException('Jadwal cicilan tidak lengkap.');
    $q=$db->prepare("SELECT tanggal_jatuh_tempo FROM angsuran WHERE pinjaman_id=? ORDER BY angsuran_ke LIMIT 1");$q->execute([$newId]);$first=$q->fetchColumn();
    if($first!==date('Y-m-d',strtotime('+7 days')))throw new RuntimeException('Angsuran pertama tidak dimulai tujuh hari kemudian.');
    $q=$db->prepare("SELECT status,sisa_pokok FROM pinjaman WHERE id=?");$q->execute([(int)$p['id']]);$parent=$q->fetch(PDO::FETCH_ASSOC);
    if($parent['status']!=='reschedule'||(float)$parent['sisa_pokok']!==0.0)throw new RuntimeException('Kontrak asal tidak ditutup dengan benar.');
    echo "Rescheduling lulus; kontrak baru=$newId, cicilan=$expectedCount, kelompok=$kelompokId.\n";
    $db->rollBack();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

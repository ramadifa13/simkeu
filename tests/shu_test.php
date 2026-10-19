<?php
require_once __DIR__.'/../config/functions.php';
require_once __DIR__.'/../includes/shu_helper.php';
function isAdmin(){return ($_SESSION['role'] ?? '')==='admin';}
$n=0;function okShu($condition,$message){global $n;if(!$condition)throw new Exception($message);$n++;echo 'PASS '.$message.PHP_EOL;}
function denyShu($fn,$text){try{$fn();}catch(Throwable $e){okShu(stripos($e->getMessage(),$text)!==false,'Ditolak: '.$text);return;}throw new Exception('Tidak ditolak: '.$text);}
try{
    okShu(shuBagi(10,[1=>1,2=>1,3=>1])===[1=>4,2=>3,3=>3],'Pembulatan sisa rupiah deterministik');
    mt_srand(20261019);for($i=0;$i<500;$i++){$pool=mt_rand(0,1000000);$weights=[mt_rand(1,10000),mt_rand(1,10000),mt_rand(1,10000)];$parts=shuBagi($pool,$weights);if(array_sum($parts)!==$pool || min($parts)<0)throw new Exception('Alokasi tidak seimbang');}okShu(true,'500 variasi alokasi seimbang');
    denyShu(function(){shuBagi(100,[0,0]);},'kontribusi');
    okShu(shuSaldoDasar(['2024-12-30'=>100000,'2025-01-16'=>100000],'2025-01-01','2025-01-31','rata_harian')===1516.13,'Saldo awal dan rata-rata harian');
    okShu(shuSaldoDasar(['2024-12-30'=>100000,'2025-01-16'=>-150000],'2025-01-01','2025-01-31','saldo_akhir')==0,'Saldo negatif tidak menjadi dasar negatif');
    okShu(shuSaldoDasar(['2024-02-01'=>10000],'2024-02-01','2024-02-29','rata_harian')==100,'Periode tahun kabisat');
    foreach(['-1','1.2','1e6','1000000000001',''] as $bad)denyShu(function()use($bad){shuRupiah($bad);},'Nominal');
    $policy=['basis_simpanan'=>'rata_harian'];foreach(shuKategori() as $k=>$label)$policy['persen_'.$k]='0';$policy['persen_simpanan']='100';okShu(shuKebijakan($policy)['persen_bp']['simpanan']===10000,'Kebijakan persentase eksplisit');
    $invalid=$policy;$invalid['persen_pinjaman']='0.01';denyShu(function()use($invalid){shuKebijakan($invalid);},'100%');
    $invalid=$policy;$invalid['persen_cadangan']='-1';denyShu(function()use($invalid){shuKebijakan($invalid);},'Persentase');
    $name=getenv('DB_NAME');if(!$name || stripos($name,'test')===false)throw new Exception('Gunakan DB_NAME database test.');
    date_default_timezone_set('Asia/Jakarta');$db=new PDO('mysql:host='.(getenv('DB_HOST') ?: '127.0.0.1').';dbname='.$name.';charset=utf8mb4',getenv('DB_USER') ?: 'root',getenv('DB_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $admin=$db->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();$files=[];$_SESSION=['role'=>'teller','user_id'=>$admin];$db->beginTransaction();denyShu(function()use($db,&$files){shuProses($db,'buat',[],$files);},'hanya untuk Admin');
    $_SESSION['role']='admin';$data=$policy+['tahun'=>'2023','total_shu'=>'1001'];$id=shuProses($db,'buat',$data,$files);okShu($id>0,'Membuat periode draft');
    denyShu(function()use($db,$id,&$files){shuProses($db,'bayar',['periode_id'=>$id],$files);},'periode final');
    // Fixture contributions are part of this rollback transaction.
    $member=$db->query('SELECT id FROM anggota ORDER BY id LIMIT 1')->fetchColumn();$db->prepare("UPDATE anggota SET tanggal_daftar='2023-01-01' WHERE id=?")->execute([$member]);
    $kind=$db->query("SELECT id FROM jenis_simpanan WHERE kode_jenis NOT IN ('ANG','BONUS','SHU') LIMIT 1")->fetchColumn();
    $db->prepare("INSERT INTO transaksi(kode_transaksi,anggota_id,jenis_simpanan_id,jenis_transaksi,jumlah,saldo_sebelum,saldo_sesudah,user_id,tanggal_transaksi) VALUES ('SHUTEST2023',?,?,'setoran',1000,0,1000,?,'2023-01-01')")->execute([$member,$kind,$admin]);
    shuProses($db,'hitung',['periode_id'=>$id],$files);$period=shuPeriodeLock($db,$id);okShu($period['status']==='dihitung','Simulasi membuat snapshot');
    $sum=$db->query('SELECT SUM(total_shu) FROM shu_anggota WHERE shu_periode_id='.(int)$id)->fetchColumn();okShu((int)$sum===1001,'Hak anggota sama dengan alokasi penuh');
    $approve=['periode_id'=>$id,'referensi_rat'=>'RAT/2023/TEST','konfirmasi_rat'=>1];
    $db->prepare("UPDATE transaksi SET jumlah=2000 WHERE kode_transaksi='SHUTEST2023'")->execute();denyShu(function()use($db,$approve,&$files){shuProses($db,'sahkan',$approve,$files);},'sumber berubah');
    shuProses($db,'hitung',['periode_id'=>$id],$files);shuProses($db,'sahkan',$approve,$files);okShu(shuPeriodeLock($db,$id)['status']==='final','Pengesahan RAT mengunci periode');
    foreach(['ubah','hitung','sahkan'] as $action)denyShu(function()use($db,$id,$action,&$files){shuProses($db,$action,['periode_id'=>$id],$files);},'terkunci');
    $data['tahun']=date('Y');$current=shuProses($db,'buat',$data,$files);shuProses($db,'hitung',['periode_id'=>$current],$files);$approve['periode_id']=$current;denyShu(function()use($db,$approve,&$files){shuProses($db,'sahkan',$approve,$files);},'belum berakhir');
    $before=$db->query('SELECT COUNT(*) FROM kas')->fetchColumn();denyShu(function()use($db,$member){shuCatatKas($db,['anggota_id'=>$member],1000000000000,'keluar','Test saldo tidak cukup');},'Saldo kas');okShu($before===$db->query('SELECT COUNT(*) FROM kas')->fetchColumn(),'Kas tidak berubah saat saldo kurang');
    $db->rollBack();echo 'Semua '.$n.' pemeriksaan SHU lulus; perubahan database di-rollback.'.PHP_EOL;
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

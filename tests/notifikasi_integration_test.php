<?php
$name=getenv('DB_NAME');
if(!$name || stripos($name,'test')===false){fwrite(STDERR,"Gunakan database uji.\n");exit(1);}
$_SERVER['HTTP_HOST']='localhost';$_SERVER['SCRIPT_NAME']='/tests/notifikasi_integration_test.php';
session_save_path(sys_get_temp_dir());
require __DIR__.'/../config/config.php';
require __DIR__.'/../config/functions.php';
$admin=layananHitungNotifikasi($db,'admin',0);
$tables=['hapus_denda'=>'layanan_pengajuan_hapus_denda','pelunasan_dini'=>'layanan_pelunasan','jaminan_kembali'=>'layanan_jaminan_kembali','prioritas'=>'layanan_prioritas','laporan_macet'=>'layanan_laporan_macet'];
foreach($tables as $key=>$table){
    $expected=(int)$db->query("SELECT COUNT(*) FROM $table WHERE status='menunggu'")->fetchColumn();
    if($admin[$key]!==$expected)throw new Exception("Hitungan $key tidak sesuai.");
}
$expectedDenda=(int)$db->query('SELECT COUNT(*) FROM layanan_bayar_denda WHERE approved_by IS NULL')->fetchColumn();
if($admin['pembayaran_denda']!==$expectedDenda)throw new Exception('Hitungan review pembayaran denda tidak sesuai.');
$expectedLayanan=array_sum(array_map(function($key)use($admin){return $admin[$key];},array_merge(array_keys($tables),['pembayaran_denda'])));
if($admin['layanan']!==$expectedLayanan)throw new Exception('Total layanan Admin tidak sesuai.');
if($admin['total']!==$admin['pengajuan']+$admin['pencairan']+$admin['pembatalan']+$admin['layanan'])throw new Exception('Total lonceng tidak sesuai.');
echo "Semua hitungan notifikasi Admin lulus.\n";

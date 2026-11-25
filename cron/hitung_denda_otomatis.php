<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/functions.php';
$lock=(int)$db->query("SELECT GET_LOCK('simkeu_cron_denda_v1',0)")->fetchColumn();
if($lock!==1){fwrite(STDERR,"Proses denda lain sedang berjalan.\n");exit(2);}
$logDir=__DIR__.'/../storage/logs';
try{
    if(!is_dir($logDir)&&!mkdir($logDir,0770,true)&&!is_dir($logDir))throw new RuntimeException('Folder log tidak dapat dibuat.');
    $jumlah=perbaruiDendaOtomatis($db,false);
    $baris=date('Y-m-d H:i:s')." WIB updated={$jumlah}\n";
    file_put_contents($logDir.'/cron_denda.log',$baris,FILE_APPEND|LOCK_EX);
    fwrite(STDOUT,$baris);
}catch(Throwable $e){
    $baris=date('Y-m-d H:i:s')." WIB ERROR ".$e->getMessage()."\n";
    @file_put_contents($logDir.'/cron_denda.log',$baris,FILE_APPEND|LOCK_EX);
    fwrite(STDERR,$baris);exit(1);
}finally{$db->query("SELECT RELEASE_LOCK('simkeu_cron_denda_v1')");}

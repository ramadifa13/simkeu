<?php
// CLI saja. Jadwalkan setiap 00:00 Asia/Jakarta melalui cron / Task Scheduler.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$_SERVER['HTTP_HOST']='localhost';$_SERVER['SCRIPT_NAME']='/scripts/hitung_denda.php';
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/functions.php';
if (!$db) exit(1);
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { $jumlah=perbaruiDendaOtomatis($db,true);echo date('c').' Denda diperbarui: '.$jumlah.PHP_EOL; }
catch(Exception $e) { fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1); }

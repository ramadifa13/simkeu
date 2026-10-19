<?php
/** Pengingat WhatsApp H-1 jatuh tempo. Jalankan sekali sehari melalui Cron Job hosting. */
require_once dirname(__DIR__) . '/config/env.php';

if (PHP_SAPI !== 'cli') {
    $expected = getenv('CRON_SECRET') ?: '';
    $provided = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);exit('Akses ditolak.');
    }
    header('Content-Type: application/json; charset=utf-8');
}

date_default_timezone_set('Asia/Jakarta');
require_once dirname(__DIR__) . '/config/database.php';

function waCronNomor($number) {
    $number=preg_replace('/\D+/','',(string)$number);
    if (strpos($number,'0')===0) $number='62'.substr($number,1);
    return preg_match('/^62\d{8,13}$/D',$number)?$number:'';
}
function waCronKirim($graphVersion,$phoneId,$token,$template,$language,$to,$params) {
    if (!function_exists('curl_init')) throw new RuntimeException('Ekstensi cURL PHP belum aktif.');
    $values=[];foreach($params as $value)$values[]=['type'=>'text','text'=>(string)$value];
    $payload=['messaging_product'=>'whatsapp','to'=>$to,'type'=>'template','template'=>[
        'name'=>$template,'language'=>['code'=>$language],
        'components'=>[['type'=>'body','parameters'=>$values]],
    ]];
    $curl=curl_init('https://graph.facebook.com/'.rawurlencode($graphVersion).'/'.rawurlencode($phoneId).'/messages');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_error($curl);curl_close($curl);
    if ($body===false || $error!=='') throw new RuntimeException('WhatsApp API: '.$error);
    $decoded=json_decode($body,true);
    if ($status<200 || $status>=300) throw new RuntimeException('WhatsApp API HTTP '.$status.': '.($decoded['error']['message']??'respons tidak valid'));
    return $decoded['messages'][0]['id']??'';
}

$phoneId=getenv('WA_PHONE_NUMBER_ID')?:'';$token=getenv('WA_ACCESS_TOKEN')?:'';$graphVersion=getenv('WA_GRAPH_VERSION')?:'';
$template=getenv('WA_TEMPLATE_NAME')?:'pengingat_angsuran_h1';$language=getenv('WA_TEMPLATE_LANG')?:'id';
if ($phoneId==='' || $token==='' || !preg_match('/^v\d+\.\d+$/D',$graphVersion)) {
    http_response_code(503);
    echo PHP_SAPI==='cli'?"WA_PHONE_NUMBER_ID, WA_ACCESS_TOKEN, dan WA_GRAPH_VERSION belum valid.\n":json_encode(['ok'=>false,'message'=>'Konfigurasi WhatsApp belum lengkap.']);
    exit(2);
}

$db=(new Database())->getConnection();
$db->exec("CREATE TABLE IF NOT EXISTS wa_pengingat_log (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, angsuran_id INT NOT NULL, tanggal_jadwal DATE NOT NULL,
 nomor VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, message_id VARCHAR(150) NULL,
 error_message VARCHAR(500) NULL, percobaan INT NOT NULL DEFAULT 1,
 sent_at DATETIME NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_wa_angsuran_tanggal (angsuran_id,tanggal_jadwal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$sql="SELECT a.id,a.angsuran_ke,a.tanggal_jatuh_tempo,a.jumlah_angsuran,p.kode_pinjaman,
 ag.nama_lengkap,ag.telepon FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id
 JOIN anggota ag ON ag.id=p.anggota_id LEFT JOIN wa_pengingat_log w
 ON w.angsuran_id=a.id AND w.tanggal_jadwal=a.tanggal_jatuh_tempo AND w.status='terkirim'
 WHERE p.status='aktif' AND a.status<>'lunas' AND a.tanggal_jatuh_tempo=DATE_ADD(CURDATE(),INTERVAL 1 DAY)
 AND w.id IS NULL ORDER BY a.id";
$rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);$sent=0;$failed=0;$skipped=0;$errors=[];
$save=$db->prepare("INSERT INTO wa_pengingat_log
 (angsuran_id,tanggal_jadwal,nomor,status,message_id,error_message,percobaan,sent_at)
 VALUES (?,?,?,?,?,?,1,?) ON DUPLICATE KEY UPDATE nomor=VALUES(nomor),status=VALUES(status),
 message_id=VALUES(message_id),error_message=VALUES(error_message),percobaan=percobaan+1,sent_at=VALUES(sent_at)");
foreach($rows as $row){
    $number=waCronNomor($row['telepon']);if($number===''){$skipped++;$errors[]=$row['kode_pinjaman'].': nomor tidak valid';continue;}
    try{$messageId=waCronKirim($graphVersion,$phoneId,$token,$template,$language,$number,[
        $row['nama_lengkap'],(string)$row['angsuran_ke'],date('d-m-Y',strtotime($row['tanggal_jatuh_tempo'])),
        'Rp '.number_format($row['jumlah_angsuran'],0,',','.'),$row['kode_pinjaman']]);
        $save->execute([$row['id'],$row['tanggal_jatuh_tempo'],$number,'terkirim',$messageId,null,date('Y-m-d H:i:s')]);$sent++;
    }catch(Throwable $e){$message=substr($e->getMessage(),0,500);$save->execute([$row['id'],$row['tanggal_jatuh_tempo'],$number,'gagal',null,$message,null]);$failed++;$errors[]=$row['kode_pinjaman'].': '.$message;}
}
$result=['ok'=>$failed===0,'tanggal'=>date('Y-m-d'),'jatuh_tempo'=>date('Y-m-d',strtotime('+1 day')),'ditemukan'=>count($rows),'terkirim'=>$sent,'gagal'=>$failed,'dilewati'=>$skipped,'errors'=>$errors];
echo PHP_SAPI==='cli'?json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL:json_encode($result,JSON_UNESCAPED_UNICODE);
exit($failed?1:0);

<?php
require_once __DIR__.'/../includes/auth.php';
checkAnggota();
require_once __DIR__.'/../includes/pembaruan_helper.php';
$q=$db->prepare('SELECT id FROM anggota WHERE user_id=? LIMIT 1');
$q->execute([(int)$_SESSION['user_id']]);
$anggotaId=(int)$q->fetchColumn();
if(!$anggotaId){http_response_code(404);exit('Data anggota tidak ditemukan.');}
$pesan='';
$q=$db->prepare("SELECT p.id,p.kode_pinjaman,p.frekuensi,p.kelompok_angsuran_id,k.nama kelompok_nama,pp.hari_angsuran,
    SUM(CASE WHEN a.tanggal_jatuh_tempo<=CURDATE() AND a.status<>'lunas' AND a.jumlah_terbayar<a.jumlah_angsuran-0.005 THEN 1 ELSE 0 END) jatuh_tempo
    FROM pinjaman p JOIN pengajuan_pinjaman pp ON pp.id=p.pengajuan_id JOIN angsuran a ON a.pinjaman_id=p.id
    LEFT JOIN kelompok_angsuran k ON k.id=p.kelompok_angsuran_id
    WHERE p.anggota_id=? AND p.status IN ('aktif','macet') GROUP BY p.id");
$q->execute([$anggotaId]);$pinjaman=$q->fetchAll(PDO::FETCH_ASSOC);
$kelompokAktif=$db->query("SELECT id,kode,nama FROM kelompok_angsuran WHERE status='aktif' ORDER BY nama")->fetchAll(PDO::FETCH_ASSOC);
if($_SERVER['REQUEST_METHOD']==='POST')try{
    layananValidasiToken();$db->beginTransaction();
    if(($_POST['jenis_mutasi']??'hari')==='kelompok'){
        ajukanMutasiKelompokAngsuran($db,$anggotaId,(int)($_POST['pinjaman_id']??0),(int)($_POST['kelompok_baru_id']??0),(int)$_SESSION['user_id'],(string)($_POST['alasan']??''));
        $pesan='Permohonan pindah kelompok dikirim dan menunggu keputusan Admin.';
    }else{
        ajukanMutasiHariAngsuran($db,$anggotaId,(int)($_POST['pinjaman_id']??0),(int)($_POST['hari_baru']??0),(int)$_SESSION['user_id'],(string)($_POST['alasan']??''));
        $pesan='Permohonan pindah hari dikirim dan menunggu keputusan Admin.';
    }
    $db->commit();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$pesan=$e->getMessage();}
$hist=$db->prepare("SELECT m.*,p.kode_pinjaman,kl.nama kelompok_lama,kb.nama kelompok_baru FROM pengajuan_mutasi_angsuran m JOIN pinjaman p ON p.id=m.pinjaman_id LEFT JOIN kelompok_angsuran kl ON kl.id=m.kelompok_lama_id LEFT JOIN kelompok_angsuran kb ON kb.id=m.kelompok_baru_id WHERE m.anggota_id=? ORDER BY m.id DESC LIMIT 30");
$hist->execute([$anggotaId]);$riwayat=$hist->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mutasi Angsuran</title><?php $uiAssetPrefix='../';include __DIR__.'/../includes/layout/assets.php';?></head><body><?php include __DIR__.'/../includes/layout/shell_start.php';?><div class="ui-tx-page"><header class="ui-dash-header"><div class="ui-dash-header-icon"><i class="ti ti-calendar-event"></i></div><div class="ui-dash-header-copy"><p class="ui-dash-eyebrow">Pinjaman Saya</p><h1 class="ui-dash-title">Pindah Hari atau Kelompok</h1><p class="ui-dash-meta">Semua tagihan yang sudah jatuh tempo harus lunas. Pindah hari mengikuti jadwal baru; pindah kelompok berlaku pada pinjaman setelah disetujui Admin.</p></div></header>
<?php if($pesan):?><div class="alert alert-info"><?=htmlspecialchars($pesan)?></div><?php endif?>
<form method="post" class="card card-body mb-4"><?=layananToken()?><input type="hidden" name="jenis_mutasi" value="hari"><h2 class="h5">Ajukan pindah hari</h2><div class="row g-3"><div class="col-md-4"><label class="form-label">Pinjaman aktif</label><select name="pinjaman_id" class="form-select" required><option value="">Pilih pinjaman</option><?php foreach($pinjaman as $p):?><option value="<?=(int)$p['id']?>" <?=(int)$p['jatuh_tempo']>0?'disabled':''?>><?=htmlspecialchars($p['kode_pinjaman'].' · '.$p['frekuensi'].' · saat ini '.labelHariAngsuran($p['hari_angsuran']).((int)$p['jatuh_tempo']>0?' · lunasi tagihan jatuh tempo dulu':''))?></option><?php endforeach?></select></div><div class="col-md-3"><label class="form-label">Hari baru</label><select name="hari_baru" class="form-select" required><option value="">Pilih hari</option><?php foreach(daftarHariAngsuran() as $hari=>$nama):?><option value="<?=$hari?>"><?=htmlspecialchars($nama)?></option><?php endforeach?></select></div><div class="col-md-5"><label class="form-label">Alasan</label><input name="alasan" class="form-control" minlength="5" maxlength="2000" required></div><div class="col-12"><button class="btn btn-primary">Kirim permohonan pindah hari</button></div></div></form>
<?php if($kelompokAktif):?><form method="post" class="card card-body mb-4"><?=layananToken()?><input type="hidden" name="jenis_mutasi" value="kelompok"><h2 class="h5">Ajukan pindah kelompok penagihan</h2><div class="row g-3"><div class="col-md-4"><label class="form-label">Pinjaman aktif</label><select name="pinjaman_id" class="form-select" required><option value="">Pilih pinjaman</option><?php foreach($pinjaman as $p):?><option value="<?=(int)$p['id']?>" <?=(int)$p['jatuh_tempo']>0?'disabled':''?>><?=htmlspecialchars($p['kode_pinjaman'].' · saat ini '.($p['kelompok_nama']?:'belum memiliki kelompok').((int)$p['jatuh_tempo']>0?' · lunasi tagihan jatuh tempo dulu':''))?></option><?php endforeach?></select></div><div class="col-md-4"><label class="form-label">Kelompok tujuan</label><select name="kelompok_baru_id" class="form-select" required><option value="">Pilih kelompok</option><?php foreach($kelompokAktif as $k):?><option value="<?=(int)$k['id']?>"><?=htmlspecialchars($k['kode'].' · '.$k['nama'])?></option><?php endforeach?></select></div><div class="col-md-4"><label class="form-label">Alasan</label><input name="alasan" class="form-control" minlength="5" maxlength="2000" required></div><div class="col-12"><button class="btn btn-primary">Kirim permohonan pindah kelompok</button></div></div></form><?php endif;?>
<section class="card"><div class="card-header"><h2 class="h5 mb-0">Riwayat permohonan</h2></div><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Pinjaman</th><th>Jenis</th><th>Perubahan</th><th>Status</th><th>Tanggal efektif</th><th>Catatan Admin</th></tr></thead><tbody><?php foreach($riwayat as $r):?><tr><td><?=htmlspecialchars($r['kode_pinjaman'])?></td><td><?=htmlspecialchars($r['jenis']==='hari'?'Hari':'Kelompok')?></td><td><?=htmlspecialchars($r['jenis']==='hari'?labelHariAngsuran($r['hari_lama']).' → '.labelHariAngsuran($r['hari_baru']):($r['kelompok_lama']?:'Belum ada kelompok').' → '.($r['kelompok_baru']?:'Kelompok tidak tersedia'))?></td><td><?=htmlspecialchars($r['status'])?></td><td><?=htmlspecialchars($r['tanggal_efektif']??'—')?></td><td><?=htmlspecialchars($r['catatan_admin']??'—')?></td></tr><?php endforeach?></tbody></table></div></section></div><?php include __DIR__.'/../includes/layout/shell_end.php';?></body></html>

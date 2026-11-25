<?php
require_once __DIR__.'/../includes/auth.php';
checkAnggota();
require_once __DIR__.'/../includes/pembaruan_helper.php';
$q=$db->prepare('SELECT id,nama_lengkap FROM anggota WHERE user_id=?'); $q->execute([(int)$_SESSION['user_id']]); $anggota=$q->fetch(PDO::FETCH_ASSOC);
if(!$anggota){http_response_code(404);exit('Data anggota tidak ditemukan.');}
$pesan='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $foto=null;$path=null;
    try{
        layananValidasiToken();
        $pid=(int)($_POST['pinjaman_id']??0);$referensi=trim((string)($_POST['referensi']??''));$raw=trim((string)($_POST['jumlah']??''));
        if(!preg_match('/^\d{1,12}(\.\d{1,2})?$/D',$raw))throw new InvalidArgumentException('Nominal pembayaran tidak valid.');
        $jumlah=(float)$raw;if($jumlah<=0)throw new InvalidArgumentException('Nominal harus lebih dari nol.');
        if($referensi===''||mb_strlen($referensi)>120)throw new InvalidArgumentException('Nomor referensi transfer wajib diisi (maksimal 120 karakter).');
        $foto=validasiFotoUpload('bukti','Bukti transfer',null,false);
        if($foto)$path=layananSimpanFoto($foto,'pembayaran');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->beginTransaction();
        $p=$db->prepare("SELECT id,anggota_id FROM pinjaman WHERE id=? AND anggota_id=? AND status IN ('aktif','macet') FOR UPDATE");$p->execute([$pid,$anggota['id']]);$pinjaman=$p->fetch(PDO::FETCH_ASSOC);
        if(!$pinjaman)throw new RuntimeException('Pinjaman tidak ditemukan atau bukan milik Anda.');
        $s=$db->prepare("SELECT COALESCE(SUM(GREATEST(0,jumlah_angsuran-jumlah_terbayar)),0) FROM angsuran WHERE pinjaman_id=? AND status<>'lunas'");$s->execute([$pid]);$outstanding=(float)$s->fetchColumn();
        $s=$db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM pembayaran_angsuran WHERE pinjaman_id=? AND status='menunggu'");$s->execute([$pid]);$menunggu=(float)$s->fetchColumn();
        if($jumlah>$outstanding-$menunggu+0.005)throw new RuntimeException('Nominal melebihi tagihan yang belum lunas setelah memperhitungkan transfer yang sedang diverifikasi.');
        $idempotency=hash('sha256',(int)$_SESSION['user_id'].'|'.strtolower($referensi));
        $q=$db->prepare("INSERT INTO pembayaran_angsuran(kode_pembayaran,anggota_id,pinjaman_id,jumlah,metode,status,jenis,referensi,bukti,idempotency_key,user_id) VALUES(?,?,?,?,'transfer','menunggu','anggota',?,?,?,?)");
        $q->execute(['TF-'.date('ymdHis').'-'.strtoupper(bin2hex(random_bytes(3))),$anggota['id'],$pid,$jumlah,$referensi,$path,$idempotency,(int)$_SESSION['user_id']]);
        $db->commit();$pesan='Pengajuan setoran transfer tersimpan dan menunggu verifikasi Teller/Admin.';
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($path)hapusFotoUpload($path);$pesan=$e->getMessage();}
}
$q=$db->prepare("SELECT p.id,p.kode_pinjaman,p.status,COALESCE(SUM(GREATEST(0,a.jumlah_angsuran-a.jumlah_terbayar)),0) sisa FROM pinjaman p JOIN angsuran a ON a.pinjaman_id=p.id WHERE p.anggota_id=? AND p.status IN ('aktif','macet') AND a.status<>'lunas' GROUP BY p.id ORDER BY p.tanggal_pencairan DESC");$q->execute([$anggota['id']]);$pinjaman=$q->fetchAll(PDO::FETCH_ASSOC);
$q=$db->prepare('SELECT kode_pembayaran,kode_pinjaman,jumlah,referensi,status,dibuat_pada,catatan FROM pembayaran_angsuran b JOIN pinjaman p ON p.id=b.pinjaman_id WHERE b.anggota_id=? AND b.jenis=\'anggota\' ORDER BY b.id DESC LIMIT 30');$q->execute([$anggota['id']]);$riwayat=$q->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setoran Angsuran Transfer</title><?php $uiAssetPrefix='../';include __DIR__.'/../includes/layout/assets.php';?></head><body>
<?php include __DIR__.'/../includes/layout/shell_start.php'; ?><div class="ui-tx-page"><header class="ui-dash-header"><div class="ui-dash-header-icon"><i class="ti ti-building-bank"></i></div><div class="ui-dash-header-copy"><p class="ui-dash-eyebrow">Pinjaman Saya</p><h1 class="ui-dash-title">Setoran Angsuran via Transfer</h1><p class="ui-dash-meta">Pembayaran baru dicatat setelah diverifikasi Teller/Admin.</p></div></header>
<?php if($pesan):?><div class="alert alert-info"><?=htmlspecialchars($pesan,ENT_QUOTES,'UTF-8')?></div><?php endif?>
<form method="post" enctype="multipart/form-data" class="card card-body mb-4"><?=layananToken()?>
<div class="row g-3"><div class="col-md-4"><label class="form-label">Pinjaman</label><select name="pinjaman_id" class="form-select" required><option value="">Pilih pinjaman</option><?php foreach($pinjaman as $p):?><option value="<?=(int)$p['id']?>"><?=htmlspecialchars($p['kode_pinjaman'].' · tagihan '.formatRupiah($p['sisa']))?></option><?php endforeach?></select></div>
<div class="col-md-3"><label class="form-label">Nominal</label><input name="jumlah" type="number" class="form-control" min="1" step="1" required></div><div class="col-md-3"><label class="form-label">Referensi transfer</label><input name="referensi" class="form-control" maxlength="120" required></div><div class="col-md-2"><label class="form-label">Bukti (opsional)</label><input name="bukti" type="file" accept="image/jpeg,image/png,image/webp" class="form-control"></div></div>
<div class="mt-3"><button class="btn btn-primary"><i class="ti ti-send me-1"></i>Kirim untuk verifikasi</button></div></form>
<section class="card"><div class="card-header"><h2 class="h5 mb-0">Riwayat transfer angsuran</h2></div><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Kode</th><th>Pinjaman</th><th>Nominal</th><th>Referensi</th><th>Status</th><th>Waktu</th><th>Catatan</th></tr></thead><tbody><?php foreach($riwayat as $r):?><tr><td><?=htmlspecialchars($r['kode_pembayaran'])?></td><td><?=htmlspecialchars($r['kode_pinjaman'])?></td><td><?=formatRupiah($r['jumlah'])?></td><td><?=htmlspecialchars($r['referensi']??'')?></td><td><?=htmlspecialchars($r['status'])?></td><td><?=htmlspecialchars($r['dibuat_pada'])?></td><td><?=htmlspecialchars($r['catatan']??'')?></td></tr><?php endforeach?></tbody></table></div></section></div><?php include __DIR__.'/../includes/layout/shell_end.php';?></body></html>

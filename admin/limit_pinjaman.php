<?php
require_once __DIR__ . '/../includes/auth.php';
if (!isAdmin() && !isTeller()) { http_response_code(403); exit('Khusus Admin/Teller.'); }
require_once __DIR__ . '/../includes/pembaruan_helper.php';
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        layananValidasiToken();
        $anggotaId=(int)($_POST['anggota_id']??0);$batas=(float)($_POST['batas_maksimum']??0);$alasan=trim((string)($_POST['alasan']??''));
        if($anggotaId<1||$batas<=0||fmod($batas,500000)!==0.0||mb_strlen($alasan)<5)throw new InvalidArgumentException('Anggota, batas kelipatan Rp500.000, dan alasan minimal 5 karakter wajib diisi.');
        $db->beginTransaction();
        $q=$db->prepare("SELECT a.id,(SELECT p.jumlah_pinjaman FROM pinjaman p WHERE p.anggota_id=a.id AND p.status='lunas' ORDER BY COALESCE(p.tanggal_lunas,p.tanggal_pencairan) DESC,p.id DESC LIMIT 1) terakhir FROM anggota a WHERE a.id=? FOR UPDATE");$q->execute([$anggotaId]);$anggota=$q->fetch(PDO::FETCH_ASSOC);if(!$anggota)throw new RuntimeException('Anggota tidak ditemukan.');
        $minimum=$anggota['terakhir']===null?1000000:500000;$maksimumPolitik=$anggota['terakhir']===null?2000000:(float)$anggota['terakhir']+2000000;
        if($batas<$minimum||$batas>$maksimumPolitik)throw new InvalidArgumentException('Limit khusus harus berada dari '.formatRupiah($minimum).' sampai '.formatRupiah($maksimumPolitik).' sesuai kebijakan anggota.');
        $db->prepare('UPDATE pengajuan_limit_pinjaman SET aktif=0 WHERE anggota_id=? AND aktif=1')->execute([$anggotaId]);
        $db->prepare('INSERT INTO pengajuan_limit_pinjaman(anggota_id,batas_maksimum,alasan,aktif,dibuat_oleh) VALUES(?,?,?,1,?)')->execute([$anggotaId,$batas,$alasan,(int)$_SESSION['user_id']]);
        $db->commit();$_SESSION['success']='Limit pengajuan anggota diperbarui.';
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$_SESSION['error']=$e->getMessage();}
    header('Location: limit_pinjaman.php');exit;
}
$anggota=$db->query("SELECT a.id,a.kode_anggota,a.nama_lengkap,lp.batas_maksimum,lp.alasan,lp.dibuat_pada FROM anggota a LEFT JOIN pengajuan_limit_pinjaman lp ON lp.id=(SELECT x.id FROM pengajuan_limit_pinjaman x WHERE x.anggota_id=a.id AND x.aktif=1 ORDER BY x.dibuat_pada DESC,x.id DESC LIMIT 1) ORDER BY a.nama_lengkap")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Limit Pinjaman Anggota</title><?php $uiAssetPrefix='../';include __DIR__.'/../includes/layout/assets.php';?></head><body><?php include __DIR__.'/../includes/layout/shell_start.php';?>
<div class="ui-tx-page"><header class="ui-dash-header"><div class="ui-dash-header-icon"><i class="ti ti-adjustments"></i></div><div class="ui-dash-header-copy"><p class="ui-dash-eyebrow mb-0">Pengaturan Pinjaman</p><h1 class="ui-dash-title">Limit Pinjaman Anggota</h1><p class="ui-dash-meta mb-0">Batas lebih rendah ditetapkan Admin/Teller dan tidak dapat menaikkan plafon normal.</p></div></header>
<?php foreach(['success','error'] as $m)if(!empty($_SESSION[$m])){echo '<div class="alert alert-'.($m==='success'?'success':'danger').'">'.htmlspecialchars($_SESSION[$m]).'</div>';unset($_SESSION[$m]);}?>
<form method="post" class="card card-body mb-4"><h2 class="h5">Tetapkan batas khusus</h2><?=layananToken()?><div class="row g-3"><div class="col-lg-5"><label class="form-label">Anggota</label><select name="anggota_id" class="form-select" required><option value="">Pilih anggota</option><?php foreach($anggota as $a):?><option value="<?=$a['id']?>"><?=htmlspecialchars($a['kode_anggota'].' — '.$a['nama_lengkap'])?></option><?php endforeach?></select></div><div class="col-lg-3"><label class="form-label">Batas maksimum (Rp)</label><input type="number" name="batas_maksimum" class="form-control" min="500000" step="500000" required></div><div class="col-lg-4"><label class="form-label">Alasan</label><input name="alasan" class="form-control" minlength="5" maxlength="255" required></div><div class="col-12"><button class="btn btn-primary">Simpan limit</button></div></div></form>
<div class="card"><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead><tr><th>Anggota</th><th>Limit khusus</th><th>Alasan</th><th>Ditetapkan</th></tr></thead><tbody><?php foreach($anggota as $a):?><tr><td><?=htmlspecialchars($a['kode_anggota'].' — '.$a['nama_lengkap'])?></td><td><?=$a['batas_maksimum']!==null?formatRupiah($a['batas_maksimum']):'Mengikuti batas normal'?></td><td><?=htmlspecialchars($a['alasan']??'—')?></td><td><?=htmlspecialchars($a['dibuat_pada']??'—')?></td></tr><?php endforeach?></tbody></table></div></div></div><?php include __DIR__.'/../includes/layout/shell_end.php';?></body></html>

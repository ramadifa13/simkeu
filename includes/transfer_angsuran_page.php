<?php
require_once __DIR__.'/auth.php';
if(!isAdmin()&&!isTeller()){http_response_code(403);exit('Akses ditolak.');}
require_once __DIR__.'/pembaruan_helper.php';
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        layananValidasiToken();$id=(int)($_POST['id']??0);$db->beginTransaction();
        if(isset($_POST['batalkan_fifo'])){
            batalkanPembayaranFIFO($db,$id,(int)$_SESSION['user_id'],(string)($_POST['catatan']??''));
            $db->commit();$_SESSION['success']='Pembayaran FIFO dibatalkan dan kas, angsuran, serta pokok dipulihkan.';
            header('Location: transfer_angsuran.php');exit;
        }
        $q=$db->prepare("SELECT * FROM pembayaran_angsuran WHERE id=? AND metode='transfer' AND jenis='anggota' AND status='menunggu' FOR UPDATE");$q->execute([$id]);$b=$q->fetch(PDO::FETCH_ASSOC);
        if(!$b)throw new RuntimeException('Transfer tidak ditemukan atau sudah diproses.');
        if(isset($_POST['terima'])){
            terapkanPembayaranAngsuran($db,(int)$b['pinjaman_id'],(float)$b['jumlah'],(int)$_SESSION['user_id'],'transfer','anggota',$id);
            $_SESSION['success']='Transfer diverifikasi dan dialokasikan ke angsuran tertua.';
        }else{
            $catatan=trim((string)($_POST['catatan']??''));if(mb_strlen($catatan)>2000)throw new InvalidArgumentException('Catatan terlalu panjang.');
            $db->prepare("UPDATE pembayaran_angsuran SET status='ditolak',diverifikasi_oleh=?,diverifikasi_pada=NOW(),catatan=? WHERE id=?")->execute([(int)$_SESSION['user_id'],$catatan?:'Bukti/referensi tidak dapat diverifikasi.',$id]);
            $_SESSION['success']='Transfer ditolak; tidak ada perubahan kas atau angsuran.';
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$_SESSION['error']=$e->getMessage();}
    header('Location: transfer_angsuran.php');exit;
}
$rows=$db->query("SELECT b.*,p.kode_pinjaman,a.nama_lengkap FROM pembayaran_angsuran b JOIN pinjaman p ON p.id=b.pinjaman_id JOIN anggota a ON a.id=b.anggota_id WHERE b.jenis='anggota' AND b.metode='transfer' AND b.status='menunggu' ORDER BY b.dibuat_pada")->fetchAll(PDO::FETCH_ASSOC);
$diterima=$db->query("SELECT b.*,p.kode_pinjaman,a.nama_lengkap FROM pembayaran_angsuran b JOIN pinjaman p ON p.id=b.pinjaman_id JOIN anggota a ON a.id=b.anggota_id WHERE b.jenis='anggota' AND b.metode='transfer' AND b.status='diterima' AND b.transaksi_id IS NOT NULL ORDER BY b.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verifikasi Transfer Angsuran</title><?php $uiAssetPrefix='../';include __DIR__.'/layout/assets.php';?></head><body>
<?php include __DIR__.'/layout/shell_start.php';?><div class="ui-tx-page"><header class="ui-dash-header"><div class="ui-dash-header-icon"><i class="ti ti-building-bank"></i></div><div class="ui-dash-header-copy"><p class="ui-dash-eyebrow">Peminjaman</p><h1 class="ui-dash-title">Verifikasi Transfer Angsuran</h1><p class="ui-dash-meta">Setoran tidak mengubah kas sampai diterima petugas.</p></div></header><?php ui_flash();?><div class="card"><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead><tr><th>Anggota</th><th>Pinjaman</th><th>Nominal</th><th>Referensi</th><th>Bukti</th><th>Waktu</th><th>Keputusan</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=htmlspecialchars($r['nama_lengkap'])?></td><td><?=htmlspecialchars($r['kode_pinjaman'])?></td><td><?=formatRupiah($r['jumlah'])?></td><td><?=htmlspecialchars($r['referensi']??'')?></td><td><?php if($r['bukti']):?><a href="<?=htmlspecialchars(urlFoto($r['bukti']),ENT_QUOTES,'UTF-8')?>" target="_blank" rel="noopener">Lihat bukti</a><?php else:?>—<?php endif?></td><td><?=htmlspecialchars($r['dibuat_pada'])?></td><td><div class="d-flex gap-2"><form method="post"><?=layananToken()?><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-sm btn-success" name="terima" value="1" onclick="return confirm('Verifikasi transfer dan catat ke kas?')">Terima</button></form><form method="post" class="d-flex gap-1"><?=layananToken()?><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input name="catatan" class="form-control form-control-sm" placeholder="Alasan penolakan"><button class="btn btn-sm btn-outline-danger" name="tolak" value="1">Tolak</button></form></div></td></tr><?php endforeach?>
</tbody></table></div><?php if(!$rows):?><div class="card-body text-muted">Tidak ada transfer yang menunggu.</div><?php endif?></div>
<section class="card mt-4"><div class="card-header"><h2 class="h5 mb-0">Transfer yang sudah diterima</h2></div><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Anggota</th><th>Pinjaman</th><th>Nominal</th><th>Referensi</th><th>Waktu</th><th></th></tr></thead><tbody><?php foreach($diterima as $r):?><tr><td><?=htmlspecialchars($r['nama_lengkap'])?></td><td><?=htmlspecialchars($r['kode_pinjaman'])?></td><td><?=formatRupiah($r['jumlah'])?></td><td><?=htmlspecialchars($r['referensi']??'')?></td><td><?=htmlspecialchars($r['diverifikasi_pada']??$r['dibuat_pada'])?></td><td><?php if(isAdmin()):?><form method="post" class="d-flex gap-2"><?=layananToken()?><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input name="catatan" class="form-control form-control-sm" minlength="5" placeholder="Alasan pembatalan" required><button class="btn btn-sm btn-outline-danger" name="batalkan_fifo" value="1" onclick="return confirm('Batalkan setoran ini dan pulihkan seluruh alokasi?')">Batalkan</button></form><?php endif?></td></tr><?php endforeach?></tbody></table></div></section></div><?php include __DIR__.'/layout/shell_end.php';?></body></html>

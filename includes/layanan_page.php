<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/layanan_actions.php';
if (!isLoggedIn()) { header('Location: ../login.php'); exit; }
if (!isAdmin() && !isTeller()) { http_response_code(403); exit('Akses ditolak.'); }
$tab=$_GET['tab'] ?? 'macet';
if (!in_array($tab,['macet','denda','lunas','pengaturan'],true)) $tab='macet';
if ($tab==='pengaturan' && !isAdmin()) { http_response_code(403); exit('Khusus Admin.'); }
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$siap=layananSiap($db);
$id=(int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $files=[];
    try {
        layananValidasiToken();
        if (!$siap) throw new Exception('Jalankan migrasi layanan terlebih dahulu.');
        $db->beginTransaction();
        if (($_POST['aksi'] ?? '')==='simpan_pengaturan') {
            layananAdmin();
            $diskon=layananNominal($_POST['diskon'] ?? '',true);
            $tarifJaminan=layananNominal($_POST['tarif_jaminan'] ?? '',true);
            if ($diskon>100) throw new Exception('Diskon maksimal 100%.');
            $wd=trim($_POST['wa_denda'] ?? '');$wl=trim($_POST['wa_lunas'] ?? '');
            $surat=trim($_POST['surat_lunas'] ?? '');$bast=trim($_POST['bast_jaminan'] ?? '');
            if (!$surat || !$bast || strlen($surat)>4000 || strlen($bast)>4000) throw new Exception('Template Surat Lunas dan BAST wajib diisi, maksimal 4000 karakter.');
            if (!$wd || !$wl || strlen($wd)>2000 || strlen($wl)>2000) throw new Exception('Template wajib diisi, maksimal 2000 karakter.');
            $db->prepare('UPDATE layanan_pengaturan SET diskon_pelunasan=?,pemutihan=?,wa_denda=?,wa_lunas=?,denda_jaminan_per_juta=? WHERE id=1')->execute([$diskon,empty($_POST['pemutihan'])?0:1,$wd,$wl,$tarifJaminan]);
            $db->prepare('UPDATE layanan_pengaturan SET surat_lunas=?,bast_jaminan=? WHERE id=1')->execute([$surat,$bast]);
            layananAudit($db,0,'pengaturan',json_encode(['diskon'=>$diskon,'pemutihan'=>!empty($_POST['pemutihan']),'tarif_jaminan'=>$tarifJaminan,'wa_denda'=>$wd,'wa_lunas'=>$wl,'surat_lunas'=>$surat,'bast'=>$bast],JSON_UNESCAPED_UNICODE));
        } else prosesLayanan($db,$_POST['aksi'] ?? '',(int)($_POST['pinjaman_id'] ?? 0),$files);
        $db->commit();$_SESSION['success']='Tindakan berhasil dicatat.';
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($files as $file) hapusFotoUpload($file);
        error_log('Layanan pinjaman: '.$e->getMessage());
        $_SESSION['error']=$e instanceof PDOException?'Data gagal disimpan. Periksa apakah pengajuan sudah ada atau migrasi belum lengkap.':$e->getMessage();
    }
    header('Location: '.($tab==='lunas'?'laporan_lunas.php':($tab==='denda' && basename($_SERVER['PHP_SELF'])==='laporan_denda.php'?'laporan_denda.php':'layanan.php')).'?tab='.$tab.($id?'&id='.$id:''));exit;
}
$rows=[];$settings=[];$selected=null;$list=[];
if ($siap) {
    perbaruiDendaOtomatis($db,true);
    $settings=$db->query('SELECT * FROM layanan_pengaturan WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $rows=layananDaftar($db);
    foreach ($rows as $r) if ((int)$r['id']===$id) $selected=$r;
    // Sisa tagihan mengurangi denda yang sudah dibayar terpisah.
    $dendaPaid=$db->query("SELECT b.pinjaman_id,SUM(b.jumlah) jumlah FROM layanan_bayar_denda b JOIN angsuran a ON a.id=b.angsuran_id WHERE a.status<>'lunas' GROUP BY b.pinjaman_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($rows as &$r) {
        $r['sisa_tagihan']=max(0,$r['sisa_tagihan']-($dendaPaid[$r['id']] ?? 0)-layananKreditLelang($db,$r['id']));
        $r['sisa_denda']=max(0,$r['sisa_denda']-($dendaPaid[$r['id']] ?? 0));
        if ((int)$r['id']===$id) $selected=$r;
    } unset($r);
}
if ($siap && $tab==='denda' && !isAdmin()) {
    $owned=$db->prepare('SELECT DISTINCT pinjaman_id FROM layanan_bayar_denda WHERE user_id=?');
    $owned->execute([$_SESSION['user_id']]);$owned=array_flip($owned->fetchAll(PDO::FETCH_COLUMN));
    $rows=array_values(array_filter($rows,function($r)use($owned){return (int)$r['dicairkan_oleh']===(int)$_SESSION['user_id'] || isset($owned[$r['id']]);}));
    $selected=null;foreach($rows as $r)if((int)$r['id']===$id)$selected=$r;
}
$q=trim($_GET['q'] ?? '');$hari=$tab==='lunas'?0:(int)($_GET['hari'] ?? 0);
$totalMacet=0;$uangMacet=0;$lancar=0;$totalAktif=0;$lunas=0;
foreach ($rows as $r) {
    if ($r['status']==='lunas') {$lunas++;continue;}
    $totalAktif++;
    if ($r['hari_telat']>=7) {$totalMacet++;$uangMacet+=$r['sisa_tagihan'];}
    if ($r['hari_telat']<=3) $lancar++;
}
foreach ($rows as $r) {
    if ($q!=='' && stripos($r['nama_lengkap'].' '.$r['ktp'].' '.$r['kode_anggota'].' '.$r['kode_pinjaman'],$q)===false) continue;
    if ($tab==='lunas' && $r['status']!=='lunas') continue;
    if ($tab!=='lunas' && $r['status']==='lunas') continue;
    if ($tab==='denda' && $r['sisa_denda']<=0) continue;
    if ($hari && ($hari===90 ? $r['hari_telat']<=90 : $r['hari_telat']<$hari)) continue;
    $list[]=$r;
}
if (isset($_GET['export']) && $siap) {
    if (!isAdmin()) { http_response_code(403);exit('Export hanya untuk Admin.'); }
    if ($_GET['export']==='pdf') {
        require_once __DIR__.'/../tcpdf/tcpdf.php';
        $pdf=new TCPDF('L','mm','A4',true,'UTF-8',false);
        $pdf->setPrintHeader(false);$pdf->setPrintFooter(true);$pdf->SetCreator(APP_NAME);
        $pdf->SetTitle('Laporan '.ucfirst($tab));$pdf->AddPage();$pdf->SetFont('dejavusans','',8);
        $html='<h2>Laporan '.layananEscape(ucfirst($tab)).'</h2><p>Tanggal: '.date('d-m-Y').' · Pencarian: '.layananEscape($q).' · Minimum telat: '.$hari.' hari</p><table border="1" cellpadding="4"><thead><tr><th>Nama</th><th>Pinjaman</th><th>Pinjam</th><th>Sisa</th><th>Jatuh tempo</th><th>Telat</th><th>Denda</th><th>Jaminan</th><th>Status</th></tr></thead><tbody>';
        foreach($list as $r) $html.='<tr><td>'.layananEscape($r['nama_lengkap']).'</td><td>'.layananEscape($r['kode_pinjaman']).'</td><td>'.formatRupiah($r['jumlah_pinjaman']).'</td><td>'.formatRupiah($r['sisa_tagihan']).'</td><td>'.layananEscape($r['jatuh_tempo'] ?: $r['tanggal_lunas']).'</td><td>'.(int)$r['hari_telat'].'</td><td>'.formatRupiah($r['sisa_denda']).'</td><td>'.layananEscape($r['status_jaminan'] ?: 'Tanpa jaminan').'</td><td>'.layananEscape($r['status']==='lunas'?'Lunas':statusKolektibilitas($r['hari_telat'])[0]).'</td></tr>';
        $html.='</tbody></table>';$pdf->writeHTML($html);$pdf->Output('laporan-'.$tab.'-'.date('Y-m-d').'.pdf','D');exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="laporan-'.$tab.'-'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    if ($_GET['export']==='bonus') {
        fputcsv($out,['Nama','Pinjaman','Tahun','Bunga dasar','Persen','Bonus','Metode','Admin','Tanggal'],';');
        $rs=$db->query('SELECT b.*,a.nama_lengkap,p.kode_pinjaman,u.nama_lengkap admin FROM layanan_bonus b JOIN pinjaman p ON p.id=b.pinjaman_id JOIN anggota a ON a.id=b.anggota_id JOIN users u ON u.id=b.user_id ORDER BY b.id DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach($rs as $r){$data=[$r['nama_lengkap'],$r['kode_pinjaman'],$r['tahun'],$r['bunga_dasar'],$r['persen'],$r['jumlah'],$r['metode'],$r['admin'],$r['created_at']];foreach($data as &$v)if(preg_match('/^[=+@\-\t\r]/',(string)$v))$v="'".$v;unset($v);fputcsv($out,$data,';');} fclose($out);exit;
    }
    if ($_GET['export']==='denda' || ($tab==='denda' && $_GET['export']==='1')) {
        fputcsv($out,['Nama','Pinjaman','Telat hari','Denda awal','Diskon','Denda akhir','Bayar','Status','Metode','Teller','Tanggal','Approval'],';');
        $riwayat=$db->query('SELECT b.*,ag.nama_lengkap,p.kode_pinjaman,u.nama_lengkap teller,a.status angsuran_status,a.denda denda_sekarang,(SELECT COALESCE(SUM(x.jumlah),0) FROM layanan_bayar_denda x WHERE x.angsuran_id=a.id) total_bayar FROM layanan_bayar_denda b JOIN angsuran a ON a.id=b.angsuran_id JOIN pinjaman p ON p.id=b.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id JOIN users u ON u.id=b.user_id ORDER BY b.id DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach($riwayat as $r){$data=[$r['nama_lengkap'],$r['kode_pinjaman'],$r['hari_telat'],$r['denda_awal'],max(0,$r['denda_awal']-$r['denda_akhir']),$r['denda_akhir'],$r['jumlah'],($r['angsuran_status']==='lunas' || $r['total_bayar']+0.005>=$r['denda_sekarang'])?'Lunas':'Belum',$r['metode'],$r['teller'],$r['created_at'],$r['approved_by']?'Disetujui':'Menunggu'];foreach($data as &$v)if(preg_match('/^[=+@\-\t\r]/',(string)$v))$v="'".$v;unset($v);fputcsv($out,$data,';');}
        $belum=$db->query("SELECT a.*,p.jumlah_pinjaman,p.kode_pinjaman,ag.nama_lengkap,jp.memerlukan_jaminan FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id JOIN jenis_pinjaman jp ON jp.id=p.jenis_pinjaman_id WHERE a.status<>'lunas' AND (a.denda>0 OR a.denda_manual=1) AND NOT EXISTS(SELECT 1 FROM layanan_bayar_denda b WHERE b.angsuran_id=a.id) ORDER BY a.tanggal_jatuh_tempo")->fetchAll(PDO::FETCH_ASSOC);
        foreach($belum as $r){$d=hitungDendaAngsuran($db,$r,$r['jumlah_pinjaman']);$data=[$r['nama_lengkap'],$r['kode_pinjaman'],$d['hari_telat'],$d['denda'],max(0,$d['denda']-$r['denda']),$r['denda'],0,$r['denda']>0?'Belum':'Lunas (pemutihan)','','','','Belum dibayar'];foreach($data as &$v)if(preg_match('/^[=+@\-\t\r]/',(string)$v))$v="'".$v;unset($v);fputcsv($out,$data,';');}
        fclose($out);exit;
    }
    fputcsv($out,['Nama','NIK','Pinjaman','Jumlah pinjam','Sisa tagihan','Jatuh tempo','Telat hari','Denda tersisa','Nilai jaminan','Status'],';');
    foreach ($list as $r) {
        $data=[$r['nama_lengkap'],$r['ktp'],$r['kode_pinjaman'],$r['jumlah_pinjaman'],$r['sisa_tagihan'],$r['jatuh_tempo'],$r['hari_telat'],$r['sisa_denda'],$r['nilai_taksiran'],$r['status']==='lunas'?'Lunas':statusKolektibilitas($r['hari_telat'])[0]];
        foreach ($data as &$v) if (preg_match('/^[=+@\-\t\r]/',(string)$v)) $v="'".$v;unset($v);
        fputcsv($out,$data,';');
    } fclose($out);exit;
}
function layananForm($aksi,$id,$judul,$fields='',$warna='primary') {
    $expandable=$aksi!=='simpan_pengaturan';
    if($expandable)echo '<details class="service-action"><summary>'.layananEscape($judul).'</summary>';
    echo '<form method="post" enctype="multipart/form-data" class="service-action-form border rounded p-3 mb-3">'.layananToken().'<input type="hidden" name="aksi" value="'.layananEscape($aksi).'"><input type="hidden" name="pinjaman_id" value="'.(int)$id.'"><h6 class="'.($expandable?'visually-hidden':'').'">'.layananEscape($judul).'</h6>'.$fields.'<button class="btn btn-'.$warna.' mt-2">'.layananEscape($judul).'</button></form>';
    if($expandable)echo '</details>';
}
$catatanField='<label class="form-label">Catatan / alasan</label><textarea name="catatan" class="form-control" minlength="5" maxlength="2000" required></textarea>';
$buktiField='<label class="form-label">Foto bukti (wajib, maks. 5 MB)</label><input type="file" name="bukti" class="form-control mb-2" accept="image/jpeg,image/png,image/webp" required>';
$metodeField='<label class="form-label">Metode</label><select name="metode" class="form-select mb-2"><option value="cash">Cash</option><option value="transfer">Transfer</option></select>';
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Layanan Pinjaman</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>@media print{nav,aside,.sidebar,form,.no-print,header{display:none!important}main{width:100%!important;margin:0!important}.table{font-size:10px}}canvas.signature{touch-action:none;border:1px solid #777;max-width:100%}.bar{height:20px;background:#dc3545;min-width:1px}</style></head><body>
<?php include __DIR__.'/header.php'; ?><div class="container-fluid"><div class="row"><?php include __DIR__.'/sidebar.php'; ?><main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-3">
<header class="service-page-heading"><div><span class="service-eyebrow">PINJAMAN ANGGOTA</span><h1 class="h3">Layanan pinjaman</h1><p class="text-muted mb-0">Pantau tagihan, tindak lanjuti keterlambatan, dan proses pelunasan.</p></div><a class="btn btn-primary" href="angsuran.php"><i class="fas fa-plus me-1" aria-hidden="true"></i> Input pembayaran</a></header>
<nav class="nav nav-pills gap-2 my-3 no-print service-tabs" aria-label="Bagian layanan pinjaman"><?php foreach(['macet'=>['Nasabah & Macet','fa-users'],'denda'=>['Denda','fa-clock'],'lunas'=>['Laporan Lunas','fa-check-circle']] as $key=>$info): ?><a class="nav-link <?= $tab===$key?'active':'' ?>" <?= $tab===$key?'aria-current="page"':'' ?> href="<?= $key==='lunas'?'laporan_lunas.php':'layanan.php?tab='.$key ?>"><i class="fas <?= $info[1] ?> me-1" aria-hidden="true"></i><?= $info[0] ?></a><?php endforeach; ?><?php if(isAdmin()): ?><a class="nav-link <?= $tab==='pengaturan'?'active':'' ?>" <?= $tab==='pengaturan'?'aria-current="page"':'' ?> href="?tab=pengaturan"><i class="fas fa-sliders-h me-1" aria-hidden="true"></i>Pengaturan</a><?php endif; ?></nav>
<?php if(in_array($tab, ['macet', 'denda'], true)): ?><div class="alert alert-warning d-flex align-items-center gap-2" role="status"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i><strong>Dalam Pengembangan</strong><span>Fitur <?= $tab === 'denda' ? 'denda' : 'macet dan layanan' ?> masih dalam tahap penyempurnaan.</span></div><?php endif; ?>
<?php foreach(['success'=>'success','error'=>'danger'] as $key=>$color) if(isset($_SESSION[$key])): ?><div class="alert alert-<?= $color ?>"><?= layananEscape($_SESSION[$key]) ?></div><?php unset($_SESSION[$key]);endif; ?>
<?php if(!$siap): ?><div class="alert alert-warning">Database perlu diperbarui. Jalankan database/migrations/MIGRASI_UNIVERSAL.sql.</div>
<?php else: ?>
<div class="service-kpi-grid">
<div class="service-kpi"><span><i class="fas fa-exclamation-circle" aria-hidden="true"></i> Pinjaman macet</span><strong><?= $totalMacet ?></strong><small>Terlambat minimal 7 hari</small></div>
<div class="service-kpi"><span><i class="fas fa-wallet" aria-hidden="true"></i> Total uang macet</span><strong><?= formatRupiah($uangMacet) ?></strong><small>Sisa pokok, bunga, dan denda</small></div>
<div class="service-kpi"><span><i class="fas fa-check-circle" aria-hidden="true"></i> Pinjaman lancar</span><strong><?= $lancar ?> <small>/ <?= $totalAktif ?> aktif</small></strong><small>Keterlambatan 0–3 hari</small></div>
<div class="service-kpi"><span><i class="fas fa-flag-checkered" aria-hidden="true"></i> Sudah lunas</span><strong><?= $lunas ?></strong><small>Pinjaman selesai</small></div>
</div>
<?php if($tab==='pengaturan'): ?>
<?php include __DIR__.'/layanan_settings_panel.php'; ?>
<?php else: ?>
<section class="card service-section"><div class="card-header"><h2 class="h5 mb-0"><?= $tab==='lunas'?'Laporan pelunasan':($tab==='denda'?'Tagihan denda':'Data nasabah & angsuran') ?></h2><span class="badge bg-primary"><?= count($list) ?> pinjaman</span></div><div class="card-body">
<form method="get" class="row g-2 my-3 no-print"><input type="hidden" name="tab" value="<?= $tab ?>"><div class="col-md-6"><label for="layanan_cari" class="form-label">Cari nasabah / pinjaman</label><input id="layanan_cari" name="q" class="form-control" placeholder="Cari NIK / Nama / Kode pinjaman" value="<?= layananEscape($q) ?>"></div><?php if($tab==='macet'): ?><div class="col-md-3"><label for="layanan_hari" class="form-label">Keterlambatan</label><select id="layanan_hari" name="hari" class="form-select"><option value="0">Semua keterlambatan</option><?php foreach([7,14,30,90] as $d): ?><option value="<?= $d ?>" <?= $hari===$d?'selected':'' ?>><?= $d===90?'>90':'≥'.$d ?> hari</option><?php endforeach; ?></select></div><?php endif; ?><div class="col service-search-action"><button class="btn btn-primary">Cari</button><a class="btn btn-outline-secondary" href="?tab=<?= $tab ?>">Reset</a></div></form>
<div class="my-3 no-print"><button type="button" class="btn btn-outline-secondary" onclick="window.print()">Cetak / Simpan PDF</button> <?php if(isAdmin()): ?><a class="btn btn-outline-success" href="?<?= layananEscape(http_build_query(['tab'=>$tab,'hari'=>$hari,'q'=>$q,'export'=>1])) ?>">Export Excel (CSV)</a> <a class="btn btn-outline-danger" href="?<?= layananEscape(http_build_query(['tab'=>$tab,'hari'=>$hari,'q'=>$q,'export'=>'pdf'])) ?>">Download PDF</a><?php endif; ?></div>
<?php if($tab==='macet'): ?><div class="table-responsive"><table class="table table-striped align-middle service-loan-table"><thead><tr><th>Nama / Pinjaman</th><th>Pinjam</th><th>Total Balik</th><th>Sisa</th><th>Jatuh Tempo</th><th>Telat</th><th>Denda</th><th>Jaminan</th><th>Status</th><th class="no-print">Aksi</th></tr></thead><tbody>
<?php foreach($list as $r): $status=$r['status']==='lunas'?['Lunas','#0d6efd']:statusKolektibilitas($r['hari_telat']); ?><tr><td><?= layananEscape($r['nama_lengkap']) ?><br><small><?= layananEscape($r['kode_pinjaman']) ?></small></td><td><?= formatRupiah($r['jumlah_pinjaman']) ?></td><td><?= formatRupiah($r['total_balik']) ?></td><td><?= formatRupiah($r['sisa_tagihan']) ?></td><td><?= !empty($r['jatuh_tempo'])?layananEscape(date('d/m/Y',strtotime($r['jatuh_tempo']))):(!empty($r['tanggal_lunas'])?layananEscape(date('d/m/Y',strtotime($r['tanggal_lunas']))):'—') ?></td><td><?= (int)$r['hari_telat'] ?> hari</td><td><?= formatRupiah($r['sisa_denda']) ?></td><td><?= $r['memerlukan_jaminan']?formatRupiah($r['nilai_taksiran']).'<br>'.layananEscape($r['status_jaminan']):'Tanpa jaminan' ?></td><td><span class="badge" style="background:<?= $status[1] ?>"><?= $status[0] ?></span></td><td class="no-print"><a class="btn btn-sm btn-primary" href="?tab=<?= $tab ?>&id=<?= (int)$r['id'] ?>#detail">Detail</a> <a class="btn btn-sm btn-success" href="angsuran.php?pinjaman_id=<?= (int)$r['id'] ?>#daftar-angsuran">Bayar</a></td></tr><?php endforeach; ?>
<?php if(!$list): ?><tr><td colspan="10" class="text-center">Data tidak ditemukan.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
<?php if($tab==='denda') include __DIR__.'/denda_report_panel.php'; ?>
<?php if($tab==='lunas'): ?>
<div class="table-responsive"><table class="table"><thead><tr><th>Nama</th><th>Tgl Lunas</th><th>Tipe</th><th>Total Pinjam</th><th>Bunga Didapat</th><th>Jaminan</th><th>Teller Pelunasan</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($list as $r):
$stmt=$db->prepare("SELECT COALESCE(SUM(bunga),0) FROM angsuran WHERE pinjaman_id=? AND status='lunas'");$stmt->execute([$r['id']]);$bunga=(float)$stmt->fetchColumn();
$stmt=$db->prepare("SELECT diskon_persen,(SELECT COALESCE(SUM(bunga),0) FROM angsuran WHERE pinjaman_id=? AND cara_bayar='pelunasan') bunga FROM layanan_pelunasan WHERE pinjaman_id=? AND status='selesai'");$stmt->execute([$r['id'],$r['id']]);$pl=$stmt->fetch(PDO::FETCH_ASSOC);if($pl)$bunga-=round($pl['bunga']*$pl['diskon_persen']/100,2);
$stmt=$db->prepare("SELECT u.nama_lengkap FROM layanan_pembayaran b JOIN users u ON u.id=b.user_id WHERE b.pinjaman_id=? AND b.dibatalkan_at IS NULL ORDER BY b.id DESC LIMIT 1");$stmt->execute([$r['id']]);$tellerLunas=$stmt->fetchColumn(); ?>
<tr><td><?= layananEscape($r['nama_lengkap']) ?></td><td><?= layananEscape($r['tanggal_lunas']) ?></td><td><?= $pl?'Dipercepat':'Normal' ?></td><td><?= formatRupiah($r['jumlah_pinjaman']) ?></td><td><?= formatRupiah($bunga) ?></td><td><?= layananEscape($r['status_jaminan'] ?: 'Tanpa jaminan') ?></td><td><?= layananEscape($tellerLunas ?: 'Data lama') ?></td><td><a href="cetak_bukti_pelunasan.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">Surat Lunas</a> · <a href="?tab=lunas&id=<?= (int)$r['id'] ?>#detail">Detail / BAST</a></td></tr><?php endforeach; ?></tbody></table></div>

<?php endif; ?>
</div></section>
<?php if($selected): $p=$selected; ?>
<section id="detail" class="card my-4"><div class="card-header"><h2 class="h5">Detail <?= layananEscape($p['nama_lengkap']) ?> · <?= layananEscape($p['kode_pinjaman']) ?></h2></div><div class="card-body">
<a class="btn btn-outline-primary mb-3" href="detail_anggota.php?id=<?= (int)$p['anggota_id'] ?>">Lihat KTP, Foto Pencairan & Jaminan</a>
<?php if(isAdmin() || $tab==='denda'): $pesan=$p['status']==='lunas'?$settings['wa_lunas']:$settings['wa_denda'];$pesan=strtr($pesan,['{nama}'=>$p['nama_lengkap'],'{hari}'=>$p['hari_telat'],'{denda}'=>number_format($p['sisa_denda'],0,',','.'),'{norek}'=>$p['kode_pinjaman']]);echo tombolWhatsApp($p['telepon'],$pesan,$p['status']==='lunas'?'WA Lunas':'WA Tagih');endif; ?>
<?php
$stmt=$db->prepare('SELECT a.*,COALESCE(b.dibayar,0) denda_dibayar FROM angsuran a LEFT JOIN (SELECT angsuran_id,SUM(jumlah) dibayar FROM layanan_bayar_denda GROUP BY angsuran_id) b ON b.angsuran_id=a.id WHERE a.pinjaman_id=? ORDER BY a.angsuran_ke');$stmt->execute([$id]);$histori=$stmt->fetchAll(PDO::FETCH_ASSOC);
$options='';foreach($histori as $a) if($a['status']!=='lunas') $options.='<option value="'.(int)$a['id'].'">Cicilan '.(int)$a['angsuran_ke'].' · Denda '.formatRupiah($a['denda']).' · Dibayar '.formatRupiah($a['denda_dibayar']).'</option>';
$pilihAngsuran='<label>Angsuran</label><select name="angsuran_id" class="form-select mb-2" required>'.$options.'</select>';
$dendaData=[];
foreach ($histori as $ha) {
    $dh=hitungDendaAngsuran($db,$ha,$p['jumlah_pinjaman']);
    $dendaData[$ha['id']]=['hari'=>$dh['hari_telat'],'tarif'=>$dh['per_hari'],'total'=>(float)$ha['denda'],'sisa'=>max(0,$ha['denda']-$ha['denda_dibayar'])];
}
$dendaRingkasan='<label>Nama nasabah</label><input class="form-control mb-2" readonly value="'.layananEscape($p['nama_lengkap']).'">'
    .'<div class="denda-auto-fields" data-denda="'.layananEscape(json_encode($dendaData)).'">'
    .'<label>Telat (hari)</label><input class="form-control mb-2" data-denda-field="hari" readonly>'
    .'<label>Tarif denda / hari (Rp)</label><input class="form-control mb-2" data-denda-field="tarif" readonly>'
    .'<label>Total denda (Rp)</label><input class="form-control mb-2" data-denda-field="total" readonly>'
    .'<label>Sisa denda (Rp)</label><input class="form-control mb-2" data-denda-field="sisa" readonly></div>'
    .'<label>Diskon</label><input class="form-control mb-2" value="Hanya melalui keputusan Admin" disabled>';

?>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Ke</th><th>Jatuh tempo</th><th>Pokok + bunga</th><th>Denda</th><th>Denda dibayar terpisah</th><th>Status</th><th>Tanggal bayar</th></tr></thead><tbody><?php foreach($histori as $a): ?><tr><td><?= (int)$a['angsuran_ke'] ?></td><td><?= layananEscape($a['tanggal_jatuh_tempo']) ?></td><td><?= formatRupiah($a['jumlah_angsuran']) ?></td><td><?= formatRupiah($a['denda']) ?></td><td><?= formatRupiah($a['denda_dibayar']) ?></td><td><?= layananEscape($a['status']) ?></td><td><?= layananEscape($a['tanggal_bayar']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<div class="no-print">
<?php include __DIR__.'/layanan_detail_lanjutan.php'; ?>
<?php if($p['status']!=='lunas'): ?>
<?php if($p['hari_telat']>0) layananForm('lapor_macet',$id,($tab==='denda'?'Catat Keterlambatan':'Buat Laporan Macet'),$catatanField,'warning'); ?>
<?php if($options) layananForm('bayar_denda',$id,'Bayar Denda',$pilihAngsuran.$dendaRingkasan.'<label>Nominal bayar denda</label><input name="jumlah" class="form-control mb-2" type="number" min="0.01" step="0.01" required>'.$metodeField.$buktiField,'success'); ?>
<?php if(isAdmin()): ?>
<?php layananForm('diskon_denda',$id,'Diskon / Pemutihan Denda',$pilihAngsuran.'<label>Total denda akhir (termasuk yang sudah dibayar)</label><input type="number" name="jumlah" class="form-control" min="0" step="0.01" required>'.$catatanField,'warning'); ?>
<?php layananForm('reschedule',$id,'Perpanjang Jadwal','<label>Jatuh tempo baru cicilan belum lunas pertama</label><input name="tanggal" type="date" class="form-control" min="'.date('Y-m-d',strtotime('+1 day')).'" required>'.$catatanField); ?>
<?php if($p['memerlukan_jaminan']) layananForm('eksekusi',$id,'Putuskan Eksekusi Jaminan',$catatanField,'danger'); ?>
<?php endif; ?>
<?php $stmt=$db->prepare('SELECT * FROM layanan_pelunasan WHERE pinjaman_id=?');$stmt->execute([$id]);$approval=$stmt->fetch(PDO::FETCH_ASSOC); ?>
<?php if(!$approval || $approval['status']==='ditolak'): layananForm('ajukan_lunas',$id,'Ajukan Pelunasan Dipercepat',$catatanField);else: ?>
<p>Status pengajuan pelunasan: <strong><?= layananEscape($approval['status']) ?></strong></p>
<?php if(isAdmin() && $approval['status']==='menunggu'): ?>
<?php layananForm('approve_lunas',$id,'Approve Pelunasan','<label>Diskon sisa bunga (%)</label><input name="diskon" type="number" class="form-control" min="0" max="100" step="0.01" value="'.layananEscape($settings['diskon_pelunasan']).'" required>'.$catatanField,'success');layananForm('tolak_lunas',$id,'Tolak Pelunasan','<input type="hidden" name="diskon" value="0">'.$catatanField,'danger'); ?>
<?php endif; if($approval['status']==='disetujui'): $sisa=layananSisa($db,$id,$approval['diskon_persen']); ?>
<div class="alert alert-info">Sisa pokok <?= formatRupiah($sisa['pokok']) ?> + bunga <?= formatRupiah($sisa['bunga']) ?> − diskon <?= formatRupiah($sisa['diskon_bunga']) ?> + denda tersisa <?= formatRupiah(max(0,$sisa['denda']-$sisa['denda_dibayar'])) ?> = <?= formatRupiah($sisa['total']) ?></div>
<?php layananForm('proses_lunas',$id,'Proses Pelunasan','<label>Total bayar</label><input type="number" class="form-control mb-2" name="jumlah" value="'.layananEscape($sisa['total']).'" readonly>'.$metodeField.$buktiField.($p['memerlukan_jaminan']?'<label><input type="checkbox" name="cek_fisik" required> Fisik jaminan telah diperiksa dan tersedia</label>':''),'success'); ?>
<?php endif;endif; ?>
<?php else: ?>
<a class="btn btn-success my-2" target="_blank" rel="noopener" href="cetak_bukti_pelunasan.php?id=<?= $id ?>">Cetak Surat Lunas</a>
<?php $stmt=$db->prepare('SELECT * FROM layanan_prioritas WHERE pinjaman_id=?');$stmt->execute([$id]);$prioritas=$stmt->fetch(PDO::FETCH_ASSOC);if(!$prioritas): layananForm('prioritas',$id,'Ajukan Anggota Prioritas');else: ?><p>Prioritas: <?= layananEscape($prioritas['status']) ?></p><?php if(isAdmin() && $prioritas['status']==='menunggu') layananForm('approve_prioritas',$id,'Approve Anggota Prioritas');endif; ?>
<?php if($p['memerlukan_jaminan']): $stmt=$db->prepare('SELECT * FROM layanan_jaminan_kembali WHERE pinjaman_id=?');$stmt->execute([$id]);$kembali=$stmt->fetch(PDO::FETCH_ASSOC); ?>
<?php if(!$kembali): layananForm('ajukan_jaminan',$id,'Ajukan Pengembalian Jaminan');else: ?><p>Pengembalian jaminan: <strong><?= layananEscape($kembali['status']) ?></strong></p>
<?php if(isAdmin() && $kembali['status']==='menunggu') layananForm('approve_jaminan',$id,'Approve Pengembalian Jaminan'); ?>
<?php if($kembali['status']==='disetujui') layananForm('serahkan_jaminan',$id,'Serahkan Jaminan',$buktiField.'<label class="d-block my-2"><input type="checkbox" name="cek_fisik" required> Jaminan sudah diserahkan kembali dan diperiksa bersama nasabah</label><p>Tanda tangan nasabah</p><canvas class="signature" width="500" height="180"></canvas><input type="hidden" name="ttd"><button type="button" class="clear-sign btn btn-sm btn-outline-secondary d-block">Hapus tanda tangan</button>','success'); ?>
<?php if($kembali['status']==='diserahkan'): ?><a class="btn btn-outline-primary" href="kwitansi_layanan.php?jenis=bast&id=<?= (int)$kembali['id'] ?>" target="_blank" rel="noopener">Cetak BAST</a><?php endif;endif;endif; ?>
<?php endif; ?>
</div>
<h3 class="h5 mt-3">Bukti pembayaran & denda</h3>
<?php
$stmt=$db->prepare('SELECT b.*,u.nama_lengkap teller FROM layanan_bayar_denda b JOIN users u ON u.id=b.user_id WHERE b.pinjaman_id=?'.(isAdmin()?'':' AND b.user_id=?').' ORDER BY b.id DESC');$stmt->execute(isAdmin()?[$id]:[$id,$_SESSION['user_id']]);
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $b): ?>
<div class="border rounded p-2 mb-2">Denda <?= formatRupiah($b['jumlah']) ?> · <?= layananEscape($b['teller']) ?> · <?= layananEscape($b['created_at']) ?> · <?= $b['approved_by']?'Disetujui':'Menunggu review' ?> <a target="_blank" rel="noopener" href="kwitansi_layanan.php?jenis=denda&id=<?= (int)$b['id'] ?>">Kwitansi & Bukti</a>
<?php if(isAdmin() && !$b['approved_by']) layananForm('approve_denda',$id,'Approve Pembayaran Denda','<input type="hidden" name="bayar_id" value="'.(int)$b['id'].'">'); ?></div><?php endforeach; ?>
<?php $stmt=$db->prepare('SELECT * FROM layanan_pembayaran WHERE pinjaman_id=? AND dibatalkan_at IS NULL ORDER BY id DESC');$stmt->execute([$id]);foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $b): ?><p><?= layananEscape($b['jenis']) ?> · <?= formatRupiah($b['jumlah']) ?> · <?= layananEscape($b['metode']) ?> · <a target="_blank" rel="noopener" href="kwitansi_layanan.php?jenis=bayar&id=<?= (int)$b['id'] ?>">Kwitansi & Bukti</a></p><?php endforeach; ?>
<h3 class="h5 mt-3">Laporan Macet</h3>
<?php $stmt=$db->prepare('SELECT l.*,u.nama_lengkap teller FROM layanan_laporan_macet l JOIN users u ON u.id=l.user_id WHERE l.pinjaman_id=?'.(isAdmin()?'':' AND l.user_id=?').' ORDER BY l.id DESC');$stmt->execute(isAdmin()?[$id]:[$id,$_SESSION['user_id']]);foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $lap): ?>
<div class="border rounded p-3 mb-3"><strong><?= layananEscape($lap['status']) ?></strong> · <?= layananEscape($lap['teller']) ?> · <?= layananEscape($lap['created_at']) ?><p><?= layananEscape($lap['catatan']) ?></p><p><?= layananEscape($lap['keputusan']) ?></p>
<?php if(isAdmin() && $lap['status']==='menunggu') layananForm('keputusan_macet',$id,'Simpan Keputusan','<input type="hidden" name="laporan_id" value="'.(int)$lap['id'].'"><select class="form-select" name="keputusan"><option value="ditindaklanjuti">Ditindaklanjuti</option><option value="selesai">Selesai</option><option value="ditolak">Ditolak</option></select>'.$catatanField); ?></div><?php endforeach; ?>
<?php if(isAdmin()): ?><h3 class="h5">Log Aktivitas</h3><?php $stmt=$db->prepare('SELECT a.*,u.nama_lengkap FROM layanan_audit a JOIN users u ON u.id=a.user_id WHERE a.pinjaman_id=? ORDER BY a.id DESC LIMIT 100');$stmt->execute([$id]);foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $log): ?><p class="small"><?= layananEscape($log['created_at'].' · '.$log['nama_lengkap'].' · '.$log['aksi'].' · '.$log['detail']) ?></p><?php endforeach;endif; ?>
</div></section><?php endif; ?>
<?php include __DIR__.'/layanan_followup_panel.php'; ?>
<?php endif;endif; ?>
</main></div></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script><script src="../assets/js/tanda-tangan-layanan.js"></script><script src="../assets/js/denda-form.js?v=1"></script></body></html>

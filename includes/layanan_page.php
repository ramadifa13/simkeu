<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/layanan_actions.php';
if (!isLoggedIn()) { header('Location: ../login.php'); exit; }
if (!isAdmin() && !isTeller()) { http_response_code(403); exit('Akses ditolak.'); }

function layananStatusBadge($status) {
    $status = strtolower(trim((string)$status));
    $map = [
        'disetujui' => ['Disetujui', 'success'],
        'diserahkan' => ['Diserahkan', 'success'],
        'menunggu' => ['Menunggu persetujuan admin', 'warning'],
        'pengecekan' => ['Dalam pengecekan', 'warning'],
        'koreksi' => ['Dalam koreksi', 'warning'],
        'koreksi_prioritas' => ['Dalam koreksi', 'warning'],
        'ditolak' => ['Ditolak', 'danger'],
        'jaminan_belum_diambil' => ['Jaminan belum diambil', 'warning'],
        'jaminan_siap_diambil' => ['Siap diambil', 'info'],
        'jaminan_dikembalikan' => ['Jaminan sudah dikembalikan', 'success'],
    ];
    return $map[$status] ?? [ucfirst($status ?: 'Tidak diketahui'), 'secondary'];
}

function layananStatusBadgeHtml($status) {
    [$label, $warna] = layananStatusBadge($status);
    return '<span class="badge bg-' . $warna . ($warna === 'warning' ? ' text-dark' : '') . '">' . layananEscape($label) . '</span>';
}

?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.badge').forEach(function (badge) {
        var status = badge.textContent.trim().toLowerCase();
        if (status === 'ditolak') {
            badge.classList.remove('bg-warning', 'text-dark');
            badge.classList.add('bg-danger');
        } else if (status === 'disetujui' || status === 'diserahkan') {
            badge.classList.remove('bg-warning', 'text-dark');
            badge.classList.add('bg-success');
        } else if (status === 'pengecekan' || status === 'koreksi') {
            badge.classList.remove('bg-success', 'bg-danger');
            badge.classList.add('bg-warning', 'text-dark');
        }
    });
});
</script>
<?php
$layananMode = $layananMode ?? 'list';
$tab=$_GET['tab'] ?? 'macet';
if (!in_array($tab,['macet','denda','lunas','pengaturan'],true)) $tab='macet';
if ($tab==='pengaturan' && !isAdmin()) { http_response_code(403); exit('Khusus Admin.'); }
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$siap=layananSiap($db);
$id=(int)($_GET['id'] ?? 0);
// List page tidak menumpuk detail — arahkan ke halaman detail terpisah
if ($layananMode === 'list' && $id > 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !isset($_GET['export'])) {
    header('Location: detail_layanan.php?id=' . $id . '&tab=' . rawurlencode($tab));
    exit;
}
if ($layananMode === 'detail' && $id <= 0) {
    header('Location: layanan.php?tab=' . rawurlencode($tab));
    exit;
}
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
    $postId = (int)($_POST['pinjaman_id'] ?? $id);
    if ($postId > 0) {
        header('Location: detail_layanan.php?id=' . $postId . '&tab=' . rawurlencode($tab));
    } else {
        $listTarget = ($tab==='lunas'?'laporan_lunas.php':($tab==='denda' && str_contains(basename($_SERVER['PHP_SELF'] ?? ''),'laporan_denda')?'laporan_denda.php':'layanan.php'));
        header('Location: '.$listTarget.'?tab='.$tab);
    }
    exit;
}
$rows=[];$settings=[];$selected=null;$list=[];
if ($siap) {
    layananPastikanPengaturan($db);
    // Paksa hitung ulang bila diminta dari tab Denda (uji setelah import / koreksi tarif).
    if ($tab === 'denda' && isset($_GET['hitung_ulang'])) {
        $nDenda = perbaruiDendaOtomatis($db, true);
        $_SESSION['success'] = 'Denda otomatis dihitung ulang: ' . (int) $nDenda . ' cicilan diperbarui.';
        header('Location: ' . ($id ? ('detail_layanan.php?id=' . $id . '&tab=denda') : 'layanan.php?tab=denda') . (isset($_GET['q']) && $_GET['q'] !== '' ? '&q=' . rawurlencode((string)$_GET['q']) : ''));
        exit;
    }
    perbaruiDendaOtomatis($db,true);
    $settings=$db->query('SELECT * FROM layanan_pengaturan WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: [];
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
$list_all = $list;
if (isset($_GET['export']) && $siap) {
    if (!isAdmin()) { http_response_code(403);exit('Export hanya untuk Admin.'); }
    if ($_GET['export']==='pdf') {
        require_once __DIR__.'/../tcpdf/tcpdf.php';
        $pdf=new TCPDF('L','mm','A4',true,'UTF-8',false);
        $pdf->setPrintHeader(false);$pdf->setPrintFooter(true);$pdf->SetMargins(15,15,15);$pdf->SetAutoPageBreak(true,15);$pdf->SetCreator(APP_NAME);
        $pdf->SetTitle('Laporan '.ucfirst($tab));$pdf->AddPage();$pdf->SetFont('dejavusans','',8);
        $html='<h2>Laporan '.layananEscape(ucfirst($tab)).'</h2><p>Tanggal: '.date('d-m-Y').' · Pencarian: '.layananEscape($q).' · Minimum telat: '.$hari.' hari</p><table border="1" cellpadding="4"><thead><tr><th>Nama</th><th>Pinjaman</th><th>Pinjam</th><th>Sisa</th><th>Jatuh tempo</th><th>Telat</th><th>Denda</th><th>Jaminan</th><th>Status</th></tr></thead><tbody>';
        foreach($list_all as $r) $html.='<tr><td>'.layananEscape($r['nama_lengkap']).'</td><td>'.layananEscape($r['kode_pinjaman']).'</td><td>'.formatRupiah($r['jumlah_pinjaman']).'</td><td>'.formatRupiah($r['sisa_tagihan']).'</td><td>'.layananEscape($r['jatuh_tempo'] ?: $r['tanggal_lunas']).'</td><td>'.(int)$r['hari_telat'].'</td><td>'.formatRupiah($r['sisa_denda']).'</td><td>'.layananEscape($r['status_jaminan'] ?: 'Tanpa jaminan').'</td><td>'.layananEscape($r['status']==='lunas'?'Lunas':statusKolektibilitas($r['hari_telat'])[0]).'</td></tr>';
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
        $belum=$db->query("SELECT a.*,p.jumlah_pinjaman,p.kode_pinjaman,ag.nama_lengkap,jp.memerlukan_jaminan,
            COALESCE((SELECT SUM(b.jumlah) FROM layanan_bayar_denda b WHERE b.angsuran_id=a.id),0) sudah_bayar
            FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id
            JOIN jenis_pinjaman jp ON jp.id=p.jenis_pinjaman_id
            WHERE a.status<>'lunas' AND (a.denda>0 OR a.denda_manual=1)
              AND a.denda > COALESCE((SELECT SUM(b.jumlah) FROM layanan_bayar_denda b WHERE b.angsuran_id=a.id),0)
            ORDER BY a.tanggal_jatuh_tempo")->fetchAll(PDO::FETCH_ASSOC);
        foreach($belum as $r){$d=hitungDendaAngsuran($db,$r,$r['jumlah_pinjaman']);$sisa=max(0,(float)$r['denda']-(float)$r['sudah_bayar']);$data=[$r['nama_lengkap'],$r['kode_pinjaman'],$d['hari_telat'],$d['denda'],max(0,$d['denda']-$r['denda']),$r['denda'],$r['sudah_bayar'],$sisa>0?'Belum (sisa '.number_format($sisa,0,',','.').')':'Lunas (pemutihan)','','','','Belum lunas'];foreach($data as &$v)if(preg_match('/^[=+@\-\t\r]/',(string)$v))$v="'".$v;unset($v);fputcsv($out,$data,';');}
        fclose($out);exit;
    }
    fputcsv($out,['Nama','NIK','Pinjaman','Jumlah pinjam','Sisa tagihan','Jatuh tempo','Telat hari','Denda tersisa','Nilai jaminan','Status'],';');
    foreach ($list_all as $r) {
        $data=[$r['nama_lengkap'],$r['ktp'],$r['kode_pinjaman'],$r['jumlah_pinjaman'],$r['sisa_tagihan'],$r['jatuh_tempo'],$r['hari_telat'],$r['sisa_denda'],$r['nilai_taksiran'],$r['status']==='lunas'?'Lunas':statusKolektibilitas($r['hari_telat'])[0]];
        foreach ($data as &$v) if (preg_match('/^[=+@\-\t\r]/',(string)$v)) $v="'".$v;unset($v);
        fputcsv($out,$data,';');
    } fclose($out);exit;
}
$ui_list = ui_query_params();
$sliced = ui_slice_rows($list_all, $ui_list['page'], $ui_list['per_page']);
$list = $sliced['rows'];
$list_pager = $sliced['pager'];
function layananForm($aksi,$id,$judul,$fields='',$warna='primary') {
    static $layananFormSeq = 0;
    $layananFormSeq++;
    if ($aksi==='approve_jaminan') {
        $fields .= '<label class="form-label">Keterangan Admin</label><textarea name="catatan" class="form-control" minlength="5" maxlength="2000" required></textarea>';
    }
    $warna = preg_replace('/[^a-z]/', '', strtolower((string)$warna)) ?: 'primary';
    $safeAksi = preg_replace('/[^a-z0-9_]/i', '', (string)$aksi) ?: 'aksi';
    // Pengaturan tetap inline (bukan aksi proses baris)
    if ($aksi === 'simpan_pengaturan') {
        echo '<form method="post" enctype="multipart/form-data" class="service-action-form border rounded p-3 mb-3">'.layananToken().'<input type="hidden" name="aksi" value="'.layananEscape($aksi).'"><input type="hidden" name="pinjaman_id" value="'.(int)$id.'"><h6>'.layananEscape($judul).'</h6>'.$fields.'<button type="submit" class="btn btn-'.$warna.' mt-2">'.layananEscape($judul).'</button></form>';
        return;
    }
    $modalId = 'layananModal_'.$safeAksi.'_'.(int)$id.'_'.$layananFormSeq;
    $icons = [
        'lapor_macet' => 'ti ti-alert-triangle',
        'bayar_denda' => 'ti ti-coin',
        'diskon_denda' => 'ti ti-discount-2',
        'reschedule' => 'ti ti-calendar-plus',
        'eksekusi' => 'ti ti-gavel',
        'ajukan_lunas' => 'ti ti-flag',
        'approve_lunas' => 'ti ti-check',
        'tolak_lunas' => 'ti ti-x',
        'proses_lunas' => 'ti ti-cash',
        'prioritas' => 'ti ti-star',
        'ajukan_jaminan' => 'ti ti-package',
        'approve_jaminan' => 'ti ti-package-export',
        'serahkan_jaminan' => 'ti ti-hand-click',
    ];
    $icon = $icons[$aksi] ?? 'ti ti-forms';
    echo '<button type="button" class="btn btn-outline-'.$warna.' service-action-trigger" data-bs-toggle="modal" data-bs-target="#'.layananEscape($modalId).'">';
    echo '<span class="service-action-icon"><i class="'.$icon.'" aria-hidden="true"></i></span>';
    echo '<span class="service-action-copy"><strong>'.layananEscape($judul).'</strong><small>Buka formulir</small></span>';
    echo '</button>';
    echo '<div class="modal fade" id="'.layananEscape($modalId).'" tabindex="-1" aria-labelledby="'.layananEscape($modalId).'Label" aria-hidden="true">';
    echo '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">';
    echo '<form method="post" enctype="multipart/form-data" class="service-action-form">'.layananToken();
    echo '<input type="hidden" name="aksi" value="'.layananEscape($aksi).'"><input type="hidden" name="pinjaman_id" value="'.(int)$id.'">';
    echo '<div class="modal-header border-0 pb-0"><div><div class="text-uppercase small text-muted fw-semibold">Proses layanan</div>';
    echo '<h5 class="modal-title mb-0" id="'.layananEscape($modalId).'Label"><i class="'.$icon.' me-2" aria-hidden="true"></i>'.layananEscape($judul).'</h5></div>';
    echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>';
    echo '<div class="modal-body">'.$fields.'</div>';
    echo '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>';
    echo '<button type="submit" class="btn btn-'.$warna.'"><i class="'.$icon.' me-1" aria-hidden="true"></i>'.layananEscape($judul).'</button></div>';
    echo '</form></div></div></div>';
}
$catatanField='<label class="form-label">Catatan / alasan</label><textarea name="catatan" class="form-control" minlength="5" maxlength="2000" required></textarea>';
$buktiField='<label class="form-label">Foto bukti (wajib, maks. 5 MB)</label><input type="file" name="bukti" class="form-control mb-2" accept="image/jpeg,image/png,image/webp" required>';
$buktiJaminanField='<label class="form-label">Foto bukti jaminan</label><input type="file" name="bukti_jaminan" class="form-control mb-2" accept="image/jpeg,image/png,image/webp" required><label class="form-label">Kuitansi / surat bukti lunas</label><input type="file" name="bukti_lunas" class="form-control mb-2" accept="image/jpeg,image/png,image/webp" required>';
$metodeField='<label class="form-label">Metode</label><select name="metode" class="form-select mb-2"><option value="cash">Cash</option><option value="transfer">Transfer</option></select>';
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Layanan Pinjaman</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>@media print{nav,aside,.sidebar,form,.no-print,header{display:none!important}main{width:100%!important;margin:0!important}.table{font-size:10px}}canvas.signature{touch-action:none;border:1px solid #777;max-width:100%}.bar{height:20px;background:#dc3545;min-width:1px}</style></head><body>
<?php include __DIR__.'/header.php'; ?><div class="container-fluid"><div class="row"><?php include __DIR__.'/sidebar.php'; ?><main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
<?php
if ($tab === 'lunas') {
    $detailListHref = 'laporan_lunas.php';
} elseif ($tab === 'denda') {
    $detailListHref = 'layanan.php?tab=denda';
} else {
    $detailListHref = 'layanan.php?tab=' . rawurlencode($tab);
}
?>
<?php if ($layananMode === 'detail'): ?>
<?php ui_page_header([
    'title' => 'Detail pinjaman',
    'icon' => 'fas fa-folder-open text-primary',
    'subtitle' => 'Riwayat angsuran, tindakan layanan, dan dokumen terkait',
    'actions' => '<a class="btn btn-outline-secondary" href="' . layananEscape($detailListHref) . '"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Kembali</a>'
        . '<a class="btn btn-primary" href="angsuran.php"><i class="fas fa-plus me-1" aria-hidden="true"></i>Input pembayaran</a>',
]); ?>
<?php else: ?>
<?php ui_page_header([
    'title' => $tab === 'lunas' ? 'Laporan pelunasan' : 'Layanan pinjaman',
    'icon' => $tab === 'lunas' ? 'fas fa-check-circle text-success' : 'fas fa-hand-holding-usd text-primary',
    'subtitle' => $tab === 'lunas'
        ? 'Tinjau pinjaman selesai, dokumen pelunasan, dan pengembalian jaminan'
        : 'Pantau tagihan, tindak lanjuti keterlambatan, dan proses pelunasan',
    'actions' => '<a class="btn btn-primary" href="angsuran.php"><i class="fas fa-plus me-1" aria-hidden="true"></i>Input pembayaran</a>',
]); ?>
<nav class="nav nav-pills gap-2 my-3 no-print service-tabs" aria-label="Bagian layanan pinjaman"><?php foreach(['macet'=>['Nasabah & Macet','fa-users'],'denda'=>['Denda','fa-clock'],'lunas'=>['Laporan Lunas','fa-check-circle']] as $key=>$info): ?><a class="nav-link <?= $tab===$key?'active':'' ?>" <?= $tab===$key?'aria-current="page"':'' ?> href="<?= $key==='lunas'?'laporan_lunas.php':'layanan.php?tab='.$key ?>"><i class="fas <?= $info[1] ?> me-1" aria-hidden="true"></i><?= $info[0] ?></a><?php endforeach; ?><?php if(isAdmin()): ?><a class="nav-link <?= $tab==='pengaturan'?'active':'' ?>" <?= $tab==='pengaturan'?'aria-current="page"':'' ?> href="?tab=pengaturan"><i class="fas fa-sliders-h me-1" aria-hidden="true"></i>Pengaturan</a><?php endif; ?></nav>
<?php if($tab === 'denda'): ?><div class="alert alert-info d-flex align-items-start gap-2" role="status"><i class="fas fa-info-circle mt-1" aria-hidden="true"></i><div><strong>Cara uji fitur denda:</strong> buka pinjaman yang cicilannya lewat jatuh tempo → cek kolom sisa denda → <em>Detail</em> untuk bayar denda terpisah (bukti wajib). Admin bisa Diskon/Pemutihan.</div></div><?php endif; ?>
<?php endif; ?>
<?php ui_flash(); ?>
<?php if(!$siap): ?><div class="alert alert-warning">Database perlu diperbarui. Jalankan database/migrations/MIGRASI_UNIVERSAL.sql.</div>
<?php elseif ($layananMode === 'list'): ?>
<div class="service-kpi-grid">
<div class="service-kpi"><span><i class="fas fa-exclamation-circle" aria-hidden="true"></i> Pinjaman macet</span><strong><?= $totalMacet ?></strong><small>Terlambat minimal 7 hari</small></div>
<div class="service-kpi"><span><i class="fas fa-wallet" aria-hidden="true"></i> Total uang macet</span><strong><?= formatRupiah($uangMacet) ?></strong><small>Sisa pokok, bunga, dan denda</small></div>
<div class="service-kpi"><span><i class="fas fa-check-circle" aria-hidden="true"></i> Pinjaman lancar</span><strong><?= $lancar ?> <small>/ <?= $totalAktif ?> aktif</small></strong><small>Keterlambatan 0–3 hari</small></div>
<div class="service-kpi"><span><i class="fas fa-flag-checkered" aria-hidden="true"></i> Sudah lunas</span><strong><?= $lunas ?></strong><small>Pinjaman selesai</small></div>
</div>
<?php if($tab==='pengaturan'): ?>
<?php include __DIR__.'/layanan_settings_panel.php'; ?>
<?php else: ?>
<section class="card service-section"><div class="card-header"><h2 class="h5 mb-0"><?= $tab==='lunas'?'Laporan pelunasan':($tab==='denda'?'Tagihan denda':'Data nasabah & angsuran') ?></h2><span class="badge bg-primary"><?= (int)($list_pager['total'] ?? count($list)) ?> pinjaman</span></div><div class="card-body">
<form method="get" class="row g-2 my-3 no-print align-items-end"><input type="hidden" name="tab" value="<?= $tab ?>"><div class="col-md-5"><label for="layanan_cari" class="form-label">Cari nasabah / pinjaman</label><input id="layanan_cari" name="q" class="form-control" placeholder="Cari NIK / Nama / Kode pinjaman" value="<?= layananEscape($q) ?>"></div><?php if($tab==='macet'): ?><div class="col-md-3"><label for="layanan_hari" class="form-label">Keterlambatan</label><select id="layanan_hari" name="hari" class="form-select"><option value="0">Semua keterlambatan</option><?php foreach([7,14,30,90] as $d): ?><option value="<?= $d ?>" <?= $hari===$d?'selected':'' ?>><?= $d===90?'>90':'≥'.$d ?> hari</option><?php endforeach; ?></select></div><?php endif; ?><div class="col-md-2"><label class="form-label">Per halaman</label><select name="per_page" class="form-select"><?php foreach([10,20,50] as $n): ?><option value="<?= $n ?>" <?= (int)($list_pager['per_page'] ?? 10)===$n?'selected':'' ?>><?= $n ?></option><?php endforeach; ?></select></div><div class="col service-search-action"><button class="btn btn-primary">Cari</button><a class="btn btn-outline-secondary" href="?tab=<?= $tab ?>">Reset</a></div></form>
<?php if ($tab === 'macet'): ?>
<div class="my-3 no-print"><button type="button" class="btn btn-outline-secondary" onclick="window.print()">Cetak / Simpan PDF</button> <?php if(isAdmin()): ?><a class="btn btn-outline-success" href="?<?= layananEscape(http_build_query(['tab'=>$tab,'hari'=>$hari,'q'=>$q,'export'=>1])) ?>">Export Excel (CSV)</a> <a class="btn btn-outline-danger" href="?<?= layananEscape(http_build_query(['tab'=>$tab,'hari'=>$hari,'q'=>$q,'export'=>'pdf'])) ?>">Download PDF</a><?php endif; ?></div>
<?php elseif (isAdmin() && $tab === 'denda'): ?>
<div class="my-3 no-print"><a class="btn btn-outline-success btn-sm" href="?<?= layananEscape(http_build_query(['tab'=>'denda','q'=>$q,'export'=>'denda'])) ?>">Export Excel Denda (CSV)</a></div>
<?php endif; ?>
<?php if($tab==='macet'): ?><div class="table-responsive ui-table-scroll"><table class="table table-striped align-middle service-loan-table"><thead><tr><th>Nama / Pinjaman</th><th>Pinjam</th><th>Total Balik</th><th>Sisa</th><th>Jatuh Tempo</th><th>Telat</th><th>Denda</th><th>Jaminan</th><th>Status</th><th class="no-print">Aksi</th></tr></thead><tbody>
<?php foreach($list as $r): ?><tr><td><?= layananEscape($r['nama_lengkap']) ?><br><small><?= layananEscape($r['kode_pinjaman']) ?></small></td><td><?= formatRupiah($r['jumlah_pinjaman']) ?></td><td><?= formatRupiah($r['total_balik']) ?></td><td><?= formatRupiah($r['sisa_tagihan']) ?></td><td><?= !empty($r['jatuh_tempo'])?layananEscape(formatTanggalUi($r['jatuh_tempo'])):(!empty($r['tanggal_lunas'])?layananEscape(formatTanggalUi($r['tanggal_lunas'])):'—') ?></td><td><?= (int)$r['hari_telat'] ?> hari</td><td><?= formatRupiah($r['sisa_denda']) ?></td><td><?= $r['memerlukan_jaminan']?formatRupiah($r['nilai_taksiran']).'<br>'.layananEscape($r['status_jaminan']):'Tanpa jaminan' ?></td><td><?= $r['status']==='lunas'?'<span class="badge bg-primary">Lunas</span>':statusKolektibilitasBadgeHtml($r['hari_telat']) ?></td><td class="no-print table-actions text-end"><?= ui_action_menu([
    ['label' => 'Detail', 'href' => 'detail_layanan.php?tab=' . rawurlencode($tab) . '&id=' . (int)$r['id'], 'icon' => 'fas fa-eye'],
    ['label' => 'Bayar', 'href' => 'detail_angsuran.php?pinjaman_id=' . (int)$r['id'], 'icon' => 'fas fa-money-bill-wave'],
]) ?></td></tr><?php endforeach; ?>
<?php if(!$list): ?><tr><td colspan="10" class="text-center">Data tidak ditemukan.</td></tr><?php endif; ?></tbody></table></div>
<?php if(!empty($list_pager)) ui_pagination($list_pager, basename($_SERVER['PHP_SELF'] ?? 'layanan.php')); ?>
<?php endif; ?>
<?php if($tab==='denda') include __DIR__.'/denda_report_panel.php'; ?>
<?php if($tab==='lunas'): ?>
<div class="service-table-toolbar"><div><strong>Riwayat pinjaman lunas</strong><span>Pilih detail untuk melihat angsuran, dokumen, BAST, dan aktivitas layanan.</span></div><span class="service-result-count"><?= count($list) ?> data</span></div>
<div class="table-responsive ui-table-scroll"><table class="table service-paid-table" data-empty-message="Belum ada pinjaman lunas yang sesuai dengan pencarian."><thead><tr><th>Anggota / Pinjaman</th><th>Tanggal lunas</th><th>Pelunasan</th><th>Total pinjaman</th><th>Bunga diterima</th><th>Jaminan</th><th>Teller</th><th>Status layanan</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($list as $r):
$stmt=$db->prepare("SELECT COALESCE(SUM(bunga),0) FROM angsuran WHERE pinjaman_id=? AND status='lunas'");$stmt->execute([$r['id']]);$bunga=(float)$stmt->fetchColumn();
$stmt=$db->prepare("SELECT diskon_persen,(SELECT COALESCE(SUM(bunga),0) FROM angsuran WHERE pinjaman_id=? AND cara_bayar='pelunasan') bunga FROM layanan_pelunasan WHERE pinjaman_id=? AND status='selesai'");$stmt->execute([$r['id'],$r['id']]);$pl=$stmt->fetch(PDO::FETCH_ASSOC);if($pl)$bunga-=round($pl['bunga']*$pl['diskon_persen']/100,2);
$jenisTutup=layananJenisPenutupan($db,(int)$r['id']);
$stmt=$db->prepare("SELECT u.nama_lengkap FROM layanan_pembayaran b JOIN users u ON u.id=b.user_id WHERE b.pinjaman_id=? AND b.dibatalkan_at IS NULL ORDER BY b.id DESC LIMIT 1");$stmt->execute([$r['id']]);$tellerLunas=$stmt->fetchColumn();
$stmt=$db->prepare("SELECT status FROM layanan_jaminan_kembali WHERE pinjaman_id=? UNION ALL SELECT status FROM layanan_prioritas WHERE pinjaman_id=?");$stmt->execute([$r['id'],$r['id']]);$statusLayanan=$stmt->fetchAll(PDO::FETCH_COLUMN);
$statusLayanan=array_values(array_unique(array_filter($statusLayanan)));
$statusAktual='';foreach(['ditolak','menunggu','pengecekan','koreksi_prioritas','disetujui','diserahkan'] as $statusCari){if(in_array($statusCari,$statusLayanan,true)){$statusAktual=$statusCari;break;}} if(!$statusAktual && !empty($r['memerlukan_jaminan']) && ($r['status_jaminan']??'')==='dititipkan') $statusAktual='jaminan_belum_diambil'; if($statusAktual==='disetujui' && ($r['status_jaminan']??'')==='dititipkan') $statusAktual='jaminan_siap_diambil'; if(($r['status_jaminan']??'')==='dikembalikan') $statusAktual='jaminan_dikembalikan'; ?>
<tr><td><strong class="service-member-name"><?= layananEscape($r['nama_lengkap']) ?></strong><small class="service-loan-code"><?= layananEscape($r['kode_pinjaman']) ?></small></td><td><?= !empty($r['tanggal_lunas']) ? layananEscape(formatTanggalUi($r['tanggal_lunas'])) : '—' ?></td><td><span class="badge bg-<?= layananEscape($jenisTutup[1]) ?>"><?= layananEscape($jenisTutup[0]) ?></span></td><td class="service-money"><?= formatRupiah($r['jumlah_pinjaman']) ?></td><td class="service-money"><?= formatRupiah($bunga) ?></td><td><?= layananEscape($r['status_jaminan'] ?: 'Tanpa jaminan') ?></td><td><?= layananEscape($tellerLunas ?: 'Data lama') ?></td><td><?= $statusAktual ? layananStatusBadgeHtml($statusAktual) : '<span class="badge bg-secondary">Tidak ada pengajuan</span>' ?></td><td class="no-print table-actions text-end"><?= ui_action_menu([
    ['label' => 'Cetak / Simpan PDF', 'href' => 'cetak_bukti_pelunasan.php?id=' . (int)$r['id'], 'target' => '_blank', 'icon' => 'fas fa-file-pdf', 'class' => ''],
    ['label' => 'Detail', 'href' => 'detail_layanan.php?tab=lunas&id=' . (int)$r['id'], 'icon' => 'fas fa-eye', 'class' => ''],
]) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php if(!empty($list_pager)) ui_pagination($list_pager, basename($_SERVER['PHP_SELF'] ?? 'layanan.php')); ?>
<script>
(function () {
    var icon = {dititipkan: 'fa-box-archive text-warning', dikembalikan: 'fa-box-open text-success'};
    document.querySelectorAll('.service-paid-table tbody tr').forEach(function (row) {
        var cell = row.cells[5];
        if (!cell) return;
        var label = cell.textContent.trim(), key = label.toLowerCase();
        var iconClass = icon[key] || 'fa-shield-halved text-secondary';
        cell.innerHTML = '<strong><i class="fas ' + iconClass + ' me-1" aria-hidden="true"></i>' + label + '</strong>';
    });
}());
</script>
<?php endif; ?>
</div></section>
<?php include __DIR__.'/layanan_followup_panel.php'; ?>
<?php endif; /* end pengaturan else */ ?>
<?php elseif ($layananMode === 'detail'): ?>
<?php if(!$selected): ?>
<div class="alert alert-warning">Data pinjaman tidak ditemukan. <a href="<?= layananEscape($detailListHref) ?>">Kembali ke daftar</a></div>
<?php else:
$p=$selected;
$stmt=$db->prepare('SELECT * FROM layanan_jaminan_kembali WHERE pinjaman_id=?');
$stmt->execute([$id]);
$kembali=$stmt->fetch(PDO::FETCH_ASSOC);
?>
<section id="detail" class="service-detail-view">
<div class="card mb-3 service-detail-card"><div class="card-header service-detail-header"><div><span class="service-eyebrow"><?= $p['status']==='lunas'?'DETAIL PELUNASAN':'DETAIL PINJAMAN' ?></span><h2 class="h5"><?= layananEscape($p['nama_lengkap']) ?></h2><small><?= layananEscape($p['kode_pinjaman']) ?></small></div></div><div class="card-body">
<div class="service-detail-summary" aria-label="Ringkasan pinjaman"><div><span>Status pinjaman</span><strong><i class="fas <?= $p['status']==='lunas'?'fa-circle-check':'fa-clock' ?>" aria-hidden="true"></i><?= $p['status']==='lunas'?'Lunas':layananEscape(ucfirst($p['status'])) ?></strong></div><div><span>Total pinjaman</span><strong><?= formatRupiah($p['jumlah_pinjaman']) ?></strong></div><div><span><?= $p['status']==='lunas'?'Tanggal lunas':'Sisa tagihan' ?></span><strong><?= $p['status']==='lunas' ? (!empty($p['tanggal_lunas']) ? layananEscape(formatTanggalUi($p['tanggal_lunas'])) : '—') : formatRupiah($p['sisa_tagihan']) ?></strong></div><div><span>Status jaminan</span><strong><?= layananEscape($p['status_jaminan'] ?: 'Tanpa jaminan') ?></strong></div></div>
<div class="service-detail-actions no-print"><?php if($kembali && $kembali['status']!=='ditolak'): ?><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dokumenJaminanKembaliModal"><i class="fas fa-file-circle-check me-1" aria-hidden="true"></i>Periksa dokumen pengembalian</button><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#dokumenPinjamanModal"><i class="fas fa-images me-1" aria-hidden="true"></i>Dokumen pinjaman</button><?php else: ?><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#dokumenPinjamanModal"><i class="fas fa-images me-1" aria-hidden="true"></i>Lihat dokumen</button><?php endif; ?><?php if($p['status']==='lunas'): ?><a class="btn btn-success" target="_blank" rel="noopener" href="cetak_bukti_pelunasan.php?id=<?= $id ?>"><i class="fas fa-print me-1" aria-hidden="true"></i>Cetak surat lunas</a><?php endif; ?></div>
<?php if(isAdmin() || $tab==='denda'): $pesan=$p['status']==='lunas'?$settings['wa_lunas']:$settings['wa_denda'];$pesan=strtr($pesan,['{nama}'=>$p['nama_lengkap'],'{hari}'=>$p['hari_telat'],'{denda}'=>number_format($p['sisa_denda'],0,',','.'),'{norek}'=>$p['kode_pinjaman']]);echo tombolWhatsApp($p['telepon'],$pesan,$p['status']==='lunas'?'WA Lunas':'WA Tagih');endif; ?>
</div></div>
<div class="modal fade" id="dokumenPinjamanModal" tabindex="-1" aria-labelledby="dokumenPinjamanModalLabel" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1" id="dokumenPinjamanModalLabel">Dokumen Pinjaman</h5><small class="text-muted"><?= layananEscape($p['nama_lengkap'].' · '.$p['kode_pinjaman']) ?></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-4"><div class="document-preview-card"><div class="document-preview-label">KTP Nasabah</div><?php if(!empty($p['foto_ktp'])): ?><img src="<?= layananEscape(urlFoto($p['foto_ktp'])) ?>" alt="KTP nasabah" class="document-preview-image" loading="lazy"><?php else: ?><div class="document-preview-empty">Foto KTP belum tersedia</div><?php endif; ?></div></div><div class="col-md-4"><div class="document-preview-card"><div class="document-preview-label">Foto Pencairan</div><?php if(!empty($p['foto_pencairan'])): ?><img src="<?= layananEscape(urlFoto($p['foto_pencairan'])) ?>" alt="Foto pencairan" class="document-preview-image" loading="lazy"><?php else: ?><div class="document-preview-empty">Foto pencairan belum tersedia</div><?php endif; ?></div></div><div class="col-md-4"><div class="document-preview-card"><div class="document-preview-label">Foto Jaminan</div><?php if(!empty($p['foto_barang'])): ?><img src="<?= layananEscape(urlFoto($p['foto_barang'])) ?>" alt="Foto barang jaminan" class="document-preview-image" loading="lazy"><?php else: ?><div class="document-preview-empty">Foto jaminan belum tersedia</div><?php endif; ?></div></div></div></div></div></div></div>
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
<div class="service-detail-stack">
<section class="service-stack-card"><div class="service-panel-heading"><div><span class="service-eyebrow">RIWAYAT PEMBAYARAN</span><h3 class="h5 mb-0">Rincian angsuran</h3></div><span class="service-result-count"><?= count($histori) ?> angsuran</span></div>
<div class="table-responsive ui-table-scroll"><table class="table table-sm mb-0" data-empty-message="Belum ada riwayat angsuran."><thead><tr><th>Ke</th><th>Jatuh tempo</th><th>Pokok + bunga</th><th>Denda</th><th>Denda dibayar terpisah</th><th>Status</th><th>Tanggal bayar</th></tr></thead><tbody><?php foreach($histori as $a): $lbl=layananLabelCaraBayar($a['status']??'',$a['cara_bayar']??''); ?><tr><td><?= (int)$a['angsuran_ke'] ?></td><td><?= !empty($a['tanggal_jatuh_tempo']) ? layananEscape(formatTanggalUi($a['tanggal_jatuh_tempo'])) : '—' ?></td><td class="service-money"><?= formatRupiah($a['jumlah_angsuran']) ?></td><td class="service-money"><?= formatRupiah($a['denda']) ?></td><td class="service-money"><?= formatRupiah($a['denda_dibayar']) ?></td><td><span class="badge bg-<?= layananEscape($lbl[1]) ?>"><?= layananEscape($lbl[0]) ?></span></td><td><?= !empty($a['tanggal_bayar']) ? layananEscape(formatTanggalUi($a['tanggal_bayar'])) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<div class="no-print">
<section class="service-stack-card service-workflow-panel service-primary-workflow" aria-labelledby="tindakan-layanan-title"><div class="service-panel-heading"><div><span class="service-eyebrow">TINDAKAN LANJUTAN</span><h3 class="h5" id="tindakan-layanan-title">Proses layanan</h3><p>Pilih tindakan. Formulir dibuka di modal.</p></div></div>
<?php if($p['status']!=='lunas'): ?>
<div class="service-workflow-list service-workflow-actions">
<?php if($p['hari_telat']>0) layananForm('lapor_macet',$id,($tab==='denda'?'Catat Keterlambatan':'Buat Laporan Macet'),$catatanField,'warning'); ?>
<?php if($options) layananForm('bayar_denda',$id,'Bayar Denda',$pilihAngsuran.$dendaRingkasan.'<label>Nominal bayar denda</label><input name="jumlah" class="form-control mb-2" type="number" min="0.01" step="0.01" required>'.$metodeField.$buktiField,'success'); ?>
<?php if(isAdmin()): ?>
<?php layananForm('diskon_denda',$id,'Diskon / Pemutihan Denda',$pilihAngsuran.'<label>Total denda akhir (termasuk yang sudah dibayar)</label><input type="number" name="jumlah" class="form-control" min="0" step="0.01" required>'.$catatanField,'warning'); ?>
<?php layananForm('reschedule',$id,'Perpanjang Jadwal','<label>Jatuh tempo baru cicilan belum lunas pertama</label><input name="tanggal" type="date" class="form-control" min="'.date('Y-m-d',strtotime('+1 day')).'" required>'.$catatanField); ?>
<?php if($p['memerlukan_jaminan']) layananForm('eksekusi',$id,'Putuskan Eksekusi Jaminan',$catatanField,'danger'); ?>
<?php endif; ?>
<?php $stmt=$db->prepare('SELECT * FROM layanan_pelunasan WHERE pinjaman_id=?');$stmt->execute([$id]);$approval=$stmt->fetch(PDO::FETCH_ASSOC); ?>
<?php if(!$approval || $approval['status']==='ditolak'): layananForm('ajukan_lunas',$id,'Ajukan Pelunasan Dipercepat',$catatanField); endif; ?>
</div>
<?php if($approval && $approval['status']!=='ditolak'): ?>
<div class="service-settle-card">
<div class="service-settle-head"><div><span class="service-eyebrow">PELUNASAN DIPERCEPAT</span><strong>Status pengajuan</strong></div><span class="badge bg-<?= $approval['status']==='disetujui'?'success':($approval['status']==='menunggu'?'warning':'secondary') ?>"><?= layananEscape(ucfirst($approval['status'])) ?></span></div>
<?php if(isAdmin() && $approval['status']==='menunggu'): ?>
<div class="service-workflow-actions mt-3">
<?php layananForm('approve_lunas',$id,'Approve Pelunasan','<label>Diskon sisa bunga (%)</label><input name="diskon" type="number" class="form-control" min="0" max="100" step="0.01" value="'.layananEscape($settings['diskon_pelunasan']).'" required>'.$catatanField,'success');layananForm('tolak_lunas',$id,'Tolak Pelunasan','<input type="hidden" name="diskon" value="0">'.$catatanField,'danger'); ?>
</div>
<?php endif; if($approval['status']==='disetujui'): $sisa=layananSisa($db,$id,$approval['diskon_persen']); ?>
<div class="service-settle-breakdown" aria-label="Rincian pelunasan">
<div><span>Sisa pokok</span><strong><?= formatRupiah($sisa['pokok']) ?></strong></div>
<div><span>Bunga</span><strong><?= formatRupiah($sisa['bunga']) ?></strong></div>
<div><span>Diskon</span><strong>− <?= formatRupiah($sisa['diskon_bunga']) ?></strong></div>
<div><span>Denda tersisa</span><strong><?= formatRupiah(max(0,$sisa['denda']-$sisa['denda_dibayar'])) ?></strong></div>
<div class="is-total"><span>Total bayar</span><strong><?= formatRupiah($sisa['total']) ?></strong></div>
</div>
<div class="service-settle-action">
<?php layananForm('proses_lunas',$id,'Proses Pelunasan','<label>Total bayar</label><input type="number" class="form-control mb-2" name="jumlah" value="'.layananEscape($sisa['total']).'" readonly>'.$metodeField.$buktiField.($p['memerlukan_jaminan']?'<label><input type="checkbox" name="cek_fisik" required> Fisik jaminan telah diperiksa dan tersedia</label>':''),'success'); ?>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
<?php else: ?>
<div class="service-workflow-list service-workflow-actions">
<?php
$stmt=$db->prepare('SELECT * FROM layanan_prioritas WHERE pinjaman_id=?');
$stmt->execute([$id]);
$prioritas=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$prioritas || $prioritas['status']==='ditolak'):
    layananForm('prioritas',$id,'Ajukan Anggota Prioritas',$catatanField);
elseif($prioritas['status']==='disetujui'):
?>
<div class="service-priority-state is-approved"><span>Anggota prioritas</span><span class="badge bg-success">Disetujui / ACC</span></div>
<?php else: ?>
<section class="service-priority-review"><div><span>Pengajuan anggota prioritas</span><?= layananStatusBadgeHtml($prioritas['status']) ?></div><dl><div><dt>Keterangan pengajuan</dt><dd><?= layananEscape(trim((string)($prioritas['catatan'] ?? '')) ?: 'Tidak ada keterangan.') ?></dd></div><div><dt>Koreksi Admin</dt><dd><?= layananEscape(trim((string)($prioritas['koreksi'] ?? '')) ?: 'Belum ada koreksi.') ?></dd></div></dl></section>
<?php if(isAdmin() && in_array($prioritas['status'],['menunggu','pengecekan'],true)): ?>
<div class="table-responsive ui-table-scroll"><table class="table table-sm"><thead><tr><th>Angsuran</th><th>Jatuh tempo</th><th>Status</th><th>Telat</th><th>Tanggal bayar</th></tr></thead><tbody><?php $rh=$db->prepare("SELECT angsuran_ke,tanggal_jatuh_tempo,status,tanggal_bayar,GREATEST(0,DATEDIFF(COALESCE(tanggal_bayar,CURDATE()),tanggal_jatuh_tempo)) hari_telat FROM angsuran WHERE pinjaman_id=? ORDER BY angsuran_ke");$rh->execute([$id]);foreach($rh->fetchAll(PDO::FETCH_ASSOC) as $rr): ?><tr><td><?= (int)$rr['angsuran_ke'] ?></td><td><?= !empty($rr['tanggal_jatuh_tempo']) ? layananEscape(formatTanggalUi($rr['tanggal_jatuh_tempo'])) : '—' ?></td><td><?= layananEscape($rr['status']) ?></td><td><span class="badge bg-<?= (int)$rr['hari_telat']===0?'success':((int)$rr['hari_telat']<=3?'warning':'danger') ?>"><?= (int)$rr['hari_telat'] ?> hari<?= (int)$rr['hari_telat']===0?' · Lancar':((int)$rr['hari_telat']<=3?' · Telat ringan':' · Macet') ?></span></td><td><?= !empty($rr['tanggal_bayar']) ? layananEscape(formatTanggalUi($rr['tanggal_bayar'])) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php layananForm('koreksi_prioritas',$id,'Koreksi Anggota Prioritas',$catatanField,'warning'); layananForm('approve_prioritas',$id,'Approve Anggota Prioritas','','success'); layananForm('tolak_prioritas',$id,'Tolak Anggota Prioritas',$catatanField,'danger'); endif; ?>
<?php endif; ?>
<?php if($kembali || !empty($p['memerlukan_jaminan']) || !empty($p['status_jaminan'])): ?>
<?php if(!$kembali || $kembali['status']==='ditolak'): layananForm('ajukan_jaminan',$id,'Ajukan Pengembalian Jaminan',$buktiJaminanField.$catatanField);else: $statusKembali=strtolower((string)$kembali['status']);$warnaKembali=$statusKembali==='diserahkan'?'success':($statusKembali==='disetujui'?'info':'warning'); ?>
<section class="service-collateral-state" aria-labelledby="status-jaminan-title"><div class="service-collateral-head"><div class="service-collateral-icon"><i class="fas fa-box-open" aria-hidden="true"></i></div><div><span>Pengembalian jaminan</span><h4 id="status-jaminan-title">Status pengajuan</h4></div><span class="badge bg-<?= $warnaKembali ?>"><?= layananEscape(ucfirst($statusKembali)) ?></span></div><dl><div><dt><i class="fas fa-user" aria-hidden="true"></i>Alasan Teller</dt><dd><?= layananEscape(trim((string)($kembali['alasan'] ?? '')) ?: 'Belum ada alasan yang dicatat.') ?></dd></div><div><dt><i class="fas fa-user-shield" aria-hidden="true"></i>Keterangan Admin</dt><dd><?= layananEscape(trim((string)($kembali['catatan_admin'] ?? '')) ?: 'Belum ada keterangan dari Admin.') ?></dd></div></dl><?php if($statusKembali==='pengecekan'): ?><div class="service-collateral-progress"><span><i class="fas fa-spinner" aria-hidden="true"></i>Sedang dalam proses pengecekan Admin</span><small>Pastikan dokumen dan kondisi fisik jaminan telah sesuai sebelum keputusan diberikan.</small></div><?php endif; ?><div class="service-collateral-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#dokumenJaminanKembaliModal"><i class="fas fa-images me-1" aria-hidden="true"></i>Periksa dokumen pengajuan</button></div></section>
<div class="modal fade" id="dokumenJaminanKembaliModal" tabindex="-1" aria-labelledby="dokumenJaminanKembaliModalLabel" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1" id="dokumenJaminanKembaliModalLabel">Dokumen Pengembalian Jaminan</h5><small class="text-muted"><?= layananEscape($p['nama_lengkap'].' · '.$p['kode_pinjaman']) ?></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-4"><div class="document-preview-card"><div class="document-preview-label">KTP Nasabah</div><?php if(!empty($p['foto_ktp'])): ?><img src="<?= layananEscape(urlFoto($p['foto_ktp'])) ?>" alt="KTP nasabah" class="document-preview-image" loading="lazy"><?php else: ?><div class="document-preview-empty">Foto KTP belum tersedia</div><?php endif; ?></div></div><div class="col-md-4"><div class="document-preview-card"><div class="document-preview-label">Foto Bukti Jaminan dari Teller</div><?php if(!empty($kembali['bukti_jaminan'])): ?><img src="<?= layananEscape(urlFoto($kembali['bukti_jaminan'])) ?>" alt="Bukti jaminan dari Teller" class="document-preview-image" loading="lazy"><?php else: ?><div class="document-preview-empty">Foto bukti jaminan belum tersedia</div><?php endif; ?></div></div><div class="col-md-4"><div class="document-preview-card"><div class="document-preview-label">Kuitansi / Surat Lunas dari Teller</div><?php if(!empty($kembali['bukti_lunas'])): ?><img src="<?= layananEscape(urlFoto($kembali['bukti_lunas'])) ?>" alt="Kuitansi atau surat lunas" class="document-preview-image" loading="lazy"><?php else: ?><div class="document-preview-empty">Kuitansi atau surat lunas belum tersedia</div><?php endif; ?></div></div></div></div></div></div></div>
<?php if(isAdmin() && in_array($kembali['status'],['menunggu','pengecekan'],true)): ?>
<div class="alert alert-info mb-2"><i class="fas fa-circle-info me-1" aria-hidden="true"></i>Periksa KTP, foto bukti jaminan, dan kuitansi/surat lunas dari Teller sebelum memberi keputusan.</div>
<?php layananForm('approve_jaminan',$id,'Approve Pengembalian Jaminan','<p class="small">Dokumen dan kondisi jaminan telah diperiksa. Sertakan keterangan keputusan.</p>'.$catatanField,'success'); layananForm('tolak_jaminan',$id,'Tolak Pengembalian Jaminan',$catatanField,'danger'); ?>
<?php endif; ?>
<?php if($kembali['status']==='disetujui') layananForm('serahkan_jaminan',$id,'Serahkan Jaminan',$buktiField.'<label class="d-block my-2"><input type="checkbox" name="cek_fisik" required> Jaminan sudah diserahkan kembali dan diperiksa bersama nasabah</label><div class="signature-field"><div class="signature-field-head"><div><span class="signature-field-title">Tanda tangan nasabah</span><small class="signature-field-help">Gunakan mouse atau layar sentuh untuk menandatangani.</small></div><span class="signature-status"><i class="fas fa-pen-nib"></i> Belum diisi</span></div><div class="signature-canvas-wrap"><canvas class="signature" width="500" height="220"></canvas></div><input type="hidden" name="ttd"><div class="signature-field-foot"><span class="signature-field-note">Pastikan tanda tangan terlihat jelas sebelum dikirim.</span><button type="button" class="clear-sign signature-clear btn btn-sm btn-outline-danger"><i class="fas fa-eraser me-1"></i>Hapus</button></div></div><div class="signature-field"><div class="signature-field-head"><div><span class="signature-field-title">Tanda tangan Teller / petugas</span><small class="signature-field-help">Teller menandatangani sebagai pihak yang menyerahkan jaminan.</small></div><span class="signature-status"><i class="fas fa-pen-nib"></i> Belum diisi</span></div><div class="signature-canvas-wrap"><canvas class="signature" data-target="ttd_teller" width="500" height="220"></canvas></div><input type="hidden" name="ttd_teller"><div class="signature-field-foot"><span class="signature-field-note">TTD ini dicetak pada akad serah terima.</span><button type="button" class="clear-sign signature-clear btn btn-sm btn-outline-danger"><i class="fas fa-eraser me-1"></i>Hapus</button></div></div>','success'); ?>
<?php if($kembali['status']==='diserahkan'): ?><div class="btn-group" role="group" aria-label="Pilihan cetak akad"><a class="btn btn-outline-primary" href="kwitansi_layanan.php?jenis=bast&id=<?= (int)$kembali['id'] ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf me-1" aria-hidden="true"></i>Cetak Akad Otomatis</a><a class="btn btn-outline-secondary" href="kwitansi_layanan.php?jenis=bast&id=<?= (int)$kembali['id'] ?>&mode=manual" target="_blank" rel="noopener"><i class="fas fa-pen me-1" aria-hidden="true"></i>Cetak Akad Manual</a></div><?php endif;endif;endif; ?>
</div>
<?php endif; ?>
</section>
</div>
<?php include __DIR__.'/layanan_detail_lanjutan.php'; ?>
<?php
$stmt=$db->prepare('SELECT b.*,u.nama_lengkap teller FROM layanan_bayar_denda b JOIN users u ON u.id=b.user_id WHERE b.pinjaman_id=?'.(isAdmin()?'':' AND b.user_id=?').' ORDER BY b.id DESC');$stmt->execute(isAdmin()?[$id]:[$id,$_SESSION['user_id']]);$buktiDenda=$stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt=$db->prepare('SELECT * FROM layanan_pembayaran WHERE pinjaman_id=? AND dibatalkan_at IS NULL ORDER BY id DESC');$stmt->execute([$id]);$buktiPembayaran=$stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<section class="service-stack-card service-proof-panel" aria-labelledby="bukti-pembayaran-title"><div class="service-panel-heading"><div><span class="service-eyebrow">DOKUMEN TRANSAKSI</span><h3 class="h5" id="bukti-pembayaran-title">Bukti pembayaran &amp; denda</h3><p>Kwitansi dan status pemeriksaan.</p></div><span class="service-result-count"><?= count($buktiDenda)+count($buktiPembayaran) ?> dokumen</span></div>
<?php if($buktiDenda || $buktiPembayaran): ?><div class="service-proof-list">
<?php foreach($buktiDenda as $b): ?><article class="service-proof-card"><div class="service-proof-icon is-penalty"><i class="fas fa-receipt" aria-hidden="true"></i></div><div class="service-proof-content"><div class="service-proof-title"><div><span>Pembayaran denda</span><strong><?= formatRupiah($b['jumlah']) ?></strong></div><span class="badge bg-<?= $b['approved_by']?'success':'warning' ?>"><?= $b['approved_by']?'Disetujui':'Menunggu review' ?></span></div><div class="service-proof-meta"><span><i class="fas fa-user" aria-hidden="true"></i><?= layananEscape($b['teller']) ?></span><span><i class="fas fa-calendar" aria-hidden="true"></i><?= !empty($b['created_at'])?layananEscape(formatTanggalUi($b['created_at'], true)):'—' ?></span></div><?php if(isAdmin() && !$b['approved_by']): ?><div class="service-proof-review"><?php layananForm('approve_denda',$id,'Approve Pembayaran Denda','<input type="hidden" name="bayar_id" value="'.(int)$b['id'].'">'); ?></div><?php endif; ?></div><a class="btn btn-sm btn-outline-primary service-proof-link" target="_blank" rel="noopener" href="kwitansi_layanan.php?jenis=denda&id=<?= (int)$b['id'] ?>"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i><span>Lihat bukti</span></a></article><?php endforeach; ?>
<?php foreach($buktiPembayaran as $b): ?><article class="service-proof-card"><div class="service-proof-icon is-payment"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></div><div class="service-proof-content"><div class="service-proof-title"><div><span><?= layananEscape(ucwords(str_replace('_',' ',$b['jenis']))) ?></span><strong><?= formatRupiah($b['jumlah']) ?></strong></div><span class="badge bg-success">Tercatat</span></div><div class="service-proof-meta"><span><i class="fas fa-credit-card" aria-hidden="true"></i><?= layananEscape(ucfirst($b['metode'])) ?></span><?php if(!empty($b['created_at'])): ?><span><i class="fas fa-calendar" aria-hidden="true"></i><?= layananEscape(formatTanggalUi($b['created_at'], true)) ?></span><?php endif; ?></div></div><a class="btn btn-sm btn-outline-primary service-proof-link" target="_blank" rel="noopener" href="kwitansi_layanan.php?jenis=bayar&id=<?= (int)$b['id'] ?>"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i><span>Lihat bukti</span></a></article><?php endforeach; ?>
</div><?php else: ?><div class="service-empty is-inline"><i class="fas fa-file-circle-check" aria-hidden="true"></i><div><strong>Belum ada bukti transaksi</strong><p>Bukti muncul setelah pembayaran/denda dicatat.</p></div></div><?php endif; ?></section>
<?php $stmt=$db->prepare('SELECT l.*,u.nama_lengkap teller FROM layanan_laporan_macet l JOIN users u ON u.id=l.user_id WHERE l.pinjaman_id=?'.(isAdmin()?'':' AND l.user_id=?').' ORDER BY l.id DESC');$stmt->execute(isAdmin()?[$id]:[$id,$_SESSION['user_id']]);$laporanMacet=$stmt->fetchAll(PDO::FETCH_ASSOC); ?>
<section class="service-stack-card service-history-panel" aria-labelledby="laporan-macet-title"><div class="service-panel-heading"><div><span class="service-eyebrow">CATATAN PENANGANAN</span><h3 class="h5" id="laporan-macet-title">Laporan macet</h3><p>Riwayat keterlambatan dan keputusan.</p></div><span class="service-result-count"><?= count($laporanMacet) ?> laporan</span></div>
<?php if($laporanMacet): ?><div class="service-report-list"><?php foreach($laporanMacet as $lap): $lapStatus=strtolower((string)$lap['status']); ?><article class="service-report-card"><div class="service-report-head"><div><span class="badge bg-<?= $lapStatus==='selesai'?'success':($lapStatus==='ditolak'?'danger':'warning') ?>"><?= layananEscape(ucfirst($lapStatus)) ?></span><strong><?= layananEscape($lap['teller']) ?></strong></div><time datetime="<?= layananEscape($lap['created_at']) ?>"><?= !empty($lap['created_at'])?layananEscape(formatTanggalUi($lap['created_at'], true)):'—' ?></time></div><dl class="service-report-detail"><div><dt>Catatan laporan</dt><dd><?= layananEscape(trim((string)$lap['catatan']) ?: 'Tidak ada catatan.') ?></dd></div><div><dt>Keputusan</dt><dd><?= layananEscape(trim((string)$lap['keputusan']) ?: 'Belum ada keputusan.') ?></dd></div></dl><?php if(isAdmin() && $lap['status']==='menunggu'): ?><div class="service-report-action"><?php layananForm('keputusan_macet',$id,'Simpan Keputusan','<input type="hidden" name="laporan_id" value="'.(int)$lap['id'].'"><select class="form-select" name="keputusan"><option value="ditindaklanjuti">Ditindaklanjuti</option><option value="selesai">Selesai</option><option value="ditolak">Ditolak</option></select>'.$catatanField); ?></div><?php endif; ?></article><?php endforeach; ?></div><?php else: ?><div class="service-empty is-inline"><i class="fas fa-clipboard-check" aria-hidden="true"></i><div><strong>Tidak ada laporan macet</strong><p>Belum ada catatan penanganan.</p></div></div><?php endif; ?></section>
<?php if(isAdmin()): $stmt=$db->prepare('SELECT a.*,u.nama_lengkap FROM layanan_audit a JOIN users u ON u.id=a.user_id WHERE a.pinjaman_id=? ORDER BY a.id DESC LIMIT 100');$stmt->execute([$id]);$auditLogs=$stmt->fetchAll(PDO::FETCH_ASSOC); ?><section class="service-stack-card service-history-panel service-audit-panel" aria-labelledby="log-aktivitas-title"><div class="service-panel-heading"><div><span class="service-eyebrow">JEJAK SISTEM</span><h3 class="h5" id="log-aktivitas-title">Log aktivitas</h3><p>Perubahan terbaru pinjaman ini.</p></div><span class="service-result-count"><?= count($auditLogs) ?> aktivitas</span></div><?php if($auditLogs): ?><div class="service-audit-list"><?php foreach($auditLogs as $log): ?><article class="service-audit-item"><div class="service-audit-icon"><i class="fas fa-check" aria-hidden="true"></i></div><div class="service-audit-content"><div class="service-audit-head"><strong><?= layananEscape(ucwords(str_replace('_',' ',$log['aksi']))) ?></strong><time datetime="<?= layananEscape($log['created_at']) ?>"><?= !empty($log['created_at'])?layananEscape(formatTanggalUi($log['created_at'], true)):'—' ?></time></div><div class="service-audit-user"><i class="fas fa-user-shield" aria-hidden="true"></i><?= layananEscape($log['nama_lengkap']) ?></div><p><?= layananEscape(trim((string)($log['detail'] ?? '')) ?: 'Tidak ada catatan tambahan.') ?></p></div></article><?php endforeach; ?></div><?php else: ?><div class="service-empty is-inline"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i><div><strong>Belum ada aktivitas</strong><p>Perubahan akan tercatat otomatis.</p></div></div><?php endif; ?></section><?php endif; ?>
</div>
</section>
<?php endif; /* selected */ ?>
<?php endif; /* siap / list / detail */ ?>
</main></div></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script><script src="../assets/js/denda-form.js?v=1"></script></body></html>

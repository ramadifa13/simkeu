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
// Daftar pakai DataTables (cari + paging client-side) — tanpa filter/paging ganda di form.
$ui_list = ui_query_params();
$list = $list_all;
$list_pager = null;
/** Buffer modal layanan — jangan render di dalam flex (.ui-ly-actions) agar Bootstrap bisa membuka. */
$GLOBALS['layanan_modal_buffer'] = $GLOBALS['layanan_modal_buffer'] ?? '';

function layananForm($aksi,$id,$judul,$fields='',$warna='primary') {
    static $layananFormSeq = 0;
    $layananFormSeq++;
    if ($aksi==='approve_jaminan' && strpos($fields, 'name="catatan"') === false) {
        $fields .= '<div class="ui-ly-field"><label class="form-label">Keterangan Admin</label><textarea name="catatan" class="form-control" rows="3" minlength="5" maxlength="2000" required></textarea></div>';
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
        'kembalikan_lebih' => 'ti ti-cash',
        'jual_jaminan' => 'ti ti-gavel',
        'ajukan_hapus_denda' => 'ti ti-eraser',
        'putuskan_hapus_denda' => 'ti ti-gavel',
        'repeat_order' => 'ti ti-refresh',
        'keputusan_macet' => 'ti ti-clipboard-check',
        'approve_denda' => 'ti ti-check',
        'koreksi_prioritas' => 'ti ti-edit',
        'approve_prioritas' => 'ti ti-check',
        'tolak_prioritas' => 'ti ti-x',
        'tolak_jaminan' => 'ti ti-x',
    ];
    $icon = $icons[$aksi] ?? 'ti ti-forms';
    echo '<button type="button" class="btn btn-outline-'.$warna.' ui-ly-action" data-bs-toggle="modal" data-bs-target="#'.layananEscape($modalId).'">';
    echo '<span class="ui-ly-action-icon"><i class="'.$icon.'" aria-hidden="true"></i></span>';
    echo '<span class="ui-ly-action-copy"><strong>'.layananEscape($judul).'</strong><small>Buka formulir</small></span>';
    echo '</button>';
    $modal = '<div class="modal fade ui-tx-modal ui-tx-modal-layanan" id="'.layananEscape($modalId).'" tabindex="-1" aria-labelledby="'.layananEscape($modalId).'Label" aria-hidden="true">';
    $modal .= '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">';
    $modal .= '<form method="post" enctype="multipart/form-data" class="ui-ly-action-form">'.layananToken();
    $modal .= '<input type="hidden" name="aksi" value="'.layananEscape($aksi).'"><input type="hidden" name="pinjaman_id" value="'.(int)$id.'">';
    $modal .= '<div class="modal-header"><div class="min-w-0">';
    $modal .= '<h5 class="modal-title mb-0" id="'.layananEscape($modalId).'Label"><i class="'.$icon.' me-2" aria-hidden="true"></i>'.layananEscape($judul).'</h5>';
    $modal .= '<p class="ui-ly-modal-sub mb-0">Proses layanan pinjaman</p></div>';
    $modal .= '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button></div>';
    $modal .= '<div class="modal-body ui-ly-modal-body"><div class="ui-ly-form">'.$fields.'</div></div>';
    $modal .= '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>';
    $modal .= '<button type="submit" class="btn btn-'.$warna.'"><i class="'.$icon.' me-1" aria-hidden="true"></i>'.layananEscape($judul).'</button></div>';
    $modal .= '</form></div></div></div>';
    $GLOBALS['layanan_modal_buffer'] .= $modal;
}

function layananRenderModals() {
    if (!empty($GLOBALS['layanan_modal_buffer'])) {
        echo $GLOBALS['layanan_modal_buffer'];
        $GLOBALS['layanan_modal_buffer'] = '';
    }
}
$catatanField='<div class="ui-ly-field"><label class="form-label">Catatan / alasan</label><textarea name="catatan" class="form-control" rows="3" minlength="5" maxlength="2000" required placeholder="Minimal 5 karakter"></textarea></div>';
$buktiField='<div class="ui-ly-field"><label class="form-label">Foto bukti <span class="text-danger">*</span></label><input type="file" name="bukti" class="form-control" accept="image/jpeg,image/png,image/webp" required><p class="form-text mb-0">Otomatis dikompres ≤ 1 MB</p></div>';
$buktiJaminanField='<div class="ui-ly-field"><label class="form-label">Foto bukti jaminan <span class="text-danger">*</span></label><input type="file" name="bukti_jaminan" class="form-control" accept="image/jpeg,image/png,image/webp" required><p class="form-text">Otomatis ≤ 1 MB</p></div><div class="ui-ly-field"><label class="form-label">Kuitansi / surat bukti lunas <span class="text-danger">*</span></label><input type="file" name="bukti_lunas" class="form-control" accept="image/jpeg,image/png,image/webp" required><p class="form-text mb-0">Otomatis ≤ 1 MB</p></div>';
$metodeField='<div class="ui-ly-field"><label class="form-label">Metode pembayaran</label><div class="ui-ly-metode"><label class="ui-ly-metode-opt"><input type="radio" name="metode" value="cash" checked><span><i class="ti ti-cash" aria-hidden="true"></i>Cash</span></label><label class="ui-ly-metode-opt"><input type="radio" name="metode" value="transfer"><span><i class="ti ti-building-bank" aria-hidden="true"></i>Transfer</span></label></div></div>';
$ttdSerahField='<div class="ui-ly-field"><label class="ui-ly-check-row"><input type="checkbox" name="cek_fisik" required> Jaminan sudah diserahkan kembali dan diperiksa bersama nasabah</label></div>'
    .'<div class="row g-3">'
    .'<div class="col-md-6"><div class="signature-field mb-0 ui-ly-sign"><div class="signature-field-head"><div><span class="signature-field-title">TTD Nasabah *</span><small class="signature-field-help">Penerima jaminan</small></div><span class="signature-status"><i class="ti ti-signature"></i> Belum</span></div><div class="signature-canvas-wrap"><canvas class="signature" width="700" height="200"></canvas></div><input type="hidden" name="ttd"><div class="signature-field-foot"><span class="signature-field-note">Wajib</span><button type="button" class="clear-sign signature-clear btn btn-sm btn-outline-danger"><i class="ti ti-eraser me-1"></i>Hapus</button></div></div></div>'
    .'<div class="col-md-6"><div class="signature-field mb-0 ui-ly-sign"><div class="signature-field-head"><div><span class="signature-field-title">TTD Teller *</span><small class="signature-field-help">Petugas penyerahan</small></div><span class="signature-status"><i class="ti ti-signature"></i> Belum</span></div><div class="signature-canvas-wrap"><canvas class="signature" data-target="ttd_teller" width="700" height="200"></canvas></div><input type="hidden" name="ttd_teller"><div class="signature-field-foot"><span class="signature-field-note">Dicantumkan di BAST</span><button type="button" class="clear-sign signature-clear btn btn-sm btn-outline-danger"><i class="ti ti-eraser me-1"></i>Hapus</button></div></div></div>'
    .'</div>';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Layanan Pinjaman - <?= htmlspecialchars(APP_NAME) ?></title>
<style>
@media print {
nav, aside, .sidebar, form, .no-print, header, .ui-dash-header-actions { display:none!important }
}
canvas.signature { touch-action:none;border:1px solid #777;max-width:100% }
</style>
<?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
<?php include __DIR__ . '/layout/shell_start.php'; ?>
<?php
if ($tab === 'lunas') {
    $detailListHref = 'laporan_lunas.php';
} elseif ($tab === 'denda') {
    $detailListHref = 'layanan.php?tab=denda';
} else {
    $detailListHref = 'layanan.php?tab=' . rawurlencode($tab);
}
$lyTitles = [
    'macet' => ['Nasabah & Macet', 'Pantau keterlambatan dan tindak lanjut koleksi'],
    'denda' => ['Denda', 'Tagihan denda jatuh tempo & laporan pembayaran'],
    'lunas' => ['Laporan Lunas', 'Pinjaman selesai, dokumen, dan pengembalian jaminan'],
    'pengaturan' => ['Pengaturan Layanan', 'Tarif, template WA, dan kebijakan pemutihan'],
];
$lyTitle = $lyTitles[$tab] ?? $lyTitles['macet'];
?>
<div class="ui-tx-page">
<?php if ($layananMode === 'detail'): ?>
<header class="ui-dash-header">
    <div class="ui-dash-header-icon ui-tx-icon-layanan" aria-hidden="true"><i class="ti ti-folder-open"></i></div>
    <div class="ui-dash-header-copy">
        <p class="ui-dash-eyebrow mb-0">Layanan</p>
        <h1 class="ui-dash-title">Detail pinjaman</h1>
        <p class="ui-dash-meta mb-0">Riwayat angsuran, tindakan, dan dokumen terkait</p>
    </div>
    <div class="ui-dash-header-actions">
        <a class="btn ui-tx-btn-layanan-ghost" href="<?= layananEscape($detailListHref) ?>"><i class="ti ti-arrow-left me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Kembali</span></a>
        <a class="btn btn-light" href="angsuran.php"><i class="ti ti-cash me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Bayar angsuran</span></a>
    </div>
</header>
<?php else: ?>
<header class="ui-dash-header">
    <div class="ui-dash-header-icon ui-tx-icon-layanan" aria-hidden="true"><i class="ti ti-moneybag"></i></div>
    <div class="ui-dash-header-copy">
        <p class="ui-dash-eyebrow mb-0">Layanan</p>
        <h1 class="ui-dash-title"><?= layananEscape($lyTitle[0]) ?></h1>
        <p class="ui-dash-meta mb-0"><?= layananEscape($lyTitle[1]) ?></p>
    </div>
    <div class="ui-dash-header-actions">
        <a class="btn ui-tx-btn-layanan-ghost" href="angsuran.php"><i class="ti ti-calendar-check me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Angsuran</span></a>
        <a class="btn btn-light" href="pelunasan.php"><i class="ti ti-flag-check me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Pelunasan</span></a>
    </div>
</header>
<nav class="ui-ly-tabs no-print" aria-label="Bagian layanan pinjaman">
<?php foreach (['macet'=>['Nasabah & Macet','ti-users'],'denda'=>['Denda','ti-clock'],'lunas'=>['Laporan Lunas','ti-circle-check']] as $key=>$info): ?>
<a class="ui-ly-tab<?= $tab===$key?' is-active':'' ?>" <?= $tab===$key?'aria-current="page"':'' ?> href="<?= $key==='lunas'?'laporan_lunas.php':'layanan.php?tab='.$key ?>"><i class="ti <?= $info[1] ?>" aria-hidden="true"></i><?= $info[0] ?></a>
<?php endforeach; ?>
<?php if (isAdmin()): ?>
<a class="ui-ly-tab<?= $tab==='pengaturan'?' is-active':'' ?>" <?= $tab==='pengaturan'?'aria-current="page"':'' ?> href="layanan.php?tab=pengaturan"><i class="ti ti-adjustments" aria-hidden="true"></i>Pengaturan</a>
<?php endif; ?>
</nav>
<?php if ($tab === 'denda'): ?>
<div class="alert alert-info ui-ly-info" role="status" data-ui-toast-skip>
    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
    Buka pinjaman telat → cek sisa denda → <em>Detail</em> untuk bayar (bukti wajib). Admin dapat Diskon/Pemutihan.
</div>
<?php endif; ?>
<?php endif; ?>
<?php ui_flash(); ?>
<?php if(!$siap): ?><div class="alert alert-warning">Database perlu diperbarui. Jalankan database/migrations/MIGRASI_UNIVERSAL.sql.</div>
<?php elseif ($layananMode === 'list'): ?>
<div class="ui-ly-kpi">
<?php ui_stat_grid([
    ['label' => 'Pinjaman macet', 'value' => (int)$totalMacet, 'tone' => 'danger', 'icon' => 'ti ti-alert-circle', 'hint' => 'Telat ≥ 7 hari'],
    ['label' => 'Uang macet', 'value' => formatRupiah($uangMacet), 'tone' => 'warning', 'icon' => 'ti ti-wallet', 'hint' => 'Pokok + bunga + denda'],
    ['label' => 'Lancar', 'value' => $lancar . ' / ' . $totalAktif, 'tone' => 'success', 'icon' => 'ti ti-circle-check', 'hint' => 'Telat 0–3 hari'],
    ['label' => 'Sudah lunas', 'value' => (int)$lunas, 'tone' => 'info', 'icon' => 'ti ti-flag-check', 'hint' => 'Selesai'],
]); ?>
</div>
<?php if($tab==='pengaturan'): ?>
<?php include __DIR__.'/layanan_settings_panel.php'; ?>
<?php else: ?>
<div class="card mt-3 ui-ly-daftar">
<div class="card-header ui-dash-sec">
<span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i><?= $tab==='lunas'?'Laporan pelunasan':($tab==='denda'?'Tagihan denda':'Data nasabah & angsuran') ?></span>
<span class="ui-dash-sec-note"><?= (int)count($list) ?> data</span>
</div>
<div class="card-body">
<?php if ($tab === 'macet'): ?>
<div class="ui-ly-toolbar no-print mb-3">
<form method="get" class="d-flex flex-wrap align-items-end gap-2 mb-0">
<input type="hidden" name="tab" value="macet">
<div>
<label for="layanan_hari" class="form-label mb-1">Keterlambatan</label>
<select id="layanan_hari" name="hari" class="form-select form-select-sm" onchange="this.form.submit()">
<option value="0">Semua keterlambatan</option>
<?php foreach ([7,14,30,90] as $d): ?><option value="<?= $d ?>" <?= $hari===$d?'selected':'' ?>><?= $d===90?'>90':'≥'.$d ?> hari</option><?php endforeach; ?>
</select>
</div>
</form>
<div class="d-flex flex-wrap gap-2">
<button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">Cetak / Simpan PDF</button>
<?php if (isAdmin()): ?>
<a class="btn btn-outline-success btn-sm" href="?<?= layananEscape(http_build_query(['tab'=>$tab,'hari'=>$hari,'export'=>1])) ?>">Export Excel (CSV)</a>
<a class="btn btn-outline-danger btn-sm" href="?<?= layananEscape(http_build_query(['tab'=>$tab,'hari'=>$hari,'export'=>'pdf'])) ?>">Download PDF</a>
<?php endif; ?>
</div>
</div>
<div class="table-responsive ui-table-scroll"><table class="table table-hover align-middle ui-ly-table js-datatable" data-dt-page-length="10" data-empty-message="Data tidak ditemukan."><thead><tr><th>Nama / Pinjaman</th><th>Pinjam</th><th>Total Balik</th><th>Sisa</th><th>Jatuh Tempo</th><th>Telat</th><th>Denda</th><th>Jaminan</th><th>Status</th><th class="no-print">Aksi</th></tr></thead><tbody>
<?php foreach($list as $r): ?><tr><td><strong class="ui-ly-member"><?= layananEscape($r['nama_lengkap']) ?></strong><small class="ui-ly-kode"><?= layananEscape($r['kode_pinjaman']) ?></small></td><td class="ui-ly-money"><?= formatRupiah($r['jumlah_pinjaman']) ?></td><td class="ui-ly-money"><?= formatRupiah($r['total_balik']) ?></td><td class="ui-ly-money"><?= formatRupiah($r['sisa_tagihan']) ?></td><td><?= !empty($r['jatuh_tempo'])?layananEscape(formatTanggalUi($r['jatuh_tempo'])):(!empty($r['tanggal_lunas'])?layananEscape(formatTanggalUi($r['tanggal_lunas'])):'—') ?></td><td><?= (int)$r['hari_telat'] ?> hari</td><td class="ui-ly-money"><?= formatRupiah($r['sisa_denda']) ?></td><td><?= $r['memerlukan_jaminan']?formatRupiah($r['nilai_taksiran']).'<br>'.layananEscape($r['status_jaminan']):'Tanpa jaminan' ?></td><td><?= $r['status']==='lunas'?'<span class="badge bg-primary">Lunas</span>':statusKolektibilitasBadgeHtml($r['hari_telat']) ?></td><td class="no-print table-actions text-end"><?= ui_action_menu([
    ['label' => 'Detail', 'href' => 'detail_layanan.php?tab=' . rawurlencode($tab) . '&id=' . (int)$r['id'], 'icon' => 'ti ti-eye'],
    ['label' => 'Bayar', 'href' => 'detail_angsuran.php?pinjaman_id=' . (int)$r['id'], 'icon' => 'ti ti-cash'],
]) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php if($tab==='denda') include __DIR__.'/denda_report_panel.php'; ?>
<?php if($tab==='lunas'): ?>
<p class="ui-ly-lead text-muted small mb-3">Detail angsuran, dokumen, BAST, dan aktivitas layanan — pakai kolom Cari di tabel.</p>
<div class="table-responsive ui-table-scroll"><table class="table align-middle ui-ly-table js-datatable" data-dt-page-length="10" data-empty-message="Belum ada pinjaman lunas yang sesuai dengan pencarian."><thead><tr><th>Anggota / Pinjaman</th><th>Tanggal lunas</th><th>Pelunasan</th><th>Total pinjaman</th><th>Bunga diterima</th><th>Jaminan</th><th>Teller</th><th>Status layanan</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($list as $r):
$stmt=$db->prepare("SELECT COALESCE(SUM(bunga),0) FROM angsuran WHERE pinjaman_id=? AND status='lunas'");$stmt->execute([$r['id']]);$bunga=(float)$stmt->fetchColumn();
$stmt=$db->prepare("SELECT diskon_persen,(SELECT COALESCE(SUM(bunga),0) FROM angsuran WHERE pinjaman_id=? AND cara_bayar='pelunasan') bunga FROM layanan_pelunasan WHERE pinjaman_id=? AND status='selesai'");$stmt->execute([$r['id'],$r['id']]);$pl=$stmt->fetch(PDO::FETCH_ASSOC);if($pl)$bunga-=round($pl['bunga']*$pl['diskon_persen']/100,2);
$jenisTutup=layananJenisPenutupan($db,(int)$r['id']);
$stmt=$db->prepare("SELECT u.nama_lengkap FROM layanan_pembayaran b JOIN users u ON u.id=b.user_id WHERE b.pinjaman_id=? AND b.dibatalkan_at IS NULL ORDER BY b.id DESC LIMIT 1");$stmt->execute([$r['id']]);$tellerLunas=$stmt->fetchColumn();
$stmt=$db->prepare("SELECT status FROM layanan_jaminan_kembali WHERE pinjaman_id=? UNION ALL SELECT status FROM layanan_prioritas WHERE pinjaman_id=?");$stmt->execute([$r['id'],$r['id']]);$statusLayanan=$stmt->fetchAll(PDO::FETCH_COLUMN);
$statusLayanan=array_values(array_unique(array_filter($statusLayanan)));
$statusAktual='';foreach(['ditolak','menunggu','pengecekan','koreksi_prioritas','disetujui','diserahkan'] as $statusCari){if(in_array($statusCari,$statusLayanan,true)){$statusAktual=$statusCari;break;}} if(!$statusAktual && !empty($r['memerlukan_jaminan']) && ($r['status_jaminan']??'')==='dititipkan') $statusAktual='jaminan_belum_diambil'; if($statusAktual==='disetujui' && ($r['status_jaminan']??'')==='dititipkan') $statusAktual='jaminan_siap_diambil'; if(($r['status_jaminan']??'')==='dikembalikan') $statusAktual='jaminan_dikembalikan'; ?>
<tr><td><strong class="ui-ly-member"><?= layananEscape($r['nama_lengkap']) ?></strong><small class="ui-ly-kode"><?= layananEscape($r['kode_pinjaman']) ?></small></td><td><?= !empty($r['tanggal_lunas']) ? layananEscape(formatTanggalUi($r['tanggal_lunas'])) : '—' ?></td><td><span class="badge bg-<?= layananEscape($jenisTutup[1]) ?>"><?= layananEscape($jenisTutup[0]) ?></span></td><td class="ui-ly-money"><?= formatRupiah($r['jumlah_pinjaman']) ?></td><td class="ui-ly-money"><?= formatRupiah($bunga) ?></td><td><?= layananEscape($r['status_jaminan'] ?: 'Tanpa jaminan') ?></td><td><?= layananEscape($tellerLunas ?: 'Data lama') ?></td><td><?= $statusAktual ? layananStatusBadgeHtml($statusAktual) : '<span class="badge bg-secondary">Tidak ada pengajuan</span>' ?></td><td class="no-print table-actions text-end"><?= ui_action_menu([
    ['label' => 'Cetak / Simpan PDF', 'href' => 'cetak_bukti_pelunasan.php?id=' . (int)$r['id'], 'target' => '_blank', 'icon' => 'ti ti-file-type-pdf', 'class' => ''],
    ['label' => 'Detail', 'href' => 'detail_layanan.php?tab=lunas&id=' . (int)$r['id'], 'icon' => 'ti ti-eye', 'class' => ''],
]) ?></td></tr><?php endforeach; ?></tbody></table></div>
<script>
(function () {
    var icon = {dititipkan: 'ti-archive text-warning', dikembalikan: 'ti-package text-success'};
    document.querySelectorAll('.ui-ly-table tbody tr').forEach(function (row) {
        var cell = row.cells[5];
        if (!cell) return;
        var label = cell.textContent.trim(), key = label.toLowerCase();
        var iconClass = icon[key] || 'ti-shield-check text-secondary';
        cell.innerHTML = '<strong><i class="ti ' + iconClass + ' me-1" aria-hidden="true"></i>' + label + '</strong>';
    });
}());
</script>
<?php endif; ?>
</div></div>
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
<section id="detail" class="ui-ly-detail">
<div class="card mb-3 ui-ly-hero-card">
<div class="card-header ui-ly-hero-head">
<div class="ui-ly-hero-avatar" aria-hidden="true"><i class="ti ti-user"></i></div>
<div class="min-w-0">
<span class="ui-ly-eyebrow"><?= $p['status']==='lunas'?'Detail pelunasan':'Detail pinjaman' ?></span>
<h2 class="ui-ly-hero-nama"><?= layananEscape($p['nama_lengkap']) ?></h2>
<small class="ui-ly-hero-kode"><code><?= layananEscape($p['kode_pinjaman']) ?></code></small>
</div>
</div>
<div class="card-body">
<div class="ui-ly-summary" aria-label="Ringkasan pinjaman">
<div><span>Status pinjaman</span><strong><i class="ti <?= $p['status']==='lunas'?'ti-circle-check':'ti-clock' ?>" aria-hidden="true"></i><?= $p['status']==='lunas'?'Lunas':layananEscape(ucfirst($p['status'])) ?></strong></div>
<div><span>Total pinjaman</span><strong><?= formatRupiah($p['jumlah_pinjaman']) ?></strong></div>
<div><span><?= $p['status']==='lunas'?'Tanggal lunas':'Sisa tagihan' ?></span><strong><?= $p['status']==='lunas' ? (!empty($p['tanggal_lunas']) ? layananEscape(formatTanggalUi($p['tanggal_lunas'])) : '—') : formatRupiah($p['sisa_tagihan']) ?></strong></div>
<div><span>Status jaminan</span><strong><?= layananEscape($p['status_jaminan'] ?: 'Tanpa jaminan') ?></strong></div>
</div>
<div class="ui-ly-detail-actions no-print">
<?php if($kembali && $kembali['status']!=='ditolak'): ?>
<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dokumenJaminanKembaliModal"><i class="ti ti-file-check me-1" aria-hidden="true"></i>Periksa dokumen pengembalian</button>
<button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#dokumenPinjamanModal"><i class="ti ti-photo me-1" aria-hidden="true"></i>Dokumen pinjaman</button>
<?php else: ?>
<button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#dokumenPinjamanModal"><i class="ti ti-photo me-1" aria-hidden="true"></i>Lihat dokumen</button>
<?php endif; ?>
<?php if($p['status']==='lunas'): ?><a class="btn btn-success" target="_blank" rel="noopener" href="cetak_bukti_pelunasan.php?id=<?= $id ?>"><i class="ti ti-printer me-1" aria-hidden="true"></i>Cetak surat lunas</a><?php endif; ?>
<?php if(isAdmin() || $tab==='denda'):
$pesan=$p['status']==='lunas'?$settings['wa_lunas']:$settings['wa_denda'];
$pesan=strtr($pesan,['{nama}'=>$p['nama_lengkap'],'{hari}'=>$p['hari_telat'],'{denda}'=>number_format($p['sisa_denda'],0,',','.'),'{norek}'=>$p['kode_pinjaman']]);
$waNomor=nomorWhatsApp($p['telepon']);
if($waNomor):
$waLabel=$p['status']==='lunas'?'WA Lunas':'WA Tagih';
?>
<a class="btn ui-ly-wa" target="_blank" rel="noopener noreferrer" href="https://wa.me/<?= layananEscape($waNomor) ?>?text=<?= rawurlencode($pesan) ?>">
<span class="ui-ly-wa-icon" aria-hidden="true"><i class="ti ti-brand-whatsapp"></i></span>
<span class="ui-ly-wa-copy"><strong><?= layananEscape($waLabel) ?></strong><small>Buka WhatsApp</small></span>
</a>
<?php endif; endif; ?>
</div>
</div></div>
<div class="modal fade ui-tx-modal ui-tx-modal-layanan" id="dokumenPinjamanModal" tabindex="-1" aria-labelledby="dokumenPinjamanModalLabel" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div class="min-w-0"><h5 class="modal-title mb-0" id="dokumenPinjamanModalLabel"><i class="ti ti-photo me-2" aria-hidden="true"></i>Dokumen Pinjaman</h5><p class="ui-ly-modal-sub mb-0"><?= layananEscape($p['nama_lengkap'].' · '.$p['kode_pinjaman']) ?></p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-4"><div class="ui-ly-doc"><div class="ui-ly-doc-label">KTP Nasabah</div><?php if(!empty($p['foto_ktp'])): ?><img src="<?= layananEscape(urlFoto($p['foto_ktp'])) ?>" alt="KTP nasabah" class="ui-ly-doc-img" loading="lazy"><?php else: ?><div class="ui-ly-doc-empty">Foto KTP belum tersedia</div><?php endif; ?></div></div><div class="col-md-4"><div class="ui-ly-doc"><div class="ui-ly-doc-label">Foto Pencairan</div><?php if(!empty($p['foto_pencairan'])): ?><img src="<?= layananEscape(urlFoto($p['foto_pencairan'])) ?>" alt="Foto pencairan" class="ui-ly-doc-img" loading="lazy"><?php else: ?><div class="ui-ly-doc-empty">Foto pencairan belum tersedia</div><?php endif; ?></div></div><div class="col-md-4"><div class="ui-ly-doc"><div class="ui-ly-doc-label">Foto Jaminan</div><?php if(!empty($p['foto_barang'])): ?><img src="<?= layananEscape(urlFoto($p['foto_barang'])) ?>" alt="Foto barang jaminan" class="ui-ly-doc-img" loading="lazy"><?php else: ?><div class="ui-ly-doc-empty">Foto jaminan belum tersedia</div><?php endif; ?></div></div></div></div></div></div></div>
<?php
$stmt=$db->prepare('SELECT a.*,COALESCE(b.dibayar,0) denda_dibayar FROM angsuran a LEFT JOIN (SELECT angsuran_id,SUM(jumlah) dibayar FROM layanan_bayar_denda GROUP BY angsuran_id) b ON b.angsuran_id=a.id WHERE a.pinjaman_id=? ORDER BY a.angsuran_ke');$stmt->execute([$id]);$histori=$stmt->fetchAll(PDO::FETCH_ASSOC);
$options='';foreach($histori as $a) if($a['status']!=='lunas') $options.='<option value="'.(int)$a['id'].'">Cicilan '.(int)$a['angsuran_ke'].' · Denda '.formatRupiah($a['denda']).' · Dibayar '.formatRupiah($a['denda_dibayar']).'</option>';
$pilihAngsuran='<div class="ui-ly-field"><label class="form-label">Angsuran</label><select name="angsuran_id" class="form-select" required>'.$options.'</select></div>';
$dendaData=[];
foreach ($histori as $ha) {
    $dh=hitungDendaAngsuran($db,$ha,$p['jumlah_pinjaman']);
    $dendaData[$ha['id']]=['hari'=>$dh['hari_telat'],'tarif'=>$dh['per_hari'],'total'=>(float)$ha['denda'],'sisa'=>max(0,$ha['denda']-$ha['denda_dibayar'])];
}
$dendaRingkasan='<div class="ui-ly-field"><label class="form-label">Nama nasabah</label><input class="form-control" readonly value="'.layananEscape($p['nama_lengkap']).'"></div>'
    .'<div class="denda-auto-fields ui-ly-denda-grid" data-denda="'.layananEscape(json_encode($dendaData)).'">'
    .'<div class="ui-ly-field"><label class="form-label">Telat (hari)</label><input class="form-control" data-denda-field="hari" readonly></div>'
    .'<div class="ui-ly-field"><label class="form-label">Tarif / hari</label><input class="form-control" data-denda-field="tarif" readonly></div>'
    .'<div class="ui-ly-field"><label class="form-label">Total denda</label><input class="form-control" data-denda-field="total" readonly></div>'
    .'<div class="ui-ly-field"><label class="form-label">Sisa denda</label><input class="form-control" data-denda-field="sisa" readonly></div></div>'
    .'<div class="ui-ly-field"><label class="form-label">Diskon</label><input class="form-control" value="Hanya melalui keputusan Admin" disabled></div>';

?>
<div class="ui-ly-stack">
<section class="card ui-ly-panel"><div class="card-header ui-dash-sec"><span class="ui-dash-sec-title"><i class="ti ti-calendar-stats" aria-hidden="true"></i>Rincian angsuran</span><span class="ui-dash-sec-note"><?= count($histori) ?> angsuran</span></div>
<div class="card-body p-0"><div class="table-responsive ui-table-scroll"><table class="table table-sm align-middle mb-0 js-datatable" data-dt-plain data-empty-message="Belum ada riwayat angsuran."><thead><tr><th>Ke</th><th>Jatuh tempo</th><th>Pokok + bunga</th><th>Denda</th><th>Denda dibayar terpisah</th><th>Status</th><th>Tanggal bayar</th></tr></thead><tbody><?php foreach($histori as $a): $lbl=layananLabelCaraBayar($a['status']??'',$a['cara_bayar']??''); ?><tr><td><?= (int)$a['angsuran_ke'] ?></td><td><?= !empty($a['tanggal_jatuh_tempo']) ? layananEscape(formatTanggalUi($a['tanggal_jatuh_tempo'])) : '—' ?></td><td class="ui-ly-money"><?= formatRupiah($a['jumlah_angsuran']) ?></td><td class="ui-ly-money"><?= formatRupiah($a['denda']) ?></td><td class="ui-ly-money"><?= formatRupiah($a['denda_dibayar']) ?></td><td><span class="badge bg-<?= layananEscape($lbl[1]) ?>"><?= layananEscape($lbl[0]) ?></span></td><td><?= !empty($a['tanggal_bayar']) ? layananEscape(formatTanggalUi($a['tanggal_bayar'])) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></div></section>
<div class="no-print">
<section class="card ui-ly-panel ui-ly-workflow" aria-labelledby="tindakan-layanan-title"><div class="card-header ui-dash-sec"><div><span class="ui-dash-sec-title" id="tindakan-layanan-title"><i class="ti ti-tool" aria-hidden="true"></i>Proses layanan</span><p class="text-muted small mb-0 mt-1">Pilih tindakan — formulir dibuka di modal.</p></div></div>
<div class="card-body">
<?php if($p['status']!=='lunas'): ?>
<div class="ui-ly-actions">
<?php if($p['hari_telat']>0) layananForm('lapor_macet',$id,($tab==='denda'?'Catat Keterlambatan':'Buat Laporan Macet'),$catatanField,'warning'); ?>
<?php if($options) layananForm('bayar_denda',$id,'Bayar Denda',$pilihAngsuran.$dendaRingkasan.'<div class="ui-ly-field"><label class="form-label">Nominal bayar denda</label><input name="jumlah" class="form-control" type="number" min="0.01" step="0.01" required></div>'.$metodeField.$buktiField,'success'); ?>
<?php if(isAdmin()): ?>
<?php layananForm('diskon_denda',$id,'Diskon / Pemutihan Denda',$pilihAngsuran.'<div class="ui-ly-field"><label class="form-label">Total denda akhir (termasuk yang sudah dibayar)</label><input type="number" name="jumlah" class="form-control" min="0" step="0.01" required></div>'.$catatanField,'warning'); ?>
<?php layananForm('reschedule',$id,'Perpanjang Jadwal','<div class="ui-ly-field"><label class="form-label">Jatuh tempo baru (cicilan belum lunas pertama)</label><input name="tanggal" type="date" class="form-control" min="'.date('Y-m-d',strtotime('+1 day')).'" required></div>'.$catatanField); ?>
<?php if($p['memerlukan_jaminan']) layananForm('eksekusi',$id,'Putuskan Eksekusi Jaminan',$catatanField,'danger'); ?>
<?php endif; ?>
<?php $stmt=$db->prepare('SELECT * FROM layanan_pelunasan WHERE pinjaman_id=?');$stmt->execute([$id]);$approval=$stmt->fetch(PDO::FETCH_ASSOC); ?>
<?php if(!$approval || $approval['status']==='ditolak'): layananForm('ajukan_lunas',$id,'Ajukan Pelunasan Dipercepat',$catatanField); endif; ?>
</div>
<?php if($approval && $approval['status']!=='ditolak'): ?>
<div class="ui-ly-settle">
<div class="ui-ly-settle-head"><div><span class="ui-ly-eyebrow">Pelunasan dipercepat</span><strong>Status pengajuan</strong></div><span class="badge bg-<?= $approval['status']==='disetujui'?'success':($approval['status']==='menunggu'?'warning':'secondary') ?>"><?= layananEscape(ucfirst($approval['status'])) ?></span></div>
<?php if(isAdmin() && $approval['status']==='menunggu'): ?>
<div class="ui-ly-actions mt-3">
<?php layananForm('approve_lunas',$id,'Approve Pelunasan','<div class="ui-ly-field"><label class="form-label">Diskon sisa bunga (%)</label><input name="diskon" type="number" class="form-control" min="0" max="100" step="0.01" value="'.layananEscape($settings['diskon_pelunasan']).'" required></div>'.$catatanField,'success');layananForm('tolak_lunas',$id,'Tolak Pelunasan','<input type="hidden" name="diskon" value="0">'.$catatanField,'danger'); ?>
</div>
<?php endif; if($approval['status']==='disetujui'): $sisa=layananSisa($db,$id,$approval['diskon_persen']); ?>
<div class="ui-ly-settle-grid" aria-label="Rincian pelunasan">
<div><span>Sisa pokok</span><strong><?= formatRupiah($sisa['pokok']) ?></strong></div>
<div><span>Bunga</span><strong><?= formatRupiah($sisa['bunga']) ?></strong></div>
<div><span>Diskon</span><strong>− <?= formatRupiah($sisa['diskon_bunga']) ?></strong></div>
<div><span>Denda tersisa</span><strong><?= formatRupiah(max(0,$sisa['denda']-$sisa['denda_dibayar'])) ?></strong></div>
<div class="is-total"><span>Total bayar</span><strong><?= formatRupiah($sisa['total']) ?></strong></div>
</div>
<div class="ui-ly-actions mt-3">
<?php layananForm('proses_lunas',$id,'Proses Pelunasan','<div class="ui-ly-field"><label class="form-label">Total bayar</label><input type="hidden" name="jumlah" value="'.layananEscape($sisa['total']).'"><input class="form-control" value="'.layananEscape(formatRupiah($sisa['total'])).'" readonly></div>'.$metodeField.$buktiField.($p['memerlukan_jaminan']?'<div class="ui-ly-field"><label class="ui-ly-check-row"><input type="checkbox" name="cek_fisik" required> Fisik jaminan telah diperiksa dan tersedia</label></div>':''),'success'); ?>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
<?php else: ?>
<div class="ui-ly-actions">
<?php
$stmt=$db->prepare('SELECT * FROM layanan_prioritas WHERE pinjaman_id=?');
$stmt->execute([$id]);
$prioritas=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$prioritas || $prioritas['status']==='ditolak'):
    layananForm('prioritas',$id,'Ajukan Anggota Prioritas',$catatanField);
elseif($prioritas['status']==='disetujui'):
?>
<div class="ui-ly-state is-ok"><span>Anggota prioritas</span><span class="badge bg-success">Disetujui / ACC</span></div>
<?php else: ?>
<section class="ui-ly-review"><div class="ui-ly-review-head"><span>Pengajuan anggota prioritas</span><?= layananStatusBadgeHtml($prioritas['status']) ?></div><dl><div><dt>Keterangan pengajuan</dt><dd><?= layananEscape(trim((string)($prioritas['catatan'] ?? '')) ?: 'Tidak ada keterangan.') ?></dd></div><div><dt>Koreksi Admin</dt><dd><?= layananEscape(trim((string)($prioritas['koreksi'] ?? '')) ?: 'Belum ada koreksi.') ?></dd></div></dl></section>
<?php if(isAdmin() && in_array($prioritas['status'],['menunggu','pengecekan'],true)): ?>
<div class="table-responsive ui-table-scroll"><table class="table table-sm js-datatable" data-dt-plain><thead><tr><th>Angsuran</th><th>Jatuh tempo</th><th>Status</th><th>Telat</th><th>Tanggal bayar</th></tr></thead><tbody><?php $rh=$db->prepare("SELECT angsuran_ke,tanggal_jatuh_tempo,status,tanggal_bayar,GREATEST(0,DATEDIFF(COALESCE(tanggal_bayar,CURDATE()),tanggal_jatuh_tempo)) hari_telat FROM angsuran WHERE pinjaman_id=? ORDER BY angsuran_ke");$rh->execute([$id]);foreach($rh->fetchAll(PDO::FETCH_ASSOC) as $rr): ?><tr><td><?= (int)$rr['angsuran_ke'] ?></td><td><?= !empty($rr['tanggal_jatuh_tempo']) ? layananEscape(formatTanggalUi($rr['tanggal_jatuh_tempo'])) : '—' ?></td><td><?= layananEscape($rr['status']) ?></td><td><span class="badge bg-<?= (int)$rr['hari_telat']===0?'success':((int)$rr['hari_telat']<=3?'warning':'danger') ?>"><?= (int)$rr['hari_telat'] ?> hari<?= (int)$rr['hari_telat']===0?' · Lancar':((int)$rr['hari_telat']<=3?' · Telat ringan':' · Macet') ?></span></td><td><?= !empty($rr['tanggal_bayar']) ? layananEscape(formatTanggalUi($rr['tanggal_bayar'])) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php layananForm('koreksi_prioritas',$id,'Koreksi Anggota Prioritas',$catatanField,'warning'); layananForm('approve_prioritas',$id,'Approve Anggota Prioritas','','success'); layananForm('tolak_prioritas',$id,'Tolak Anggota Prioritas',$catatanField,'danger'); endif; ?>
<?php endif; ?>
<?php if($kembali || !empty($p['memerlukan_jaminan']) || !empty($p['status_jaminan'])): ?>
<?php if(!$kembali || $kembali['status']==='ditolak'): layananForm('ajukan_jaminan',$id,'Ajukan Pengembalian Jaminan',$buktiJaminanField.$catatanField);else: $statusKembali=strtolower((string)$kembali['status']);$warnaKembali=$statusKembali==='diserahkan'?'success':($statusKembali==='disetujui'?'info':'warning'); ?>
<section class="ui-ly-collateral" aria-labelledby="status-jaminan-title"><div class="ui-ly-collateral-head"><div class="ui-ly-collateral-icon"><i class="ti ti-package" aria-hidden="true"></i></div><div><span>Pengembalian jaminan</span><h4 id="status-jaminan-title">Status pengajuan</h4></div><span class="badge bg-<?= $warnaKembali ?>"><?= layananEscape(ucfirst($statusKembali)) ?></span></div><dl><div><dt><i class="ti ti-user" aria-hidden="true"></i>Alasan Teller</dt><dd><?= layananEscape(trim((string)($kembali['alasan'] ?? '')) ?: 'Belum ada alasan yang dicatat.') ?></dd></div><div><dt><i class="ti ti-user-shield" aria-hidden="true"></i>Keterangan Admin</dt><dd><?= layananEscape(trim((string)($kembali['catatan_admin'] ?? '')) ?: 'Belum ada keterangan dari Admin.') ?></dd></div></dl><?php if($statusKembali==='pengecekan'): ?><div class="ui-ly-collateral-progress"><span><i class="ti ti-loader-2" aria-hidden="true"></i>Sedang dalam proses pengecekan Admin</span><small>Pastikan dokumen dan kondisi fisik jaminan telah sesuai sebelum keputusan diberikan.</small></div><?php endif; ?><div class="mt-2"><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#dokumenJaminanKembaliModal"><i class="ti ti-photo me-1" aria-hidden="true"></i>Periksa dokumen pengajuan</button></div></section>
<div class="modal fade ui-tx-modal ui-tx-modal-layanan" id="dokumenJaminanKembaliModal" tabindex="-1" aria-labelledby="dokumenJaminanKembaliModalLabel" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div class="min-w-0"><h5 class="modal-title mb-0" id="dokumenJaminanKembaliModalLabel"><i class="ti ti-package me-2" aria-hidden="true"></i>Dokumen Pengembalian Jaminan</h5><p class="ui-ly-modal-sub mb-0"><?= layananEscape($p['nama_lengkap'].' · '.$p['kode_pinjaman']) ?></p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-4"><div class="ui-ly-doc"><div class="ui-ly-doc-label">KTP Nasabah</div><?php if(!empty($p['foto_ktp'])): ?><img src="<?= layananEscape(urlFoto($p['foto_ktp'])) ?>" alt="KTP nasabah" class="ui-ly-doc-img" loading="lazy"><?php else: ?><div class="ui-ly-doc-empty">Foto KTP belum tersedia</div><?php endif; ?></div></div><div class="col-md-4"><div class="ui-ly-doc"><div class="ui-ly-doc-label">Foto Bukti Jaminan dari Teller</div><?php if(!empty($kembali['bukti_jaminan'])): ?><img src="<?= layananEscape(urlFoto($kembali['bukti_jaminan'])) ?>" alt="Bukti jaminan dari Teller" class="ui-ly-doc-img" loading="lazy"><?php else: ?><div class="ui-ly-doc-empty">Foto bukti jaminan belum tersedia</div><?php endif; ?></div></div><div class="col-md-4"><div class="ui-ly-doc"><div class="ui-ly-doc-label">Kuitansi / Surat Lunas dari Teller</div><?php if(!empty($kembali['bukti_lunas'])): ?><img src="<?= layananEscape(urlFoto($kembali['bukti_lunas'])) ?>" alt="Kuitansi atau surat lunas" class="ui-ly-doc-img" loading="lazy"><?php else: ?><div class="ui-ly-doc-empty">Kuitansi atau surat lunas belum tersedia</div><?php endif; ?></div></div></div></div></div></div></div>
<?php if(isAdmin() && in_array($kembali['status'],['menunggu','pengecekan'],true)): ?>
<div class="alert alert-info mb-2"><i class="ti ti-info-circle me-1" aria-hidden="true"></i>Periksa KTP, foto bukti jaminan, dan kuitansi/surat lunas dari Teller sebelum memberi keputusan.</div>
<?php layananForm('approve_jaminan',$id,'Approve Pengembalian Jaminan','<p class="ui-ly-modal-hint">Dokumen dan kondisi jaminan telah diperiksa. Sertakan keterangan keputusan.</p>'.$catatanField,'success'); layananForm('tolak_jaminan',$id,'Tolak Pengembalian Jaminan',$catatanField,'danger'); ?>
<?php endif; ?>
<?php if($kembali['status']==='disetujui') layananForm('serahkan_jaminan',$id,'Serahkan Jaminan',$buktiField.$ttdSerahField,'success'); ?>
<?php if($kembali['status']==='diserahkan'): ?><div class="btn-group" role="group" aria-label="Pilihan cetak akad"><a class="btn btn-outline-primary" href="kwitansi_layanan.php?jenis=bast&id=<?= (int)$kembali['id'] ?>" target="_blank" rel="noopener"><i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i>Cetak Akad Otomatis</a><a class="btn btn-outline-secondary" href="kwitansi_layanan.php?jenis=bast&id=<?= (int)$kembali['id'] ?>&mode=manual" target="_blank" rel="noopener"><i class="ti ti-edit me-1" aria-hidden="true"></i>Cetak Akad Manual</a></div><?php endif;endif;endif; ?>
</div>
<?php endif; ?>
</div></section>
</div>
<?php include __DIR__.'/layanan_detail_lanjutan.php'; ?>
<?php
$stmt=$db->prepare('SELECT b.*,u.nama_lengkap teller FROM layanan_bayar_denda b JOIN users u ON u.id=b.user_id WHERE b.pinjaman_id=?'.(isAdmin()?'':' AND b.user_id=?').' ORDER BY b.id DESC');$stmt->execute(isAdmin()?[$id]:[$id,$_SESSION['user_id']]);$buktiDenda=$stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt=$db->prepare('SELECT * FROM layanan_pembayaran WHERE pinjaman_id=? AND dibatalkan_at IS NULL ORDER BY id DESC');$stmt->execute([$id]);$buktiPembayaran=$stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<section class="card ui-ly-panel" aria-labelledby="bukti-pembayaran-title"><div class="card-header ui-dash-sec"><div><span class="ui-dash-sec-title" id="bukti-pembayaran-title"><i class="ti ti-receipt" aria-hidden="true"></i>Bukti pembayaran &amp; denda</span><p class="text-muted small mb-0 mt-1">Kwitansi dan status pemeriksaan.</p></div><span class="ui-dash-sec-note"><?= count($buktiDenda)+count($buktiPembayaran) ?> dokumen</span></div>
<div class="card-body"><?php if($buktiDenda || $buktiPembayaran): ?><div class="ui-ly-proof-list">
<?php foreach($buktiDenda as $b): ?><article class="ui-ly-proof"><div class="ui-ly-proof-icon is-penalty"><i class="ti ti-receipt-2" aria-hidden="true"></i></div><div class="ui-ly-proof-body"><div class="ui-ly-proof-title"><div><span>Pembayaran denda</span><strong><?= formatRupiah($b['jumlah']) ?></strong></div><span class="badge bg-<?= $b['approved_by']?'success':'warning' ?>"><?= $b['approved_by']?'Disetujui':'Menunggu review' ?></span></div><div class="ui-ly-proof-meta"><span><i class="ti ti-user" aria-hidden="true"></i><?= layananEscape($b['teller']) ?></span><span><i class="ti ti-calendar" aria-hidden="true"></i><?= !empty($b['created_at'])?layananEscape(formatTanggalUi($b['created_at'], true)):'—' ?></span></div><?php if(isAdmin() && !$b['approved_by']): ?><div class="ui-ly-actions mt-2"><?php layananForm('approve_denda',$id,'Approve Pembayaran Denda','<input type="hidden" name="bayar_id" value="'.(int)$b['id'].'">'); ?></div><?php endif; ?></div><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="kwitansi_layanan.php?jenis=denda&id=<?= (int)$b['id'] ?>"><i class="ti ti-external-link" aria-hidden="true"></i><span>Lihat</span></a></article><?php endforeach; ?>
<?php foreach($buktiPembayaran as $b): ?><article class="ui-ly-proof"><div class="ui-ly-proof-icon is-payment"><i class="ti ti-cash" aria-hidden="true"></i></div><div class="ui-ly-proof-body"><div class="ui-ly-proof-title"><div><span><?= layananEscape(ucwords(str_replace('_',' ',$b['jenis']))) ?></span><strong><?= formatRupiah($b['jumlah']) ?></strong></div><span class="badge bg-success">Tercatat</span></div><div class="ui-ly-proof-meta"><span><i class="ti ti-credit-card" aria-hidden="true"></i><?= layananEscape(ucfirst($b['metode'])) ?></span><?php if(!empty($b['created_at'])): ?><span><i class="ti ti-calendar" aria-hidden="true"></i><?= layananEscape(formatTanggalUi($b['created_at'], true)) ?></span><?php endif; ?></div></div><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="kwitansi_layanan.php?jenis=bayar&id=<?= (int)$b['id'] ?>"><i class="ti ti-external-link" aria-hidden="true"></i><span>Lihat</span></a></article><?php endforeach; ?>
</div><?php else: ?><div class="ui-ly-empty"><i class="ti ti-file-check" aria-hidden="true"></i><div><strong>Belum ada bukti transaksi</strong><p>Bukti muncul setelah pembayaran/denda dicatat.</p></div></div><?php endif; ?></div></section>
<?php $stmt=$db->prepare('SELECT l.*,u.nama_lengkap teller FROM layanan_laporan_macet l JOIN users u ON u.id=l.user_id WHERE l.pinjaman_id=?'.(isAdmin()?'':' AND l.user_id=?').' ORDER BY l.id DESC');$stmt->execute(isAdmin()?[$id]:[$id,$_SESSION['user_id']]);$laporanMacet=$stmt->fetchAll(PDO::FETCH_ASSOC); ?>
<section class="card ui-ly-panel" aria-labelledby="laporan-macet-title"><div class="card-header ui-dash-sec"><div><span class="ui-dash-sec-title" id="laporan-macet-title"><i class="ti ti-clipboard-list" aria-hidden="true"></i>Laporan macet</span><p class="text-muted small mb-0 mt-1">Riwayat keterlambatan dan keputusan.</p></div><span class="ui-dash-sec-note"><?= count($laporanMacet) ?> laporan</span></div>
<div class="card-body"><?php if($laporanMacet): ?><div class="ui-ly-report-list"><?php foreach($laporanMacet as $lap): $lapStatus=strtolower((string)$lap['status']); ?><article class="ui-ly-report"><div class="ui-ly-report-head"><div><span class="badge bg-<?= $lapStatus==='selesai'?'success':($lapStatus==='ditolak'?'danger':'warning') ?>"><?= layananEscape(ucfirst($lapStatus)) ?></span><strong><?= layananEscape($lap['teller']) ?></strong></div><time datetime="<?= layananEscape($lap['created_at']) ?>"><?= !empty($lap['created_at'])?layananEscape(formatTanggalUi($lap['created_at'], true)):'—' ?></time></div><dl class="ui-ly-report-detail"><div><dt>Catatan laporan</dt><dd><?= layananEscape(trim((string)$lap['catatan']) ?: 'Tidak ada catatan.') ?></dd></div><div><dt>Keputusan</dt><dd><?= layananEscape(trim((string)$lap['keputusan']) ?: 'Belum ada keputusan.') ?></dd></div></dl><?php if(isAdmin() && $lap['status']==='menunggu'): ?><div class="ui-ly-actions mt-2"><?php layananForm('keputusan_macet',$id,'Simpan Keputusan','<input type="hidden" name="laporan_id" value="'.(int)$lap['id'].'"><select class="form-select" name="keputusan"><option value="ditindaklanjuti">Ditindaklanjuti</option><option value="selesai">Selesai</option><option value="ditolak">Ditolak</option></select>'.$catatanField); ?></div><?php endif; ?></article><?php endforeach; ?></div><?php else: ?><div class="ui-ly-empty"><i class="ti ti-clipboard-check" aria-hidden="true"></i><div><strong>Tidak ada laporan macet</strong><p>Belum ada catatan penanganan.</p></div></div><?php endif; ?></div></section>
<?php if(isAdmin()): $stmt=$db->prepare('SELECT a.*,u.nama_lengkap FROM layanan_audit a JOIN users u ON u.id=a.user_id WHERE a.pinjaman_id=? ORDER BY a.id DESC LIMIT 100');$stmt->execute([$id]);$auditLogs=$stmt->fetchAll(PDO::FETCH_ASSOC); ?><section class="card ui-ly-panel" aria-labelledby="log-aktivitas-title"><div class="card-header ui-dash-sec"><div><span class="ui-dash-sec-title" id="log-aktivitas-title"><i class="ti ti-history" aria-hidden="true"></i>Log aktivitas</span><p class="text-muted small mb-0 mt-1">Perubahan terbaru pinjaman ini.</p></div><span class="ui-dash-sec-note"><?= count($auditLogs) ?> aktivitas</span></div><div class="card-body"><?php if($auditLogs): ?><div class="ui-ly-audit-list"><?php foreach($auditLogs as $log): ?><article class="ui-ly-audit"><div class="ui-ly-audit-icon"><i class="ti ti-check" aria-hidden="true"></i></div><div><div class="ui-ly-audit-head"><strong><?= layananEscape(ucwords(str_replace('_',' ',$log['aksi']))) ?></strong><time datetime="<?= layananEscape($log['created_at']) ?>"><?= !empty($log['created_at'])?layananEscape(formatTanggalUi($log['created_at'], true)):'—' ?></time></div><div class="ui-ly-audit-user"><i class="ti ti-user-shield" aria-hidden="true"></i><?= layananEscape($log['nama_lengkap']) ?></div><p><?= layananEscape(trim((string)($log['detail'] ?? '')) ?: 'Tidak ada catatan tambahan.') ?></p></div></article><?php endforeach; ?></div><?php else: ?><div class="ui-ly-empty"><i class="ti ti-history" aria-hidden="true"></i><div><strong>Belum ada aktivitas</strong><p>Perubahan akan tercatat otomatis.</p></div></div><?php endif; ?></div></section><?php endif; ?>
</div>
</section>
<?php endif; /* selected */ ?>
<?php endif; /* siap / list / detail */ ?>
</div><!-- /.ui-tx-page -->
<?php include __DIR__ . '/layout/shell_end.php'; ?>
<?php layananRenderModals(); ?>
<script src="../assets/js/denda-form.js?v=1"></script>
<script>
(function () {
  // Modal dokumen dll. yang masih di dalam card/flex → pindah ke body.
  document.querySelectorAll('.page .modal, .ui-tx-page .modal, .ui-ly-detail .modal').forEach(function (el) {
    if (el.parentElement !== document.body) document.body.appendChild(el);
  });
}());
</script>
</body></html>

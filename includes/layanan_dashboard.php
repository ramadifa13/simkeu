<?php
if (layananSiap($db)) {
    perbaruiDendaOtomatis($db);
    $portofolio=layananDaftar($db);
    $paidFine=$db->query("SELECT b.pinjaman_id,SUM(b.jumlah) jumlah FROM layanan_bayar_denda b JOIN angsuran a ON a.id=b.angsuran_id WHERE a.status<>'lunas' GROUP BY b.pinjaman_id")->fetchAll(PDO::FETCH_KEY_PAIR);$macet=0;$uang=0;$aktif=0;$lancar=0;$lunasHari=0;$siapJaminan=0;$repeat=0;
    foreach($portofolio as $r) {
        if($r['status']==='lunas') {
            if($r['tanggal_lunas']===date('Y-m-d'))$lunasHari++;
            if($r['status_jaminan']==='dititipkan')$siapJaminan++;
            if($r['tanggal_lunas'] && $r['tanggal_lunas']<=date('Y-m-d',strtotime('-7 days')) && !$r['repeat_selesai'])$repeat++;
        } else {
            $aktif++;if($r['hari_telat']<=3)$lancar++;
            if($r['hari_telat']>=7){$macet++;$uang+=max(0,$r['sisa_tagihan']-($paidFine[$r['id']] ?? 0)-layananKreditLelang($db,$r['id']));}
        }
    }
    $scope=isAdmin()?'':' AND b.user_id='.(int)$_SESSION['user_id'];
    $bayarHari=$db->query("SELECT COUNT(*) jumlah,COALESCE(SUM(b.jumlah),0) total FROM layanan_pembayaran b WHERE b.jenis IN ('angsuran','pelunasan') AND b.dibatalkan_at IS NULL AND DATE(b.created_at)=CURDATE()$scope")->fetch(PDO::FETCH_ASSOC);
    $sisaDenda="GREATEST(0,a.denda-COALESCE((SELECT SUM(bd.jumlah) FROM layanan_bayar_denda bd WHERE bd.angsuran_id=a.id),0))";
    $dendaHari=$db->query("SELECT COUNT(DISTINCT p.anggota_id) jumlah,COALESCE(SUM($sisaDenda),0) total FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id WHERE a.status<>'lunas' AND $sisaDenda>0".(isAdmin()?'':' AND p.dicairkan_oleh='.(int)$_SESSION['user_id']))->fetch(PDO::FETCH_ASSOC);
    $tagihan=$db->query("SELECT COUNT(*) jumlah,COALESCE(SUM(jumlah_angsuran+denda),0) total FROM angsuran WHERE tanggal_jatuh_tempo=CURDATE()")->fetch(PDO::FETCH_ASSOC);
    $persen=$aktif?round($macet/$aktif*100,1):0;
    $sudahHari=(int)$db->query("SELECT COUNT(*) FROM angsuran WHERE tanggal_jatuh_tempo=CURDATE() AND status='lunas'")->fetchColumn();
?>
<section class="card my-3"><div class="card-body"><h2 class="h5">Pemantauan Angsuran</h2>
<div class="row g-3"><div class="col-md-3">Tagihan jatuh tempo hari ini<strong class="d-block"><?= formatRupiah($tagihan['total']) ?></strong><?= (int)$tagihan['jumlah'] ?> cicilan</div><div class="col-md-3">Pembayaran hari ini<?= isAdmin()?'':' oleh saya' ?><strong class="d-block"><?= formatRupiah($bayarHari['total']) ?></strong><?= (int)$bayarHari['jumlah'] ?> pembayaran</div><div class="col-md-3">Denda belum lunas<?= isAdmin()?'':' nasabah pencairan saya' ?><strong class="d-block"><?= formatRupiah($dendaHari['total']) ?></strong><?= (int)$dendaHari['jumlah'] ?> nasabah</div><div class="col-md-3">Lunas hari ini<strong class="d-block"><?= $lunasHari ?> pinjaman</strong>Jaminan belum diambil: <?= $siapJaminan ?></div></div>
<?php if(isAdmin()): ?>
<div class="portfolio-chart"><div class="portfolio-donut" role="img" aria-label="<?= $macet ?> dari <?= $aktif ?> pinjaman aktif macet" style="background:<?= $aktif?'conic-gradient(#d64d5f 0% '.$persen.'%,#168565 '.$persen.'% 100%)':'#dce6e9' ?>"><span><?= $aktif ?><small>pinjaman aktif</small></span></div><div><h3 class="h6">Kualitas pinjaman aktif</h3><p><span class="text-success">?</span> Aktif lainnya: <strong><?= $aktif-$macet ?></strong> &nbsp; <span class="text-danger">?</span> Macet =7 hari: <strong><?= $macet ?> (<?= $persen ?>%)</strong></p><p>Total uang macet: <strong><?= formatRupiah($uang) ?></strong></p><p><a href="layanan.php?tab=lunas">Tindak lanjut <?= $repeat ?> pinjaman lunas =7 hari</a></p></div></div>
<div class="ui-insight-grid"><section class="ui-insight"><h3 class="h6 mt-3">Pembayaran Denda per Minggu (8 minggu terakhir)</h3>
<?php $minggu=$db->query('SELECT YEARWEEK(created_at,3) minggu,SUM(jumlah) total FROM layanan_bayar_denda WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 8 WEEK) GROUP BY YEARWEEK(created_at,3) ORDER BY minggu')->fetchAll(PDO::FETCH_ASSOC);$maks=1;foreach($minggu as $m)$maks=max($maks,$m['total']);foreach($minggu as $m): ?><div class="d-flex align-items-center gap-2 my-1"><small style="width:70px"><?= layananEscape($m['minggu']) ?></small><div style="height:18px;background:#0d6efd;width:<?= round($m['total']/$maks*60,2) ?>%"></div><small><?= formatRupiah($m['total']) ?></small></div><?php endforeach; ?>
<?php if(!$minggu): ?><p class="text-muted small py-3">Belum ada pembayaran denda dalam 8 minggu terakhir.</p><?php endif; ?></section><section class="ui-insight"><h3 class="h6 mt-3">Top 5 Nasabah Paling Sering Kena Denda</h3><ol><?php $top=$db->query('SELECT ag.nama_lengkap,COUNT(*) jumlah FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id WHERE a.denda>0 GROUP BY ag.id,ag.nama_lengkap ORDER BY jumlah DESC,ag.id LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);foreach($top as $t): ?><li><?= layananEscape($t['nama_lengkap']) ?> · <?= (int)$t['jumlah'] ?> cicilan</li><?php endforeach; ?></ol>
</section><section class="ui-insight"><h3 class="h6 mt-3">Lunas per Bulan (12 bulan terakhir)</h3>
<?php $totalSelesai=(int)$db->query("SELECT COUNT(*) FROM pinjaman WHERE status='lunas'")->fetchColumn();$pctLunas=($totalSelesai+$macet)>0?round($totalSelesai/($totalSelesai+$macet)*100,1):0;$bonusTahun=kolomAda($db,'shu_hak_pinjaman','jumlah')?(float)$db->query('SELECT COALESCE(SUM(jumlah),0) FROM shu_hak_pinjaman WHERE YEAR(created_at)=YEAR(CURDATE())')->fetchColumn():0; ?>
<p>Lunas dibanding lunas + macet: <strong><?= $pctLunas ?>%</strong> · Hak SHU dari pelunasan tahun ini: <strong><?= formatRupiah($bonusTahun) ?></strong>.</p>
<?php $bulanLunas=$db->query("SELECT DATE_FORMAT(tanggal_lunas,'%Y-%m') bulan,COUNT(*) jumlah FROM pinjaman WHERE status='lunas' AND tanggal_lunas>=DATE_SUB(CURDATE(),INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(tanggal_lunas,'%Y-%m') ORDER BY bulan")->fetchAll(PDO::FETCH_ASSOC);$maxLunas=1;foreach($bulanLunas as $bl)$maxLunas=max($maxLunas,$bl['jumlah']);foreach($bulanLunas as $bl): ?><div class="d-flex align-items-center gap-2 my-1"><small style="width:70px"><?= layananEscape($bl['bulan']) ?></small><div style="height:18px;background:#198754;width:<?= round($bl['jumlah']/$maxLunas*60,2) ?>%"></div><small><?= (int)$bl['jumlah'] ?> lunas</small></div><?php endforeach; ?>
<?php if(!$bulanLunas): ?><p class="text-muted small">Belum ada pelunasan dalam 12 bulan terakhir.</p><?php endif; ?></section></div>
<?php endif; ?>
<p class="mt-3 mb-0">Dari <?= (int)$tagihan['jumlah'] ?> cicilan jatuh tempo hari ini, <?= $sudahHari ?> sudah dibayar.</p>
<a class="btn btn-primary mt-3" href="layanan.php">Laporan Macet, Denda & Pelunasan</a></div></section>
<?php } ?>

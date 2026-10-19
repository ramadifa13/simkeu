<?php if (layananSiap($db)):
perbaruiDendaOtomatis($db);
$fineScope=isAdmin()?'':' AND p.dicairkan_oleh='.(int)$_SESSION['user_id'];
$fineDue="GREATEST(0,a.denda-COALESCE((SELECT SUM(b.jumlah) FROM layanan_bayar_denda b WHERE b.angsuran_id=a.id),0))";
$fineToday=$db->query("SELECT COUNT(DISTINCT p.anggota_id) jumlah,COALESCE(SUM($fineDue),0) total FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id WHERE a.status<>'lunas' AND a.tanggal_jatuh_tempo<CURDATE() AND $fineDue>0 $fineScope")->fetch(PDO::FETCH_ASSOC);
?>
<section class="card my-3"><div class="card-body">
<h2 class="h5">Dashboard Denda</h2>
<p>Hari ini ada <strong><?= (int)$fineToday['jumlah'] ?> nasabah</strong> dengan denda belum lunas, total <strong><?= formatRupiah($fineToday['total']) ?></strong><?= isAdmin()?'':' (nasabah pencairan saya)' ?>.</p>
<a class="btn btn-outline-primary" href="denda.php">Input keterlambatan & bayar denda</a>
<a class="btn btn-outline-secondary" href="laporan_denda.php">Laporan denda <?= isAdmin()?'semua teller':'saya' ?></a>
<?php if(isAdmin()): ?><a class="btn btn-outline-secondary" href="layanan.php?tab=pengaturan">Setting tarif, template WA & pemutihan</a>
<div class="row g-3 mt-2"><div class="col-md-6"><h3 class="h6">Pembayaran Denda per Minggu</h3>
<?php
$fineWeeks=$db->query('SELECT DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY) minggu,SUM(jumlah) total FROM layanan_bayar_denda WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 8 WEEK) GROUP BY DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY) ORDER BY minggu')->fetchAll(PDO::FETCH_ASSOC);
$fineMax=1;foreach($fineWeeks as $fw)$fineMax=max($fineMax,$fw['total']);
foreach($fineWeeks as $fw): ?>
<div class="mb-2"><small>Minggu <?= layananEscape($fw['minggu']) ?> · <?= formatRupiah($fw['total']) ?></small><div class="progress"><div class="progress-bar bg-danger" role="progressbar" aria-label="Denda minggu <?= layananEscape($fw['minggu']) ?>" aria-valuenow="<?= (float)$fw['total'] ?>" aria-valuemin="0" aria-valuemax="<?= (float)$fineMax ?>" style="width:<?= round($fw['total']/$fineMax*100,2) ?>%"></div></div></div>
<?php endforeach;if(!$fineWeeks): ?><p>Belum ada pembayaran denda.</p><?php endif; ?>
</div><div class="col-md-6"><h3 class="h6">Top 5 Nasabah Paling Sering Kena Denda</h3><ol>
<?php $fineTop=$db->query('SELECT ag.nama_lengkap,COUNT(*) jumlah FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id WHERE a.denda>0 OR EXISTS(SELECT 1 FROM layanan_bayar_denda b WHERE b.angsuran_id=a.id) GROUP BY ag.id,ag.nama_lengkap ORDER BY jumlah DESC,ag.id LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);foreach($fineTop as $ft): ?>
<li><?= layananEscape($ft['nama_lengkap']) ?> · <?= (int)$ft['jumlah'] ?> cicilan</li>
<?php endforeach; ?></ol></div></div>
<?php endif; ?></div></section>
<?php endif; ?>

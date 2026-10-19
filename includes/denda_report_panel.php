<?php
$dueScope=isAdmin()?'':' AND p.dicairkan_oleh='.(int)$_SESSION['user_id'];
$dueStmt=$db->prepare("SELECT a.*,ag.nama_lengkap,ag.telepon,p.kode_pinjaman,p.jumlah_pinjaman,jp.memerlukan_jaminan,
 COALESCE((SELECT SUM(b.jumlah) FROM layanan_bayar_denda b WHERE b.angsuran_id=a.id),0) dibayar
 FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id
 JOIN jenis_pinjaman jp ON jp.id=p.jenis_pinjaman_id
 WHERE a.status<>'lunas' AND a.tanggal_jatuh_tempo<=CURDATE() $dueScope
 AND (ag.nama_lengkap LIKE ? OR p.kode_pinjaman LIKE ?) ORDER BY a.tanggal_jatuh_tempo,a.id");
$dueStmt->execute(['%'.$q.'%','%'.$q.'%']);$fineDueRows=$dueStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<h2 class="h5">Data Jatuh Tempo <?= isAdmin()?'':'Nasabah Saya' ?></h2>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Nama / Pinjaman</th><th>Tgl Jatuh Tempo</th><th>Hari Ini</th><th>Telat Hari</th><th>Tarif / Hari</th><th>Denda Otomatis</th><th>Status Bayar Denda</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($fineDueRows as $fd): $calc=hitungDendaAngsuran($db,$fd,$fd['jumlah_pinjaman']);$fineRemaining=max(0,$fd['denda']-$fd['dibayar']); ?>
<tr><td><?= layananEscape($fd['nama_lengkap']) ?><br><small><?= layananEscape($fd['kode_pinjaman']) ?> / <?= (int)$fd['angsuran_ke'] ?></small></td><td><?= layananEscape($fd['tanggal_jatuh_tempo']) ?></td><td><?= date('Y-m-d') ?></td><td><?= $calc['hari_telat'] ?></td><td><?= formatRupiah($calc['per_hari']) ?></td><td><?= formatRupiah($calc['denda']) ?></td><td><?= $fineRemaining>0?'Belum lunas':'Lunas' ?></td><td><a class="btn btn-sm btn-outline-primary" href="?tab=denda&id=<?= (int)$fd['pinjaman_id'] ?>#detail">Detail / Bayar</a></td></tr>
<?php endforeach;if(!$fineDueRows): ?><tr><td colspan="8" class="text-center">Tidak ada tagihan jatuh tempo.</td></tr><?php endif; ?></tbody></table></div>
<h2 class="h5 mt-4">Laporan Denda Lengkap <?= isAdmin()?'Semua Teller':'Saya' ?></h2>
<?php if(isAdmin()): ?><a class="btn btn-outline-success mb-2" href="?tab=denda&export=denda">Export Laporan Denda untuk RAT (Excel CSV)</a><?php endif; ?>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Nama</th><th>Telat</th><th>Denda Awal</th><th>Diskon</th><th>Denda Akhir</th><th>Dibayar</th><th>Status</th><th>Teller</th><th>Review</th><th>Aksi</th></tr></thead><tbody>
<?php
$paymentScope=isAdmin()?'':' AND b.user_id='.(int)$_SESSION['user_id'];
$fineHistory=$db->prepare("SELECT b.*,ag.nama_lengkap,ag.telepon,u.nama_lengkap teller,a.status angsuran_status,a.denda denda_sekarang,
 (SELECT COALESCE(SUM(x.jumlah),0) FROM layanan_bayar_denda x WHERE x.angsuran_id=b.angsuran_id) total_bayar
 FROM layanan_bayar_denda b JOIN pinjaman p ON p.id=b.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id
 JOIN angsuran a ON a.id=b.angsuran_id JOIN users u ON u.id=b.user_id
 WHERE (ag.nama_lengkap LIKE ? OR p.kode_pinjaman LIKE ?) $paymentScope ORDER BY b.id DESC");
$fineHistory->execute(['%'.$q.'%','%'.$q.'%']);$fineHistoryRows=$fineHistory->fetchAll(PDO::FETCH_ASSOC);
foreach($fineHistoryRows as $fr):
$fineMessage=strtr($settings['wa_denda'],['{nama}'=>$fr['nama_lengkap'],'{hari}'=>$fr['hari_telat'],'{denda}'=>number_format(($fr['angsuran_status']==='lunas'?0:max(0,$fr['denda_sekarang']-$fr['total_bayar'])),0,',','.')]); ?>
<tr><td><?= layananEscape($fr['nama_lengkap']) ?></td><td><?= (int)$fr['hari_telat'] ?> hari</td><td><?= formatRupiah($fr['denda_awal']) ?></td><td><?= formatRupiah(max(0,$fr['denda_awal']-$fr['denda_akhir'])) ?></td><td><?= formatRupiah($fr['denda_akhir']) ?></td><td><?= formatRupiah($fr['jumlah']) ?></td><td><?= ($fr['angsuran_status']==='lunas' || $fr['total_bayar']+0.005>=$fr['denda_sekarang'])?'Lunas':'Belum' ?></td><td><?= layananEscape($fr['teller']) ?></td><td><?= $fr['approved_by']?'Disetujui / terkunci':'Menunggu review' ?></td><td><a href="?tab=denda&id=<?= (int)$fr['pinjaman_id'] ?>#detail">Detail</a> · <a href="kwitansi_layanan.php?jenis=denda&id=<?= (int)$fr['id'] ?>" target="_blank" rel="noopener">Kwitansi</a> <?= tombolWhatsApp($fr['telepon'],$fineMessage,'WA') ?></td></tr>
<?php endforeach;
// Tagihan yang belum pernah dibayar tetap muncul di laporan, bukan hanya riwayat kas.
foreach($fineDueRows as $fd):if(($fd['denda']<=0 && empty($fd['denda_manual'])) || $fd['dibayar']>0)continue;$calc=hitungDendaAngsuran($db,$fd,$fd['jumlah_pinjaman']);
$fineMessage=strtr($settings['wa_denda'],['{nama}'=>$fd['nama_lengkap'],'{hari}'=>$calc['hari_telat'],'{denda}'=>number_format($fd['denda'],0,',','.')]); ?>
<tr><td><?= layananEscape($fd['nama_lengkap']) ?></td><td><?= $calc['hari_telat'] ?> hari</td><td><?= formatRupiah($calc['denda']) ?></td><td><?= formatRupiah(max(0,$calc['denda']-$fd['denda'])) ?></td><td><?= formatRupiah($fd['denda']) ?></td><td>Rp 0</td><td><?= $fd['denda']>0?'Belum':'Lunas (pemutihan)' ?></td><td>—</td><td>Belum dibayar</td><td><a href="?tab=denda&id=<?= (int)$fd['pinjaman_id'] ?>#detail">Detail / Bayar</a> <?= tombolWhatsApp($fd['telepon'],$fineMessage,'WA') ?></td></tr>
<?php endforeach; ?></tbody></table></div>

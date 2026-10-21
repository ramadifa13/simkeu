<?php
// Berada dalam bagian detail pinjaman; formulir memakai handler dan transaksi yang sama.
?>
<?php
$riwayatTelat=[];$jumlahTelat=0;$totalHariTelat=0;$telatMaksimal=0;
foreach($histori as $ha){
    $tanggalAkhir=$ha['tanggal_bayar'] ?: date('Y-m-d');
    $late=max(0,(int)(new DateTime($ha['tanggal_jatuh_tempo']))->diff(new DateTime($tanggalAkhir))->format('%r%a'));
    if($late>0){$jumlahTelat++;$totalHariTelat+=$late;$telatMaksimal=max($telatMaksimal,$late);}
    $riwayatTelat[]=['data'=>$ha,'hari'=>$late];
}
$rataTelat=$jumlahTelat ? round($totalHariTelat/$jumlahTelat,1) : 0;
?>
<section class="service-late-panel" aria-labelledby="riwayat-keterlambatan-title">
<div class="service-panel-heading"><div><span class="service-eyebrow">KEDISIPLINAN PEMBAYARAN</span><h3 class="h5" id="riwayat-keterlambatan-title">Riwayat keterlambatan</h3><p>Perbandingan jatuh tempo dan tanggal pembayaran setiap cicilan.</p></div><span class="service-result-count"><?= count($riwayatTelat) ?> cicilan</span></div>
<div class="service-late-summary"><div><span>Tepat waktu</span><strong><?= max(0,count($riwayatTelat)-$jumlahTelat) ?></strong></div><div><span>Pernah terlambat</span><strong><?= $jumlahTelat ?></strong></div><div><span>Rata-rata telat</span><strong><?= layananEscape(number_format($rataTelat,1,',','.')) ?> hari</strong></div><div><span>Telat terlama</span><strong><?= $telatMaksimal ?> hari</strong></div></div>
<?php if($riwayatTelat): ?><div class="service-late-list">
<?php foreach($riwayatTelat as $item): $ha=$item['data'];$late=$item['hari'];$lateClass=$late===0?'is-on-time':($late<=3?'is-light':($late<=30?'is-late':'is-severe')); ?>
<article class="service-late-row <?= $lateClass ?>"><div class="service-installment-number"><span>Cicilan</span><strong>Ke-<?= (int)$ha['angsuran_ke'] ?></strong></div><div class="service-late-dates"><div><span>Jatuh tempo</span><strong><?= layananEscape(date('d/m/Y',strtotime($ha['tanggal_jatuh_tempo']))) ?></strong></div><i class="fas fa-arrow-right" aria-hidden="true"></i><div><span>Dibayar</span><strong><?= !empty($ha['tanggal_bayar'])?layananEscape(date('d/m/Y',strtotime($ha['tanggal_bayar']))):'Belum dibayar' ?></strong></div></div><div class="service-late-status"><span class="badge"><?= $late===0?'Tepat waktu':$late.' hari terlambat' ?></span><div class="service-late-track" aria-hidden="true"><span style="width:<?= $late===0?100:max(8,min(100,round($late/60*100))) ?>%"></span></div></div></article>
<?php endforeach; ?></div><?php else: ?><div class="service-empty compact"><i class="fas fa-calendar-check" aria-hidden="true"></i><strong>Belum ada riwayat cicilan</strong><p>Riwayat keterlambatan akan tampil setelah jadwal angsuran tersedia.</p></div><?php endif; ?>
</section>
<section class="service-workflow-panel service-secondary-workflow" aria-labelledby="kebijakan-layanan-title"><div class="service-panel-heading"><div><span class="service-eyebrow">KEBIJAKAN & TINDAK LANJUT</span><h3 class="h5" id="kebijakan-layanan-title">Layanan tambahan</h3><p>Penanganan jaminan, penghapusan denda, dan tindak lanjut setelah pelunasan.</p></div></div><div class="service-workflow-list">
<?php
$s=$db->prepare('SELECT * FROM layanan_lelang WHERE pinjaman_id=?');$s->execute([$id]);$lelang=$s->fetch(PDO::FETCH_ASSOC);
if($lelang): ?>
<div class="service-auction-card"><div class="service-auction-head"><div><i class="fas fa-gavel" aria-hidden="true"></i><div><span>Hasil penjualan jaminan</span><strong><?= formatRupiah($lelang['harga_jual']) ?></strong></div></div><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="kwitansi_layanan.php?jenis=lelang&id=<?= (int)$lelang['id'] ?>">Lihat kwitansi</a></div><dl><div><dt>Dialokasikan</dt><dd><?= formatRupiah($lelang['terpakai']) ?></dd></div><div><dt>Kredit berikutnya</dt><dd><?= formatRupiah($lelang['kredit']) ?></dd></div><div><dt>Kelebihan nasabah</dt><dd><?= formatRupiah($lelang['kelebihan']) ?></dd></div><div><dt>Sudah dikembalikan</dt><dd><?= formatRupiah($lelang['dikembalikan']) ?></dd></div></dl></div>
<?php if(isAdmin() && $lelang['kelebihan']>$lelang['dikembalikan'])layananForm('kembalikan_lebih',$id,'Kembalikan Kelebihan Hasil Penjualan',$buktiField,'warning'); ?>
<?php elseif(isAdmin() && $p['status']!=='lunas' && $p['status_jaminan']==='dilelang'):
layananForm('jual_jaminan',$id,'Catat Penjualan Jaminan','<label>Harga jual diterima (tanpa potongan tersembunyi)</label><input name="jumlah" type="number" class="form-control mb-2" min="0.01" step="0.01" required>'.$metodeField.$buktiField.$catatanField,'danger');
endif;
if($options && $p['status']!=='lunas')layananForm('ajukan_hapus_denda',$id,'Ajukan Penghapusan Sisa Denda',$pilihAngsuran.$catatanField,'warning');
$s=$db->prepare('SELECT h.*,u.nama_lengkap teller FROM layanan_pengajuan_hapus_denda h JOIN users u ON u.id=h.user_id WHERE h.pinjaman_id=?'.(isAdmin()?'':' AND h.user_id=?').' ORDER BY h.id DESC');$s->execute(isAdmin()?[$id]:[$id,$_SESSION['user_id']]);
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $hapus): ?>
<div class="service-request-card"><div class="service-request-head"><div><span>Penghapusan denda</span><strong><?= layananEscape($hapus['teller']) ?></strong></div><span class="badge bg-<?= $hapus['status']==='ditolak'?'danger':($hapus['status']==='disetujui'?'success':'warning') ?>"><?= layananEscape(ucfirst($hapus['status'])) ?></span></div><p><?= layananEscape(trim((string)$hapus['alasan']) ?: 'Tidak ada alasan yang dicatat.') ?></p>
<?php if(isAdmin() && $hapus['status']==='menunggu')layananForm('putuskan_hapus_denda',$id,'Putuskan Penghapusan Denda','<input type="hidden" name="pengajuan_id" value="'.(int)$hapus['id'].'"><select name="keputusan" class="form-select"><option value="setuju">Setujui (hanya denda yang belum dibayar)</option><option value="tolak">Tolak</option></select>'); ?></div>
<?php endforeach;
if(isAdmin() && $p['status']==='lunas') {
    layananForm('repeat_order',$id,'Tandai / Catat Penawaran Pinjaman Ulang',$catatanField);
    if(!$p['memerlukan_jaminan']) echo '<div class="service-note"><i class="fas fa-circle-info" aria-hidden="true"></i><p>Hak SHU pelunasan dikelola pada menu <strong>SHU &rarr; Pembayaran SHU</strong>.</p></div>';
    if(false && !$p['memerlukan_jaminan']) {
        $s=$db->prepare('SELECT * FROM layanan_bonus WHERE pinjaman_id=?');$s->execute([$id]);$bonus=$s->fetch(PDO::FETCH_ASSOC);
        if($bonus)echo '<p>Bonus dibagikan '.formatRupiah($bonus['jumlah']).' ('.$bonus['tahun'].') · <a target="_blank" rel="noopener" href="kwitansi_layanan.php?jenis=bonus&id='.(int)$bonus['id'].'">Kwitansi bonus</a></p>';
        else {
            $bungaBonus=layananBungaDiterima($db,$id);$nominalBonus=round($bungaBonus*$settings['bonus_persen']/100,2);
            echo '<p>Simulasi bonus: '.formatRupiah($bungaBonus).' bunga diterima × '.layananEscape($settings['bonus_persen']).'% = '.formatRupiah($nominalBonus).'. Setiap pinjaman mendapat satu bonus; pelaporan dikelompokkan per tahun pembayaran.</p>';
            if($nominalBonus>0)layananForm('bayar_bonus',$id,'Bagikan Bonus Loyalitas','<label>Nominal hasil perhitungan</label><input name="jumlah" type="number" class="form-control" value="'.layananEscape($nominalBonus).'" readonly>'.$metodeField.$buktiField,'success');
            else echo '<p>Admin perlu menetapkan persentase bonus pada Pengaturan sebelum membagikan.</p>';
        }
    }
}
?>
</div></section>

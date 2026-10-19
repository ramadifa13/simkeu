<?php
// Berada dalam bagian detail pinjaman; formulir memakai handler dan transaksi yang sama.
?>
<h3 class="h6 mt-3">Riwayat Keterlambatan (hari per cicilan)</h3>
<?php $jumlahTelat=0;foreach($histori as $ha):$late=max(0,(int)(new DateTime($ha['tanggal_jatuh_tempo']))->diff(new DateTime($ha['tanggal_bayar'] ?: date('Y-m-d')))->format('%r%a'));if($late>0)$jumlahTelat++; ?>
<div class="d-flex gap-2 align-items-center mb-1"><small style="width:60px">Ke-<?= (int)$ha['angsuran_ke'] ?></small><div style="height:14px;background:<?= $late?'#dc3545':'#198754' ?>;width:<?= max(1,min(60,$late)) ?>%"></div><small><?= $late ?> hari</small></div>
<?php endforeach; ?><p class="small">Pernah terlambat <?= $jumlahTelat ?> cicilan.</p>
<?php
$s=$db->prepare('SELECT * FROM layanan_lelang WHERE pinjaman_id=?');$s->execute([$id]);$lelang=$s->fetch(PDO::FETCH_ASSOC);
if($lelang): ?>
<div class="alert alert-info">Hasil penjualan jaminan: <?= formatRupiah($lelang['harga_jual']) ?> · Dialokasikan: <?= formatRupiah($lelang['terpakai']) ?> · Kredit pembayaran berikutnya: <?= formatRupiah($lelang['kredit']) ?> · Kelebihan milik nasabah: <?= formatRupiah($lelang['kelebihan']) ?> · Sudah dikembalikan: <?= formatRupiah($lelang['dikembalikan']) ?>.
<a target="_blank" rel="noopener" href="kwitansi_layanan.php?jenis=lelang&id=<?= (int)$lelang['id'] ?>">Kwitansi Penjualan</a></div>
<?php if(isAdmin() && $lelang['kelebihan']>$lelang['dikembalikan'])layananForm('kembalikan_lebih',$id,'Kembalikan Kelebihan Hasil Penjualan',$buktiField,'warning'); ?>
<?php elseif(isAdmin() && $p['status']!=='lunas' && $p['status_jaminan']==='dilelang'):
layananForm('jual_jaminan',$id,'Catat Penjualan Jaminan','<label>Harga jual diterima (tanpa potongan tersembunyi)</label><input name="jumlah" type="number" class="form-control mb-2" min="0.01" step="0.01" required>'.$metodeField.$buktiField.$catatanField,'danger');
endif;
if($options && $p['status']!=='lunas')layananForm('ajukan_hapus_denda',$id,'Ajukan Penghapusan Sisa Denda',$pilihAngsuran.$catatanField,'warning');
$s=$db->prepare('SELECT h.*,u.nama_lengkap teller FROM layanan_pengajuan_hapus_denda h JOIN users u ON u.id=h.user_id WHERE h.pinjaman_id=?'.(isAdmin()?'':' AND h.user_id=?').' ORDER BY h.id DESC');$s->execute(isAdmin()?[$id]:[$id,$_SESSION['user_id']]);
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $hapus): ?>
<div class="border rounded p-2 mb-2">Penghapusan denda · <?= layananEscape($hapus['status'].' · '.$hapus['teller'].' · '.$hapus['alasan']) ?>
<?php if(isAdmin() && $hapus['status']==='menunggu')layananForm('putuskan_hapus_denda',$id,'Putuskan Penghapusan Denda','<input type="hidden" name="pengajuan_id" value="'.(int)$hapus['id'].'"><select name="keputusan" class="form-select"><option value="setuju">Setujui (hanya denda yang belum dibayar)</option><option value="tolak">Tolak</option></select>'); ?></div>
<?php endforeach;
if(isAdmin() && $p['status']==='lunas') {
    layananForm('repeat_order',$id,'Tandai / Catat Penawaran Pinjaman Ulang',$catatanField);
    if(!$p['memerlukan_jaminan']) echo '<p>Hak SHU pelunasan dikelola pada menu <strong>SHU &rarr; Pembayaran SHU</strong>.</p>';
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

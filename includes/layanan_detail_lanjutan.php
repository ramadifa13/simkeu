<?php
// Berada dalam bagian detail pinjaman; formulir memakai handler dan transaksi yang sama.
?>
<?php
$riwayatTelat = [];
$jumlahTelat = 0;
$totalHariTelat = 0;
$telatMaksimal = 0;
$tepatWaktu = 0;
$belumJatuhTempo = 0;
foreach ($histori as $ha) {
    $sudahBayar = !empty($ha['tanggal_bayar']) || strtolower((string)($ha['status'] ?? '')) === 'lunas';
    $tanggalAkhir = $ha['tanggal_bayar'] ?: date('Y-m-d');
    $late = max(0, (int)(new DateTime($ha['tanggal_jatuh_tempo']))->diff(new DateTime($tanggalAkhir))->format('%r%a'));
    if (!$sudahBayar && $late === 0) {
        $belumJatuhTempo++;
        $tone = 'pending';
    } elseif ($late === 0) {
        $tepatWaktu++;
        $tone = 'on-time';
    } else {
        $jumlahTelat++;
        $totalHariTelat += $late;
        $telatMaksimal = max($telatMaksimal, $late);
        $tone = $late <= 3 ? 'light' : ($late <= 30 ? 'late' : 'severe');
    }
    $riwayatTelat[] = ['data' => $ha, 'hari' => $late, 'bayar' => $sudahBayar, 'tone' => $tone];
}
$rataTelat = $jumlahTelat ? round($totalHariTelat / $jumlahTelat, 1) : 0;
?>
<section class="card ui-ly-panel" aria-labelledby="riwayat-keterlambatan-title">
<div class="card-header ui-dash-sec">
<div>
<span class="ui-dash-sec-title" id="riwayat-keterlambatan-title"><i class="ti ti-clock-exclamation" aria-hidden="true"></i>Riwayat keterlambatan</span>
<p class="text-muted small mb-0 mt-1">Jatuh tempo dibanding tanggal bayar tiap cicilan.</p>
</div>
<span class="ui-dash-sec-note"><?= count($riwayatTelat) ?> cicilan</span>
</div>
<div class="card-body">
<div class="ui-ly-late-summary">
<div><span>Tepat waktu</span><strong><?= (int)$tepatWaktu ?></strong></div>
<div><span>Terlambat</span><strong><?= (int)$jumlahTelat ?></strong></div>
<div><span>Belum jatuh tempo</span><strong><?= (int)$belumJatuhTempo ?></strong></div>
<div><span>Telat terlama</span><strong><?= $telatMaksimal > 0 ? $telatMaksimal . ' hari' : '—' ?></strong></div>
</div>
<?php if ($riwayatTelat): ?>
<div class="ui-ly-late-list">
<?php foreach ($riwayatTelat as $item):
    $ha = $item['data'];
    $late = (int)$item['hari'];
    $sudahBayar = !empty($item['bayar']);
    $tone = $item['tone'];
    $ketepatan = function_exists('layananLabelKetepatanCicilan')
        ? layananLabelKetepatanCicilan($ha, $late)
        : [$late === 0 ? ($sudahBayar ? 'Tepat waktu' : 'Belum jatuh tempo') : $late . ' hari terlambat', $late === 0 ? ($sudahBayar ? 'success' : 'info') : 'danger'];
    $caraLbl = ($sudahBayar && function_exists('layananLabelCaraBayar'))
        ? layananLabelCaraBayar($ha['status'] ?? '', $ha['cara_bayar'] ?? '')
        : null;
    $trackPct = $late > 0 ? max(12, min(100, (int)round($late / 60 * 100))) : 0;
?>
<article class="ui-ly-late-row is-<?= layananEscape($tone) ?>">
    <div class="ui-ly-late-ke" aria-label="Cicilan ke-<?= (int)$ha['angsuran_ke'] ?>">
        <span>Ke</span>
        <strong><?= (int)$ha['angsuran_ke'] ?></strong>
    </div>
    <div class="ui-ly-late-main">
        <div class="ui-ly-late-flow">
            <div class="ui-ly-late-point">
                <span>Jatuh tempo</span>
                <strong><?= layananEscape(formatTanggalUi($ha['tanggal_jatuh_tempo'])) ?></strong>
            </div>
            <span class="ui-ly-late-arrow" aria-hidden="true"><i class="ti ti-arrow-narrow-right"></i></span>
            <div class="ui-ly-late-point">
                <span>Dibayar</span>
                <strong class="<?= $sudahBayar ? '' : 'is-muted' ?>"><?= $sudahBayar ? layananEscape(formatTanggalUi($ha['tanggal_bayar'])) : 'Belum dibayar' ?></strong>
            </div>
        </div>
        <?php if ($late > 0): ?>
        <div class="ui-ly-late-track" role="presentation"><span style="width:<?= $trackPct ?>%"></span></div>
        <?php endif; ?>
    </div>
    <div class="ui-ly-late-side">
        <span class="ui-ly-late-badge tone-<?= layananEscape($ketepatan[1]) ?>"><?= layananEscape($ketepatan[0]) ?></span>
        <?php if ($caraLbl): ?>
        <span class="ui-ly-late-badge tone-<?= layananEscape($caraLbl[1]) ?> is-soft"><?= layananEscape($caraLbl[0]) ?></span>
        <?php endif; ?>
    </div>
</article>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="ui-ly-empty compact"><i class="ti ti-calendar-check" aria-hidden="true"></i><div><strong>Belum ada riwayat cicilan</strong><p>Riwayat muncul setelah jadwal angsuran tersedia.</p></div></div>
<?php endif; ?>
</div>
</section>
<section class="card ui-ly-panel ui-ly-workflow" aria-labelledby="kebijakan-layanan-title">
<div class="card-header ui-dash-sec">
<div>
<span class="ui-dash-sec-title" id="kebijakan-layanan-title"><i class="ti ti-adjustments" aria-hidden="true"></i>Layanan tambahan</span>
<p class="text-muted small mb-0 mt-1">Opsional: hapus denda, lelang, penawaran ulang.</p>
</div>
</div>
<div class="card-body">
<div class="ui-ly-actions">
<?php
$s=$db->prepare('SELECT * FROM layanan_lelang WHERE pinjaman_id=?');$s->execute([$id]);$lelang=$s->fetch(PDO::FETCH_ASSOC);
if($lelang): ?>
<div class="ui-ly-auction"><div class="ui-ly-auction-head"><div><i class="ti ti-gavel" aria-hidden="true"></i><div><span>Hasil penjualan jaminan</span><strong><?= formatRupiah($lelang['harga_jual']) ?></strong></div></div><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="kwitansi_layanan.php?jenis=lelang&id=<?= (int)$lelang['id'] ?>">Lihat kwitansi</a></div><dl><div><dt>Dialokasikan</dt><dd><?= formatRupiah($lelang['terpakai']) ?></dd></div><div><dt>Kredit berikutnya</dt><dd><?= formatRupiah($lelang['kredit']) ?></dd></div><div><dt>Kelebihan nasabah</dt><dd><?= formatRupiah($lelang['kelebihan']) ?></dd></div><div><dt>Sudah dikembalikan</dt><dd><?= formatRupiah($lelang['dikembalikan']) ?></dd></div></dl></div>
<?php if(isAdmin() && $lelang['kelebihan']>$lelang['dikembalikan'])layananForm('kembalikan_lebih',$id,'Kembalikan Kelebihan Hasil Penjualan',$buktiField,'warning'); ?>
<?php elseif(isAdmin() && $p['status']!=='lunas' && $p['status_jaminan']==='dilelang'):
layananForm('jual_jaminan',$id,'Catat Penjualan Jaminan','<div class="ui-ly-field"><label class="form-label">Harga jual diterima</label><input name="jumlah" type="number" class="form-control" min="0.01" step="0.01" required><p class="form-text mb-0">Tanpa potongan tersembunyi</p></div>'.$metodeField.$buktiField.$catatanField,'danger');
endif;
if($options && $p['status']!=='lunas')layananForm('ajukan_hapus_denda',$id,'Ajukan Penghapusan Sisa Denda',$pilihAngsuran.$catatanField,'warning');
$s=$db->prepare('SELECT h.*,u.nama_lengkap teller FROM layanan_pengajuan_hapus_denda h JOIN users u ON u.id=h.user_id WHERE h.pinjaman_id=?'.(isAdmin()?'':' AND h.user_id=?').' ORDER BY h.id DESC');$s->execute(isAdmin()?[$id]:[$id,$_SESSION['user_id']]);
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $hapus): ?>
<div class="ui-ly-request"><div class="ui-ly-request-head"><div><span>Penghapusan denda</span><strong><?= layananEscape($hapus['teller']) ?></strong></div><span class="badge bg-<?= $hapus['status']==='ditolak'?'danger':($hapus['status']==='disetujui'?'success':'warning') ?>"><?= layananEscape(ucfirst($hapus['status'])) ?></span></div><p><?= layananEscape(trim((string)$hapus['alasan']) ?: 'Tidak ada alasan yang dicatat.') ?></p>
<?php if(isAdmin() && $hapus['status']==='menunggu')layananForm('putuskan_hapus_denda',$id,'Putuskan Penghapusan Denda','<input type="hidden" name="pengajuan_id" value="'.(int)$hapus['id'].'"><select name="keputusan" class="form-select"><option value="setuju">Setujui (hanya denda yang belum dibayar)</option><option value="tolak">Tolak</option></select>'); ?></div>
<?php endforeach;
if(isAdmin() && $p['status']==='lunas') {
    layananForm('repeat_order',$id,'Tandai / Catat Penawaran Pinjaman Ulang',$catatanField);
    if(!$p['memerlukan_jaminan']) echo '<div class="ui-ly-note"><i class="ti ti-info-circle" aria-hidden="true"></i><p>Hak SHU pelunasan dikelola pada menu <strong>SHU</strong>.</p></div>';
    if(false && !$p['memerlukan_jaminan']) {
        $s=$db->prepare('SELECT * FROM layanan_bonus WHERE pinjaman_id=?');$s->execute([$id]);$bonus=$s->fetch(PDO::FETCH_ASSOC);
        if($bonus)echo '<p>Bonus dibagikan '.formatRupiah($bonus['jumlah']).' ('.$bonus['tahun'].') · <a target="_blank" rel="noopener" href="kwitansi_layanan.php?jenis=bonus&id='.(int)$bonus['id'].'">Kwitansi bonus</a></p>';
        else {
            $bungaBonus=layananBungaDiterima($db,$id);$nominalBonus=round($bungaBonus*$settings['bonus_persen']/100,2);
            echo '<p>Simulasi bonus: '.formatRupiah($bungaBonus).' bunga diterima × '.layananEscape($settings['bonus_persen']).'% = '.formatRupiah($nominalBonus).'. Setiap pinjaman mendapat satu bonus; pelaporan dikelompokkan per tahun pembayaran.</p>';
            if($nominalBonus>0)layananForm('bayar_bonus',$id,'Bagikan Bonus Loyalitas','<label class="form-label">Nominal hasil perhitungan</label><input name="jumlah" type="number" class="form-control" value="'.layananEscape($nominalBonus).'" readonly>'.$metodeField.$buktiField,'success');
            else echo '<p>Admin perlu menetapkan persentase bonus pada Pengaturan sebelum membagikan.</p>';
        }
    }
}
?>
</div></div></section>

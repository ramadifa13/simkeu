<?php
// Read-only presentation of the existing bonus policy and payment records.
$bonusYear=(int)($_GET['bonus_tahun'] ?? date('Y'));
if($bonusYear<2000 || $bonusYear>2100)$bonusYear=(int)date('Y');
$bonusYears=$db->query('SELECT DISTINCT tahun FROM layanan_bonus ORDER BY tahun DESC')->fetchAll(PDO::FETCH_COLUMN);
$bonusYears=array_unique(array_merge([(int)date('Y'),$bonusYear],$bonusYears));rsort($bonusYears);
$bonusStmt=$db->prepare('SELECT b.*,a.nama_lengkap,p.kode_pinjaman FROM layanan_bonus b JOIN anggota a ON a.id=b.anggota_id JOIN pinjaman p ON p.id=b.pinjaman_id WHERE b.tahun=? ORDER BY b.created_at DESC,b.id DESC');
$bonusStmt->execute([$bonusYear]);$bonusPayments=$bonusStmt->fetchAll(PDO::FETCH_ASSOC);
$bonusTotal=array_sum(array_column($bonusPayments,'jumlah'));
$bonusPaidLoans=$db->query('SELECT pinjaman_id FROM layanan_bonus')->fetchAll(PDO::FETCH_COLUMN);
$bonusEligible=array_values(array_filter($rows,function($loan)use($bonusPaidLoans,$bonusYear){return $loan['status']==='lunas' && !$loan['memerlukan_jaminan'] && !in_array($loan['id'],$bonusPaidLoans) && substr((string)$loan['tanggal_lunas'],0,4)===(string)$bonusYear;}));
?>
<section id="bonus-tahunan" class="card service-section">
    <div class="card-header service-heading">
        <div><span class="service-eyebrow">LOYALITAS ANGGOTA</span><h2 class="h5 mb-1">Bonus tahunan</h2><p class="text-muted mb-0">Pinjaman lunas tanpa jaminan · pencatatan terpisah dari SHU koperasi.</p></div>
        <a class="btn btn-outline-success" href="?tab=lunas&export=bonus"><i class="ti ti-download me-1" aria-hidden="true"></i> Export semua tahun</a>
    </div>
    <div class="card-body">
        <div class="service-metrics">
            <div><span>Total dibagikan · <?= $bonusYear ?></span><strong><?= formatRupiah($bonusTotal) ?></strong><small><?= count($bonusPayments) ?> pinjaman menerima bonus</small></div>
            <div><span>Belum menerima bonus</span><strong><?= count($bonusEligible) ?> pinjaman</strong><small>Lunas pada <?= $bonusYear ?>, tanpa jaminan</small></div>
            <div><span>Tarif bonus saat ini</span><strong><?= layananEscape($settings['bonus_persen']) ?>%</strong><small>Dari bunga diterima setelah diskon</small><?php if((float)$settings['bonus_persen']>0): ?><a class="small no-print" href="?tab=pengaturan#bonus_persen">Ubah kebijakan bonus</a><?php endif; ?></div>
        </div>
        <?php if((float)$settings['bonus_persen']<=0): ?>
        <div class="service-callout no-print"><div><strong>Bonus belum diaktifkan</strong><p class="mb-0">Tetapkan persentase terlebih dahulu. Sistem tidak membagikan bonus sampai kebijakan ditentukan Admin.</p></div><a class="btn btn-primary" href="?tab=pengaturan#bonus_persen">Atur persentase</a></div>
        <?php else: ?><p class="text-muted small mt-3">Pilih pinjaman pada daftar di bawah, periksa perhitungannya, lalu unggah bukti saat membagikan bonus. Setiap pinjaman hanya mendapat satu pembayaran bonus.</p><?php endif; ?>
        <form method="get" action="layanan.php#bonus-tahunan" class="service-year-filter no-print">
            <input type="hidden" name="tab" value="lunas"><label for="bonus_tahun">Tahun laporan</label>
            <select id="bonus_tahun" name="bonus_tahun" class="form-select"><?php foreach($bonusYears as $year): ?><option value="<?= (int)$year ?>" <?= (int)$year===$bonusYear?'selected':'' ?>><?= (int)$year ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary">Tampilkan</button>
        </form>
        <h3 class="h6 mt-4">Riwayat pembagian · <?= $bonusYear ?></h3>
        <?php if(!$bonusPayments): ?><div class="service-empty"><i class="ti ti-receipt-2" aria-hidden="true"></i><strong>Belum ada bonus dibagikan pada <?= $bonusYear ?></strong><p>Pembayaran yang sudah berhasil dicatat akan muncul di sini beserta kwitansinya.</p></div>
        <?php else: ?><div class="table-responsive"><table class="table js-datatable"><thead><tr><th>Nasabah / Pinjaman</th><th>Tanggal</th><th>Bunga dasar</th><th>Tarif</th><th>Bonus dibagikan</th><th>Aksi</th></tr></thead><tbody><?php foreach($bonusPayments as $payment): ?><tr><td><?= layananEscape($payment['nama_lengkap']) ?><br><small class="text-muted"><?= layananEscape($payment['kode_pinjaman']) ?></small></td><td><?= layananEscape(formatTanggalUi($payment['created_at'])) ?></td><td><?= formatRupiah($payment['bunga_dasar']) ?></td><td><?= layananEscape($payment['persen']) ?>%</td><td><strong><?= formatRupiah($payment['jumlah']) ?></strong></td><td><a class="btn btn-sm btn-outline-primary" href="kwitansi_layanan.php?jenis=bonus&id=<?= (int)$payment['id'] ?>" target="_blank" rel="noopener">Kwitansi</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <h3 class="h6 mt-4">Pinjaman lunas yang belum menerima bonus</h3>
        <?php if(!$bonusEligible): ?><div class="service-empty compact"><strong>Belum ada pinjaman yang perlu diproses untuk <?= $bonusYear ?></strong><p>Pinjaman lunas tanpa jaminan yang belum menerima bonus akan ditampilkan di sini.</p></div>
        <?php else: ?><div class="table-responsive"><table class="table js-datatable"><thead><tr><th>Nasabah</th><th>Pinjaman</th><th>Tanggal lunas</th><th>Aksi</th></tr></thead><tbody><?php foreach($bonusEligible as $loan): ?><tr><td><?= layananEscape($loan['nama_lengkap']) ?></td><td><?= layananEscape($loan['kode_pinjaman']) ?></td><td><?= layananEscape($loan['tanggal_lunas']) ?></td><td><a class="btn btn-sm btn-primary" href="detail_layanan.php?tab=lunas&id=<?= (int)$loan['id'] ?>"><?= (float)$settings['bonus_persen']>0?'Periksa & bagikan':'Lihat perhitungan' ?></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
</section>

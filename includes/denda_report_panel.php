<?php
if (!is_array($settings ?? null)) {
    $settings = [];
}
$waDendaTpl = (string) ($settings['wa_denda'] ?? 'Yth {nama}, Anda telat {hari} hari, denda Rp {denda}. Mohon segera dibayar.');
$dueScope = isAdmin() ? '' : ' AND p.dicairkan_oleh=' . (int)$_SESSION['user_id'];
$hanyaSisa = !isset($_GET['semua_jt']); // default: tampilkan yang masih punya sisa denda / sudah telat
$dueStmt = $db->query(
    "SELECT a.*,ag.nama_lengkap,ag.telepon,p.kode_pinjaman,p.jumlah_pinjaman,jp.memerlukan_jaminan,
     COALESCE((SELECT SUM(b.jumlah) FROM layanan_bayar_denda b WHERE b.angsuran_id=a.id),0) dibayar
     FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id
     JOIN jenis_pinjaman jp ON jp.id=p.jenis_pinjaman_id
     WHERE a.status<>'lunas' AND a.tanggal_jatuh_tempo < CURDATE() $dueScope
     ORDER BY a.tanggal_jatuh_tempo,a.id"
);
$fineDueRows = $dueStmt->fetchAll(PDO::FETCH_ASSOC);

$fineDueVisible = [];
$totalSisaDenda = 0.0;
$nasabahTelat = [];
$cicilanBelumLunas = 0;
foreach ($fineDueRows as $fd) {
    $calc = hitungDendaAngsuran($db, $fd, $fd['jumlah_pinjaman']);
    // denda_manual=1 (termasuk cut-off migrasi) = pakai nilai tersimpan, jangan timpa hitungan otomatis.
    $dendaTampil = !empty($fd['denda_manual'])
        ? (float) $fd['denda']
        : max((float) $fd['denda'], (float) $calc['denda']);
    $fineRemaining = max(0, $dendaTampil - (float) $fd['dibayar']);
    $fd['_calc'] = $calc;
    $fd['_denda_tampil'] = $dendaTampil;
    $fd['_sisa'] = $fineRemaining;
    if ($hanyaSisa && $fineRemaining <= 0.005) {
        continue;
    }
    $fineDueVisible[] = $fd;
    $totalSisaDenda += $fineRemaining;
    if ($fineRemaining > 0.005) {
        $nasabahTelat[$fd['nama_lengkap']] = true;
        $cicilanBelumLunas++;
    }
}

$paymentScope = isAdmin() ? '' : ' AND b.user_id=' . (int)$_SESSION['user_id'];
$fineHistory = $db->query(
    "SELECT b.*,ag.nama_lengkap,ag.telepon,u.nama_lengkap teller,a.status angsuran_status,a.denda denda_sekarang,
     a.tanggal_jatuh_tempo,a.angsuran_ke,
     (SELECT COALESCE(SUM(x.jumlah),0) FROM layanan_bayar_denda x WHERE x.angsuran_id=b.angsuran_id) total_bayar
     FROM layanan_bayar_denda b JOIN pinjaman p ON p.id=b.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id
     JOIN angsuran a ON a.id=b.angsuran_id JOIN users u ON u.id=b.user_id
     WHERE 1=1 $paymentScope ORDER BY b.id DESC"
);
$fineHistoryRows = $fineHistory->fetchAll(PDO::FETCH_ASSOC);
$menungguReview = 0;
foreach ($fineHistoryRows as $fr) {
    if (empty($fr['approved_by'])) {
        $menungguReview++;
    }
}
$laporanRows = $fineHistoryRows;
foreach ($fineDueVisible as $fd) {
    if ($fd['_sisa'] <= 0.005 || (float)$fd['dibayar'] > 0) {
        continue;
    }
    $laporanRows[] = ['_unpaid_due' => true, '_fd' => $fd];
}
?>
<div class="ui-ly-denda-kpi mb-3">
<?php ui_stat_grid([
    ['label' => 'Nasabah telat', 'value' => count($nasabahTelat), 'tone' => 'danger', 'icon' => 'ti ti-users', 'hint' => $hanyaSisa ? 'Ada sisa denda' : 'Semua cicilan telat'],
    ['label' => 'Sisa denda', 'value' => formatRupiah($totalSisaDenda), 'tone' => 'warning', 'icon' => 'ti ti-coin', 'hint' => 'Total belum dibayar'],
    ['label' => 'Cicilan tertagih', 'value' => (int)$cicilanBelumLunas, 'tone' => 'info', 'icon' => 'ti ti-calendar-due', 'hint' => 'Belum lunas denda'],
    ['label' => 'Menunggu review', 'value' => (int)$menungguReview, 'tone' => 'primary', 'icon' => 'ti ti-hourglass', 'hint' => 'Pembayaran denda'],
]); ?>
</div>

<div class="ui-ly-toolbar no-print mb-3">
    <div>
        <strong>Tagihan denda jatuh tempo<?= isAdmin() ? '' : ' — nasabah saya' ?></strong>
        <span>Dihitung otomatis dari tarif Master Denda / gadai.</span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($hanyaSisa): ?>
            <a class="btn btn-sm btn-outline-secondary" href="?tab=denda&amp;semua_jt=1">Semua cicilan telat</a>
        <?php else: ?>
            <a class="btn btn-sm btn-outline-primary" href="?tab=denda">Hanya sisa denda</a>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-warning" href="?tab=denda&amp;hitung_ulang=1"><i class="ti ti-refresh me-1"></i>Hitung ulang</a>
    </div>
</div>

<div class="table-responsive ui-table-scroll">
<table class="table table-hover align-middle ui-ly-table ui-ly-table-compact js-datatable" data-dt-page-length="10" data-empty-message="Tidak ada tagihan denda untuk filter saat ini.">
<thead>
<tr>
    <th>Nasabah</th>
    <th>Jatuh tempo</th>
    <th>Telat</th>
    <th class="text-end">Sisa denda</th>
    <th class="text-end no-print">Aksi</th>
</tr>
</thead>
<tbody>
<?php foreach ($fineDueVisible as $fd):
    $calc = $fd['_calc'];
    $fineRemaining = $fd['_sisa'];
?>
<tr>
    <td>
        <strong class="ui-ly-member"><?= layananEscape($fd['nama_lengkap']) ?></strong>
        <small class="ui-ly-kode"><?= layananEscape($fd['kode_pinjaman']) ?> · cicilan <?= (int)$fd['angsuran_ke'] ?><?= !empty($fd['memerlukan_jaminan']) ? ' · Gadai' : '' ?></small>
        <small class="ui-ly-kode d-block">Denda <?= formatRupiah($fd['_denda_tampil']) ?> · dibayar <?= formatRupiah($fd['dibayar']) ?> · <?= formatRupiah($calc['per_hari']) ?>/hari</small>
    </td>
    <td><?= layananEscape(formatTanggalUi($fd['tanggal_jatuh_tempo'])) ?></td>
    <td><span class="badge bg-<?= $calc['hari_telat'] > 7 ? 'danger' : ($calc['hari_telat'] > 0 ? 'warning text-dark' : 'secondary') ?>"><?= (int)$calc['hari_telat'] ?> hari</span></td>
    <td class="text-end ui-ly-money"><strong><?= formatRupiah($fineRemaining) ?></strong></td>
    <td class="text-end no-print table-actions">
        <a class="btn btn-sm btn-outline-primary" href="detail_layanan.php?tab=denda&amp;id=<?= (int)$fd['pinjaman_id'] ?>"><i class="ti ti-eye me-1"></i>Bayar</a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="ui-ly-toolbar mt-4 mb-2">
    <div>
        <strong>Laporan pembayaran denda<?= isAdmin() ? '' : ' — saya' ?></strong>
        <span><?= count($fineHistoryRows) ?> transaksi tercatat</span>
    </div>
    <?php if (isAdmin()): ?><a class="btn btn-outline-success btn-sm" href="?tab=denda&amp;export=denda"><i class="ti ti-file-spreadsheet me-1"></i>Export Excel</a><?php endif; ?>
</div>

<div class="table-responsive ui-table-scroll">
<table class="table table-hover align-middle ui-ly-table ui-ly-table-compact js-datatable" data-dt-page-length="10" data-empty-message="Belum ada data denda.">
<thead>
<tr>
    <th>Nasabah</th>
    <th>Tanggal telat</th>
    <th>Telat</th>
    <th class="text-end">Dibayar</th>
    <th>Status</th>
    <th>Review</th>
    <th class="text-end no-print">Aksi</th>
</tr>
</thead>
<tbody>
<?php
foreach ($laporanRows as $fr):
    if (!empty($fr['_unpaid_due'])) {
        $fd = $fr['_fd'];
        $calc = $fd['_calc'];
        $fineMessage = strtr($waDendaTpl, [
            '{nama}' => $fd['nama_lengkap'],
            '{hari}' => $calc['hari_telat'],
            '{denda}' => number_format($fd['_sisa'], 0, ',', '.'),
        ]);
        $waItems = [
            ['label' => 'Detail / Bayar', 'href' => 'detail_layanan.php?tab=denda&id=' . (int)$fd['pinjaman_id'], 'icon' => 'ti ti-eye'],
        ];
        $waNomor = nomorWhatsApp($fd['telepon']);
        if ($waNomor) {
            $waItems[] = ['label' => 'WhatsApp', 'href' => 'https://wa.me/' . $waNomor . '?text=' . rawurlencode($fineMessage), 'icon' => 'ti ti-brand-whatsapp', 'target' => '_blank'];
        }
        $aksiHtml = count($waItems) >= 2
            ? ui_action_menu($waItems)
            : '<a class="btn btn-sm btn-outline-primary" href="' . htmlspecialchars($waItems[0]['href'], ENT_QUOTES, 'UTF-8') . '"><i class="ti ti-eye me-1"></i>Detail</a>';
        $tglTelatUnpaid = !empty($fd['tanggal_jatuh_tempo']) ? formatTanggalUi($fd['tanggal_jatuh_tempo']) : '—';
?>
<tr>
    <td>
        <strong class="ui-ly-member"><?= layananEscape($fd['nama_lengkap']) ?></strong>
        <small class="ui-ly-kode">Belum ada pembayaran · sisa <?= formatRupiah($fd['_sisa']) ?></small>
    </td>
    <td>
        <strong><?= layananEscape($tglTelatUnpaid) ?></strong>
        <small class="ui-ly-kode d-block">Jatuh tempo cicilan <?= (int) $fd['angsuran_ke'] ?></small>
    </td>
    <td><?= (int)$calc['hari_telat'] ?> hari</td>
    <td class="text-end ui-ly-money">Rp 0</td>
    <td><span class="badge bg-warning text-dark"><?= $fd['denda'] > 0 ? 'Belum' : 'Lunas (pemutihan)' ?></span></td>
    <td><span class="badge bg-secondary">Belum dibayar</span></td>
    <td class="table-actions text-end no-print"><?= $aksiHtml ?></td>
</tr>
<?php
        continue;
    }
    $sisaWa = ($fr['angsuran_status'] === 'lunas') ? 0 : max(0, $fr['denda_sekarang'] - $fr['total_bayar']);
    $fineMessage = strtr($waDendaTpl, [
        '{nama}' => $fr['nama_lengkap'],
        '{hari}' => $fr['hari_telat'],
        '{denda}' => number_format($sisaWa, 0, ',', '.'),
    ]);
    $lunasDenda = ($fr['angsuran_status'] === 'lunas' || $fr['total_bayar'] + 0.005 >= $fr['denda_sekarang']);
    $histItems = [
        ['label' => 'Detail', 'href' => 'detail_layanan.php?tab=denda&id=' . (int)$fr['pinjaman_id'], 'icon' => 'ti ti-eye'],
        ['label' => 'Cetak / Simpan PDF', 'href' => 'kwitansi_layanan.php?jenis=denda&id=' . (int)$fr['id'], 'icon' => 'ti ti-file-type-pdf', 'target' => '_blank'],
    ];
    $waNomor = nomorWhatsApp($fr['telepon']);
    if ($waNomor) {
        $histItems[] = ['label' => 'WhatsApp', 'href' => 'https://wa.me/' . $waNomor . '?text=' . rawurlencode($fineMessage), 'icon' => 'ti ti-brand-whatsapp', 'target' => '_blank'];
    }
    $tglTelat = !empty($fr['created_at']) ? formatTanggalUi($fr['created_at']) : '—';
    $tglJt = !empty($fr['tanggal_jatuh_tempo']) ? formatTanggalUi($fr['tanggal_jatuh_tempo']) : '';
?>
<tr>
    <td>
        <strong class="ui-ly-member"><?= layananEscape($fr['nama_lengkap']) ?></strong>
        <small class="ui-ly-kode"><?= layananEscape($fr['teller']) ?> · awal <?= formatRupiah($fr['denda_awal']) ?><?php if ($fr['denda_awal'] > $fr['denda_akhir']): ?> · diskon <?= formatRupiah($fr['denda_awal'] - $fr['denda_akhir']) ?><?php endif; ?></small>
    </td>
    <td>
        <strong><?= layananEscape($tglTelat) ?></strong>
        <?php if ($tglJt !== ''): ?>
        <small class="ui-ly-kode d-block">Jatuh tempo <?= layananEscape($tglJt) ?><?php if (!empty($fr['angsuran_ke'])): ?> · cicilan <?= (int) $fr['angsuran_ke'] ?><?php endif; ?></small>
        <?php endif; ?>
    </td>
    <td><?= (int)$fr['hari_telat'] ?> hari</td>
    <td class="text-end ui-ly-money"><?= formatRupiah($fr['jumlah']) ?></td>
    <td><span class="badge bg-<?= $lunasDenda ? 'success' : 'warning text-dark' ?>"><?= $lunasDenda ? 'Lunas' : 'Belum' ?></span></td>
    <td><span class="badge bg-<?= $fr['approved_by'] ? 'success' : 'secondary' ?>"><?= $fr['approved_by'] ? 'Disetujui' : 'Menunggu' ?></span></td>
    <td class="table-actions text-end no-print"><?= ui_action_menu($histItems) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php
if (!is_array($settings ?? null)) {
    $settings = [];
}
$waDendaTpl = (string) ($settings['wa_denda'] ?? 'Yth {nama}, Anda telat {hari} hari, denda Rp {denda}. Mohon segera dibayar.');
$dueScope = isAdmin() ? '' : ' AND p.dicairkan_oleh=' . (int)$_SESSION['user_id'];
$hanyaSisa = !isset($_GET['semua_jt']); // default: tampilkan yang masih punya sisa denda / sudah telat
$dueStmt = $db->prepare(
    "SELECT a.*,ag.nama_lengkap,ag.telepon,p.kode_pinjaman,p.jumlah_pinjaman,jp.memerlukan_jaminan,
     COALESCE((SELECT SUM(b.jumlah) FROM layanan_bayar_denda b WHERE b.angsuran_id=a.id),0) dibayar
     FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id
     JOIN jenis_pinjaman jp ON jp.id=p.jenis_pinjaman_id
     WHERE a.status<>'lunas' AND a.tanggal_jatuh_tempo < CURDATE() $dueScope
     AND (ag.nama_lengkap LIKE ? OR p.kode_pinjaman LIKE ? OR ag.kode_anggota LIKE ?)
     ORDER BY a.tanggal_jatuh_tempo,a.id"
);
$dueStmt->execute(['%' . $q . '%', '%' . $q . '%', '%' . $q . '%']);
$fineDueRows = $dueStmt->fetchAll(PDO::FETCH_ASSOC);

$fineDueVisible = [];
$totalSisaDenda = 0.0;
$nasabahTelat = [];
foreach ($fineDueRows as $fd) {
    $calc = hitungDendaAngsuran($db, $fd, $fd['jumlah_pinjaman']);
    // Pakai denda tersimpan (setelah perbaruiDendaOtomatis); fallback ke hitungan bila belum tersimpan.
    $dendaTampil = max((float) $fd['denda'], (float) $calc['denda']);
    $fineRemaining = max(0, $dendaTampil - (float) $fd['dibayar']);
    $fd['_calc'] = $calc;
    $fd['_denda_tampil'] = $dendaTampil;
    $fd['_sisa'] = $fineRemaining;
    if ($hanyaSisa && $fineRemaining <= 0.005 && empty($fd['denda_manual'])) {
        continue;
    }
    $fineDueVisible[] = $fd;
    $totalSisaDenda += $fineRemaining;
    if ($fineRemaining > 0.005) {
        $nasabahTelat[$fd['nama_lengkap']] = true;
    }
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 mb-1">Tagihan denda jatuh tempo <?= isAdmin() ? '' : 'nasabah saya' ?></h2>
        <p class="text-muted small mb-0">
            <?= count($nasabahTelat) ?> nasabah · sisa denda
            <strong><?= formatRupiah($totalSisaDenda) ?></strong>
            · dihitung otomatis tiap buka halaman (tarif Master Denda / gadai).
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($hanyaSisa): ?>
            <a class="btn btn-sm btn-outline-secondary" href="?tab=denda&amp;semua_jt=1&amp;q=<?= urlencode($q) ?>">Tampilkan semua cicilan telat</a>
        <?php else: ?>
            <a class="btn btn-sm btn-outline-primary" href="?tab=denda&amp;q=<?= urlencode($q) ?>">Hanya yang masih ada sisa denda</a>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-warning" href="?tab=denda&amp;hitung_ulang=1&amp;q=<?= urlencode($q) ?>"><i class="fas fa-sync-alt me-1"></i>Hitung ulang denda</a>
    </div>
</div>
<?php
$duePaged = ui_slice_rows($fineDueVisible, ui_page_key('page_due'), $ui_list['per_page'] ?? 10);
$fineDuePage = $duePaged['rows'];
$duePager = $duePaged['pager'];
?>
<div class="table-responsive ui-table-scroll"><table class="table table-striped align-middle"><thead><tr><th>Nama / Pinjaman</th><th>Tgl Jatuh Tempo</th><th>Telat</th><th>Tarif / Hari</th><th>Denda</th><th>Sudah bayar</th><th>Sisa</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($fineDuePage as $fd):
    $calc = $fd['_calc'];
    $fineRemaining = $fd['_sisa'];
    $statusLabel = $fineRemaining > 0.005 ? 'Belum lunas' : 'Lunas / pemutihan';
    $statusClass = $fineRemaining > 0.005 ? 'warning text-dark' : 'success';
?>
<tr>
    <td><?= layananEscape($fd['nama_lengkap']) ?><br><small><?= layananEscape($fd['kode_pinjaman']) ?> / cicilan <?= (int)$fd['angsuran_ke'] ?><?= !empty($fd['memerlukan_jaminan']) ? ' · Gadai' : '' ?></small></td>
    <td><?= layananEscape(formatTanggalUi($fd['tanggal_jatuh_tempo'])) ?></td>
    <td><span class="badge bg-<?= $calc['hari_telat'] > 7 ? 'danger' : ($calc['hari_telat'] > 0 ? 'warning text-dark' : 'secondary') ?>"><?= (int)$calc['hari_telat'] ?> hari</span></td>
    <td><?= formatRupiah($calc['per_hari']) ?></td>
    <td><?= formatRupiah($fd['_denda_tampil']) ?></td>
    <td><?= formatRupiah($fd['dibayar']) ?></td>
    <td><strong><?= formatRupiah($fineRemaining) ?></strong></td>
    <td><span class="badge bg-<?= $statusClass ?>"><?= $statusLabel ?></span></td>
    <td><a class="btn btn-sm btn-outline-primary" href="detail_layanan.php?tab=denda&amp;id=<?= (int)$fd['pinjaman_id'] ?>"><i class="fas fa-eye me-1"></i>Detail / Bayar</a></td>
</tr>
<?php endforeach; if (!$fineDuePage): ?>
<tr><td colspan="9" class="text-center text-muted">Tidak ada tagihan denda<?= $hanyaSisa ? ' tersisa' : '' ?> untuk filter saat ini.</td></tr>
<?php endif; ?>
</tbody></table></div>
<?php ui_pagination($duePager, basename($_SERVER['PHP_SELF'] ?? 'layanan.php'), 'page_due'); ?>

<h2 class="h5 mt-4">Laporan pembayaran denda <?= isAdmin() ? 'semua teller' : 'saya' ?></h2>
<?php if (isAdmin()): ?><a class="btn btn-outline-success btn-sm mb-2" href="?tab=denda&amp;export=denda">Export Laporan Denda untuk RAT (Excel CSV)</a><?php endif; ?>
<?php
$paymentScope = isAdmin() ? '' : ' AND b.user_id=' . (int)$_SESSION['user_id'];
$fineHistory = $db->prepare(
    "SELECT b.*,ag.nama_lengkap,ag.telepon,u.nama_lengkap teller,a.status angsuran_status,a.denda denda_sekarang,
     (SELECT COALESCE(SUM(x.jumlah),0) FROM layanan_bayar_denda x WHERE x.angsuran_id=b.angsuran_id) total_bayar
     FROM layanan_bayar_denda b JOIN pinjaman p ON p.id=b.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id
     JOIN angsuran a ON a.id=b.angsuran_id JOIN users u ON u.id=b.user_id
     WHERE (ag.nama_lengkap LIKE ? OR p.kode_pinjaman LIKE ?) $paymentScope ORDER BY b.id DESC"
);
$fineHistory->execute(['%' . $q . '%', '%' . $q . '%']);
$fineHistoryRows = $fineHistory->fetchAll(PDO::FETCH_ASSOC);
$laporanRows = $fineHistoryRows;
foreach ($fineDueVisible as $fd) {
    if ($fd['_sisa'] <= 0.005 || (float)$fd['dibayar'] > 0) {
        continue;
    }
    $laporanRows[] = ['_unpaid_due' => true, '_fd' => $fd];
}
$histPaged = ui_slice_rows($laporanRows, ui_page_key('page_hist'), $ui_list['per_page'] ?? 10);
$histPage = $histPaged['rows'];
$histPager = $histPaged['pager'];
?>
<div class="table-responsive ui-table-scroll"><table class="table table-striped align-middle"><thead><tr><th>Nama</th><th>Telat</th><th>Denda Awal</th><th>Diskon</th><th>Denda Akhir</th><th>Dibayar</th><th>Status</th><th>Teller</th><th>Review</th><th>Aksi</th></tr></thead><tbody>
<?php
foreach ($histPage as $fr):
    if (!empty($fr['_unpaid_due'])) {
        $fd = $fr['_fd'];
        $calc = $fd['_calc'];
        $fineMessage = strtr($waDendaTpl, [
            '{nama}' => $fd['nama_lengkap'],
            '{hari}' => $calc['hari_telat'],
            '{denda}' => number_format($fd['_sisa'], 0, ',', '.'),
        ]);
        $waItems = [
            ['label' => 'Detail / Bayar', 'href' => 'detail_layanan.php?tab=denda&id=' . (int)$fd['pinjaman_id'], 'icon' => 'fas fa-eye'],
        ];
        $waNomor = nomorWhatsApp($fd['telepon']);
        if ($waNomor) {
            $waItems[] = ['label' => 'WhatsApp', 'href' => 'https://wa.me/' . $waNomor . '?text=' . rawurlencode($fineMessage), 'icon' => 'fab fa-whatsapp', 'target' => '_blank'];
        }
        $aksiHtml = count($waItems) >= 2
            ? ui_action_menu($waItems)
            : '<a class="btn btn-sm btn-outline-primary" href="' . htmlspecialchars($waItems[0]['href'], ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-eye me-1"></i>Detail / Bayar</a>';
?>
<tr>
    <td><?= layananEscape($fd['nama_lengkap']) ?></td>
    <td><?= (int)$calc['hari_telat'] ?> hari</td>
    <td><?= formatRupiah($fd['_denda_tampil']) ?></td>
    <td><?= formatRupiah(max(0, $fd['_denda_tampil'] - (float)$fd['denda'])) ?></td>
    <td><?= formatRupiah($fd['denda']) ?></td>
    <td>Rp 0</td>
    <td><span class="badge bg-warning text-dark"><?= $fd['denda'] > 0 ? 'Belum' : 'Lunas (pemutihan)' ?></span></td>
    <td>—</td>
    <td><span class="badge bg-secondary">Belum dibayar</span></td>
    <td class="table-actions text-end"><?= $aksiHtml ?></td>
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
        ['label' => 'Detail', 'href' => 'detail_layanan.php?tab=denda&id=' . (int)$fr['pinjaman_id'], 'icon' => 'fas fa-eye'],
        ['label' => 'Cetak / Simpan PDF', 'href' => 'kwitansi_layanan.php?jenis=denda&id=' . (int)$fr['id'], 'icon' => 'fas fa-file-pdf', 'target' => '_blank'],
    ];
    $waNomor = nomorWhatsApp($fr['telepon']);
    if ($waNomor) {
        $histItems[] = ['label' => 'WhatsApp', 'href' => 'https://wa.me/' . $waNomor . '?text=' . rawurlencode($fineMessage), 'icon' => 'fab fa-whatsapp', 'target' => '_blank'];
    }
?>
<tr>
    <td><?= layananEscape($fr['nama_lengkap']) ?></td>
    <td><?= (int)$fr['hari_telat'] ?> hari</td>
    <td><?= formatRupiah($fr['denda_awal']) ?></td>
    <td><?= formatRupiah(max(0, $fr['denda_awal'] - $fr['denda_akhir'])) ?></td>
    <td><?= formatRupiah($fr['denda_akhir']) ?></td>
    <td><?= formatRupiah($fr['jumlah']) ?></td>
    <td><span class="badge bg-<?= $lunasDenda ? 'success' : 'warning text-dark' ?>"><?= $lunasDenda ? 'Lunas' : 'Belum' ?></span></td>
    <td><?= layananEscape($fr['teller']) ?></td>
    <td><span class="badge bg-<?= $fr['approved_by'] ? 'success' : 'secondary' ?>"><?= $fr['approved_by'] ? 'Disetujui' : 'Menunggu review' ?></span></td>
    <td class="table-actions text-end"><?= ui_action_menu($histItems) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$histPage): ?>
<tr><td colspan="10" class="text-center text-muted">Belum ada data denda.</td></tr>
<?php endif; ?>
</tbody></table></div>
<?php ui_pagination($histPager, basename($_SERVER['PHP_SELF'] ?? 'layanan.php'), 'page_hist'); ?>

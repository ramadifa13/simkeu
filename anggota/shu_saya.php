<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shu_pinjaman_helper.php';
checkAnggota();

$page_title = 'SHU Saya';
$s = $db->prepare('SELECT id, kategori_shu FROM anggota WHERE user_id = ?');
$s->execute([$_SESSION['user_id']]);
$member = $s->fetch(PDO::FETCH_ASSOC);
if (!$member) {
    http_response_code(404);
    exit('Data anggota tidak ditemukan.');
}

$rows = [];
if (shuPinjamanSiap($db)) {
    $s = $db->prepare(
        'SELECT h.*, p.kode_pinjaman, p.tanggal_lunas, src.nama_lengkap AS sumber_nama
         FROM shu_hak_pinjaman h
         JOIN pinjaman p ON p.id = h.pinjaman_id
         JOIN anggota src ON src.id = h.sumber_anggota_id
         WHERE h.anggota_id = ?
         ORDER BY h.created_at DESC, h.id DESC'
    );
    $s->execute([$member['id']]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
}

$total = 0;
$paid = 0;
foreach ($rows as $r) {
    $total += (int) $r['jumlah'];
    if ($r['status_bayar'] === 'sudah') {
        $paid += (int) $r['jumlah'];
    }
}
$peran = ($member['kategori_shu'] ?? '') === 'ketua_kelompok' ? 'Ketua Kelompok' : 'Anggota';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-agtshu" aria-hidden="true"><i class="ti ti-coins"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Akun anggota</p>
                        <h1 class="ui-dash-title">SHU Saya</h1>
                        <p class="ui-dash-meta mb-0">Peran: <?php echo htmlspecialchars($peran); ?> · hak muncul saat pinjaman tanpa jaminan lunas</p>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-agt-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total hak', 'value' => formatRupiah($total), 'tone' => 'warning', 'icon' => 'ti ti-coins', 'hint' => 'Pelunasan & referensi'],
                    ['label' => 'Sudah diterima', 'value' => formatRupiah($paid), 'tone' => 'success', 'icon' => 'ti ti-circle-check', 'hint' => 'Sudah dibayar'],
                    ['label' => 'Belum diterima', 'value' => formatRupiah($total - $paid), 'tone' => 'danger', 'icon' => 'ti ti-clock', 'hint' => 'Menunggu pengurus'],
                    ['label' => 'Jumlah catatan', 'value' => count($rows), 'tone' => 'primary', 'icon' => 'ti ti-list', 'hint' => 'Semua riwayat'],
                ]); ?>
                </div>

                <div class="card ui-agt-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Riwayat hak SHU</span>
                        <span class="ui-dash-sec-note"><?php echo (int) count($rows); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="0,desc"
                                   data-dt-filter="1,4"
                                   data-empty-message="Belum ada hak SHU pinjaman.">
                                <thead>
                                    <tr>
                                        <th>Pinjaman</th>
                                        <th>Jenis</th>
                                        <th>Perhitungan</th>
                                        <th class="text-end">Hak</th>
                                        <th>Status</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $r):
                                    $sudah = ($r['status_bayar'] ?? '') === 'sudah';
                                    $persen = rtrim(rtrim(number_format((float) $r['persen'], 4, '.', ''), '0'), '.');
                                    ?>
                                    <tr>
                                        <td data-order="<?php echo htmlspecialchars((string) ($r['tanggal_lunas'] ?? $r['created_at'] ?? '')); ?>">
                                            <strong><?php echo layananEscape($r['kode_pinjaman']); ?></strong>
                                            <span class="ui-agt-meta"><?php echo !empty($r['tanggal_lunas']) ? layananEscape(formatTanggalUi($r['tanggal_lunas'])) : '—'; ?></span>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($r['jenis'] ?? ''); ?>">
                                            <?php echo ($r['jenis'] ?? '') === 'referensi'
                                                ? 'Referensi: ' . layananEscape($r['sumber_nama'])
                                                : 'Pelunasan pribadi'; ?>
                                        </td>
                                        <td><?php echo formatRupiah($r['dasar_pinjaman']); ?> × <?php echo htmlspecialchars($persen); ?>%</td>
                                        <td class="text-end fw-semibold"><?php echo formatRupiah($r['jumlah']); ?></td>
                                        <td data-filter="<?php echo $sudah ? 'sudah' : 'belum'; ?>">
                                            <span class="badge <?php echo $sudah ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                                <?php echo $sudah ? 'Sudah dibayar' : 'Belum dibayar'; ?>
                                            </span>
                                            <?php if (!empty($r['dibayar_at'])): ?>
                                            <span class="ui-agt-meta"><?php echo layananEscape(formatTanggalUi($r['dibayar_at'], true)); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($sudah): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="kwitansi_shu.php?id=<?php echo (int) $r['id']; ?>">
                                                <i class="ti ti-receipt-2 me-1" aria-hidden="true"></i>Kwitansi
                                            </a>
                                            <?php else: ?>
                                            <span class="text-muted small">Menunggu Admin</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
</body>
</html>

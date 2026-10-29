<?php
/**
 * Partial: toolbar + tabel riwayat transaksi simpanan.
 * Expects: $riwayat (from ui_riwayat_transaksi), $jenisOptions (id=>nama), $riwayatTitle, $riwayatAction
 */
$riwayatTitle = $riwayatTitle ?? 'Riwayat';
$riwayatAction = $riwayatAction ?? '';
$jenisOptions = $jenisOptions ?? ['' => 'Semua jenis'];
$rows = $riwayat['rows'] ?? [];
$pager = $riwayat['pager'] ?? ui_paginate(0, 1, 25);
$q = $riwayat['q'] ?? '';
$filters = $riwayat['filters'] ?? [];
?>
<div class="card mt-4" id="riwayat">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="fas fa-history me-2"></i><?= htmlspecialchars($riwayatTitle) ?></h5>
        <span class="text-muted small"><?= (int)$pager['total'] ?> data</span>
    </div>
    <div class="card-body">
        <?php
        ui_table_toolbar([
            'action' => $riwayatAction,
            'q' => $q,
            'placeholder' => 'Cari nama, kode anggota, kode transaksi…',
            'per_page' => $pager['per_page'],
            'reset_href' => $riwayatAction,
            'filters' => [
                [
                    'name' => 'jenis_id',
                    'label' => 'Jenis',
                    'type' => 'select',
                    'value' => $filters['jenis_id'] ?? '',
                    'options' => $jenisOptions,
                ],
                ['name' => 'mulai', 'label' => 'Dari', 'type' => 'date', 'value' => $filters['mulai'] ?? ''],
                ['name' => 'sampai', 'label' => 'Sampai', 'type' => 'date', 'value' => $filters['sampai'] ?? ''],
            ],
        ]);
        ?>
        <div class="table-responsive ui-table-scroll">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kode</th>
                        <th>Anggota</th>
                        <th>Jenis</th>
                        <th class="text-end">Jumlah</th>
                        <th>Petugas</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars(formatTanggalUi($r['tanggal_transaksi'], true)) ?></td>
                        <td><code><?= htmlspecialchars($r['kode_transaksi']) ?></code></td>
                        <td><?= ui_cell_ellipsis(($r['kode_anggota'] ?? '') . ' — ' . ($r['nama_lengkap'] ?? ''), 32) ?></td>
                        <td><?= ui_cell_ellipsis($r['nama_jenis'] ?? '-', 20) ?></td>
                        <td class="text-end fw-semibold"><?= formatRupiah($r['jumlah']) ?></td>
                        <td><?= ui_cell_ellipsis($r['petugas'] ?? ($r['teller'] ?? '-'), 18) ?></td>
                        <td><?= ui_cell_ellipsis($r['keterangan'] ?? '-', 28) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php ui_pagination($pager, $riwayatAction); ?>
    </div>
</div>

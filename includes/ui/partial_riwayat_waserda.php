<?php
$rows = $riwayat['rows'] ?? [];
$pager = $riwayat['pager'] ?? ui_paginate(0, 1, 25);
$q = $riwayat['q'] ?? '';
$filters = $riwayat['filters'] ?? [];
$riwayatAction = $riwayatAction ?? 'pembelian.php';
$riwayatTitle = $riwayatTitle ?? 'Riwayat Pembelian Waserda';
$riwayatCanDelete = !empty($riwayatCanDelete);
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
                        <th class="text-end">Total</th>
                        <th>Metode</th>
                        <th>Petugas</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data</td></tr>
                    <?php else: foreach ($rows as $t):
                        $items = json_decode($t['items'] ?? '[]', true) ?: [];
                        $kodeJs = htmlspecialchars($t['kode_transaksi'] ?? '', ENT_QUOTES, 'UTF-8');
                        $totalJs = (float)($t['total_bayar'] ?? 0);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars(formatTanggalUi($t['created_at'], true)) ?></td>
                        <td><code><?= htmlspecialchars($t['kode_transaksi']) ?></code></td>
                        <td><?= ui_cell_ellipsis(($t['kode_anggota'] ?? '') . ' — ' . ($t['nama_lengkap'] ?? ''), 32) ?></td>
                        <td class="text-end fw-semibold"><?= formatRupiah($t['total_bayar']) ?></td>
                        <td><?= htmlspecialchars($t['metode_bayar'] ?? '-') ?></td>
                        <td><?= ui_cell_ellipsis($t['petugas'] ?? '-', 18) ?></td>
                        <td class="text-end">
                            <?php
                            $waserdaMenu = [
                                [
                                    'label' => 'Detail',
                                    'icon' => 'fas fa-eye',
                                    'title' => 'Detail',
                                    'onclick' => "typeof showDetail==='function'&&showDetail(JSON.parse(this.getAttribute('data-items')), this.getAttribute('data-kode'), parseFloat(this.getAttribute('data-total')))",
                                    'attrs' => [
                                        'data-items' => json_encode($items, JSON_UNESCAPED_UNICODE),
                                        'data-kode' => $t['kode_transaksi'] ?? '',
                                        'data-total' => $totalJs,
                                    ],
                                ],
                            ];
                            if ($riwayatCanDelete && !empty($t['id'])) {
                                $waserdaMenu[] = [
                                    'label' => 'Hapus',
                                    'icon' => 'fas fa-trash',
                                    'class' => 'btn-delete text-danger',
                                    'href' => '?delete=' . (int)$t['id'],
                                    'onclick' => "return typeof confirmDelete==='function'?confirmDelete('" . $kodeJs . "'):confirm('Hapus transaksi ini?')",
                                ];
                            }
                            echo ui_action_menu($waserdaMenu);
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php ui_pagination($pager, $riwayatAction); ?>
    </div>
</div>

<?php
/**
 * Partial: riwayat pembelian waserda (DataTables client).
 * Expects: $riwayat, $riwayatTitle, $riwayatCanDelete (bool)
 */
$riwayatTitle = $riwayatTitle ?? 'Riwayat Pembelian Waserda';
$rows = $riwayat['rows'] ?? [];
$pager = $riwayat['pager'] ?? ui_paginate(0, 1, 25);
$riwayatCanDelete = !empty($riwayatCanDelete);
$rowCount = count($rows);
?>
<div class="card mt-3" id="riwayat">
    <div class="card-header ui-dash-sec">
        <span class="ui-dash-sec-title"><i class="ti ti-history" aria-hidden="true"></i><?= htmlspecialchars($riwayatTitle) ?></span>
        <span class="ui-dash-sec-note"><?= (int)($pager['total'] ?? $rowCount) ?> data</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive ui-table-scroll">
            <table class="table table-hover align-middle mb-0 js-datatable"
                   data-dt-page-length="10"
                   data-dt-order="0,desc"
                   data-dt-filter="4"
                   data-dt-date-col="0"
                   data-dt-nosort="<?= $riwayatCanDelete ? '6' : '6' ?>">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kode</th>
                        <th>Anggota</th>
                        <th class="text-end">Total</th>
                        <th>Metode</th>
                        <th>Petugas</th>
                        <th class="text-end no-sort">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $t):
                        $items = json_decode($t['items'] ?? '[]', true) ?: [];
                        $ts = strtotime((string)($t['created_at'] ?? ''));
                        $orderTs = $ts ? date('Y-m-d H:i:s', $ts) : '';
                        $day = $ts ? date('Y-m-d', $ts) : '';
                        $kodeJs = htmlspecialchars($t['kode_transaksi'] ?? '', ENT_QUOTES, 'UTF-8');
                        $totalJs = (float)($t['total_bayar'] ?? 0);
                        $metode = (string)($t['metode_bayar'] ?? '-');
                        $metodeLabel = $metode === 'tunai' ? 'Tunai' : ($metode === 'transfer' ? 'Transfer' : $metode);
                    ?>
                    <tr>
                        <td data-order="<?= htmlspecialchars($orderTs) ?>" data-date="<?= htmlspecialchars($day) ?>">
                            <?= htmlspecialchars(formatTanggalUi($t['created_at'], true, true)) ?>
                        </td>
                        <td><code><?= htmlspecialchars($t['kode_transaksi']) ?></code></td>
                        <td><?= ui_cell_ellipsis(($t['kode_anggota'] ?? '') . ' — ' . ($t['nama_lengkap'] ?? ''), 32) ?></td>
                        <td class="text-end fw-semibold"><?= formatRupiah($t['total_bayar']) ?></td>
                        <td><?= htmlspecialchars($metodeLabel) ?></td>
                        <td><?= ui_cell_ellipsis($t['petugas'] ?? '-', 18) ?></td>
                        <td class="text-end">
                            <?php
                            $waserdaMenu = [
                                [
                                    'label' => 'Detail',
                                    'icon' => 'ti ti-eye',
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
                                    'icon' => 'ti ti-trash',
                                    'class' => 'text-danger',
                                    'href' => '?delete=' . (int)$t['id'],
                                    'onclick' => "return typeof confirmDelete==='function'?confirmDelete(this):confirm('Hapus transaksi ini?')",
                                    'attrs' => [
                                        'data-kode' => $t['kode_transaksi'] ?? '',
                                    ],
                                ];
                            }
                            echo ui_action_menu($waserdaMenu);
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
/**
 * Partial: tabel riwayat transaksi simpanan (DataTables).
 * Expects: $riwayat (from ui_riwayat_transaksi), $riwayatTitle
 * Opsional: $jenisOptions (tidak dipakai di mode client DT)
 */
$riwayatTitle = $riwayatTitle ?? 'Riwayat';
$rows = $riwayat['rows'] ?? [];
$pager = $riwayat['pager'] ?? ui_paginate(0, 1, 25);
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
                   data-dt-filter="3"
                   data-dt-date-col="0">
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
                    <?php foreach ($rows as $r):
                        $ts = strtotime((string)($r['tanggal_transaksi'] ?? ''));
                        $orderTs = $ts ? date('Y-m-d H:i:s', $ts) : '';
                        $day = $ts ? date('Y-m-d', $ts) : '';
                    ?>
                    <tr>
                        <td data-order="<?= htmlspecialchars($orderTs) ?>" data-date="<?= htmlspecialchars($day) ?>">
                            <?= htmlspecialchars(formatTanggalUi($r['tanggal_transaksi'], true, true)) ?>
                        </td>
                        <td><code><?= htmlspecialchars($r['kode_transaksi']) ?></code></td>
                        <td><?= ui_cell_ellipsis(($r['kode_anggota'] ?? '') . ' — ' . ($r['nama_lengkap'] ?? ''), 32) ?></td>
                        <td><?= ui_cell_ellipsis($r['nama_jenis'] ?? '-', 20) ?></td>
                        <td class="text-end fw-semibold"><?= formatRupiah($r['jumlah']) ?></td>
                        <td><?= ui_cell_ellipsis($r['petugas'] ?? ($r['teller'] ?? '-'), 18) ?></td>
                        <td><?= ui_cell_ellipsis($r['keterangan'] ?? '-', 28) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

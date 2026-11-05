<?php
/**
 * Keranjang pembayaran angsuran.
 *
 * Variabel yang dibutuhkan dari pemanggil: $keranjang dan $totalKeranjang.
 */
?>
<div class="card ui-ag-cart mb-3<?php echo $keranjang ? ' has-items' : ''; ?>" id="keranjangAngsuran">
    <div class="card-header ui-dash-sec">
        <span class="ui-dash-sec-title"><i class="ti ti-basket" aria-hidden="true"></i>Keranjang pembayaran</span>
        <?php if ($keranjang): ?>
        <span class="badge bg-success"><?php echo count($keranjang); ?> item &middot; siap proses</span>
        <?php else: ?>
        <span class="ui-dash-sec-note">Kosong &mdash; proses langsung atau masukkan ke keranjang</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if ($keranjang): ?>
        <div class="table-responsive ui-table-scroll">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Nasabah</th><th>Pinjaman</th><th>Ke</th><th>Tgl proses</th><th>Metode</th><th class="text-end">Nominal</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($keranjang as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['nama']); ?></td>
                        <td><code><?php echo htmlspecialchars($item['kode']); ?></code></td>
                        <td><?php echo (int)$item['angsuran_ke']; ?></td>
                        <td><?php echo formatTanggalUi($item['tanggal_proses'] ?? date('Y-m-d')); ?></td>
                        <td class="text-capitalize"><?php echo htmlspecialchars($item['metode']); ?></td>
                        <td class="text-end fw-semibold"><?php echo formatRupiah((float)$item['jumlah'] + max(0, (float)$item['denda_tampil'])); ?></td>
                        <td class="text-end"><form method="POST" class="d-inline"><?php echo layananToken(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                            <?php if (!empty($_GET['pinjaman_id'])): ?><input type="hidden" name="pinjaman_id" value="<?php echo (int)$_GET['pinjaman_id']; ?>"><?php endif; ?>
                            <button name="hapus_keranjang" class="btn btn-sm btn-outline-danger" title="Hapus dari keranjang"><i class="ti ti-x"></i></button>
                        </form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="ui-ag-cart-foot">
            <strong>Total: <?php echo formatRupiah($totalKeranjang); ?></strong>
            <form method="POST"><?php echo layananToken(); ?>
                <button type="submit" name="checkout_keranjang" class="btn btn-success" onclick="return confirm('Proses seluruh item di keranjang? Kas &amp; laporan akan diperbarui.');"><i class="ti ti-cash-register me-1"></i>Proses semua</button>
            </form>
        </div>
        <?php else: ?>
        <div class="p-3 text-muted small mb-0">Keranjang kosong. Dari jadwal angsuran, buka menu &hellip; &rarr; <em>Masukkan ke keranjang</em> atau <em>Proses langsung</em>.</div>
        <?php endif; ?>
    </div>
</div>

<?php
/**
 * Panel tindak lanjut di bawah daftar layanan.
 * Inbox "Perlu ditinjau Admin" dihapus — sudah ada notifikasi di topbar.
 */
if (isAdmin() && $tab === 'lunas'):
$repeatItems = array_values(array_filter($rows, function ($loan) {
    return $loan['status'] === 'lunas'
        && $loan['tanggal_lunas']
        && $loan['tanggal_lunas'] <= date('Y-m-d', strtotime('-7 days'))
        && !$loan['repeat_selesai'];
}));
?>
<section class="card mt-3 ui-ly-follow">
    <div class="card-header ui-dash-sec">
        <div>
            <span class="ui-dash-sec-title"><i class="ti ti-refresh" aria-hidden="true"></i>Penawaran pinjaman ulang</span>
            <p class="text-muted small mb-0 mt-1">Lunas ≥ 7 hari dan belum ditandai selesai ditindaklanjuti.</p>
        </div>
        <span class="ui-dash-sec-note"><?= count($repeatItems) ?> nasabah</span>
    </div>
    <div class="card-body">
        <?php if (!$repeatItems): ?>
        <div class="ui-ly-empty compact"><i class="ti ti-mood-smile" aria-hidden="true"></i><div><strong>Belum ada penawaran</strong><p class="mb-0">Pengingat muncul otomatis setelah 7 hari pelunasan.</p></div></div>
        <?php else: ?>
        <div class="ui-ly-follow-grid">
            <?php foreach ($repeatItems as $loan): ?>
            <a class="ui-ly-follow-card" href="detail_layanan.php?tab=lunas&amp;id=<?= (int)$loan['id'] ?>">
                <span class="ui-ly-follow-avatar" aria-hidden="true"><i class="ti ti-user"></i></span>
                <span class="ui-ly-follow-copy">
                    <strong><?= layananEscape($loan['nama_lengkap']) ?></strong>
                    <small>Lunas <?= layananEscape(formatTanggalUi($loan['tanggal_lunas'])) ?></small>
                </span>
                <i class="ti ti-chevron-right ui-ly-follow-go" aria-hidden="true"></i>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php
endif;

$nearlyPaid = array_values(array_filter($rows, function ($loan) {
    return (int)$loan['sisa_cicilan'] === 1
        && $loan['terakhir'] >= date('Y-m-d')
        && $loan['terakhir'] <= date('Y-m-d', strtotime('+7 days'));
}));
?>
<section class="card mt-3 ui-ly-follow">
    <div class="card-header ui-dash-sec">
        <div>
            <span class="ui-dash-sec-title"><i class="ti ti-calendar-event" aria-hidden="true"></i>Akan lunas dalam 7 hari</span>
            <p class="text-muted small mb-0 mt-1">Sisa satu cicilan — siapkan pengembalian jaminan bila perlu.</p>
        </div>
        <span class="ui-dash-sec-note"><?= count($nearlyPaid) ?> pinjaman</span>
    </div>
    <div class="card-body<?= $nearlyPaid ? ' p-2' : '' ?>">
        <?php if (!$nearlyPaid): ?>
        <div class="ui-ly-empty compact"><i class="ti ti-calendar-check" aria-hidden="true"></i><div><strong>Tidak ada cicilan terakhir dalam 7 hari</strong><p class="mb-0">Daftar diperbarui otomatis dari jadwal angsuran.</p></div></div>
        <?php else: ?>
        <div class="ui-ly-follow-list">
            <?php foreach ($nearlyPaid as $loan): ?>
            <article class="ui-ly-follow-row">
                <div class="ui-ly-follow-main">
                    <strong class="ui-ly-member"><?= layananEscape($loan['nama_lengkap']) ?></strong>
                    <small class="ui-ly-kode">JT terakhir <?= layananEscape(formatTanggalUi($loan['terakhir'])) ?> · <?= layananEscape($loan['status_jaminan'] ?: 'Tanpa jaminan') ?></small>
                </div>
                <div class="ui-ly-follow-amt">
                    <span>Sisa bayar</span>
                    <strong><?= formatRupiah($loan['sisa_tagihan']) ?></strong>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="detail_layanan.php?tab=macet&amp;id=<?= (int)$loan['id'] ?>">Periksa</a>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

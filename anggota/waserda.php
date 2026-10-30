<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isAnggota()) {
    $_SESSION['error'] = 'Anda tidak memiliki akses ke halaman ini';
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt_anggota = $db->prepare('SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE user_id = :user_id');
$stmt_anggota->bindParam(':user_id', $user_id);
$stmt_anggota->execute();
$anggota = $stmt_anggota->fetch(PDO::FETCH_ASSOC);
if (!$anggota) {
    $_SESSION['error'] = 'Data anggota tidak ditemukan';
    header('Location: dashboard.php');
    exit();
}
$anggota_id = (int) $anggota['id'];

$stmt_transaksi = $db->prepare(
    'SELECT w.*, u.nama_lengkap AS petugas
     FROM waserda_transaksi w
     LEFT JOIN users u ON w.user_id = u.id
     WHERE w.anggota_id = :anggota_id
     ORDER BY w.created_at DESC'
);
$stmt_transaksi->bindParam(':anggota_id', $anggota_id);
$stmt_transaksi->execute();
$transaksi = $stmt_transaksi->fetchAll(PDO::FETCH_ASSOC);

$total_pembelian = 0;
$total_item = 0;
$detailMap = [];
foreach ($transaksi as $t) {
    $total_pembelian += (float) $t['total_bayar'];
    $items = json_decode($t['items'] ?? '[]', true);
    if (!is_array($items)) {
        $items = [];
    }
    $total_item += count($items);
    $detailMap[(int) $t['id']] = [
        'id' => (int) $t['id'],
        'kode' => (string) $t['kode_transaksi'],
        'total' => (float) $t['total_bayar'],
        'metode' => (string) ($t['metode_bayar'] ?? ''),
        'petugas' => (string) ($t['petugas'] ?? '—'),
        'tanggal' => (string) ($t['created_at'] ?? ''),
        'items' => array_values(array_map(static function ($it) {
            return [
                'nama' => (string) ($it['nama'] ?? '-'),
                'harga' => (float) ($it['harga'] ?? 0),
                'jumlah' => (int) ($it['jumlah'] ?? 0),
            ];
        }, $items)),
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waserda Saya - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page ui-agt-waserda">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-agtwas" aria-hidden="true"><i class="ti ti-building-store"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Akun anggota</p>
                        <h1 class="ui-dash-title">Waserda Saya</h1>
                        <p class="ui-dash-meta mb-0">Riwayat belanja warung koperasi</p>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-agt-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total belanja', 'value' => formatRupiah($total_pembelian), 'tone' => 'success', 'icon' => 'ti ti-shopping-cart', 'hint' => 'Semua waktu'],
                    ['label' => 'Nota', 'value' => count($transaksi), 'tone' => 'primary', 'icon' => 'ti ti-receipt-2', 'hint' => 'Jumlah transaksi'],
                    ['label' => 'Item terbeli', 'value' => $total_item, 'tone' => 'info', 'icon' => 'ti ti-package', 'hint' => 'Semua baris item'],
                ]); ?>
                </div>

                <div class="ui-agt-note mb-3" role="note">
                    <i class="ti ti-info-circle" aria-hidden="true"></i>
                    <div>
                        <strong>Hanya riwayat</strong>
                        <span>Pembelian dilakukan di waserda. Ketuk kartu atau tombol Detail untuk melihat isi nota.</span>
                    </div>
                </div>

                <?php if (!$transaksi): ?>
                <div class="card">
                    <div class="card-body">
                        <div class="ui-agt-empty">
                            <i class="ti ti-building-store" aria-hidden="true"></i>
                            <strong>Belum ada belanja</strong>
                            <p>Transaksi waserda Anda akan muncul di sini setelah belanja di koperasi.</p>
                        </div>
                    </div>
                </div>
                <?php else: ?>

                <div class="card ui-agt-daftar ui-agt-was-desktop d-none d-lg-block">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-history" aria-hidden="true"></i>Riwayat pembelian</span>
                        <span class="ui-dash-sec-note"><?php echo (int) count($transaksi); ?> nota</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="0,desc"
                                   data-dt-filter="4"
                                   data-empty-message="Belum ada transaksi waserda.">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Kode</th>
                                        <th>Item</th>
                                        <th class="text-end">Total</th>
                                        <th>Metode</th>
                                        <th>Petugas</th>
                                        <th class="text-end no-sort">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($transaksi as $t):
                                    $items = json_decode($t['items'] ?? '[]', true);
                                    if (!is_array($items)) {
                                        $items = [];
                                    }
                                    $jumlah_item = count($items);
                                    $first_item = $items[0]['nama'] ?? 'Barang';
                                    $metode = (string) ($t['metode_bayar'] ?? '');
                                    ?>
                                    <tr>
                                        <td data-order="<?php echo htmlspecialchars((string) $t['created_at']); ?>">
                                            <span class="ui-agt-text"><?php echo htmlspecialchars(formatTanggalUi($t['created_at'], true)); ?></span>
                                        </td>
                                        <td><code class="ui-agt-code"><?php echo htmlspecialchars($t['kode_transaksi']); ?></code></td>
                                        <td>
                                            <strong class="ui-agt-name"><?php echo htmlspecialchars($first_item); ?></strong>
                                            <?php if ($jumlah_item > 1): ?>
                                            <span class="ui-agt-meta">+<?php echo $jumlah_item - 1; ?> item lain</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><strong class="ui-agt-money is-in"><?php echo formatRupiah($t['total_bayar']); ?></strong></td>
                                        <td data-filter="<?php echo htmlspecialchars($metode); ?>">
                                            <span class="ui-agt-pill is-<?php echo htmlspecialchars($metode); ?>"><?php echo htmlspecialchars(ucfirst($metode)); ?></span>
                                        </td>
                                        <td><span class="ui-agt-text"><?php echo htmlspecialchars($t['petugas'] ?? '—'); ?></span></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-primary js-agt-was-detail"
                                                    data-was-id="<?php echo (int) $t['id']; ?>">
                                                <i class="ti ti-eye" aria-hidden="true"></i> Detail
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="ui-agt-was-list d-lg-none" aria-label="Daftar pembelian">
                    <?php foreach ($transaksi as $t):
                        $items = json_decode($t['items'] ?? '[]', true);
                        if (!is_array($items)) {
                            $items = [];
                        }
                        $jumlah_item = count($items);
                        $first_item = $items[0]['nama'] ?? 'Barang';
                        $metode = (string) ($t['metode_bayar'] ?? '');
                        ?>
                    <article class="ui-agt-was-card">
                        <button type="button" class="ui-agt-was-card-hit js-agt-was-detail" data-was-id="<?php echo (int) $t['id']; ?>" aria-label="Detail <?php echo htmlspecialchars($t['kode_transaksi']); ?>">
                            <div class="ui-agt-was-card-top">
                                <div>
                                    <code class="ui-agt-code"><?php echo htmlspecialchars($t['kode_transaksi']); ?></code>
                                    <span class="ui-agt-meta"><?php echo htmlspecialchars(formatTanggalUi($t['created_at'], true)); ?></span>
                                </div>
                                <strong class="ui-agt-money is-in"><?php echo formatRupiah($t['total_bayar']); ?></strong>
                            </div>
                            <div class="ui-agt-was-card-body">
                                <strong class="ui-agt-name"><?php echo htmlspecialchars($first_item); ?></strong>
                                <?php if ($jumlah_item > 1): ?>
                                <span class="ui-agt-meta">+<?php echo $jumlah_item - 1; ?> item lain</span>
                                <?php endif; ?>
                            </div>
                            <div class="ui-agt-was-card-foot">
                                <span class="ui-agt-pill is-<?php echo htmlspecialchars($metode); ?>"><?php echo htmlspecialchars(ucfirst($metode)); ?></span>
                                <span class="ui-agt-was-petugas"><i class="ti ti-user" aria-hidden="true"></i><?php echo htmlspecialchars($t['petugas'] ?? '—'); ?></span>
                                <span class="ui-agt-was-cta">Detail <i class="ti ti-chevron-right" aria-hidden="true"></i></span>
                            </div>
                        </button>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>

<div class="modal fade" id="detailWaserdaModal" tabindex="-1" aria-labelledby="detailWaserdaTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content ui-agt-was-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="detailWaserdaTitle"><i class="ti ti-receipt-2 me-1" aria-hidden="true"></i>Detail pembelian</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body" id="detailWaserdaBody">
                <p class="text-muted mb-0">Memuat…</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<script>
window.__agtWaserdaDetail = <?php echo json_encode($detailMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
(function () {
    var map = window.__agtWaserdaDetail || {};
    var modalEl = document.getElementById('detailWaserdaModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    document.body.appendChild(modalEl);
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var bodyEl = document.getElementById('detailWaserdaBody');
    var titleEl = document.getElementById('detailWaserdaTitle');

    function formatRupiah(n) {
        n = Math.round(Number(n) || 0);
        return 'Rp ' + Math.abs(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    function formatTanggal(value) {
        if (!value) return '—';
        var m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
        if (m) {
            return m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? (' ' + m[4] + ':' + m[5]) : '');
        }
        return String(value);
    }
    function escapeHtml(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
        });
    }
    function render(d) {
        titleEl.innerHTML = '<i class="ti ti-receipt-2 me-1" aria-hidden="true"></i>' + escapeHtml(d.kode);
        var rows = '';
        (d.items || []).forEach(function (item, i) {
            var sub = (Number(item.harga) || 0) * (Number(item.jumlah) || 0);
            rows += '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(item.nama) + '</td>'
                + '<td class="text-end">' + formatRupiah(item.harga) + '</td>'
                + '<td class="text-center">' + (item.jumlah || 0) + '</td>'
                + '<td class="text-end fw-semibold">' + formatRupiah(sub) + '</td></tr>';
        });
        bodyEl.innerHTML =
            '<div class="ui-agt-was-modal-meta">'
            + '<div><span>Tanggal</span><strong>' + escapeHtml(formatTanggal(d.tanggal)) + '</strong></div>'
            + '<div><span>Metode</span><strong>' + escapeHtml((d.metode || '—').replace(/^./, function (c) { return c.toUpperCase(); })) + '</strong></div>'
            + '<div><span>Petugas</span><strong>' + escapeHtml(d.petugas || '—') + '</strong></div>'
            + '</div>'
            + '<div class="table-responsive"><table class="table table-sm align-middle mb-0">'
            + '<thead><tr><th>No</th><th>Barang</th><th class="text-end">Harga</th><th class="text-center">Qty</th><th class="text-end">Subtotal</th></tr></thead>'
            + '<tbody>' + (rows || '<tr><td colspan="5" class="text-center text-muted">Tidak ada item</td></tr>') + '</tbody>'
            + '<tfoot><tr><th colspan="4" class="text-end">TOTAL</th><th class="text-end text-success">' + formatRupiah(d.total) + '</th></tr></tfoot>'
            + '</table></div>';
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-agt-was-detail');
        if (!btn) return;
        e.preventDefault();
        var id = String(btn.getAttribute('data-was-id') || '');
        var d = map[id];
        if (!d) {
            bodyEl.innerHTML = '<div class="alert alert-warning mb-0">Detail tidak ditemukan.</div>';
        } else {
            render(d);
        }
        modal.show();
    });
})();
</script>
</body>
</html>

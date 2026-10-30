<?php
/**
 * Single implementation — Closing Angsuran Bulanan (Admin & Teller).
 * Kunci periode: Admin saja (can('closing.lock')).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}
if (!isAdmin() && !isTeller()) {
    http_response_code(403);
    exit('Akses ditolak.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tutup_closing'])) {
    if (!can('closing.lock')) {
        $_SESSION['error'] = 'Hanya Admin yang dapat menutup periode angsuran.';
    } else {
        try {
            layananValidasiToken();
            $b = trim($_POST['bulan'] ?? '');
            $h = tutupClosingAngsuran($db, $b, $_SESSION['user_id'], trim($_POST['catatan'] ?? ''));
            $_SESSION['success'] = 'Periode ' . $b . ' berhasil dikunci ('
                . $h['jumlah_pembayaran'] . ' pembayaran, '
                . formatRupiah((float)$h['total_pembayaran']) . ').';
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    }
    header('Location: closing_angsuran.php');
    exit;
}

$bulanNama = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$fmtBulan = static function ($ym) use ($bulanNama) {
    if (!is_string($ym) || !preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        return (string)$ym;
    }
    $n = (int)$m[2];
    return ($bulanNama[$n] ?? $m[2]) . ' ' . $m[1];
};

$closing = closingTabelAda($db)
    ? $db->query("SELECT c.*, u.nama_lengkap FROM closing_angsuran_bulanan c LEFT JOIN users u ON u.id = c.ditutup_oleh ORDER BY c.bulan DESC")->fetchAll(PDO::FETCH_ASSOC)
    : [];

$previewRows = $db->query(
    "SELECT DATE_FORMAT(tanggal_bayar,'%Y-%m') bulan,
            COUNT(*) jumlah,
            COALESCE(SUM(jumlah_angsuran + COALESCE(denda,0)),0) total
     FROM angsuran
     WHERE status='lunas' AND tanggal_bayar IS NOT NULL
     GROUP BY DATE_FORMAT(tanggal_bayar,'%Y-%m')
     ORDER BY bulan DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$previewMap = [];
foreach ($previewRows as $pr) {
    $previewMap[$pr['bulan']] = [
        'jumlah' => (int)$pr['jumlah'],
        'total' => (float)$pr['total'],
    ];
}
$lockedSet = [];
foreach ($closing as $row) {
    if (($row['status'] ?? '') === 'terkunci') {
        $lockedSet[$row['bulan']] = true;
    }
}

$bulanDefault = date('Y-m', strtotime('first day of last month'));
if (isset($lockedSet[$bulanDefault]) || !isset($previewMap[$bulanDefault])) {
    $bulanDefault = date('Y-m');
}
$previewDefault = $previewMap[$bulanDefault] ?? ['jumlah' => 0, 'total' => 0.0];

$totalTerkunci = 0;
$totalPembayaran = 0;
$totalNominal = 0.0;
$terakhir = null;
foreach ($closing as $row) {
    if (($row['status'] ?? '') === 'terkunci') {
        $totalTerkunci++;
        $totalPembayaran += (int)$row['jumlah_pembayaran'];
        $totalNominal += (float)$row['total_pembayaran'];
        if ($terakhir === null) {
            $terakhir = $row;
        }
    }
}
$bulanBerjalanTerkunci = !empty($lockedSet[date('Y-m')]);

$page_title = 'Closing Angsuran';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Closing Angsuran - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
<?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-closing" aria-hidden="true"><i class="ti ti-calendar-check"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Laporan</p>
                        <h1 class="ui-dash-title">Closing Angsuran Bulanan</h1>
                        <p class="ui-dash-meta mb-0">Kunci periode agar pembayaran &amp; koreksi bulan tersebut tidak diubah</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="angsuran.php" class="btn ui-tx-btn-closing-ghost">
                            <i class="ti ti-calendar-due me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Angsuran</span>
                        </a>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="alert alert-info ui-cl-info" role="status" data-ui-toast-skip>
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                    Setelah periode dikunci, pembayaran dan pembatalan untuk tanggal dalam bulan tersebut tidak dapat diubah.
                    <?php if ($bulanBerjalanTerkunci): ?>
                    <strong class="ms-1">Bulan berjalan sudah terkunci.</strong>
                    <?php endif; ?>
                </div>

                <div class="ui-cl-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Periode terkunci', 'value' => $totalTerkunci, 'tone' => 'success', 'icon' => 'ti ti-lock', 'hint' => 'Riwayat closing'],
                    ['label' => 'Pembayaran dikunci', 'value' => $totalPembayaran, 'tone' => 'primary', 'icon' => 'ti ti-receipt', 'hint' => 'Cicilan lunas'],
                    ['label' => 'Total nominal', 'value' => formatRupiah($totalNominal), 'tone' => 'warning', 'icon' => 'ti ti-cash', 'hint' => 'Pokok + denda'],
                    ['label' => 'Terakhir dikunci', 'value' => $terakhir ? $fmtBulan($terakhir['bulan']) : '—', 'tone' => 'info', 'icon' => 'ti ti-calendar-event', 'hint' => $terakhir ? formatTanggalUi($terakhir['ditutup_at'], true) : 'Belum ada'],
                ]); ?>
                </div>

                <?php if (can('closing.lock')): ?>
                <div class="card mb-3 ui-cl-lock">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-lock" aria-hidden="true"></i>Kunci periode baru</span>
                        <span class="ui-dash-sec-note">Admin saja</span>
                    </div>
                    <div class="card-body">
                        <form method="post" class="ui-cl-form" id="closingLockForm">
                            <?php echo layananToken(); ?>
                            <input type="hidden" name="tutup_closing" value="1">
                            <div class="ui-cl-form-grid">
                                <div class="ui-cl-field">
                                    <label class="form-label" for="closing_bulan">Bulan yang ditutup</label>
                                    <input type="month" id="closing_bulan" name="bulan" class="form-control" value="<?php echo htmlspecialchars($bulanDefault); ?>" max="<?php echo date('Y-m'); ?>" required>
                                </div>
                                <div class="ui-cl-field ui-cl-field-wide">
                                    <label class="form-label" for="closing_catatan">Catatan</label>
                                    <input id="closing_catatan" name="catatan" class="form-control" maxlength="500" placeholder="Contoh: Closing <?php echo htmlspecialchars($fmtBulan($bulanDefault)); ?>">
                                </div>
                                <div class="ui-cl-field ui-cl-field-action">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <button type="submit" class="btn btn-primary w-100" onclick="return confirm('Kunci periode ini? Perubahan angsuran di bulan tersebut akan diblokir.')">
                                        <i class="ti ti-lock me-1" aria-hidden="true"></i>Kunci periode
                                    </button>
                                </div>
                            </div>
                        </form>
                        <div class="ui-cl-preview" id="closingPreview" data-locked='<?php echo htmlspecialchars(json_encode(array_keys($lockedSet), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>' data-map='<?php echo htmlspecialchars(json_encode($previewMap, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>'>
                            <div class="ui-cl-preview-item">
                                <span>Pembayaran di bulan ini</span>
                                <strong data-preview="jumlah"><?php echo number_format((int)$previewDefault['jumlah'], 0, ',', '.'); ?></strong>
                            </div>
                            <div class="ui-cl-preview-item">
                                <span>Estimasi total</span>
                                <strong data-preview="total"><?php echo formatRupiah((float)$previewDefault['total']); ?></strong>
                            </div>
                            <div class="ui-cl-preview-item is-status">
                                <span>Status</span>
                                <strong data-preview="status"><?php echo !empty($lockedSet[$bulanDefault]) ? 'Sudah terkunci' : 'Siap dikunci'; ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-secondary ui-cl-info mb-3" role="status" data-ui-toast-skip>
                    <i class="ti ti-eye me-1" aria-hidden="true"></i>
                    Anda dapat melihat riwayat closing. Penguncian periode hanya dilakukan Admin.
                </div>
                <?php endif; ?>

                <div class="card ui-cl-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-history" aria-hidden="true"></i>Riwayat closing</span>
                        <span class="ui-dash-sec-note"><?php echo (int)count($closing); ?> periode</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable" data-dt-page-length="10" data-dt-order="0,desc" data-empty-message="Belum ada periode yang ditutup.">
                                <thead>
                                    <tr>
                                        <th>Bulan</th>
                                        <th>Status</th>
                                        <th class="text-end">Jumlah pembayaran</th>
                                        <th class="text-end">Total angsuran</th>
                                        <th>Ditutup oleh</th>
                                        <th>Waktu</th>
                                        <th>Catatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($closing as $row): ?>
                                    <tr>
                                        <td data-order="<?php echo htmlspecialchars($row['bulan']); ?>">
                                            <strong class="ui-cl-bulan"><?php echo htmlspecialchars($fmtBulan($row['bulan'])); ?></strong>
                                            <small class="ui-cl-bulan-code"><?php echo htmlspecialchars($row['bulan']); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($row['status'] === 'terkunci'): ?>
                                            <span class="badge bg-success"><i class="ti ti-lock me-1" aria-hidden="true"></i>Terkunci</span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst((string)$row['status'])); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-semibold"><?php echo number_format((int)$row['jumlah_pembayaran'], 0, ',', '.'); ?></td>
                                        <td class="text-end fw-semibold ui-cl-money"><?php echo formatRupiah((float)$row['total_pembayaran']); ?></td>
                                        <td><?php echo htmlspecialchars($row['nama_lengkap'] ?: '—'); ?></td>
                                        <td data-order="<?php echo htmlspecialchars((string)$row['ditutup_at']); ?>"><?php echo formatTanggalUi($row['ditutup_at'], true); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($row['catatan'] ?: '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php include __DIR__ . '/layout/shell_end.php'; ?>
<script>
(function () {
  var bulanEl = document.getElementById('closing_bulan');
  var box = document.getElementById('closingPreview');
  if (!bulanEl || !box) return;
  var map = {};
  var locked = {};
  try { map = JSON.parse(box.getAttribute('data-map') || '{}') || {}; } catch (e) { map = {}; }
  try {
    (JSON.parse(box.getAttribute('data-locked') || '[]') || []).forEach(function (b) { locked[b] = true; });
  } catch (e) { locked = {}; }
  function rp(n) {
    n = Math.round(Number(n) || 0);
    return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }
  function sync() {
    var b = bulanEl.value;
    var row = map[b] || { jumlah: 0, total: 0 };
    var j = box.querySelector('[data-preview="jumlah"]');
    var t = box.querySelector('[data-preview="total"]');
    var s = box.querySelector('[data-preview="status"]');
    if (j) j.textContent = Number(row.jumlah || 0).toLocaleString('id-ID');
    if (t) t.textContent = rp(row.total || 0);
    if (s) {
      s.textContent = locked[b] ? 'Sudah terkunci' : 'Siap dikunci';
      s.classList.toggle('is-locked', !!locked[b]);
    }
  }
  bulanEl.addEventListener('change', sync);
  sync();
})();
</script>
</body>
</html>

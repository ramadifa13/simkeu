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

$closing = closingTabelAda($db)
    ? $db->query("SELECT c.*, u.nama_lengkap FROM closing_angsuran_bulanan c LEFT JOIN users u ON u.id = c.ditutup_oleh ORDER BY c.bulan DESC")->fetchAll(PDO::FETCH_ASSOC)
    : [];

$__ui = ui_query_params([]);
$__q = $__ui['q'];
$__all = $closing;
if ($__q !== '') {
    $__fields = ['bulan', 'nama_lengkap', 'catatan', 'status'];
    $__all = array_values(array_filter($__all, static function ($r) use ($__q, $__fields) {
        $hay = '';
        foreach ($__fields as $f) {
            $hay .= ' ' . ($r[$f] ?? '');
        }
        return stripos($hay, $__q) !== false;
    }));
}
$pager = ui_paginate(count($__all), $__ui['page'], $__ui['per_page']);
$closing = array_slice($__all, $pager['offset'], $pager['per_page']);
$q = $__q;
$page_title = 'Closing Angsuran';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Closing Angsuran - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="container-fluid">
    <div class="row">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <?php ui_page_header([
                'title' => 'Closing Angsuran Bulanan',
                'icon' => 'fas fa-calendar-check',
                'subtitle' => 'Kunci periode agar pembayaran dan koreksi bulan tersebut tidak diubah',
            ]); ?>
            <?php ui_flash(); ?>

            <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle me-1"></i>
                Setelah periode dikunci, pembayaran dan pembatalan untuk tanggal dalam bulan tersebut tidak dapat diubah.
            </div>

            <?php if (can('closing.lock')): ?>
                <div class="card mb-4">
                    <div class="card-header"><strong><i class="fas fa-lock me-2 text-primary"></i>Kunci periode baru</strong></div>
                    <div class="card-body">
                        <form method="post" class="row g-3 align-items-end">
                            <?php echo layananToken(); ?>
                            <input type="hidden" name="tutup_closing" value="1">
                            <div class="col-md-3">
                                <label class="form-label">Bulan yang ditutup</label>
                                <input type="month" name="bulan" class="form-control" value="<?php echo date('Y-m'); ?>" max="<?php echo date('Y-m'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Catatan</label>
                                <input name="catatan" class="form-control" maxlength="500" placeholder="Contoh: Closing Oktober 2026">
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-primary w-100" onclick="return confirm('Kunci periode ini? Perubahan angsuran di bulan tersebut akan diblokir.')">
                                    <i class="fas fa-lock me-1"></i>Kunci periode
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-secondary mb-3">Anda dapat melihat riwayat closing. Penguncian periode hanya dilakukan Admin.</div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong>Riwayat closing</strong>
                    <span class="badge bg-primary"><?php echo (int)$pager['total']; ?> periode</span>
                </div>
                <?php ui_table_toolbar(['action' => 'closing_angsuran.php', 'q' => $q ?? '', 'placeholder' => 'Cari bulan / petugas…', 'per_page' => $pager['per_page'], 'reset_href' => 'closing_angsuran.php']); ?>
                <div class="table-responsive ui-table-scroll">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Bulan</th>
                                <th>Status</th>
                                <th>Jumlah pembayaran</th>
                                <th>Total angsuran</th>
                                <th>Ditutup oleh</th>
                                <th>Waktu</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$closing): ?>
                            <tr><td colspan="7" class="text-center text-muted py-5">
                                <i class="fas fa-calendar-times fa-2x mb-2 d-block opacity-50"></i>
                                Belum ada periode yang ditutup.
                            </td></tr>
                        <?php else: foreach ($closing as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo date('F Y', strtotime($row['bulan'] . '-01')); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $row['status'] === 'terkunci' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format((int)$row['jumlah_pembayaran'], 0, ',', '.'); ?></td>
                                <td><?php echo formatRupiah((float)$row['total_pembayaran']); ?></td>
                                <td><?php echo htmlspecialchars($row['nama_lengkap'] ?: '—'); ?></td>
                                <td><?php echo formatTanggalUi($row['ditutup_at'], true); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars($row['catatan'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php ui_pagination($pager, 'closing_angsuran.php'); ?>
        </main>
    </div>
</div>
</body>
</html>

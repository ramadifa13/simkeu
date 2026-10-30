<?php
if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/shu_helper.php';
require_once __DIR__ . '/shu_pinjaman_helper.php';

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    exit('Akses SHU hanya untuk Admin.');
}

$page_title = 'SHU Pinjaman';

if (!shuPinjamanSiap($db)) {
    http_response_code(503);
    exit('Modul SHU pinjaman memerlukan MIGRASI_UNIVERSAL.sql terbaru.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $files = [];
    try {
        layananValidasiToken();
        $db->beginTransaction();
        $aksi = $_POST['aksi'] ?? '';
        if ($aksi === 'sinkronkan') {
            $jumlah = shuSinkronkanHakLama($db);
            $_SESSION['success'] = 'Sinkronisasi selesai. ' . $jumlah . ' pinjaman lunas diperiksa.';
        } elseif ($aksi === 'bayar') {
            $foto = validasiFotoUpload('bukti', 'Bukti pembayaran SHU', 5 * 1024 * 1024, true);
            shuBayarHakPinjaman(
                $db,
                (int)($_POST['hak_id'] ?? 0),
                $_POST['metode'] ?? '',
                $_POST['referensi'] ?? '',
                $foto,
                $files
            );
            $_SESSION['success'] = 'Pembayaran SHU berhasil dicatat.';
        } else {
            throw new Exception('Tindakan SHU tidak dikenal.');
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($files as $file) {
            hapusFotoUpload($file);
        }
        $_SESSION['error'] = $e instanceof PDOException ? 'Data SHU gagal disimpan.' : $e->getMessage();
    }
    header('Location: shu.php');
    exit;
}

$rows = $db->query(
    "SELECT h.*, p.kode_pinjaman, p.tanggal_lunas, a.nama_lengkap, a.kode_anggota,
            s.nama_lengkap sumber_nama, u.nama_lengkap admin_nama
     FROM shu_hak_pinjaman h
     JOIN pinjaman p ON p.id = h.pinjaman_id
     JOIN anggota a ON a.id = h.anggota_id
     JOIN anggota s ON s.id = h.sumber_anggota_id
     LEFT JOIN users u ON u.id = h.dibayar_oleh
     ORDER BY h.created_at DESC, h.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$stats = $db->query(
    "SELECT COUNT(*) jumlah,
            COALESCE(SUM(jumlah),0) total,
            COALESCE(SUM(status_bayar='sudah'),0) dibayar_jumlah,
            COALESCE(SUM(CASE WHEN status_bayar='sudah' THEN jumlah ELSE 0 END),0) dibayar,
            COALESCE(SUM(status_bayar='belum'),0) belum_jumlah,
            COALESCE(SUM(CASE WHEN status_bayar='belum' THEN jumlah ELSE 0 END),0) belum,
            COALESCE(SUM(jenis='referensi'),0) referensi,
            COALESCE(SUM(CASE WHEN jenis='referensi' THEN jumlah ELSE 0 END),0) referensi_nilai,
            COALESCE(SUM(jenis='pelunasan'),0) pelunasan,
            COALESCE(SUM(CASE WHEN jenis='pelunasan' THEN jumlah ELSE 0 END),0) pelunasan_nilai
     FROM shu_hak_pinjaman"
)->fetch(PDO::FETCH_ASSOC);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv;charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan-shu-pinjaman-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Pinjaman', 'Tanggal Lunas', 'Penerima', 'Sumber', 'Jenis', 'Kategori', 'Pokok', 'Persen', 'SHU', 'Lancar', 'Status', 'Dibayar']);
    foreach ($rows as $r) {
        fputcsv($o, [
            $r['kode_pinjaman'],
            $r['tanggal_lunas'],
            $r['nama_lengkap'],
            $r['sumber_nama'],
            $r['jenis'],
            $r['kategori_snapshot'],
            $r['dasar_pinjaman'],
            $r['persen'],
            $r['jumlah'],
            $r['pembayaran_lancar'] ? 'Ya' : 'Tidak',
            $r['status_bayar'],
            $r['dibayar_at'],
        ]);
    }
    fclose($o);
    exit;
}

$fmtPersen = static function ($v) {
    return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
};
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= layananEscape($page_title) ?> - <?= htmlspecialchars(APP_NAME) ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
<?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-shu" aria-hidden="true"><i class="ti ti-coins"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Modul SHU</p>
                        <h1 class="ui-dash-title">SHU Pinjaman</h1>
                        <p class="ui-dash-meta mb-0">Ringkasan, pembayaran, dan laporan hak SHU pinjaman tanpa jaminan</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a class="btn btn-outline-success btn-sm" href="?export=csv">
                            <i class="ti ti-file-spreadsheet me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Export CSV</span>
                        </a>
                        <form method="post" class="d-inline"><?= layananToken() ?>
                            <input type="hidden" name="aksi" value="sinkronkan">
                            <button class="btn btn-outline-primary btn-sm" type="submit">
                                <i class="ti ti-refresh me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Sinkronkan</span>
                            </button>
                        </form>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-shu-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total hak', 'value' => formatRupiah($stats['total']), 'tone' => 'primary', 'icon' => 'ti ti-coins', 'hint' => (int)$stats['jumlah'] . ' catatan'],
                    ['label' => 'Sudah dibayar', 'value' => formatRupiah($stats['dibayar']), 'tone' => 'success', 'icon' => 'ti ti-circle-check', 'hint' => (int)$stats['dibayar_jumlah'] . ' pembayaran'],
                    ['label' => 'Belum dibayar', 'value' => formatRupiah($stats['belum']), 'tone' => 'warning', 'icon' => 'ti ti-clock-hour-4', 'hint' => (int)$stats['belum_jumlah'] . ' menunggu'],
                    ['label' => 'Bonus referensi', 'value' => (int)$stats['referensi'], 'tone' => 'info', 'icon' => 'ti ti-users-group', 'hint' => formatRupiah($stats['referensi_nilai'])],
                ]); ?>
                </div>

                <div class="ui-shu-rules mb-3">
                    <article class="ui-shu-rule">
                        <span class="ui-shu-rule-badge is-anggota">1%</span>
                        <strong>Anggota</strong>
                        <p>1% × pokok pinjaman tanpa jaminan saat lunas.</p>
                    </article>
                    <article class="ui-shu-rule">
                        <span class="ui-shu-rule-badge is-ketua">2%</span>
                        <strong>Ketua Kelompok</strong>
                        <p>2% × pokok pinjaman pribadi tanpa jaminan saat lunas.</p>
                    </article>
                    <article class="ui-shu-rule">
                        <span class="ui-shu-rule-badge is-ref">0,25%</span>
                        <strong>Referensi Ketua</strong>
                        <p>0,25% × pokok anggota yang diajak, hanya jika pembayaran lancar.</p>
                    </article>
                </div>

                <div class="ui-shu-breakdown mb-3">
                    <div class="ui-shu-break-card is-pelunasan">
                        <span>Hak pelunasan</span>
                        <strong><?= formatRupiah($stats['pelunasan_nilai']) ?></strong>
                        <small><?= (int)$stats['pelunasan'] ?>× catatan</small>
                    </div>
                    <div class="ui-shu-break-card is-ref">
                        <span>Hak referensi</span>
                        <strong><?= formatRupiah($stats['referensi_nilai']) ?></strong>
                        <small><?= (int)$stats['referensi'] ?>× catatan</small>
                    </div>
                    <div class="ui-shu-break-card is-paid">
                        <span>Sudah dibayar</span>
                        <strong><?= formatRupiah($stats['dibayar']) ?></strong>
                        <small><?= (int)$stats['dibayar_jumlah'] ?>×</small>
                    </div>
                    <div class="ui-shu-break-card is-pending">
                        <span>Belum dibayar</span>
                        <strong><?= formatRupiah($stats['belum']) ?></strong>
                        <small><?= (int)$stats['belum_jumlah'] ?>×</small>
                    </div>
                </div>

                <div class="card ui-shu-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Daftar hak SHU</span>
                        <span class="ui-dash-sec-note"><?= (int)count($rows) ?> data · filter di toolbar tabel</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="25"
                                   data-dt-order="0,desc"
                                   data-dt-filter="3,5"
                                   data-empty-message="Belum ada hak SHU pinjaman.">
                                <thead>
                                    <tr>
                                        <th>Pinjaman</th>
                                        <th>Penerima</th>
                                        <th>Sumber hak</th>
                                        <th>Jenis</th>
                                        <th>Perhitungan</th>
                                        <th>Status</th>
                                        <th class="text-end">Nominal</th>
                                        <th class="no-print">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $r):
                                    $isRef = ($r['jenis'] ?? '') === 'referensi';
                                    $isPaid = ($r['status_bayar'] ?? '') === 'sudah';
                                    $ts = !empty($r['tanggal_lunas']) ? strtotime((string)$r['tanggal_lunas']) : 0;
                                    $orderTs = $ts ? date('Y-m-d', $ts) : '';
                                    $jenisLabel = $isRef ? 'Referensi' : 'Pelunasan';
                                    $statusLabel = $isPaid ? 'Sudah dibayar' : 'Belum dibayar';
                                    $persenTxt = $fmtPersen($r['persen']);
                                    $detailTxt = $r['kode_pinjaman'] . ' · ' . $jenisLabel . ' · ' . formatRupiah($r['dasar_pinjaman']) . ' × ' . $persenTxt . '%';
                                ?>
                                    <tr class="ui-shu-row <?= $isRef ? 'is-ref' : 'is-pelunasan' ?> <?= $isPaid ? 'is-paid' : 'is-pending' ?>">
                                        <td data-order="<?= layananEscape($orderTs) ?>">
                                            <code class="ui-shu-kode"><?= layananEscape($r['kode_pinjaman']) ?></code>
                                            <small class="ui-shu-meta"><?= !empty($r['tanggal_lunas']) ? layananEscape(formatTanggalUi($r['tanggal_lunas'])) : '—' ?></small>
                                        </td>
                                        <td>
                                            <strong class="ui-shu-name"><?= layananEscape($r['nama_lengkap']) ?></strong>
                                            <small class="ui-shu-meta"><?= layananEscape($r['kode_anggota']) ?></small>
                                        </td>
                                        <td>
                                            <?php if ($isRef): ?>
                                            <span class="ui-shu-sumber">Referensi dari</span>
                                            <small class="ui-shu-meta"><?= layananEscape($r['sumber_nama']) ?></small>
                                            <?php else: ?>
                                            <span class="ui-shu-sumber">Pelunasan pribadi</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-filter="<?= layananEscape($jenisLabel) ?>">
                                            <span class="ui-shu-tipe <?= $isRef ? 'is-ref' : 'is-pelunasan' ?>"><?= layananEscape($jenisLabel) ?></span>
                                            <small class="ui-shu-meta"><?= ($r['kategori_snapshot'] ?? '') === 'ketua_kelompok' ? 'Ketua' : 'Anggota' ?></small>
                                        </td>
                                        <td>
                                            <span class="ui-shu-calc"><?= formatRupiah($r['dasar_pinjaman']) ?> × <?= layananEscape($persenTxt) ?>%</span>
                                            <small class="ui-shu-meta <?= !empty($r['pembayaran_lancar']) ? 'text-success' : 'text-warning' ?>">
                                                <?= !empty($r['pembayaran_lancar']) ? 'Pembayaran lancar' : 'Ada keterlambatan' ?>
                                            </small>
                                        </td>
                                        <td data-filter="<?= layananEscape($statusLabel) ?>">
                                            <span class="ui-shu-status <?= $isPaid ? 'is-paid' : 'is-pending' ?>"><?= layananEscape($statusLabel) ?></span>
                                            <?php if (!empty($r['dibayar_at'])): ?>
                                            <small class="ui-shu-meta"><?= layananEscape(formatTanggalUi($r['dibayar_at'], true)) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-semibold ui-shu-money"><?= formatRupiah($r['jumlah']) ?></td>
                                        <td class="no-print">
                                            <?php if (!$isPaid): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-primary js-shu-bayar"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalBayarShu"
                                                    data-hak-id="<?= (int)$r['id'] ?>"
                                                    data-nama="<?= layananEscape($r['nama_lengkap']) ?>"
                                                    data-jumlah="<?= layananEscape(formatRupiah($r['jumlah'])) ?>"
                                                    data-detail="<?= layananEscape($detailTxt) ?>">
                                                <i class="ti ti-cash me-1" aria-hidden="true"></i>Bayar
                                            </button>
                                            <?php else: ?>
                                            <a class="btn btn-sm btn-outline-primary" href="kwitansi_shu.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">
                                                <i class="ti ti-receipt-2 me-1" aria-hidden="true"></i>Kwitansi
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="ui-shu-totals">
                            <div><span>Total hak</span><strong><?= formatRupiah($stats['total']) ?></strong></div>
                            <div><span>Sudah dibayar</span><strong class="text-success"><?= formatRupiah($stats['dibayar']) ?></strong></div>
                            <div><span>Belum dibayar</span><strong class="text-warning"><?= formatRupiah($stats['belum']) ?></strong></div>
                            <div><span>Referensi</span><strong><?= formatRupiah($stats['referensi_nilai']) ?></strong></div>
                        </div>
                        <div class="ui-shu-legend no-print">
                            <span class="ui-shu-leg is-pelunasan">Pelunasan</span>
                            <span class="ui-shu-leg is-ref">Referensi</span>
                            <span class="ui-shu-leg is-pending">Belum dibayar</span>
                            <span class="ui-shu-leg is-paid">Sudah dibayar</span>
                        </div>
                    </div>
                </div>
            </div>
<?php include __DIR__ . '/layout/shell_end.php'; ?>

<div class="modal fade" id="modalBayarShu" tabindex="-1" aria-labelledby="modalBayarShuLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" enctype="multipart/form-data" class="modal-content ui-shu-pay-modal" id="formBayarShu">
            <?= layananToken() ?>
            <input type="hidden" name="aksi" value="bayar">
            <input type="hidden" name="hak_id" id="shuHakId" value="">
            <div class="modal-header border-0 pb-0">
                <div>
                    <p class="text-muted small mb-1">Pembayaran SHU</p>
                    <h2 class="modal-title h4 mb-0" id="modalBayarShuLabel">Bayar SHU</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="ui-shu-pay-hero">
                    <div class="ui-shu-pay-amount" id="shuJumlah">—</div>
                    <p class="ui-shu-pay-detail mb-0" id="shuDetail">—</p>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="shuMetode">Metode</label>
                    <select name="metode" id="shuMetode" class="form-select" required>
                        <option value="cash">Cash</option>
                        <option value="transfer">Transfer</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="shuReferensi">Referensi / penerima</label>
                    <input name="referensi" id="shuReferensi" class="form-control" minlength="3" maxlength="100" required placeholder="Nama rekening / keterangan">
                </div>
                <div>
                    <label class="form-label" for="shuBukti">Foto bukti pembayaran</label>
                    <input name="bukti" id="shuBukti" type="file" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                    <div class="form-text">JPG, PNG, atau WEBP · maks. 5 MB</div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button class="btn btn-primary" type="submit">
                    <i class="ti ti-check me-1" aria-hidden="true"></i>Catat pembayaran
                </button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('modalBayarShu');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (ev) {
        var btn = ev.relatedTarget;
        if (!btn || !btn.classList.contains('js-shu-bayar')) return;
        document.getElementById('shuHakId').value = btn.getAttribute('data-hak-id') || '';
        document.getElementById('modalBayarShuLabel').textContent = 'Bayar SHU — ' + (btn.getAttribute('data-nama') || '');
        document.getElementById('shuJumlah').textContent = btn.getAttribute('data-jumlah') || '—';
        document.getElementById('shuDetail').textContent = btn.getAttribute('data-detail') || '—';
        document.getElementById('shuMetode').value = 'cash';
        document.getElementById('shuReferensi').value = '';
        document.getElementById('shuBukti').value = '';
    });
    modal.addEventListener('hidden.bs.modal', function () {
        var form = document.getElementById('formBayarShu');
        if (form) form.reset();
        document.getElementById('shuHakId').value = '';
    });
})();
</script>
</body>
</html>

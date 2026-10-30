<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isAdmin() || !can('koreksi.mutate')) {
    http_response_code(403);
    exit('Akses ditolak. Koreksi khusus Admin.');
}

schemaPastikanLogKoreksi($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['koreksi'])) {
    require_can('koreksi.mutate');
    $transaksi_id = $_POST['transaksi_id'];
    $jumlah_baru = $_POST['jumlah_baru'];
    $keterangan_koreksi = $_POST['keterangan_koreksi'];

    try {
        layananValidasiToken();
        if (!is_scalar($jumlah_baru) || !preg_match('/^\d+(?:\.\d{1,2})?$/D', (string)$jumlah_baru) || !is_finite((float)$jumlah_baru) || (float)$jumlah_baru < 0 || (float)$jumlah_baru > 999999999999) {
            throw new Exception('Nominal koreksi tidak valid.');
        }
        $db->beginTransaction();

        $query_transaksi = "SELECT * FROM transaksi WHERE id = :id FOR UPDATE";
        $stmt_transaksi = $db->prepare($query_transaksi);
        $stmt_transaksi->bindParam(':id', $transaksi_id);
        $stmt_transaksi->execute();
        $transaksi = $stmt_transaksi->fetch(PDO::FETCH_ASSOC);

        if (!$transaksi) {
            throw new Exception("Transaksi tidak ditemukan");
        }

        $journal = $db->prepare('SELECT kode_jenis FROM jenis_simpanan WHERE id=?');
        $journal->execute([$transaksi['jenis_simpanan_id']]);
        if ($journal->fetchColumn() === 'SHU') {
            throw new Exception('Jurnal SHU terkunci. Gunakan Koreksi pada riwayat pembayaran SHU agar hak anggota dan jurnal pembalik tetap sesuai.');
        }
        $selisih = $jumlah_baru - $transaksi['jumlah'];

        $query_update = "UPDATE transaksi SET jumlah = :jumlah, saldo_sesudah = saldo_sesudah + :selisih,
                        keterangan = CONCAT(keterangan, ' | Koreksi: ', :keterangan_koreksi)
                        WHERE id = :id";
        $stmt_update = $db->prepare($query_update);
        $stmt_update->bindParam(':jumlah', $jumlah_baru);
        $stmt_update->bindParam(':selisih', $selisih);
        $stmt_update->bindParam(':keterangan_koreksi', $keterangan_koreksi);
        $stmt_update->bindParam(':id', $transaksi_id);
        $stmt_update->execute();

        $query_simpanan = "UPDATE simpanan SET saldo = saldo + :selisih, updated_at = NOW()
                          WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_simpanan_id";
        $stmt_simpanan = $db->prepare($query_simpanan);
        $stmt_simpanan->bindParam(':selisih', $selisih);
        $stmt_simpanan->bindParam(':anggota_id', $transaksi['anggota_id']);
        $stmt_simpanan->bindParam(':jenis_simpanan_id', $transaksi['jenis_simpanan_id']);
        $stmt_simpanan->execute();

        // PDO native prepares tidak mengizinkan nama parameter yang sama dua kali.
        $query_kas = "UPDATE kas SET jumlah = jumlah + :selisih_jumlah, saldo_sesudah = saldo_sesudah + :selisih_saldo
                     WHERE transaksi_id = :transaksi_id";
        $stmt_kas = $db->prepare($query_kas);
        $stmt_kas->bindValue(':selisih_jumlah', $selisih);
        $stmt_kas->bindValue(':selisih_saldo', $selisih);
        $stmt_kas->bindValue(':transaksi_id', $transaksi_id);
        $stmt_kas->execute();

        $query_log = "INSERT INTO log_koreksi (transaksi_id, jumlah_lama, jumlah_baru, selisih,
                     keterangan, user_id)
                     VALUES (:transaksi_id, :jumlah_lama, :jumlah_baru, :selisih,
                     :keterangan, :user_id)";
        $stmt_log = $db->prepare($query_log);
        $stmt_log->bindParam(':transaksi_id', $transaksi_id);
        $stmt_log->bindParam(':jumlah_lama', $transaksi['jumlah']);
        $stmt_log->bindParam(':jumlah_baru', $jumlah_baru);
        $stmt_log->bindParam(':selisih', $selisih);
        $stmt_log->bindParam(':keterangan', $keterangan_koreksi);
        $stmt_log->bindParam(':user_id', $_SESSION['user_id']);
        $stmt_log->execute();

        $db->commit();
        $_SESSION['success'] = "Koreksi transaksi berhasil dilakukan";
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "Gagal melakukan koreksi: " . $e->getMessage();
    }
    header('Location: koreksi.php');
    exit;
}

$transaksi_list = $db->query(
    "SELECT t.*, a.nama_lengkap, a.kode_anggota, j.nama_jenis, u.nama_lengkap AS teller
     FROM transaksi t
     JOIN anggota a ON t.anggota_id = a.id
     JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
     JOIN users u ON t.user_id = u.id
     WHERE j.kode_jenis <> 'SHU'
     ORDER BY t.tanggal_transaksi DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$log_list = $db->query(
    "SELECT l.*, t.kode_transaksi, a.nama_lengkap, u.nama_lengkap AS petugas
     FROM log_koreksi l
     JOIN transaksi t ON t.id = l.transaksi_id
     JOIN anggota a ON a.id = t.anggota_id
     JOIN users u ON u.id = l.user_id
     ORDER BY l.created_at DESC
     LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

$totalTx = count($transaksi_list);
$setoran = 0;
$penarikan = 0;
$totalNominal = 0.0;
foreach ($transaksi_list as $t) {
    $totalNominal += (float)$t['jumlah'];
    if (($t['jenis_transaksi'] ?? '') === 'setoran') {
        $setoran++;
    } else {
        $penarikan++;
    }
}
$totalLog = count($log_list);
$selisihLog = 0.0;
foreach ($log_list as $l) {
    $selisihLog += abs((float)$l['selisih']);
}

$page_title = 'Koreksi Transaksi';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Koreksi Transaksi - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-koreksi" aria-hidden="true"><i class="ti ti-adjustments"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Transaksi</p>
                        <h1 class="ui-dash-title">Koreksi Transaksi</h1>
                        <p class="ui-dash-meta mb-0">Perbaiki nominal transaksi — saldo simpanan &amp; kas ikut disesuaikan</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="setoran.php" class="btn ui-tx-btn-koreksi-ghost">
                            <i class="ti ti-circle-plus me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Setoran</span>
                        </a>
                        <a href="penarikan.php" class="btn ui-tx-btn-koreksi-ghost">
                            <i class="ti ti-circle-minus me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Penarikan</span>
                        </a>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="alert alert-warning ui-kr-info" role="status" data-ui-toast-skip>
                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                    Koreksi mempengaruhi saldo simpanan dan kas. Jurnal SHU tidak bisa dikoreksi di sini.
                </div>

                <div class="ui-kr-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Transaksi', 'value' => $totalTx, 'tone' => 'primary', 'icon' => 'ti ti-receipt', 'hint' => 'Non-SHU'],
                    ['label' => 'Setoran', 'value' => $setoran, 'tone' => 'success', 'icon' => 'ti ti-arrow-down-left', 'hint' => 'Masuk kas'],
                    ['label' => 'Penarikan', 'value' => $penarikan, 'tone' => 'warning', 'icon' => 'ti ti-arrow-up-right', 'hint' => 'Keluar kas'],
                    ['label' => 'Riwayat koreksi', 'value' => $totalLog, 'tone' => 'info', 'icon' => 'ti ti-history', 'hint' => $totalLog ? formatRupiah($selisihLog) . ' total |selisih|' : 'Belum ada'],
                ]); ?>
                </div>

                <div class="card ui-kr-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Daftar transaksi</span>
                        <span class="ui-dash-sec-note"><?php echo (int)$totalTx; ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="1,desc"
                                   data-dt-filter="3,4"
                                   data-empty-message="Belum ada transaksi yang dapat dikoreksi.">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Tanggal</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th>Tipe</th>
                                        <th class="text-end">Jumlah</th>
                                        <th>Keterangan</th>
                                        <th class="text-end no-sort">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($transaksi_list as $transaksi):
                                    $ts = strtotime((string)$transaksi['tanggal_transaksi']);
                                    $orderTs = $ts ? date('Y-m-d H:i:s', $ts) : '';
                                    $isSetoran = ($transaksi['jenis_transaksi'] ?? '') === 'setoran';
                                ?>
                                    <tr>
                                        <td><code class="ui-kr-kode"><?php echo htmlspecialchars($transaksi['kode_transaksi']); ?></code></td>
                                        <td data-order="<?php echo htmlspecialchars($orderTs); ?>">
                                            <?php echo htmlspecialchars(formatTanggalUi($transaksi['tanggal_transaksi'], true)); ?>
                                        </td>
                                        <td>
                                            <strong class="ui-kr-member"><?php echo htmlspecialchars($transaksi['nama_lengkap']); ?></strong>
                                            <small class="ui-kr-kode-anggota"><?php echo htmlspecialchars($transaksi['kode_anggota'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($transaksi['nama_jenis']); ?></td>
                                        <td data-filter="<?php echo htmlspecialchars(ucfirst((string)$transaksi['jenis_transaksi'])); ?>">
                                            <span class="badge <?php echo $isSetoran ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                                <?php echo htmlspecialchars(ucfirst((string)$transaksi['jenis_transaksi'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-end fw-semibold ui-kr-money"><?php echo formatRupiah($transaksi['jumlah']); ?></td>
                                        <td>
                                            <span class="ui-kr-ket"><?php echo ui_cell_ellipsis($transaksi['keterangan'], 48); ?></span>
                                            <small class="ui-kr-teller">Oleh: <?php echo htmlspecialchars($transaksi['teller']); ?></small>
                                        </td>
                                        <td class="table-actions text-end">
                                            <?php echo ui_action_menu([
                                                [
                                                    'label' => 'Koreksi',
                                                    'icon' => 'ti ti-edit',
                                                    'class' => 'text-warning',
                                                    'attrs' => [
                                                        'data-bs-toggle' => 'modal',
                                                        'data-bs-target' => '#koreksiModal',
                                                        'data-id' => (int)$transaksi['id'],
                                                        'data-kode' => $transaksi['kode_transaksi'],
                                                        'data-jumlah' => (string)$transaksi['jumlah'],
                                                        'data-jenis' => $transaksi['jenis_transaksi'],
                                                        'data-jenis-nama' => $transaksi['nama_jenis'],
                                                        'data-anggota' => $transaksi['nama_lengkap'],
                                                        'data-kode-anggota' => $transaksi['kode_anggota'] ?? '',
                                                        'data-teller' => $transaksi['teller'],
                                                    ],
                                                ],
                                            ]); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card mt-3 ui-kr-log">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-history" aria-hidden="true"></i>Riwayat koreksi</span>
                        <span class="ui-dash-sec-note"><?php echo (int)$totalLog; ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="0,desc"
                                   data-empty-message="Belum ada koreksi tercatat.">
                                <thead>
                                    <tr>
                                        <th>Waktu</th>
                                        <th>Kode</th>
                                        <th>Anggota</th>
                                        <th class="text-end">Lama</th>
                                        <th class="text-end">Baru</th>
                                        <th class="text-end">Selisih</th>
                                        <th>Petugas</th>
                                        <th>Alasan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($log_list as $log):
                                    $ts = strtotime((string)$log['created_at']);
                                    $orderTs = $ts ? date('Y-m-d H:i:s', $ts) : '';
                                    $selisih = (float)$log['selisih'];
                                ?>
                                    <tr>
                                        <td data-order="<?php echo htmlspecialchars($orderTs); ?>">
                                            <?php echo htmlspecialchars(formatTanggalUi($log['created_at'], true)); ?>
                                        </td>
                                        <td><code class="ui-kr-kode"><?php echo htmlspecialchars($log['kode_transaksi']); ?></code></td>
                                        <td><?php echo htmlspecialchars($log['nama_lengkap']); ?></td>
                                        <td class="text-end ui-kr-money"><?php echo formatRupiah($log['jumlah_lama']); ?></td>
                                        <td class="text-end ui-kr-money"><?php echo formatRupiah($log['jumlah_baru']); ?></td>
                                        <td class="text-end fw-semibold <?php echo $selisih > 0 ? 'text-success' : ($selisih < 0 ? 'text-danger' : ''); ?>">
                                            <?php echo ($selisih > 0 ? '+' : ($selisih < 0 ? '− ' : '')) . formatRupiah(abs($selisih)); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['petugas']); ?></td>
                                        <td class="text-muted"><?php echo trim((string)($log['keterangan'] ?? '')) !== '' ? ui_cell_ellipsis((string)$log['keterangan'], 40) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>

    <div class="modal fade ui-kr-modal" id="koreksiModal" tabindex="-1" aria-labelledby="koreksiModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" id="formKoreksi" novalidate>
                    <?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="koreksiModalLabel">Koreksi transaksi</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="transaksi_id" id="koreksi_id">
                        <input type="hidden" id="koreksi_jumlah_lama_raw" value="0">

                        <div class="ui-kr-form">
                            <div class="ui-kr-field">
                                <label class="form-label" for="koreksi_kode">Kode transaksi</label>
                                <input type="text" id="koreksi_kode" class="form-control" readonly>
                            </div>
                            <div class="ui-kr-field-grid">
                                <div class="ui-kr-field">
                                    <label class="form-label" for="koreksi_anggota">Anggota</label>
                                    <input type="text" id="koreksi_anggota" class="form-control" readonly>
                                </div>
                                <div class="ui-kr-field">
                                    <label class="form-label" for="koreksi_jenis">Tipe</label>
                                    <input type="text" id="koreksi_jenis" class="form-control" readonly>
                                </div>
                            </div>
                            <div class="ui-kr-field">
                                <label class="form-label" for="koreksi_jenis_nama">Jenis simpanan</label>
                                <input type="text" id="koreksi_jenis_nama" class="form-control" readonly>
                            </div>

                            <div class="ui-kr-compare">
                                <div class="ui-kr-compare-cell">
                                    <span>Jumlah lama</span>
                                    <strong id="koreksi_jumlah_lama">Rp 0</strong>
                                </div>
                                <div class="ui-kr-compare-arrow" aria-hidden="true"><i class="ti ti-arrow-right"></i></div>
                                <div class="ui-kr-field ui-kr-compare-input">
                                    <label class="form-label" for="koreksi_jumlah_baru">Jumlah baru *</label>
                                    <input type="number" name="jumlah_baru" class="form-control form-control-lg"
                                           required step="1000" min="0" id="koreksi_jumlah_baru">
                                </div>
                            </div>

                            <div class="ui-kr-selisih" id="koreksi_selisih_box">
                                <span>Selisih</span>
                                <strong id="koreksi_selisih">Rp 0</strong>
                            </div>

                            <div class="ui-kr-field">
                                <label class="form-label" for="koreksi_ket">Keterangan koreksi *</label>
                                <textarea name="keterangan_koreksi" id="koreksi_ket" class="form-control" rows="3" required
                                          minlength="5" maxlength="2000"
                                          placeholder="Alasan koreksi (minimal 5 karakter)"></textarea>
                            </div>

                            <div class="alert alert-info mb-0 ui-kr-modal-hint" role="status" data-ui-toast-skip>
                                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                                Saldo simpanan dan kas akan disesuaikan otomatis sebesar selisih.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="koreksi" class="btn btn-warning" value="1"
                                onclick="return confirm('Proses koreksi transaksi ini? Saldo &amp; kas akan berubah.')">
                            <i class="ti ti-check me-1" aria-hidden="true"></i>Proses koreksi
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var modal = document.getElementById('koreksiModal');
        if (modal && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
        if (!modal) return;

        function rp(n) {
            n = Math.round(Number(n) || 0);
            var neg = n < 0;
            n = Math.abs(n);
            return (neg ? '− ' : '') + 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function updateSelisih() {
            var lama = parseFloat(document.getElementById('koreksi_jumlah_lama_raw').value) || 0;
            var baru = parseFloat(document.getElementById('koreksi_jumlah_baru').value) || 0;
            var selisih = baru - lama;
            var el = document.getElementById('koreksi_selisih');
            var box = document.getElementById('koreksi_selisih_box');
            el.textContent = (selisih > 0 ? '+ ' : '') + rp(selisih);
            box.classList.remove('is-up', 'is-down', 'is-zero');
            if (selisih > 0) box.classList.add('is-up');
            else if (selisih < 0) box.classList.add('is-down');
            else box.classList.add('is-zero');
        }

        modal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;
            document.getElementById('koreksi_id').value = button.getAttribute('data-id') || '';
            document.getElementById('koreksi_kode').value = button.getAttribute('data-kode') || '';
            var anggota = button.getAttribute('data-anggota') || '';
            var kodeAnggota = button.getAttribute('data-kode-anggota') || '';
            document.getElementById('koreksi_anggota').value = kodeAnggota ? (kodeAnggota + ' — ' + anggota) : anggota;
            document.getElementById('koreksi_jenis').value = button.getAttribute('data-jenis') || '';
            document.getElementById('koreksi_jenis_nama').value = button.getAttribute('data-jenis-nama') || '';
            var jumlahLama = parseFloat(button.getAttribute('data-jumlah')) || 0;
            document.getElementById('koreksi_jumlah_lama_raw').value = String(jumlahLama);
            document.getElementById('koreksi_jumlah_lama').textContent = rp(jumlahLama);
            document.getElementById('koreksi_jumlah_baru').value = jumlahLama;
            var ket = document.getElementById('koreksi_ket');
            if (ket) ket.value = '';
            updateSelisih();
        });

        document.getElementById('koreksi_jumlah_baru').addEventListener('input', updateSelisih);
    })();
    </script>
</body>
</html>

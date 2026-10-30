<?php
/**
 * Single implementation — Pembelian Waserda (Admin & Teller).
 * Pola UI mengikuti setoran/penarikan; multi-item di modal.
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

schemaPastikanWaserda($db);

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_waserda'])) {
    $anggota_id = $_POST['anggota_id'] ?? '';
    $metode_bayar = $_POST['metode_bayar'] ?? '';
    $items = [];
    $total_bayar = 0;
    
    $nama_barang = $_POST['nama_barang'] ?? [];
    $harga = $_POST['harga'] ?? [];
    $jumlah = $_POST['jumlah'] ?? [];
    
    if (!is_array($nama_barang)) {
        $nama_barang = [];
    }
    if (!is_array($harga)) {
        $harga = [];
    }
    if (!is_array($jumlah)) {
        $jumlah = [];
    }

    $n = max(count($nama_barang), count($harga), count($jumlah));
    for ($i = 0; $i < $n; $i++) {
        $nama = trim((string)($nama_barang[$i] ?? ''));
        $hargaRaw = preg_replace('/[^\d]/', '', (string)($harga[$i] ?? ''));
        $jumlahRaw = str_replace(',', '.', preg_replace('/[^\d.,]/', '', (string)($jumlah[$i] ?? '')));
        $hargaVal = $hargaRaw !== '' ? (float)$hargaRaw : 0;
        $jumlahVal = $jumlahRaw !== '' ? (float)$jumlahRaw : 0;
        if ($nama === '' || $hargaVal <= 0 || $jumlahVal <= 0) {
            continue;
        }
        if ($hargaVal > 999999999999 || $jumlahVal > 999999) {
            $_SESSION['error'] = 'Nominal atau jumlah barang tidak valid.';
            header('Location: pembelian.php');
            exit();
        }
        $subtotal = $hargaVal * $jumlahVal;
            $items[] = [
            'nama' => $nama,
            'harga' => $hargaVal,
            'jumlah' => $jumlahVal,
            'subtotal' => $subtotal,
            ];
            $total_bayar += $subtotal;
        }

    if (empty($anggota_id) || !in_array($metode_bayar, ['tunai', 'transfer'], true)) {
        $_SESSION['error'] = 'Data tidak lengkap. Pastikan anggota dan metode bayar diisi.';
        header('Location: pembelian.php');
        exit();
    }
    if (empty($items)) {
        $_SESSION['error'] = 'Minimal satu barang harus diisi.';
        header('Location: pembelian.php');
        exit();
    }
    
    $kode_transaksi = 'WSD-' . date('ymd') . '-' . str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    try {
        $db->beginTransaction();
        $db->query('SELECT id FROM koperasi WHERE id=1 FOR UPDATE')->fetchColumn();
        $lockMember = $db->prepare("SELECT id FROM anggota WHERE id=? AND status='aktif' FOR UPDATE");
        $lockMember->execute([$anggota_id]);
        if (!$lockMember->fetchColumn()) {
            throw new Exception('Anggota tidak aktif atau tidak ditemukan.');
        }

        $items_json = json_encode($items, JSON_UNESCAPED_UNICODE);
        $query = "INSERT INTO waserda_transaksi (kode_transaksi, anggota_id, items, total_bayar, metode_bayar, user_id) 
                  VALUES (:kode_transaksi, :anggota_id, :items, :total_bayar, :metode_bayar, :user_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':kode_transaksi', $kode_transaksi);
        $stmt->bindParam(':anggota_id', $anggota_id);
        $stmt->bindParam(':items', $items_json);
        $stmt->bindParam(':total_bayar', $total_bayar);
        $stmt->bindParam(':metode_bayar', $metode_bayar);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        if (!$stmt->execute()) {
            throw new Exception('Gagal menyimpan transaksi waserda.');
        }
        
        $last_id = $db->lastInsertId();
        
        $query_saldo = "SELECT COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END), 0) as saldo FROM kas";
        $stmt_saldo = $db->prepare($query_saldo);
        $stmt_saldo->execute();
        $saldo_sebelum = (float)$stmt_saldo->fetch(PDO::FETCH_ASSOC)['saldo'];
        $saldo_sesudah = $saldo_sebelum + $total_bayar;
        
        $keterangan = 'Pembelian Waserda - ' . $kode_transaksi . ' (' . count($items) . ' item)';
        $query_kas = "INSERT INTO kas (transaksi_id, jenis, jumlah, saldo_sebelum, saldo_sesudah, keterangan) 
                     VALUES (:transaksi_id, 'masuk', :jumlah, :saldo_sebelum, :saldo_sesudah, :keterangan)";
        $stmt_kas = $db->prepare($query_kas);
        $stmt_kas->bindParam(':transaksi_id', $last_id);
        $stmt_kas->bindParam(':jumlah', $total_bayar);
        $stmt_kas->bindParam(':saldo_sebelum', $saldo_sebelum);
        $stmt_kas->bindParam(':saldo_sesudah', $saldo_sesudah);
        $stmt_kas->bindParam(':keterangan', $keterangan);
        if (!$stmt_kas->execute()) {
            throw new Exception('Gagal mengupdate kas.');
        }
        
        $db->commit();
        $_SESSION['success'] = 'Pembelian waserda berhasil.';
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = 'Gagal memproses transaksi: ' . $e->getMessage();
    }
    
    header('Location: pembelian.php');
    exit();
}

// ============================================
// HANDLE DELETE TRANSAKSI (admin only)
// ============================================
if (isset($_GET['delete'])) {
    if (!isAdmin()) {
        $_SESSION['error'] = 'Anda tidak memiliki izin untuk menghapus transaksi.';
        header('Location: pembelian.php');
        exit();
    }

    $id = (int)$_GET['delete'];
    try {
        $db->beginTransaction();
        $query_select = 'SELECT * FROM waserda_transaksi WHERE id = :id';
        $stmt_select = $db->prepare($query_select);
        $stmt_select->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_select->execute();
        $transaksi = $stmt_select->fetch(PDO::FETCH_ASSOC);
        if (!$transaksi) {
            throw new Exception('Transaksi tidak ditemukan');
        }
        
        $query_kas = "DELETE FROM kas WHERE transaksi_id = :id AND keterangan LIKE '%Waserda%'";
        $stmt_kas = $db->prepare($query_kas);
        $stmt_kas->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_kas->execute();
        
        $query_delete = 'DELETE FROM waserda_transaksi WHERE id = :id';
        $stmt_delete = $db->prepare($query_delete);
        $stmt_delete->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_delete->execute();
        
        $db->commit();
        $_SESSION['success'] = 'Transaksi waserda dihapus.';
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = 'Gagal menghapus transaksi: ' . $e->getMessage();
    }
    
    header('Location: pembelian.php');
    exit();
}

$query_anggota = "SELECT * FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

$riwayat = ui_riwayat_waserda($db, ['client' => true]);
$totalStmt = $db->query('SELECT COALESCE(SUM(total_bayar),0) FROM waserda_transaksi');
$total_waserda = (float)$totalStmt->fetchColumn();
$count_waserda = (int)($riwayat['pager']['total'] ?? 0);
$avg_waserda = $count_waserda > 0 ? ($total_waserda / $count_waserda) : 0;
$prefillAnggotaId = isset($_GET['anggota_id']) && is_numeric($_GET['anggota_id']) ? (int)$_GET['anggota_id'] : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waserda - <?php echo APP_NAME; ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-waserda" aria-hidden="true"><i class="ti ti-building-store"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Transaksi</p>
                        <h1 class="ui-dash-title">Pembelian Waserda</h1>
                        <p class="ui-dash-meta mb-0">Catat belanja anggota · tercatat sebagai pemasukan kas</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <button type="button" class="btn ui-dash-btn-add ui-tx-btn-waserda" data-bs-toggle="modal" data-bs-target="#modalWaserda">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Pembelian baru</span><span class="d-sm-none">Baru</span>
                        </button>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-stat-grid" data-count="3">
                    <div class="stat-card tone-success">
                        <div class="card-body d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <p class="mb-0 text-muted small text-uppercase">Total pemasukan</p>
                                <h4 class="mb-0"><?= formatRupiah($total_waserda) ?></h4>
                                <small class="text-muted">Semua transaksi waserda</small>
                            </div>
                            <span class="stat-icon"><i class="ti ti-cash"></i></span>
                        </div>
                    </div>
                    <div class="stat-card tone-primary">
                        <div class="card-body d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <p class="mb-0 text-muted small text-uppercase">Jumlah transaksi</p>
                                <h4 class="mb-0"><?= (int)$count_waserda ?></h4>
                                <small class="text-muted">Riwayat tersimpan</small>
                            </div>
                            <span class="stat-icon"><i class="ti ti-receipt"></i></span>
                        </div>
                    </div>
                    <div class="stat-card tone-info">
                        <div class="card-body d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <p class="mb-0 text-muted small text-uppercase">Rata-rata</p>
                                <h4 class="mb-0"><?= formatRupiah($avg_waserda) ?></h4>
                                <small class="text-muted">Per transaksi</small>
                            </div>
                            <span class="stat-icon"><i class="ti ti-chart-bar"></i></span>
                        </div>
                    </div>
                </div>

                <?php
                $riwayatTitle = 'Riwayat Pembelian Waserda';
                $riwayatCanDelete = isAdmin();
                include __DIR__ . '/ui/partial_riwayat_waserda.php';
                ?>
            </div>
            <?php include __DIR__ . '/layout/shell_end.php'; ?>

    <div class="modal fade ui-tx-modal ui-tx-modal-waserda" id="modalWaserda" tabindex="-1" aria-labelledby="modalWaserdaLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalWaserdaLabel">Pembelian baru</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                <form method="POST" id="formWaserda" novalidate>
                    <?php echo layananToken(); ?>
                    <div class="modal-body">
                        <div class="ui-ws-form">
                            <div class="ui-ws-meta">
                                <div class="ui-ws-field">
                                    <label class="form-label" for="anggotaSelect">Anggota *</label>
                                    <select name="anggota_id" class="form-select" required id="anggotaSelect" data-prefill="<?php echo (int)$prefillAnggotaId; ?>">
                                        <option value="">-- Pilih anggota --</option>
                                        <?php foreach ($anggota as $a): ?>
                                        <option value="<?php echo (int)$a['id']; ?>">
                                            <?php echo htmlspecialchars($a['kode_anggota'] . ' - ' . $a['nama_lengkap']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ui-ws-field">
                                    <span class="form-label d-block">Metode bayar *</span>
                                    <div class="ui-ws-metode" role="group" aria-label="Metode bayar">
                                        <input type="radio" class="btn-check" name="metode_bayar" id="metodeTunai" value="tunai" checked>
                                        <label class="btn ui-ws-metode-btn" for="metodeTunai"><i class="ti ti-cash me-1" aria-hidden="true"></i>Tunai</label>
                                        <input type="radio" class="btn-check" name="metode_bayar" id="metodeTransfer" value="transfer">
                                        <label class="btn ui-ws-metode-btn" for="metodeTransfer"><i class="ti ti-building-bank me-1" aria-hidden="true"></i>Transfer</label>
                                    </div>
                                </div>
                            </div>

                            <div class="ui-ws-panel">
                                <div class="ui-ws-panel-head">
                                    <div>
                                        <div class="ui-ws-panel-title">Daftar barang</div>
                                        <div class="ui-ws-panel-note" id="itemCountLabel">1 barang</div>
                                    </div>
                                </div>
                                <div class="ui-ws-cols" aria-hidden="true">
                                    <span>Nama barang</span>
                                    <span>Harga</span>
                                    <span>Qty</span>
                                    <span>Subtotal</span>
                                    <span></span>
                                </div>
                                <div id="items-container" class="ui-ws-items"></div>
                                <button type="button" class="ui-ws-add" id="btnAddItem">
                                    <i class="ti ti-plus" aria-hidden="true"></i>
                                    <span>Tambah barang</span>
                                </button>
                            </div>

                            <div class="ui-tx-total-box" aria-live="polite">
                                <div>
                                    <span class="ui-tx-total-label d-block">Total bayar</span>
                                    <span class="ui-ws-total-hint">Tercatat sebagai pemasukan kas</span>
                                </div>
                                <strong class="ui-tx-total-value" id="totalBayar">Rp 0</strong>
                                    </div>
                                </div>
                            </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="proses_waserda" value="1" class="btn btn-success" id="btnAjukanWaserda">
                            <i class="ti ti-circle-check me-1" aria-hidden="true"></i>Lanjut konfirmasi
                                </button>
                            </div>
                        </form>
            </div>
                    </div>
                </div>

    <div class="modal fade" id="modalKonfirmasiWaserda" tabindex="-1" aria-labelledby="modalKonfirmasiWaserdaLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalKonfirmasiWaserdaLabel">Konfirmasi pembelian</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <dl class="ui-tx-confirm-dl">
                        <div><dt>Anggota</dt><dd id="cfmAnggota">—</dd></div>
                        <div><dt>Metode</dt><dd id="cfmMetode">—</dd></div>
                        <div><dt>Item</dt><dd id="cfmItems">—</dd></div>
                        <div><dt>Total</dt><dd id="cfmTotal" class="text-success">—</dd></div>
                    </dl>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Periksa lagi</button>
                    <button type="button" class="btn btn-success" id="btnKonfirmasiWaserda">
                        <i class="ti ti-check me-1" aria-hidden="true"></i>Ya, proses
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade ui-tx-modal" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="detailModalLabel">Detail transaksi</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                            </div>
                            <div class="modal-body">
                    <p class="fw-semibold mb-3" id="modalKodeTransaksi"></p>
                    <div class="table-responsive ui-table-scroll">
                        <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                    <th>Barang</th>
                                                <th class="text-end">Harga</th>
                                    <th class="text-center">Qty</th>
                                                <th class="text-end">Subtotal</th>
                                            </tr>
                                        </thead>
                            <tbody id="modalItems"></tbody>
                            <tfoot id="modalTotal"></tfoot>
                                    </table>
                                </div>
                            </div>
                            <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isAdmin()): ?>
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                    <h2 class="modal-title h5" id="deleteConfirmModalLabel">Hapus transaksi</h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                            </div>
                            <div class="modal-body">
                    <p class="mb-1">Hapus transaksi waserda:</p>
                    <p class="fw-bold mb-2" id="deleteKodeTransaksi">—</p>
                    <p class="small text-danger mb-0"><i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>Data kas terkait ikut terhapus. Tidak dapat dibatalkan.</p>
                            </div>
                            <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <a href="#" id="deleteConfirmButton" class="btn btn-danger"><i class="ti ti-trash me-1" aria-hidden="true"></i>Hapus</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

    <script>
    (function () {
        const form = document.getElementById('formWaserda');
        const anggotaSelect = document.getElementById('anggotaSelect');
        const itemsContainer = document.getElementById('items-container');
        const totalEl = document.getElementById('totalBayar');
        const itemCountLabel = document.getElementById('itemCountLabel');
        let itemSeq = 0;
        let allowSubmit = false;

        function formatRp(n) {
            n = Math.max(0, Math.floor(Number(n) || 0));
            return 'Rp ' + n.toLocaleString('id-ID');
        }
        function parseRp(str) {
            return parseInt(String(str || '').replace(/\D/g, ''), 10) || 0;
        }
        function selectedAnggotaLabel() {
            const o = anggotaSelect.options[anggotaSelect.selectedIndex];
            return o && o.value ? o.textContent.trim() : '—';
        }
        function selectedMetode() {
            const el = form.querySelector('input[name="metode_bayar"]:checked');
            return el ? el.value : 'tunai';
        }
        function selectedMetodeLabel() {
            return selectedMetode() === 'transfer' ? 'Transfer' : 'Tunai';
        }
        function itemTemplate(id) {
            return (
                '<div class="ui-ws-item" data-item-id="' + id + '">' +
                '<div class="ui-ws-item-nama">' +
                '<label class="ui-ws-mobile-label">Nama barang</label>' +
                '<input type="text" name="nama_barang[]" class="form-control form-control-sm item-nama" placeholder="Contoh: Beras 5kg" required>' +
                '</div>' +
                '<div class="ui-ws-item-harga">' +
                '<label class="ui-ws-mobile-label">Harga</label>' +
                '<div class="input-group input-group-sm">' +
                '<span class="input-group-text">Rp</span>' +
                '<input type="text" class="form-control item-harga-display" inputmode="numeric" placeholder="0" required>' +
                '</div>' +
                '<input type="hidden" name="harga[]" class="item-harga" value="">' +
                '</div>' +
                '<div class="ui-ws-item-qty">' +
                '<label class="ui-ws-mobile-label">Qty</label>' +
                '<input type="number" name="jumlah[]" class="form-control form-control-sm item-qty" step="0.01" min="0.01" value="1" required>' +
                '</div>' +
                '<div class="ui-ws-item-sub">' +
                '<label class="ui-ws-mobile-label">Subtotal</label>' +
                '<div class="ui-ws-subtotal item-subtotal">Rp 0</div>' +
                '</div>' +
                '<div class="ui-ws-item-act">' +
                '<button type="button" class="btn btn-sm btn-ghost-danger ui-ws-remove" aria-label="Hapus barang" hidden>' +
                '<i class="ti ti-trash" aria-hidden="true"></i></button>' +
                '</div>' +
                '</div>'
            );
        }
        function calcRow(row) {
            const harga = parseRp(row.querySelector('.item-harga').value);
            const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
            const sub = Math.floor(harga * qty);
            row.querySelector('.item-subtotal').textContent = formatRp(sub);
            return sub;
        }
        function updateTotal() {
            let total = 0;
            const rows = itemsContainer.querySelectorAll('.ui-ws-item');
            rows.forEach(function (row) { total += calcRow(row); });
            totalEl.textContent = formatRp(total);
            if (itemCountLabel) {
                itemCountLabel.textContent = rows.length + ' barang';
            }
            return total;
        }
        function syncRemoveButtons() {
            const rows = itemsContainer.querySelectorAll('.ui-ws-item');
            rows.forEach(function (row) {
                const btn = row.querySelector('.ui-ws-remove');
                if (btn) btn.hidden = rows.length <= 1;
            });
        }
        function addItem(focus) {
            itemSeq += 1;
            const wrap = document.createElement('div');
            wrap.innerHTML = itemTemplate(itemSeq);
            const row = wrap.firstElementChild;
            itemsContainer.appendChild(row);
            syncRemoveButtons();
            updateTotal();
            if (focus) {
                const nama = row.querySelector('.item-nama');
                if (nama) nama.focus();
            }
            return row;
        }
        function collectItems() {
            const list = [];
            itemsContainer.querySelectorAll('.ui-ws-item').forEach(function (row) {
                const nama = (row.querySelector('.item-nama').value || '').trim();
                const harga = parseRp(row.querySelector('.item-harga').value);
                const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
                if (!nama || harga <= 0 || qty <= 0) return;
                list.push({ nama: nama, harga: harga, qty: qty, subtotal: Math.floor(harga * qty) });
            });
            return list;
        }
        function validateForm() {
            if (!anggotaSelect.value) { window.uiToast && uiToast('warning', 'Pilih anggota terlebih dahulu'); return false; }
            if (!selectedMetode()) { window.uiToast && uiToast('warning', 'Pilih metode bayar'); return false; }
            const items = collectItems();
            if (!items.length) { window.uiToast && uiToast('warning', 'Minimal satu barang harus diisi'); return false; }
            return true;
        }
        function openConfirm() {
            if (!validateForm()) return;
            const items = collectItems();
            const total = items.reduce(function (s, it) { return s + it.subtotal; }, 0);
            document.getElementById('cfmAnggota').textContent = selectedAnggotaLabel();
            document.getElementById('cfmMetode').textContent = selectedMetodeLabel();
            document.getElementById('cfmItems').textContent = items.length + ' barang · ' + items.map(function (it) { return it.nama; }).slice(0, 3).join(', ') + (items.length > 3 ? '…' : '');
            document.getElementById('cfmTotal').textContent = formatRp(total);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKonfirmasiWaserda')).show();
        }

        // Event delegation untuk baris barang
        itemsContainer.addEventListener('input', function (e) {
            const row = e.target.closest('.ui-ws-item');
            if (!row) return;
            if (e.target.classList.contains('item-harga-display')) {
                const n = parseRp(e.target.value);
                row.querySelector('.item-harga').value = n > 0 ? String(n) : '';
                e.target.value = n ? n.toLocaleString('id-ID') : '';
            }
            updateTotal();
        });
        itemsContainer.addEventListener('click', function (e) {
            const btn = e.target.closest('.ui-ws-remove');
            if (!btn) return;
            const row = btn.closest('.ui-ws-item');
            if (!row) return;
            if (itemsContainer.querySelectorAll('.ui-ws-item').length <= 1) return;
            row.remove();
            syncRemoveButtons();
            updateTotal();
        });

        document.getElementById('btnAddItem').addEventListener('click', function () { addItem(true); });
        form.addEventListener('submit', function (e) {
            if (allowSubmit) return;
            e.preventDefault();
            openConfirm();
        });
        document.getElementById('btnKonfirmasiWaserda').addEventListener('click', function () {
            allowSubmit = true;
            const confirmModal = bootstrap.Modal.getInstance(document.getElementById('modalKonfirmasiWaserda'));
            if (confirmModal) confirmModal.hide();
            if (!form.querySelector('input[name="proses_waserda"][type="hidden"]')) {
                const h = document.createElement('input');
                h.type = 'hidden'; h.name = 'proses_waserda'; h.value = '1';
                form.appendChild(h);
            }
            if (form.requestSubmit) form.requestSubmit();
            else form.submit();
        });

        function syncComboboxLabel(sel) {
            const box = sel.closest('.ui-combobox');
            if (!box) return;
            const input = box.querySelector('.ui-combobox-input');
            const opt = sel.options[sel.selectedIndex];
            if (input) input.value = opt && opt.value ? opt.textContent.trim() : '';
        }
        function clearSelect(sel) {
            sel.value = '';
            Array.prototype.forEach.call(sel.options, function (o) { o.selected = false; });
            if (sel.options[0]) sel.options[0].selected = true;
            syncComboboxLabel(sel);
        }
        function resetWaserdaForm() {
            allowSubmit = false;
            clearSelect(anggotaSelect);
            const tunai = document.getElementById('metodeTunai');
            if (tunai) tunai.checked = true;
            itemsContainer.innerHTML = '';
            itemSeq = 0;
            addItem(false);
            updateTotal();
            const hiddenProses = form.querySelector('input[name="proses_waserda"][type="hidden"]');
            if (hiddenProses) hiddenProses.remove();
        }

        let prefillAnggota = anggotaSelect.getAttribute('data-prefill') || '';
        const modalEl = document.getElementById('modalWaserda');
        modalEl.addEventListener('show.bs.modal', function () {
            if (prefillAnggota) {
                anggotaSelect.value = prefillAnggota;
                syncComboboxLabel(anggotaSelect);
                prefillAnggota = '';
                anggotaSelect.removeAttribute('data-prefill');
            }
        });
        modalEl.addEventListener('shown.bs.modal', function () {
            const combo = anggotaSelect.closest('.ui-combobox');
            const focusEl = anggotaSelect.value
                ? itemsContainer.querySelector('.item-nama')
                : (combo ? combo.querySelector('.ui-combobox-input') : anggotaSelect);
            if (focusEl && focusEl.focus) focusEl.focus();
        });
        modalEl.addEventListener('hidden.bs.modal', resetWaserdaForm);

        window.showDetail = function (items, kode, total) {
            document.getElementById('modalKodeTransaksi').textContent = 'Kode: ' + kode;
            let html = '';
            (items || []).forEach(function (item, index) {
                const subtotal = (Number(item.harga) || 0) * (Number(item.jumlah) || 0);
                html += '<tr><td>' + (index + 1) + '</td><td>' + String(item.nama || '').replace(/</g, '&lt;') +
                    '</td><td class="text-end">' + formatRp(item.harga) +
                    '</td><td class="text-center">' + (item.jumlah || 0) +
                    '</td><td class="text-end">' + formatRp(subtotal) + '</td></tr>';
            });
            document.getElementById('modalItems').innerHTML = html || '<tr><td colspan="5" class="text-muted text-center">Tidak ada item</td></tr>';
            document.getElementById('modalTotal').innerHTML =
                '<tr><th colspan="4" class="text-end">TOTAL</th><th class="text-end text-success">' + formatRp(total) + '</th></tr>';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('detailModal')).show();
        };

        <?php if (isAdmin()): ?>
        window.confirmDelete = function (el) {
            var kode = el.getAttribute('data-kode') || '';
            if (!kode) {
                var tr = el.closest('tr');
                var code = tr ? tr.querySelector('code') : null;
                kode = code ? code.textContent.trim() : '';
            }
            var href = el.getAttribute('href') || '#';
            document.getElementById('deleteKodeTransaksi').textContent = kode;
            document.getElementById('deleteConfirmButton').setAttribute('href', href);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteConfirmModal')).show();
            return false;
        };
        <?php endif; ?>

        document.addEventListener('DOMContentLoaded', function () {
            resetWaserdaForm();
        });
    })();
    </script>
</body>
</html>

<?php
/**
 * Single implementation — Setoran (Admin & Teller).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

if (!isLoggedIn() || (!isAdmin() && !isTeller())) {
    http_response_code(403);
    exit('Akses ditolak.');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_setoran'])) {
    
    $anggota_id = $_POST['anggota_id'] ?? '';
    $jenis_simpanan_id = $_POST['jenis_simpanan_id'] ?? '';
    $jumlahRaw = preg_replace('/[^\d]/', '', (string)($_POST['jumlah'] ?? ''));
    $jumlah = $jumlahRaw !== '' ? $jumlahRaw : '0';
    $keterangan = trim((string)($_POST['keterangan'] ?? ''));
    $user_id = $_SESSION['user_id'];
    
    // Validasi dasar
    if (empty($anggota_id) || empty($jenis_simpanan_id) || (float)$jumlah <= 0) {
        $_SESSION['error'] = "Data tidak lengkap. Pastikan semua field diisi dengan benar.";
        header("Location: setoran.php");
        exit();
    }
    
    try {
        $db->beginTransaction();
        if (!preg_match('/^\d+$/D', (string)$jumlah) || !is_finite((float)$jumlah) || (float)$jumlah <= 0 || (float)$jumlah > 999999999999) {
            throw new Exception('Nominal harus berupa angka positif yang valid.');
        }
        $jumlah = (float)$jumlah;
        $db->query('SELECT id FROM koperasi WHERE id=1 FOR UPDATE')->fetchColumn();
        $lockMember=$db->prepare("SELECT id FROM anggota WHERE id=? AND status='aktif' FOR UPDATE");
        $lockMember->execute([$anggota_id]);
        if(!$lockMember->fetchColumn()) throw new Exception('Anggota tidak aktif atau tidak ditemukan.');
        $validType=$db->prepare("SELECT id FROM jenis_simpanan WHERE id=? AND status='aktif' AND kode_jenis NOT IN ('ANG','BONUS','SHU')");
        $validType->execute([$jenis_simpanan_id]);
        if(!$validType->fetchColumn()) throw new Exception('Jenis simpanan tidak ditemukan.');

        
        // Get jenis simpanan info for validation
        $query_jenis = "SELECT nama_jenis, minimum, maksimum, kode_jenis FROM jenis_simpanan WHERE id = :jenis_id AND status = 'aktif'";
        $stmt_jenis = $db->prepare($query_jenis);
        $stmt_jenis->bindParam(':jenis_id', $jenis_simpanan_id);
        $stmt_jenis->execute();
        $jenis_info = $stmt_jenis->fetch(PDO::FETCH_ASSOC);
        
        if (!$jenis_info) {
            throw new Exception("Jenis simpanan tidak valid atau tidak aktif");
        }
        
        // Cegah setoran ke jenis simpanan ANG (angsuran)
        if ($jenis_info['kode_jenis'] == 'ANG') {
            throw new Exception("Tidak dapat melakukan setoran ke rekening angsuran. Gunakan menu pembayaran angsuran.");
        }
        
        // Validate minimum setoran
        if ($jenis_info['minimum'] > 0 && $jumlah < $jenis_info['minimum']) {
            throw new Exception("Jumlah setoran harus minimal " . formatRupiah($jenis_info['minimum']));
        }
        
        // Validate maksimum setoran
        if ($jenis_info['maksimum'] > 0 && $jumlah > $jenis_info['maksimum']) {
            throw new Exception("Jumlah setoran tidak boleh melebihi " . formatRupiah($jenis_info['maksimum']));
        }
        
        // Generate transaction code
        // Maks. 20 karakter (= panjang kolom); format lama 22 karakter ditolak MySQL strict mode
        $kode_transaksi = 'SET-' . date('ymdHis') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        // Get current balance from transaksi table (lebih akurat)
        $query_saldo = "SELECT COALESCE(SUM(CASE WHEN jenis_transaksi = 'setoran' THEN jumlah ELSE -jumlah END), 0) as saldo 
                       FROM transaksi 
                       WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_simpanan_id";
        $stmt_saldo = $db->prepare($query_saldo);
        $stmt_saldo->bindParam(':anggota_id', $anggota_id);
        $stmt_saldo->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
        $stmt_saldo->execute();
        $saldo_sebelum = $stmt_saldo->fetch(PDO::FETCH_ASSOC)['saldo'];
        
        $saldo_sesudah = $saldo_sebelum + $jumlah;
        
        // Insert transaction
        $query_transaksi = "INSERT INTO transaksi (kode_transaksi, anggota_id, jenis_simpanan_id, jenis_transaksi, 
                            jumlah, saldo_sebelum, saldo_sesudah, keterangan, user_id) 
                            VALUES (:kode_transaksi, :anggota_id, :jenis_simpanan_id, 'setoran', 
                            :jumlah, :saldo_sebelum, :saldo_sesudah, :keterangan, :user_id)";
        $stmt_transaksi = $db->prepare($query_transaksi);
        $stmt_transaksi->bindParam(':kode_transaksi', $kode_transaksi);
        $stmt_transaksi->bindParam(':anggota_id', $anggota_id);
        $stmt_transaksi->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
        $stmt_transaksi->bindParam(':jumlah', $jumlah);
        $stmt_transaksi->bindParam(':saldo_sebelum', $saldo_sebelum);
        $stmt_transaksi->bindParam(':saldo_sesudah', $saldo_sesudah);
        $stmt_transaksi->bindParam(':keterangan', $keterangan);
        $stmt_transaksi->bindParam(':user_id', $user_id);
        
        if (!$stmt_transaksi->execute()) {
            throw new Exception("Gagal menyimpan transaksi");
        }
        
        $transaksi_id = $db->lastInsertId();
        
        // Update or insert simpanan untuk sinkronisasi
        $query_check_simpanan = "SELECT id FROM simpanan WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_simpanan_id";
        $stmt_check_simpanan = $db->prepare($query_check_simpanan);
        $stmt_check_simpanan->bindParam(':anggota_id', $anggota_id);
        $stmt_check_simpanan->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
        $stmt_check_simpanan->execute();
        
        if ($stmt_check_simpanan->rowCount() > 0) {
            $query_update = "UPDATE simpanan SET saldo = :saldo, updated_at = NOW() 
                            WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_simpanan_id";
            $stmt_update = $db->prepare($query_update);
            $stmt_update->bindParam(':saldo', $saldo_sesudah);
            $stmt_update->bindParam(':anggota_id', $anggota_id);
            $stmt_update->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
            
            if (!$stmt_update->execute()) {
                throw new Exception("Gagal mengupdate simpanan");
            }
        } else {
            $query_insert = "INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo) 
                            VALUES (:anggota_id, :jenis_simpanan_id, :saldo)";
            $stmt_insert = $db->prepare($query_insert);
            $stmt_insert->bindParam(':anggota_id', $anggota_id);
            $stmt_insert->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
            $stmt_insert->bindParam(':saldo', $saldo_sesudah);
            
            if (!$stmt_insert->execute()) {
                throw new Exception("Gagal menambah simpanan");
            }
        }
        
        // Update kas
        $query_kas_saldo = "SELECT COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END), 0) as saldo FROM kas";
        $stmt_kas_saldo = $db->prepare($query_kas_saldo);
        $stmt_kas_saldo->execute();
        $saldo_kas_sebelum = $stmt_kas_saldo->fetch(PDO::FETCH_ASSOC)['saldo'];
        $saldo_kas_sesudah = $saldo_kas_sebelum + $jumlah;
        
        $query_kas = "INSERT INTO kas (transaksi_id, jenis, jumlah, saldo_sebelum, saldo_sesudah, keterangan) 
                     VALUES (:transaksi_id, 'masuk', :jumlah, :saldo_sebelum, :saldo_sesudah, :keterangan)";
        $stmt_kas = $db->prepare($query_kas);
        $stmt_kas->bindParam(':transaksi_id', $transaksi_id);
        $stmt_kas->bindParam(':jumlah', $jumlah);
        $stmt_kas->bindParam(':saldo_sebelum', $saldo_kas_sebelum);
        $stmt_kas->bindParam(':saldo_sesudah', $saldo_kas_sesudah);
        $stmt_kas->bindParam(':keterangan', $keterangan);
        
        if (!$stmt_kas->execute()) {
            throw new Exception("Gagal mengupdate kas");
        }
        
        $db->commit();
        $_SESSION['success'] = 'Setoran berhasil.';
        header('Location: setoran.php');
        exit();
        
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Gagal melakukan setoran: " . $e->getMessage();
        header("Location: setoran.php");
        exit();
    }
}

// Get data for dropdowns - HANYA jenis yang aktif dan BUKAN angsuran
$query_anggota = "SELECT * FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

// Ambil jenis simpanan kecuali yang berkode ANG (angsuran)
$query_jenis = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' AND kode_jenis NOT IN ('ANG','BONUS','SHU') ORDER BY
                CASE 
                    WHEN kode_jenis = 'POKOK' THEN 1
                    WHEN kode_jenis = 'WAJIB' THEN 2
                    WHEN kode_jenis = 'SUKARELA' THEN 3
                    ELSE 4
                END, nama_jenis";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

$riwayat = ui_riwayat_transaksi($db, 'setoran', ['client' => true]);
$prefillAnggotaId = isset($_GET['anggota_id']) && is_numeric($_GET['anggota_id']) ? (int)$_GET['anggota_id'] : 0;
$quickAmounts = [50000, 100000, 250000, 500000];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setoran Tabungan - <?php echo APP_NAME; ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon" aria-hidden="true"><i class="ti ti-circle-plus"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Transaksi</p>
                        <h1 class="ui-dash-title">Setoran Tabungan</h1>
                        <p class="ui-dash-meta mb-0">Proses setoran simpanan pokok, wajib, sukarela, dan lainnya</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <button type="button" class="btn ui-dash-btn-add" data-bs-toggle="modal" data-bs-target="#modalSetoran">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Setoran baru</span><span class="d-sm-none">Baru</span>
                        </button>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <?php
                $riwayatTitle = 'Riwayat Setoran';
                include __DIR__ . '/ui/partial_riwayat_transaksi.php';
                ?>
            </div>
            <?php include __DIR__ . '/layout/shell_end.php'; ?>

    <div class="modal fade ui-tx-modal" id="modalSetoran" tabindex="-1" aria-labelledby="modalSetoranLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalSetoranLabel">Setoran baru</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                            </div>
                <form method="POST" id="formSetoran" novalidate>
                    <?php echo layananToken(); ?>
                    <div class="modal-body">
                        <div class="row g-3">
                                        <div class="col-md-6">
                                <label class="form-label" for="anggotaSelect">Anggota *</label>
                                <select name="anggota_id" class="form-select" required id="anggotaSelect" data-prefill="<?php echo (int)$prefillAnggotaId; ?>">
                                    <option value="">-- Pilih anggota --</option>
                                                    <?php foreach ($anggota_list as $anggota): ?>
                                    <option value="<?php echo (int)$anggota['id']; ?>">
                                        <?php echo htmlspecialchars($anggota['kode_anggota'] . ' - ' . $anggota['nama_lengkap']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                        </div>
                                        <div class="col-md-6">
                                <label class="form-label" for="jenisSimpananSelect">Jenis tabungan *</label>
                                                <select name="jenis_simpanan_id" class="form-select" required id="jenisSimpananSelect">
                                    <option value="">-- Pilih jenis --</option>
                                                    <?php foreach ($jenis_simpanan as $jenis): ?>
                                    <option value="<?php echo (int)$jenis['id']; ?>"
                                            data-minimum="<?php echo htmlspecialchars((string)$jenis['minimum']); ?>"
                                            data-maksimum="<?php echo htmlspecialchars((string)$jenis['maksimum']); ?>"
                                            data-kode="<?php echo htmlspecialchars($jenis['kode_jenis']); ?>">
                                        <?php echo htmlspecialchars($jenis['nama_jenis']); ?>
                                                        <?php if ($jenis['minimum'] > 0): ?>
                                                            (Min: <?php echo formatRupiah($jenis['minimum']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted" id="minimumInfo"></small>
                                            </div>

                            <div class="col-12">
                                <div class="ui-tx-saldo-box" id="saldoInfo" aria-live="polite">
                                    <div class="text-muted small">Pilih anggota dan jenis untuk melihat saldo</div>
                                        </div>
                                    </div>
                                    
                            <div class="col-12">
                                <label class="form-label" for="jumlahSetoranDisplay">Jumlah setoran (Rp) *</label>
                                <input type="text" class="form-control form-control-lg" id="jumlahSetoranDisplay"
                                       inputmode="numeric" autocomplete="off" placeholder="Contoh: 100.000" required>
                                <input type="hidden" name="jumlah" id="jumlahSetoran" value="">
                                <div class="ui-tx-chips" id="quickAmounts" hidden>
                                    <?php foreach ($quickAmounts as $qa): ?>
                                    <button type="button" class="ui-tx-chip" data-amount="<?php echo (int)$qa; ?>"><?php echo formatRupiah($qa); ?></button>
                                    <?php endforeach; ?>
                                    <button type="button" class="ui-tx-chip" id="chipMin" data-amount="0" hidden>Pakai minimum</button>
                                </div>
                                <small class="text-muted d-block mt-1" id="validationInfo"></small>
                            </div>

                            <div class="col-12">
                                <div class="ui-tx-preview" id="saldoPreview" aria-live="polite">
                                    <div class="ui-tx-preview-cell">
                                        <span class="ui-tx-preview-label">Saldo sekarang</span>
                                        <div class="ui-tx-preview-value" id="previewSebelum">Rp 0</div>
                                    </div>
                                    <span class="ui-tx-preview-arrow" aria-hidden="true"><i class="ti ti-plus"></i></span>
                                    <div class="ui-tx-preview-cell">
                                        <span class="ui-tx-preview-label">Setoran</span>
                                        <div class="ui-tx-preview-value is-add" id="previewJumlah">Rp 0</div>
                                                </div>
                                    <span class="ui-tx-preview-arrow" aria-hidden="true"><i class="ti ti-arrow-right"></i></span>
                                    <div class="ui-tx-preview-cell">
                                        <span class="ui-tx-preview-label">Saldo sesudah</span>
                                        <div class="ui-tx-preview-value is-after" id="previewSesudah">Rp 0</div>
                                            </div>
                                        </div>
                                    </div>

                            <div class="col-12">
                                    <details class="ui-form-more">
                                        <summary>Keterangan (opsional)</summary>
                                        <div class="ui-form-more-body">
                                            <label class="form-label" for="keterangan">Keterangan</label>
                                            <input type="text" name="keterangan" class="form-control"
                                               placeholder="Contoh: Setoran bulanan" id="keterangan">
                                        </div>
                                    </details>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="proses_setoran" value="1" class="btn btn-success" id="btnAjukanSetoran">
                            <i class="ti ti-circle-check me-1" aria-hidden="true"></i>Lanjut konfirmasi
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

    <div class="modal fade" id="modalKonfirmasiSetoran" tabindex="-1" aria-labelledby="modalKonfirmasiSetoranLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalKonfirmasiSetoranLabel">Konfirmasi setoran</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <dl class="ui-tx-confirm-dl">
                        <div><dt>Anggota</dt><dd id="cfmAnggota">—</dd></div>
                        <div><dt>Jenis</dt><dd id="cfmJenis">—</dd></div>
                        <div><dt>Jumlah</dt><dd id="cfmJumlah">—</dd></div>
                        <div><dt>Saldo sesudah</dt><dd id="cfmSesudah">—</dd></div>
                        <div><dt>Keterangan</dt><dd id="cfmKet">—</dd></div>
                    </dl>
                            </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Periksa lagi</button>
                    <button type="button" class="btn btn-success" id="btnKonfirmasiSetoran">
                        <i class="ti ti-check me-1" aria-hidden="true"></i>Ya, proses
                    </button>
                                    </div>
                                </div>
                            </div>
                        </div>

    <script>
    (function () {
        const form = document.getElementById('formSetoran');
            const anggotaSelect = document.getElementById('anggotaSelect');
            const jenisSelect = document.getElementById('jenisSimpananSelect');
        const jumlahDisplay = document.getElementById('jumlahSetoranDisplay');
        const jumlahHidden = document.getElementById('jumlahSetoran');
        const chipsWrap = document.getElementById('quickAmounts');
        const chipMin = document.getElementById('chipMin');
        const saldoInfo = document.getElementById('saldoInfo');
        let currentSaldo = 0;
        let allowSubmit = false;

        function formatRp(n) {
            n = Math.max(0, Math.floor(Number(n) || 0));
            return 'Rp ' + n.toLocaleString('id-ID');
        }
        function parseRp(str) {
            return parseInt(String(str || '').replace(/\D/g, ''), 10) || 0;
        }
        function selectedJenis() {
            return jenisSelect.options[jenisSelect.selectedIndex] || null;
        }
        function selectedAnggotaLabel() {
            const o = anggotaSelect.options[anggotaSelect.selectedIndex];
            return o && o.value ? o.textContent.trim() : '—';
        }
        function syncDisplayFromHidden() {
            const n = parseRp(jumlahHidden.value);
            jumlahDisplay.value = n ? n.toLocaleString('id-ID') : '';
            updatePreview();
            validateAmount(n);
        }
        function setAmount(n) {
            n = Math.max(0, Math.floor(Number(n) || 0));
            jumlahHidden.value = n > 0 ? String(n) : '';
            syncDisplayFromHidden();
            chipsWrap.querySelectorAll('.ui-tx-chip').forEach(function (btn) {
                btn.classList.toggle('is-active', parseInt(btn.getAttribute('data-amount'), 10) === n && n > 0);
            });
        }
        function updatePreview() {
            const amount = parseRp(jumlahHidden.value);
            document.getElementById('previewSebelum').textContent = formatRp(currentSaldo);
            document.getElementById('previewJumlah').textContent = formatRp(amount);
            document.getElementById('previewSesudah').textContent = formatRp(currentSaldo + amount);
        }
        function validateAmount(amount) {
            const opt = selectedJenis();
            const el = document.getElementById('validationInfo');
            if (!opt || !opt.value) { el.innerHTML = ''; return true; }
            const minimum = parseInt(opt.getAttribute('data-minimum'), 10) || 0;
            const maksimum = parseInt(opt.getAttribute('data-maksimum'), 10) || 0;
            if (minimum > 0 && amount > 0 && amount < minimum) {
                el.innerHTML = '<span class="text-danger"><i class="ti ti-alert-triangle me-1"></i>Kurang dari minimum ' + formatRp(minimum) + '</span>';
                return false;
            }
            if (maksimum > 0 && amount > maksimum) {
                el.innerHTML = '<span class="text-danger"><i class="ti ti-alert-triangle me-1"></i>Melebihi maksimum ' + formatRp(maksimum) + '</span>';
                return false;
            }
            if (amount > 0) {
                el.innerHTML = '<span class="text-success"><i class="ti ti-circle-check me-1"></i>Jumlah valid</span>';
                return true;
            }
            el.innerHTML = '';
                return false;
        }
        function syncJenisMeta() {
            const opt = selectedJenis();
            const info = document.getElementById('minimumInfo');
            const minimum = opt ? parseInt(opt.getAttribute('data-minimum'), 10) || 0 : 0;
            const maksimum = opt ? parseInt(opt.getAttribute('data-maksimum'), 10) || 0 : 0;
            let text = '';
            if (minimum > 0) text += 'Minimum ' + formatRp(minimum);
            if (maksimum > 0) text += (text ? ' · ' : '') + 'Maksimum ' + formatRp(maksimum);
            info.textContent = text || (opt && opt.value ? 'Tidak ada batasan setoran' : '');
            chipsWrap.hidden = !(opt && opt.value);
            if (minimum > 0) {
                chipMin.hidden = false;
                chipMin.setAttribute('data-amount', String(minimum));
                chipMin.textContent = 'Min ' + formatRp(minimum);
            } else {
                chipMin.hidden = true;
            }
            validateAmount(parseRp(jumlahHidden.value));
            updateSaldoInfo();
        }
        function renderSaldoBox(data) {
            if (!data || !data.success) {
                saldoInfo.innerHTML = '<div class="text-danger small">' + ((data && data.message) || 'Gagal memuat saldo') + '</div>';
                currentSaldo = 0;
                updatePreview();
                return;
            }
            currentSaldo = Number(data.saldo) || 0;
            const detailHref = anggotaSelect.value ? ('detail_anggota.php?id=' + encodeURIComponent(anggotaSelect.value)) : '#';
            saldoInfo.innerHTML =
                '<div class="ui-tx-saldo-row">' +
                '<div class="min-w-0">' +
                '<div class="fw-semibold text-truncate">' + (data.nama_anggota || 'Anggota') + '</div>' +
                '<div class="ui-tx-saldo-meta">' + (data.kode_anggota || '') + (data.jenis_simpanan ? ' · ' + data.jenis_simpanan : '') + '</div>' +
                '</div>' +
                '<div class="ui-tx-saldo-amount">' + (data.saldo_formatted || formatRp(currentSaldo)) + '</div>' +
                '</div>' +
                '<div class="ui-tx-saldo-meta d-flex flex-wrap gap-2 align-items-center mt-2">' +
                (data.no_record ? '<span class="badge bg-secondary-lt">Tabungan baru</span>' : '<span class="badge bg-success-lt">Aktif</span>') +
                (data.last_update && !data.no_record ? '<span>Update: ' + data.last_update + '</span>' : '') +
                '<a class="ms-auto small fw-semibold" href="' + detailHref + '">Detail anggota</a>' +
                '</div>';
            updatePreview();
        }
        function updateSaldoInfo() {
            const anggotaId = anggotaSelect.value;
            const jenisId = jenisSelect.value;
            if (!anggotaId || !jenisId) {
                currentSaldo = 0;
                saldoInfo.innerHTML = '<div class="text-muted small">Pilih anggota dan jenis untuk melihat saldo</div>';
                updatePreview();
                        return;
                    }
            saldoInfo.innerHTML = '<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Memuat saldo…</div>';
            fetch('get_saldo.php?anggota_id=' + encodeURIComponent(anggotaId) + '&jenis_id=' + encodeURIComponent(jenisId))
                .then(function (r) { if (!r.ok) throw new Error('fail'); return r.json(); })
                .then(renderSaldoBox)
                .catch(function () {
                    currentSaldo = 0;
                    saldoInfo.innerHTML = '<div class="text-danger small">Tidak dapat memuat saldo</div>';
                    updatePreview();
                });
        }
        function validateForm() {
            if (!anggotaSelect.value) { window.uiToast && uiToast('warning', 'Pilih anggota terlebih dahulu'); return false; }
            if (!jenisSelect.value) { window.uiToast && uiToast('warning', 'Pilih jenis tabungan terlebih dahulu'); return false; }
            const amount = parseRp(jumlahHidden.value);
            if (amount <= 0) { window.uiToast && uiToast('warning', 'Jumlah setoran harus lebih dari 0'); jumlahDisplay.focus(); return false; }
            if (!validateAmount(amount)) { jumlahDisplay.focus(); return false; }
            return true;
        }
        function openConfirm() {
            if (!validateForm()) return;
            const opt = selectedJenis();
            const amount = parseRp(jumlahHidden.value);
            document.getElementById('cfmAnggota').textContent = selectedAnggotaLabel();
            document.getElementById('cfmJenis').textContent = opt ? opt.textContent.split('(')[0].trim() : '—';
            document.getElementById('cfmJumlah').textContent = formatRp(amount);
            document.getElementById('cfmSesudah').textContent = formatRp(currentSaldo + amount);
            document.getElementById('cfmKet').textContent = (document.getElementById('keterangan').value || '').trim() || '—';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKonfirmasiSetoran')).show();
        }

        jumlahDisplay.addEventListener('input', function () {
            setAmount(parseRp(this.value));
        });
        chipsWrap.addEventListener('click', function (e) {
            const btn = e.target.closest('.ui-tx-chip');
            if (!btn) return;
            setAmount(btn.getAttribute('data-amount'));
            jumlahDisplay.focus();
        });
        anggotaSelect.addEventListener('change', updateSaldoInfo);
        jenisSelect.addEventListener('change', syncJenisMeta);

        form.addEventListener('submit', function (e) {
            if (allowSubmit) return;
            e.preventDefault();
            openConfirm();
        });
        document.getElementById('btnKonfirmasiSetoran').addEventListener('click', function () {
            allowSubmit = true;
            const confirmModal = bootstrap.Modal.getInstance(document.getElementById('modalKonfirmasiSetoran'));
            if (confirmModal) confirmModal.hide();
            if (!form.querySelector('input[name="proses_setoran"][type="hidden"]')) {
                const h = document.createElement('input');
                h.type = 'hidden'; h.name = 'proses_setoran'; h.value = '1';
                form.appendChild(h);
            }
            if (form.requestSubmit) form.requestSubmit();
            else form.submit();
        });

        // Enter di field jumlah → konfirmasi
        jumlahDisplay.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                openConfirm();
            }
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
        function resetSetoranForm() {
            allowSubmit = false;
            clearSelect(anggotaSelect);
            clearSelect(jenisSelect);
            jumlahHidden.value = '';
            jumlahDisplay.value = '';
            const ket = document.getElementById('keterangan');
            if (ket) ket.value = '';
            document.getElementById('validationInfo').innerHTML = '';
            document.getElementById('minimumInfo').textContent = '';
            chipsWrap.hidden = true;
            chipsWrap.querySelectorAll('.ui-tx-chip').forEach(function (btn) { btn.classList.remove('is-active'); });
            currentSaldo = 0;
            saldoInfo.innerHTML = '<div class="text-muted small">Pilih anggota dan jenis untuk melihat saldo</div>';
            updatePreview();
            const details = form.querySelector('details.ui-form-more');
            if (details) details.open = false;
            const hiddenProses = form.querySelector('input[name="proses_setoran"][type="hidden"]');
            if (hiddenProses) hiddenProses.remove();
        }

        let prefillAnggota = anggotaSelect.getAttribute('data-prefill') || '';
        const modalEl = document.getElementById('modalSetoran');
        modalEl.addEventListener('show.bs.modal', function () {
            if (prefillAnggota) {
                anggotaSelect.value = prefillAnggota;
                syncComboboxLabel(anggotaSelect);
                prefillAnggota = '';
                anggotaSelect.removeAttribute('data-prefill');
            }
        });
        modalEl.addEventListener('shown.bs.modal', function () {
            syncJenisMeta();
            const comboAnggota = anggotaSelect.closest('.ui-combobox');
            const comboJenis = jenisSelect.closest('.ui-combobox');
            let focusEl;
            if (anggotaSelect.value) {
                focusEl = comboJenis ? comboJenis.querySelector('.ui-combobox-input') : jenisSelect;
            } else {
                focusEl = comboAnggota ? comboAnggota.querySelector('.ui-combobox-input') : anggotaSelect;
            }
            if (focusEl && focusEl.focus) focusEl.focus();
        });
        modalEl.addEventListener('hidden.bs.modal', resetSetoranForm);

        document.addEventListener('DOMContentLoaded', function () {
            resetSetoranForm();
        });
    })();
    </script>
</body>
</html>

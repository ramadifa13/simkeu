<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Hanya Admin
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header('Location: ../dashboard.php');
    exit();
}

// Handle form actions
if ($_POST) {
    if (isset($_POST['update_bagi_hasil'])) {
        foreach ($_POST['bagi_hasil'] as $jenis_id => $persentase) {
            $query = "UPDATE jenis_simpanan SET bagi_hasil = :bagi_hasil WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':bagi_hasil', $persentase);
            $stmt->bindParam(':id', $jenis_id);
            $stmt->execute();
        }
        $_SESSION['success'] = "Bagi hasil berhasil diupdate";
    }
    
    // Tarif denda nominal per hari menurut besar pinjaman (dipakai perhitungan denda otomatis)
    if (isset($_POST['simpan_tarif_denda'])) {
        $errmode = $db->getAttribute(PDO::ATTR_ERRMODE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $jumlah = isset($_POST['tarif_jumlah']) ? (array) $_POST['tarif_jumlah'] : [];
            $denda = isset($_POST['tarif_denda']) ? (array) $_POST['tarif_denda'] : [];
            $baris = [];
            foreach ($jumlah as $i => $j) {
                $j = trim($j);
                $d = isset($denda[$i]) ? trim($denda[$i]) : '';
                if ($j === '' && $d === '') {
                    continue;
                }
                $j = parseRupiah($j);
                $d = parseRupiah($d);
                if ($j === false || $d === false || (float) $j <= 0) {
                    throw new Exception("Jumlah pinjaman dan denda per hari harus berupa angka rupiah penuh lebih dari 0");
                }
                if (isset($baris[$j])) {
                    throw new Exception("Jumlah pinjaman " . number_format($j, 0, ',', '.') . " diisi lebih dari sekali");
                }
                $baris[$j] = $d;
            }
            if (empty($baris)) {
                throw new Exception("Isi minimal satu tarif denda");
            }
            $db->beginTransaction();
            $db->exec("DELETE FROM denda_tarif");
            $ins = $db->prepare("INSERT INTO denda_tarif (jumlah_pinjaman, denda_per_hari) VALUES (?, ?)");
            foreach ($baris as $j => $d) {
                $ins->execute([$j, $d]);
            }
            $db->commit();
            // Hitung ulang denda cicilan terlambat (kecuali yang manual) dengan tarif baru
            $diperbarui = perbaruiDendaOtomatis($db, true);
            $_SESSION['success'] = "Tarif denda disimpan. $diperbarui angsuran terlambat dihitung ulang.";
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = "Gagal menyimpan tarif denda: " . htmlspecialchars($e->getMessage())
                . (migrasiRevisiBelumDijalankan($db) ? " (database belum di-update, jalankan file migrasi)" : "");
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, $errmode);
        header("Location: pengaturan_pinjaman.php");
        exit();
    }
}

// Get jenis simpanan untuk bagi hasil
$query_jenis = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' ORDER BY nama_jenis";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// Tarif denda per hari
$tarif_denda = [];
$stmt_tarif = $db->query("SELECT jumlah_pinjaman, denda_per_hari FROM denda_tarif ORDER BY jumlah_pinjaman");
if ($stmt_tarif) {
    $tarif_denda = $stmt_tarif->fetchAll(PDO::FETCH_ASSOC);
}

// Create settings table if not exists
$db->exec("CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Pinjaman - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-setpin" aria-hidden="true"><i class="ti ti-settings-dollar"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Pengaturan</p>
                        <h1 class="ui-dash-title">Pinjaman &amp; Tabungan</h1>
                        <p class="ui-dash-meta mb-0">Bagi hasil simpanan dan tarif denda per hari</p>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-set-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Jenis simpanan', 'value' => count($jenis_simpanan), 'tone' => 'primary', 'icon' => 'ti ti-stack-2', 'hint' => 'Aktif'],
                    ['label' => 'Tingkat denda', 'value' => count($tarif_denda), 'tone' => 'warning', 'icon' => 'ti ti-alert-triangle', 'hint' => 'Tarif tersimpan'],
                    ['label' => 'Denda terendah', 'value' => !empty($tarif_denda) ? formatRupiah(min(array_column($tarif_denda, 'denda_per_hari'))) : '—', 'tone' => 'danger', 'icon' => 'ti ti-coin', 'hint' => 'Per hari'],
                    ['label' => 'Denda tertinggi', 'value' => !empty($tarif_denda) ? formatRupiah(max(array_column($tarif_denda, 'denda_per_hari'))) : '—', 'tone' => 'info', 'icon' => 'ti ti-coin', 'hint' => 'Per hari'],
                ]); ?>
                </div>

                <div class="ui-set-split mb-3">
                    <div class="card ui-set-card">
                        <div class="card-header ui-dash-sec">
                            <span class="ui-dash-sec-title"><i class="ti ti-percentage" aria-hidden="true"></i>Bagi hasil tabungan</span>
                            <span class="ui-dash-sec-note">% per jenis</span>
                        </div>
                        <div class="card-body p-0">
                            <form method="POST"><?php echo layananToken(); ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Jenis simpanan</th>
                                                <th class="text-end" style="width:9rem">Bagi hasil (%)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!$jenis_simpanan): ?>
                                            <tr><td colspan="2" class="text-center text-muted py-4">Belum ada jenis simpanan aktif.</td></tr>
                                            <?php endif; ?>
                                            <?php foreach ($jenis_simpanan as $jenis): ?>
                                            <tr>
                                                <td>
                                                    <strong class="ui-set-name"><?php echo htmlspecialchars($jenis['nama_jenis']); ?></strong>
                                                    <small class="ui-set-meta"><?php echo htmlspecialchars($jenis['kode_jenis'] ?? ''); ?></small>
                                                </td>
                                                <td>
                                                    <input type="number" name="bagi_hasil[<?php echo (int)$jenis['id']; ?>]"
                                                           class="form-control form-control-sm text-end"
                                                           value="<?php echo htmlspecialchars((string)$jenis['bagi_hasil']); ?>"
                                                           step="0.01" min="0" max="100" aria-label="Bagi hasil <?php echo htmlspecialchars($jenis['nama_jenis']); ?>">
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="ui-set-card-foot">
                                    <button type="submit" name="update_bagi_hasil" class="btn btn-primary">
                                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Update bagi hasil
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card ui-set-card">
                        <div class="card-header ui-dash-sec">
                            <span class="ui-dash-sec-title"><i class="ti ti-alert-triangle" aria-hidden="true"></i>Tarif denda per hari</span>
                            <span class="ui-dash-sec-note">Menurut pokok pinjaman</span>
                        </div>
                        <div class="card-body p-0">
                            <form method="POST" id="formTarifDenda"><?php echo layananToken(); ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Jumlah pinjaman (Rp)</th>
                                                <th>Denda / hari (Rp)</th>
                                                <th style="width:3rem"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="tarifDendaRows">
                                            <?php if (empty($tarif_denda)): ?>
                                            <tr>
                                                <td><input type="number" name="tarif_jumlah[]" class="form-control form-control-sm" min="1" step="1" required></td>
                                                <td><input type="number" name="tarif_denda[]" class="form-control form-control-sm" min="1" step="1" required></td>
                                                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="hapusBarisDenda(this)" aria-label="Hapus baris"><i class="ti ti-x"></i></button></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php foreach ($tarif_denda as $t): ?>
                                            <tr>
                                                <td><input type="number" name="tarif_jumlah[]" class="form-control form-control-sm" min="1" step="1" required value="<?php echo (int)$t['jumlah_pinjaman']; ?>"></td>
                                                <td><input type="number" name="tarif_denda[]" class="form-control form-control-sm" min="1" step="1" required value="<?php echo (int)$t['denda_per_hari']; ?>"></td>
                                                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="hapusBarisDenda(this)" aria-label="Hapus baris"><i class="ti ti-x"></i></button></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="ui-set-card-foot ui-set-card-foot-between">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="tambahBarisDenda()">
                                        <i class="ti ti-plus me-1" aria-hidden="true"></i>Tambah tingkat
                                    </button>
                                    <button type="submit" name="simpan_tarif_denda" class="btn btn-warning">
                                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Simpan tarif
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="ui-set-notes">
                    <article class="ui-set-note">
                        <strong>Bagi hasil</strong>
                        <ul>
                            <li>Dihitung per bulan dari saldo rata-rata</li>
                            <li>Pembayaran biasanya di akhir bulan</li>
                        </ul>
                    </article>
                    <article class="ui-set-note">
                        <strong>Denda</strong>
                        <ul>
                            <li>Mulai sehari setelah jatuh tempo sampai jatuh tempo berikutnya</li>
                            <li>Pinjaman di antara dua tingkat memakai tingkat di bawahnya</li>
                            <li>Denda manual tidak berubah saat tarif diupdate</li>
                        </ul>
                    </article>
                </div>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
<script>
function tambahBarisDenda() {
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="number" name="tarif_jumlah[]" class="form-control form-control-sm" min="1" step="1" required></td>' +
                   '<td><input type="number" name="tarif_denda[]" class="form-control form-control-sm" min="1" step="1" required></td>' +
                   '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="hapusBarisDenda(this)" aria-label="Hapus baris"><i class="ti ti-x"></i></button></td>';
    document.getElementById('tarifDendaRows').appendChild(tr);
}
function hapusBarisDenda(btn) {
    var tbody = document.getElementById('tarifDendaRows');
    if (tbody.rows.length > 1) btn.closest('tr').remove();
}
</script>
</body>
</html>

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
    <title>Pengaturan Pinjaman & Tabungan - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <?php ui_page_header([
                    'title' => 'Pengaturan Pinjaman & Tabungan',
                    'icon' => 'fas fa-cog text-primary',
                    'subtitle' => 'Bagi hasil tabungan dan parameter pinjaman',
                ]); ?>
                <?php ui_flash(); ?>

                <div class="row">
                    <!-- Bagi Hasil Tabungan -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-percentage me-2"></i>Bagi Hasil Tabungan
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST"><?php echo layananToken(); ?>
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Jenis Simpanan</th>
                                                <th>Bagi Hasil (%)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($jenis_simpanan as $jenis): ?>
                                            <tr>
                                                <td><?php echo $jenis['nama_jenis']; ?></td>
                                                <td>
                                                    <input type="number" name="bagi_hasil[<?php echo $jenis['id']; ?>]" 
                                                           class="form-control form-control-sm" 
                                                           value="<?php echo $jenis['bagi_hasil']; ?>" 
                                                           step="0.01" min="0" max="100">
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <div class="text-end">
                                        <button type="submit" name="update_bagi_hasil" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i> Update Bagi Hasil
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Tarif Denda per Hari -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Tarif Denda per Hari
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="formTarifDenda"><?php echo layananToken(); ?>
                                    <table class="table table-sm align-middle mb-2">
                                        <thead>
                                            <tr><th>Jumlah Pinjaman (Rp)</th><th>Denda per Hari (Rp)</th><th></th></tr>
                                        </thead>
                                        <tbody id="tarifDendaRows">
                                            <?php foreach ($tarif_denda as $t): ?>
                                            <tr>
                                                <td><input type="number" name="tarif_jumlah[]" class="form-control form-control-sm" min="1" step="1" required value="<?php echo (int) $t['jumlah_pinjaman']; ?>"></td>
                                                <td><input type="number" name="tarif_denda[]" class="form-control form-control-sm" min="1" step="1" required value="<?php echo (int) $t['denda_per_hari']; ?>"></td>
                                                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="hapusBarisDenda(this)"><i class="fas fa-times"></i></button></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <button type="button" class="btn btn-sm btn-outline-primary mb-3" onclick="tambahBarisDenda()">
                                        <i class="fas fa-plus me-1"></i> Tambah Tingkat
                                    </button>
                                    <small class="text-muted d-block mb-3">
                                        Denda dihitung <strong>per hari</strong> mulai sehari setelah jatuh tempo, sampai jatuh tempo
                                        cicilan berikutnya. Pinjaman di antara dua tingkat memakai tingkat di bawahnya.
                                        Denda yang diinput manual tidak berubah.
                                    </small>
                                    <div class="text-end">
                                        <button type="submit" name="simpan_tarif_denda" class="btn btn-warning">
                                            <i class="fas fa-save me-1"></i> Simpan Tarif Denda
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Informasi Sistem -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Informasi Sistem
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Bagi Hasil Tabungan:</h6>
                                        <ul>
                                            <li>Bagi hasil dihitung per bulan</li>
                                            <li>Persentase berdasarkan saldo rata-rata</li>
                                            <li>Pembayaran bagi hasil setiap akhir bulan</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Ketentuan Denda:</h6>
                                        <ul>
                                            <li>Denda nominal tetap per hari menurut besar pinjaman (lihat Tarif Denda per Hari)</li>
                                            <li>Dihitung mulai sehari setelah jatuh tempo sampai jatuh tempo cicilan berikutnya</li>
                                            <li>Otomatis tiap hari; petugas bisa mengisi manual saat pembayaran</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function tambahBarisDenda() {
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="number" name="tarif_jumlah[]" class="form-control form-control-sm" min="1" step="1" required></td>' +
                   '<td><input type="number" name="tarif_denda[]" class="form-control form-control-sm" min="1" step="1" required></td>' +
                   '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="hapusBarisDenda(this)"><i class="fas fa-times"></i></button></td>';
    document.getElementById('tarifDendaRows').appendChild(tr);
}
function hapusBarisDenda(btn) {
    var tbody = document.getElementById('tarifDendaRows');
    if (tbody.rows.length > 1) {
        btn.closest('tr').remove();
    }
}
</script>
</body>
</html>
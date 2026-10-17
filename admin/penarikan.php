<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Handle form submission
if ($_POST && isset($_POST['proses_penarikan'])) {
    $anggota_id = $_POST['anggota_id'];
    $jenis_simpanan_id = $_POST['jenis_simpanan_id'];
    $jumlah = $_POST['jumlah'];
    $keterangan = $_POST['keterangan'];
    
    try {
        $db->beginTransaction();
        
        // Check current balance
        $query_saldo = "SELECT saldo FROM simpanan WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_simpanan_id";
        $stmt_saldo = $db->prepare($query_saldo);
        $stmt_saldo->bindParam(':anggota_id', $anggota_id);
        $stmt_saldo->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
        $stmt_saldo->execute();
        
        if ($stmt_saldo->rowCount() > 0) {
            $saldo_sebelum = $stmt_saldo->fetch(PDO::FETCH_ASSOC)['saldo'];
        } else {
            throw new Exception("Anggota tidak memiliki simpanan jenis ini");
        }
        
        // Check if balance is sufficient
        if ($saldo_sebelum < $jumlah) {
            throw new Exception("Saldo tidak mencukupi. Saldo tersedia: " . formatRupiah($saldo_sebelum));
        }
        
        $saldo_sesudah = $saldo_sebelum - $jumlah;
        
        // Generate transaction code
        $kode_transaksi = 'TAR-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Insert transaction
        $query_transaksi = "INSERT INTO transaksi (kode_transaksi, anggota_id, jenis_simpanan_id, jenis_transaksi, 
                            jumlah, saldo_sebelum, saldo_sesudah, keterangan, user_id) 
                            VALUES (:kode_transaksi, :anggota_id, :jenis_simpanan_id, 'penarikan', 
                            :jumlah, :saldo_sebelum, :saldo_sesudah, :keterangan, :user_id)";
        $stmt_transaksi = $db->prepare($query_transaksi);
        $stmt_transaksi->bindParam(':kode_transaksi', $kode_transaksi);
        $stmt_transaksi->bindParam(':anggota_id', $anggota_id);
        $stmt_transaksi->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
        $stmt_transaksi->bindParam(':jumlah', $jumlah);
        $stmt_transaksi->bindParam(':saldo_sebelum', $saldo_sebelum);
        $stmt_transaksi->bindParam(':saldo_sesudah', $saldo_sesudah);
        $stmt_transaksi->bindParam(':keterangan', $keterangan);
        $stmt_transaksi->bindParam(':user_id', $_SESSION['user_id']);
        $stmt_transaksi->execute();
        
        $transaksi_id = $db->lastInsertId();
        
        // Update simpanan
        $query_update = "UPDATE simpanan SET saldo = :saldo, updated_at = NOW() 
                        WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_simpanan_id";
        $stmt_update = $db->prepare($query_update);
        $stmt_update->bindParam(':saldo', $saldo_sesudah);
        $stmt_update->bindParam(':anggota_id', $anggota_id);
        $stmt_update->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
        $stmt_update->execute();
        
        // Update kas
        $query_kas_saldo = "SELECT COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END), 0) as saldo FROM kas";
        $stmt_kas_saldo = $db->prepare($query_kas_saldo);
        $stmt_kas_saldo->execute();
        $saldo_kas_sebelum = $stmt_kas_saldo->fetch(PDO::FETCH_ASSOC)['saldo'];
        $saldo_kas_sesudah = $saldo_kas_sebelum - $jumlah;
        
        $query_kas = "INSERT INTO kas (transaksi_id, jenis, jumlah, saldo_sebelum, saldo_sesudah, keterangan) 
                     VALUES (:transaksi_id, 'keluar', :jumlah, :saldo_sebelum, :saldo_sesudah, :keterangan)";
        $stmt_kas = $db->prepare($query_kas);
        $stmt_kas->bindParam(':transaksi_id', $transaksi_id);
        $stmt_kas->bindParam(':jumlah', $jumlah);
        $stmt_kas->bindParam(':saldo_sebelum', $saldo_kas_sebelum);
        $stmt_kas->bindParam(':saldo_sesudah', $saldo_kas_sesudah);
        $stmt_kas->bindParam(':keterangan', $keterangan);
        $stmt_kas->execute();
        
        $db->commit();
        $_SESSION['success'] = "Penarikan berhasil dilakukan. Kode Transaksi: " . $kode_transaksi;
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Gagal melakukan penarikan: " . $e->getMessage();
    }
}

// Get data for dropdowns
$query_anggota = "SELECT * FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

$query_jenis = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' ORDER BY nama_jenis";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// Get recent penarikan
$query_recent = "SELECT t.*, a.nama_lengkap, j.nama_jenis, u.nama_lengkap as teller
                FROM transaksi t 
                JOIN anggota a ON t.anggota_id = a.id 
                JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                JOIN users u ON t.user_id = u.id
                WHERE t.jenis_transaksi = 'penarikan'
                ORDER BY t.tanggal_transaksi DESC 
                LIMIT 10";
$stmt_recent = $db->prepare($query_recent);
$stmt_recent->execute();
$recent_penarikan = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Penarikan Simpanan - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .transaction-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        body {
            background-color: #f8f9fa;
        }
        .sidebar {
            background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%) !important;
            min-height: calc(100vh - 56px);
        }
        main {
            background-color: #f8f9fa;
            min-height: calc(100vh - 56px);
        }
        .saldo-display {
            font-size: 1.8rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-minus-circle me-2"></i>Penarikan Simpanan
                    </h1>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <div class="row">
                    <!-- Form Penarikan -->
                    <div class="col-lg-8 mb-4">
                        <div class="card transaction-card">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-money-bill-wave me-2"></i>Form Penarikan
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="formPenarikan">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Pilih Anggota *</label>
                                                <select name="anggota_id" class="form-control" required id="anggotaSelect">
                                                    <option value="">-- Pilih Anggota --</option>
                                                    <?php foreach ($anggota_list as $anggota): ?>
                                                    <option value="<?php echo $anggota['id']; ?>">
                                                        <?php echo $anggota['kode_anggota'] . ' - ' . $anggota['nama_lengkap']; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Jenis Simpanan *</label>
                                                <select name="jenis_simpanan_id" class="form-control" required id="jenisSimpananSelect">
                                                    <option value="">-- Pilih Jenis --</option>
                                                    <?php foreach ($jenis_simpanan as $jenis): ?>
                                                    <option value="<?php echo $jenis['id']; ?>">
                                                        <?php echo $jenis['nama_jenis']; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Jumlah Penarikan (Rp) *</label>
                                                <input type="number" name="jumlah" class="form-control" 
                                                       required step="1000" min="0" id="jumlahPenarikan"
                                                       placeholder="Masukkan jumlah penarikan">
                                                <small class="text-muted" id="saldoInfo">Pilih anggota dan jenis simpanan</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Keterangan *</label>
                                                <input type="text" name="keterangan" class="form-control" required
                                                       placeholder="Alasan penarikan">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-warning">
                                        <small>
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            Pastikan saldo mencukupi sebelum melakukan penarikan.
                                        </small>
                                    </div>
                                    
                                    <button type="submit" name="proses_penarikan" class="btn btn-warning btn-lg w-100">
                                        <i class="fas fa-check-circle me-2"></i> Proses Penarikan
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Informasi & Riwayat -->
                    <div class="col-lg-4">
                        <!-- Informasi Saldo -->
                        <div class="card transaction-card mb-4">
                            <div class="card-header bg-info text-white">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-wallet me-2"></i>Informasi Saldo
                                </h6>
                            </div>
                            <div class="card-body">
                                <div id="detailSaldo">
                                    <p class="text-center text-muted">
                                        Pilih anggota dan jenis simpanan
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Penarikan Terbaru -->
                        <div class="card transaction-card">
                            <div class="card-header bg-danger text-white">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-history me-2"></i>Penarikan Terbaru
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($recent_penarikan)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($recent_penarikan as $penarikan): ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex w-100 justify-content-between">
                                                <small class="fw-bold"><?php echo $penarikan['nama_lengkap']; ?></small>
                                                <small class="text-danger"><?php echo formatRupiah($penarikan['jumlah']); ?></small>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo $penarikan['nama_jenis']; ?> • 
                                                <?php echo date('d/m H:i', strtotime($penarikan['tanggal_transaksi'])); ?>
                                            </small>
                                            <br>
                                            <small class="text-muted">
                                                Kode: <?php echo $penarikan['kode_transaksi']; ?>
                                            </small>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center">Belum ada penarikan</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update saldo info when anggota or jenis changes
        document.getElementById('anggotaSelect').addEventListener('change', updateSaldoInfo);
        document.getElementById('jenisSimpananSelect').addEventListener('change', updateSaldoInfo);

        function updateSaldoInfo() {
            const anggotaId = document.getElementById('anggotaSelect').value;
            const jenisId = document.getElementById('jenisSimpananSelect').value;
            const saldoInfo = document.getElementById('saldoInfo');
            const detailSaldo = document.getElementById('detailSaldo');
            const jumlahInput = document.getElementById('jumlahPenarikan');
            
            if (anggotaId && jenisId) {
                // Show loading
                saldoInfo.innerHTML = '<span class="text-warning">Memuat saldo...</span>';
                detailSaldo.innerHTML = `
                    <div class="text-center">
                        <div class="spinner-border spinner-border-sm me-2"></div>
                        <span>Memuat informasi saldo...</span>
                    </div>
                `;
                
                // AJAX call to get saldo
                fetch(`get_saldo.php?anggota_id=${anggotaId}&jenis_id=${jenisId}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            let saldoContent = '';
                            if (data.no_record) {
                                saldoContent = `
                                    <div class="text-center">
                                        <small class="text-muted">${data.kode_anggota} - ${data.nama_anggota}</small>
                                        <div class="saldo-display text-danger">${data.saldo_formatted}</div>
                                        <small class="text-muted">${data.jenis_simpanan}</small>
                                        <br>
                                        <small class="text-warning">
                                            <i class="fas fa-exclamation-triangle"></i> Tidak dapat melakukan penarikan - belum ada saldo
                                        </small>
                                    </div>
                                `;
                                // Disable jumlah input jika tidak ada saldo
                                jumlahInput.disabled = true;
                                jumlahInput.placeholder = 'Tidak ada saldo';
                                jumlahInput.value = '';
                            } else {
                                saldoContent = `
                                    <div class="text-center">
                                        <small class="text-muted">${data.kode_anggota} - ${data.nama_anggota}</small>
                                        <div class="saldo-display text-primary">${data.saldo_formatted}</div>
                                        <small class="text-muted">${data.jenis_simpanan}</small>
                                        <br>
                                        <small class="text-muted">Update: ${data.last_update}</small>
                                        <br>
                                        <small class="text-success">Maksimal penarikan: ${data.saldo_formatted}</small>
                                    </div>
                                `;
                                // Enable jumlah input dan set max value
                                jumlahInput.disabled = false;
                                jumlahInput.placeholder = 'Masukkan jumlah penarikan';
                                jumlahInput.max = data.saldo;
                                jumlahInput.min = 1000; // Minimum penarikan Rp 1,000
                            }
                            
                            saldoInfo.innerHTML = `Saldo tersedia: <strong>${data.saldo_formatted}</strong>`;
                            detailSaldo.innerHTML = saldoContent;
                            
                        } else {
                            saldoInfo.innerHTML = `<span class="text-danger">Error: ${data.message}</span>`;
                            detailSaldo.innerHTML = `
                                <div class="text-center text-danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <p>${data.message}</p>
                                </div>
                            `;
                            jumlahInput.disabled = false;
                            jumlahInput.placeholder = 'Masukkan jumlah penarikan';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        saldoInfo.innerHTML = '<span class="text-danger">Gagal memuat saldo</span>';
                        detailSaldo.innerHTML = `
                            <div class="text-center text-danger">
                                <i class="fas fa-exclamation-triangle"></i>
                                <p>Terjadi kesalahan saat memuat saldo</p>
                            </div>
                        `;
                        jumlahInput.disabled = false;
                        jumlahInput.placeholder = 'Masukkan jumlah penarikan';
                    });
            } else {
                saldoInfo.innerHTML = 'Pilih anggota dan jenis simpanan';
                detailSaldo.innerHTML = '<p class="text-center text-muted">Pilih anggota dan jenis simpanan</p>';
                jumlahInput.disabled = false;
                jumlahInput.placeholder = 'Masukkan jumlah penarikan';
                jumlahInput.value = '';
            }
        }

        // Validasi form sebelum submit
        document.getElementById('formPenarikan').addEventListener('submit', function(e) {
            const jumlah = parseInt(document.getElementById('jumlahPenarikan').value);
            const maxJumlah = parseInt(document.getElementById('jumlahPenarikan').max);
            const minJumlah = 1000; // Minimum penarikan Rp 1,000
            
            if (isNaN(jumlah) || jumlah <= 0) {
                e.preventDefault();
                alert('Jumlah penarikan harus lebih dari 0!');
                return false;
            }
            
            if (jumlah < minJumlah) {
                e.preventDefault();
                alert('Jumlah penarikan minimal Rp 1,000!');
                return false;
            }
            
            if (jumlah > maxJumlah) {
                e.preventDefault();
                alert('Jumlah penarikan melebihi saldo yang tersedia! Saldo tersedia: Rp ' + maxJumlah.toLocaleString('id-ID'));
                return false;
            }
            
            const keterangan = document.querySelector('input[name="keterangan"]').value;
            if (!keterangan.trim()) {
                e.preventDefault();
                alert('Keterangan penarikan harus diisi!');
                return false;
            }
            
            return true;
        });

        // Real-time validation untuk input jumlah
        document.getElementById('jumlahPenarikan').addEventListener('input', function() {
            const jumlah = parseInt(this.value);
            const maxJumlah = parseInt(this.max);
            const saldoInfo = document.getElementById('saldoInfo');
            
            if (!isNaN(jumlah) && jumlah > 0) {
                if (jumlah > maxJumlah) {
                    saldoInfo.innerHTML = `<span class="text-danger">Jumlah melebihi saldo tersedia!</span>`;
                } else if (jumlah < 1000) {
                    saldoInfo.innerHTML = `<span class="text-danger">Minimum penarikan Rp 1,000</span>`;
                } else {
                    saldoInfo.innerHTML = `Saldo tersedia: <strong>Rp ${maxJumlah.toLocaleString('id-ID')}</strong>`;
                }
            }
        });

        // Trigger update saldo info on page load if there are values
        document.addEventListener('DOMContentLoaded', function() {
            const anggotaSelect = document.getElementById('anggotaSelect');
            const jenisSelect = document.getElementById('jenisSimpananSelect');
            
            if (anggotaSelect.value && jenisSelect.value) {
                updateSaldoInfo();
            }
        });
    </script>
</body>
</html>
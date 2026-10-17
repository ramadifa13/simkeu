<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Handle form submission
if ($_POST && isset($_POST['proses_setoran'])) {
    $anggota_id = $_POST['anggota_id'];
    $jenis_simpanan_id = $_POST['jenis_simpanan_id'];
    $jumlah = $_POST['jumlah'];
    $keterangan = $_POST['keterangan'];
    
    try {
        $db->beginTransaction();
        
        // Get jenis simpanan info for validation
        $query_jenis = "SELECT minimum, maksimum, kode_jenis FROM jenis_simpanan WHERE id = :jenis_id AND status = 'aktif'";
        $stmt_jenis = $db->prepare($query_jenis);
        $stmt_jenis->bindParam(':jenis_id', $jenis_simpanan_id);
        $stmt_jenis->execute();
        $jenis_info = $stmt_jenis->fetch(PDO::FETCH_ASSOC);
        
        if (!$jenis_info) {
            throw new Exception("Jenis simpanan tidak valid atau tidak aktif");
        }
        
        // Cegah setoran ke jenis simpanan ANG (angsuran)
        if ($jenis_info['kode_jenis'] == 'ANG') {
            throw new Exception("Tidak dapat melakukan setoran ke rekening angsuran. Gunakan menu pembayaran angsuran di bagian teller.");
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
        $kode_transaksi = 'SET-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Get current balance
        $query_saldo = "SELECT saldo FROM simpanan WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_simpanan_id";
        $stmt_saldo = $db->prepare($query_saldo);
        $stmt_saldo->bindParam(':anggota_id', $anggota_id);
        $stmt_saldo->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
        $stmt_saldo->execute();
        
        if ($stmt_saldo->rowCount() > 0) {
            $saldo_sebelum = $stmt_saldo->fetch(PDO::FETCH_ASSOC)['saldo'];
        } else {
            $saldo_sebelum = 0;
        }
        
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
        $stmt_transaksi->bindParam(':user_id', $_SESSION['user_id']);
        $stmt_transaksi->execute();
        
        $transaksi_id = $db->lastInsertId();
        
        // Update or insert simpanan
        if ($saldo_sebelum > 0) {
            $query_update = "UPDATE simpanan SET saldo = :saldo, updated_at = NOW() 
                            WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_simpanan_id";
            $stmt_update = $db->prepare($query_update);
            $stmt_update->bindParam(':saldo', $saldo_sesudah);
            $stmt_update->bindParam(':anggota_id', $anggota_id);
            $stmt_update->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
            $stmt_update->execute();
        } else {
            $query_insert = "INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo) 
                            VALUES (:anggota_id, :jenis_simpanan_id, :saldo)";
            $stmt_insert = $db->prepare($query_insert);
            $stmt_insert->bindParam(':anggota_id', $anggota_id);
            $stmt_insert->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
            $stmt_insert->bindParam(':saldo', $saldo_sesudah);
            $stmt_insert->execute();
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
        $stmt_kas->execute();
        
        $db->commit();
        $_SESSION['success'] = "Setoran berhasil dilakukan. Kode Transaksi: " . $kode_transaksi;
        header("Location: setoran.php");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Gagal melakukan setoran: " . $e->getMessage();
    }
}

// Get data for dropdowns - HANYA jenis yang aktif dan BUKAN angsuran
$query_anggota = "SELECT * FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

// Ambil jenis simpanan kecuali yang berkode ANG (angsuran)
$query_jenis = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' AND kode_jenis != 'ANG' ORDER BY nama_jenis";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// Get recent setoran - HANYA setoran tabungan (bukan angsuran)
$query_recent = "SELECT t.*, a.nama_lengkap, j.nama_jenis, u.nama_lengkap as teller
                FROM transaksi t 
                JOIN anggota a ON t.anggota_id = a.id 
                JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                JOIN users u ON t.user_id = u.id
                WHERE t.jenis_transaksi = 'setoran' 
                AND j.kode_jenis != 'ANG'
                ORDER BY t.tanggal_transaksi DESC 
                LIMIT 10";
$stmt_recent = $db->prepare($query_recent);
$stmt_recent->execute();
$recent_setoran = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setoran Simpanan - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .transaction-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .amount-display {
            font-size: 1.5rem;
            font-weight: bold;
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
                        <i class="fas fa-plus-circle me-2"></i>Setoran Simpanan
                    </h1>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Form Setoran -->
                    <div class="col-lg-8 mb-4">
                        <div class="card transaction-card">
                            <div class="card-header bg-success text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-money-bill-wave me-2"></i>Form Setoran
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="formSetoran">
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
                                                    <option value="<?php echo $jenis['id']; ?>" 
                                                            data-minimum="<?php echo $jenis['minimum']; ?>"
                                                            data-maksimum="<?php echo $jenis['maksimum']; ?>">
                                                        <?php echo $jenis['nama_jenis']; ?>
                                                        <?php if ($jenis['minimum'] > 0): ?>
                                                            (Min: <?php echo formatRupiah($jenis['minimum']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted" id="minimumInfo"></small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Jumlah Setoran (Rp) *</label>
                                                <input type="number" name="jumlah" class="form-control" 
                                                       required step="1000" min="0" id="jumlahSetoran">
                                                <small class="text-muted" id="validationInfo"></small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Keterangan</label>
                                                <input type="text" name="keterangan" class="form-control" 
                                                       placeholder="Contoh: Setoran bulanan">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Informasi Setoran</label>
                                        <div class="alert alert-info">
                                            <div class="row text-center">
                                                <div class="col-6">
                                                    <small>Jumlah Setoran</small>
                                                    <div class="amount-display text-success" id="displayJumlah">Rp 0</div>
                                                </div>
                                                <div class="col-6">
                                                    <small>Ditangani oleh</small>
                                                    <div class="fw-bold"><?php echo $_SESSION['nama_lengkap']; ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" name="proses_setoran" class="btn btn-success btn-lg w-100">
                                        <i class="fas fa-check-circle me-2"></i> Proses Setoran
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Informasi & Riwayat -->
                    <div class="col-lg-4">
                        <!-- Informasi Saldo -->
                        <div class="card transaction-card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Informasi Saldo
                                </h6>
                            </div>
                            <div class="card-body">
                                <div id="saldoInfo">
                                    <p class="text-center text-muted">
                                        Pilih anggota dan jenis simpanan untuk melihat saldo
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Setoran Terbaru -->
                        <div class="card transaction-card">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-history me-2"></i>Setoran Terbaru
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($recent_setoran)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($recent_setoran as $setoran): ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex w-100 justify-content-between">
                                                <small class="fw-bold"><?php echo $setoran['nama_lengkap']; ?></small>
                                                <small class="text-success"><?php echo formatRupiah($setoran['jumlah']); ?></small>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo $setoran['nama_jenis']; ?> • 
                                                <?php echo date('d/m H:i', strtotime($setoran['tanggal_transaksi'])); ?>
                                            </small>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center">Belum ada setoran</p>
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
        // Update amount display
        document.getElementById('jumlahSetoran').addEventListener('input', function() {
            const amount = this.value ? parseInt(this.value) : 0;
            document.getElementById('displayJumlah').textContent = 'Rp ' + amount.toLocaleString('id-ID');
            validateAmount(amount);
        });

        // Update minimum info dan validasi
        document.getElementById('jenisSimpananSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const minimum = selectedOption.getAttribute('data-minimum');
            const maksimum = selectedOption.getAttribute('data-maksimum');
            const infoElement = document.getElementById('minimumInfo');
            const jumlahInput = document.getElementById('jumlahSetoran');
            
            let infoText = '';
            if (minimum > 0) {
                infoText += 'Minimum: Rp ' + parseInt(minimum).toLocaleString('id-ID');
            }
            if (maksimum > 0) {
                if (infoText) infoText += ' | ';
                infoText += 'Maksimum: Rp ' + parseInt(maksimum).toLocaleString('id-ID');
            }
            if (!infoText) {
                infoText = 'Tidak ada batasan setoran';
            }
            
            infoElement.textContent = infoText;
            
            // Set min dan max value untuk input
            if (minimum > 0) {
                jumlahInput.min = minimum;
            } else {
                jumlahInput.min = 0;
            }
            if (maksimum > 0) {
                jumlahInput.max = maksimum;
            } else {
                jumlahInput.max = '';
            }
            
            // Validasi ulang jumlah yang sudah diinput
            if (jumlahInput.value) {
                validateAmount(parseInt(jumlahInput.value));
            }
            
            updateSaldoInfo();
        });

        // Validasi jumlah setoran
        function validateAmount(amount) {
            const selectedOption = document.getElementById('jenisSimpananSelect').options[document.getElementById('jenisSimpananSelect').selectedIndex];
            const minimum = selectedOption ? parseInt(selectedOption.getAttribute('data-minimum')) : 0;
            const maksimum = selectedOption ? parseInt(selectedOption.getAttribute('data-maksimum')) : 0;
            const validationInfo = document.getElementById('validationInfo');
            
            if (minimum > 0 && amount < minimum) {
                validationInfo.innerHTML = '<span class="text-danger">Jumlah kurang dari minimum</span>';
            } else if (maksimum > 0 && amount > maksimum) {
                validationInfo.innerHTML = '<span class="text-danger">Jumlah melebihi maksimum</span>';
            } else if (amount > 0) {
                validationInfo.innerHTML = '<span class="text-success">Jumlah valid</span>';
            } else {
                validationInfo.innerHTML = '';
            }
        }

        // Update saldo info when anggota or jenis changes
        document.getElementById('anggotaSelect').addEventListener('change', updateSaldoInfo);
        document.getElementById('jenisSimpananSelect').addEventListener('change', updateSaldoInfo);

        function updateSaldoInfo() {
            const anggotaId = document.getElementById('anggotaSelect').value;
            const jenisId = document.getElementById('jenisSimpananSelect').value;
            const saldoInfo = document.getElementById('saldoInfo');
            
            if (anggotaId && jenisId) {
                // Show loading
                saldoInfo.innerHTML = `
                    <div class="text-center">
                        <div class="spinner-border spinner-border-sm me-2"></div>
                        <span>Memuat informasi saldo...</span>
                    </div>
                `;
                
                // AJAX call to get saldo
                fetch(`get_saldo.php?anggota_id=${anggotaId}&jenis_id=${jenisId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            saldoInfo.innerHTML = `
                                <div class="text-center">
                                    <small class="text-muted">Saldo Saat Ini</small>
                                    <div class="h5 text-primary fw-bold">${data.saldo_formatted}</div>
                                    <small class="text-muted">${data.jenis_simpanan}</small>
                                    <br>
                                    <small class="text-muted">Update: ${data.last_update}</small>
                                </div>
                            `;
                        } else {
                            saldoInfo.innerHTML = `
                                <div class="text-center text-danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <p>${data.message}</p>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        saldoInfo.innerHTML = `
                            <div class="text-center text-danger">
                                <i class="fas fa-exclamation-triangle"></i>
                                <p>Terjadi kesalahan saat memuat saldo</p>
                            </div>
                        `;
                    });
            } else {
                saldoInfo.innerHTML = '<p class="text-center text-muted">Pilih anggota dan jenis simpanan untuk melihat saldo</p>';
            }
        }

        // Form validation sebelum submit
        document.getElementById('formSetoran').addEventListener('submit', function(e) {
            const jumlah = parseInt(document.getElementById('jumlahSetoran').value);
            const selectedOption = document.getElementById('jenisSimpananSelect').options[document.getElementById('jenisSimpananSelect').selectedIndex];
            const minimum = selectedOption ? parseInt(selectedOption.getAttribute('data-minimum')) : 0;
            const maksimum = selectedOption ? parseInt(selectedOption.getAttribute('data-maksimum')) : 0;
            
            if (minimum > 0 && jumlah < minimum) {
                e.preventDefault();
                alert('Jumlah setoran harus minimal Rp ' + minimum.toLocaleString('id-ID'));
                return false;
            }
            
            if (maksimum > 0 && jumlah > maksimum) {
                e.preventDefault();
                alert('Jumlah setoran tidak boleh melebihi Rp ' + maksimum.toLocaleString('id-ID'));
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>
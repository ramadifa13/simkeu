<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Pastikan hanya teller yang bisa akses
if ($_SESSION['role'] !== 'teller') {
    header('Location: ../dashboard.php');
    exit();
}

// Handle form submission
if ($_POST && isset($_POST['proses_penarikan'])) {
    $anggota_id = $_POST['anggota_id'];
    $jenis_simpanan_id = $_POST['jenis_simpanan_id'];
    $jumlah = $_POST['jumlah'];
    $keterangan = $_POST['keterangan'];
    $user_id = $_SESSION['user_id'];
    
    try {
        $db->beginTransaction();
        
        // Check current balance from transaksi table (lebih akurat)
        $query_saldo = "SELECT COALESCE(SUM(CASE WHEN jenis_transaksi = 'setoran' THEN jumlah ELSE -jumlah END), 0) as saldo 
                       FROM transaksi 
                       WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_simpanan_id";
        $stmt_saldo = $db->prepare($query_saldo);
        $stmt_saldo->bindParam(':anggota_id', $anggota_id);
        $stmt_saldo->bindParam(':jenis_simpanan_id', $jenis_simpanan_id);
        $stmt_saldo->execute();
        $saldo_sebelum = $stmt_saldo->fetch(PDO::FETCH_ASSOC)['saldo'];
        
        // Check if balance is sufficient
        if ($saldo_sebelum < $jumlah) {
            throw new Exception("Saldo tidak mencukupi. Saldo tersedia: " . formatRupiah($saldo_sebelum));
        }
        
        $saldo_sesudah = $saldo_sebelum - $jumlah;
        
        // Generate transaction code
        // Maks. 20 karakter (= panjang kolom); format lama 22 karakter ditolak MySQL strict mode
        $kode_transaksi = 'TAR-' . date('ymdHis') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        
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
        $stmt_transaksi->bindParam(':user_id', $user_id);
        $stmt_transaksi->execute();
        
        $transaksi_id = $db->lastInsertId();
        
        // Update simpanan untuk sinkronisasi
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
        header("Location: penarikan.php");
        exit();
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
        .form-control:focus {
            border-color: #ffc107;
            box-shadow: 0 0 0 0.2rem rgba(255, 193, 7, 0.25);
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
                    
                    </a>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
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
                                                <select name="anggota_id" class="form-select" required id="anggotaSelect">
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
                                                <select name="jenis_simpanan_id" class="form-select" required id="jenisSimpananSelect">
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
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Informasi Penarikan</label>
                                        <div class="alert alert-warning">
                                            <div class="row text-center">
                                                <div class="col-6">
                                                    <small>Jumlah Penarikan</small>
                                                    <div class="saldo-display text-warning" id="displayJumlah">Rp 0</div>
                                                </div>
                                                <div class="col-6">
                                                    <small>Ditangani oleh</small>
                                                    <div class="fw-bold"><?php echo $_SESSION['nama_lengkap']; ?></div>
                                                    <small class="text-muted">Teller</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-warning">
                                        <small>
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            Pastikan saldo mencukupi sebelum melakukan penarikan. Penarikan tidak dapat dibatalkan.
                                        </small>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="proses_penarikan" class="btn btn-warning btn-lg">
                                            <i class="fas fa-check-circle me-2"></i> Proses Penarikan
                                        </button>
                                    </div>
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
                                    <div class="text-center py-3">
                                        <i class="fas fa-user fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0">Pilih anggota dan jenis simpanan</p>
                                    </div>
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
                            <div class="card-body p-0">
                                <?php if (!empty($recent_penarikan)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($recent_penarikan as $penarikan): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1"><?php echo $penarikan['nama_lengkap']; ?></h6>
                                                    <small class="text-muted">
                                                        <?php echo $penarikan['nama_jenis']; ?>
                                                    </small>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-user me-1"></i><?php echo $penarikan['teller']; ?>
                                                    </small>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-danger rounded-pill">
                                                        <?php echo formatRupiah($penarikan['jumlah']); ?>
                                                    </span>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo date('d/m H:i', strtotime($penarikan['tanggal_transaksi'])); ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <small class="text-muted">
                                                Kode: <?php echo $penarikan['kode_transaksi']; ?>
                                            </small>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0">Belum ada penarikan</p>
                                    </div>
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
        document.getElementById('jumlahPenarikan').addEventListener('input', function() {
            const amount = this.value ? parseInt(this.value) : 0;
            document.getElementById('displayJumlah').textContent = 'Rp ' + amount.toLocaleString('id-ID');
        });

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
                    <div class="text-center py-3">
                        <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                        <span class="text-muted">Memuat informasi saldo...</span>
                    </div>
                `;
                
                // Coba beberapa path yang mungkin untuk get_saldo.php
                const possiblePaths = [
                    'get_saldo.php',
                    '../admin/get_saldo.php',
                    './get_saldo.php'
                ];
                
                let currentPathIndex = 0;
                
                function tryNextPath() {
                    if (currentPathIndex >= possiblePaths.length) {
                        // Semua path gagal, tampilkan error
                        saldoInfo.innerHTML = '<span class="text-danger">Gagal memuat saldo</span>';
                        detailSaldo.innerHTML = `
                            <div class="text-center text-danger py-3">
                                <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                                <p class="mb-0">File get_saldo.php tidak ditemukan</p>
                            </div>
                        `;
                        jumlahInput.disabled = false;
                        return;
                    }
                    
                    const currentPath = possiblePaths[currentPathIndex];
                    
                    fetch(`${currentPath}?anggota_id=${anggotaId}&jenis_id=${jenisId}`)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.success) {
                                let saldoContent = '';
                                if (data.no_record || data.saldo == 0) {
                                    saldoContent = `
                                        <div class="text-center">
                                            <i class="fas fa-wallet fa-2x text-muted mb-2"></i>
                                            <small class="text-muted d-block">${data.kode_anggota}</small>
                                            <small class="text-muted d-block">${data.nama_anggota}</small>
                                            <hr class="my-2">
                                            <small class="text-muted d-block">Saldo Saat Ini</small>
                                            <div class="h4 text-danger fw-bold my-2">${data.saldo_formatted}</div>
                                            <small class="text-muted d-block">${data.jenis_simpanan}</small>
                                            <div class="mt-2">
                                                <span class="badge bg-danger">
                                                    <i class="fas fa-exclamation-triangle me-1"></i>Tidak ada saldo
                                                </span>
                                            </div>
                                        </div>
                                    `;
                                    // Disable jumlah input jika tidak ada saldo
                                    jumlahInput.disabled = true;
                                    jumlahInput.placeholder = 'Tidak ada saldo';
                                    jumlahInput.value = '';
                                } else {
                                    saldoContent = `
                                        <div class="text-center">
                                            <small class="text-muted d-block">${data.kode_anggota}</small>
                                            <small class="text-muted d-block">${data.nama_anggota}</small>
                                            <hr class="my-2">
                                            <small class="text-muted d-block">Saldo Saat Ini</small>
                                            <div class="h4 text-primary fw-bold my-2">${data.saldo_formatted}</div>
                                            <small class="text-muted d-block">${data.jenis_simpanan}</small>
                                            <small class="text-muted d-block">Update: ${data.last_update}</small>
                                            <div class="mt-2">
                                                <span class="badge bg-success">
                                                    <i class="fas fa-piggy-bank me-1"></i>Saldo tersedia
                                                </span>
                                            </div>
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
                                    <div class="text-center text-danger py-3">
                                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                                        <p class="mb-0">${data.message}</p>
                                    </div>
                                `;
                                jumlahInput.disabled = false;
                            }
                        })
                        .catch(error => {
                            console.error(`Error dengan path ${currentPath}:`, error);
                            currentPathIndex++;
                            tryNextPath();
                        });
                }
                
                // Mulai mencoba path pertama
                tryNextPath();
                
            } else {
                saldoInfo.innerHTML = 'Pilih anggota dan jenis simpanan';
                detailSaldo.innerHTML = `
                    <div class="text-center py-3">
                        <i class="fas fa-user fa-2x text-muted mb-2"></i>
                        <p class="text-muted mb-0">Pilih anggota dan jenis simpanan</p>
                    </div>
                `;
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

        // Auto-focus on first input
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('anggotaSelect').focus();
        });
    </script>
</body>
</html>
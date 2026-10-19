<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Pastikan hanya teller yang bisa akses
if ($_SESSION['role'] !== 'teller') {
    header('Location: ../dashboard.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_setoran'])) {
    
    $anggota_id = $_POST['anggota_id'] ?? '';
    $jenis_simpanan_id = $_POST['jenis_simpanan_id'] ?? '';
    $jumlah = $_POST['jumlah'] ?? 0;
    $keterangan = $_POST['keterangan'] ?? '';
    $user_id = $_SESSION['user_id'];
    
    // Validasi dasar
    if (empty($anggota_id) || empty($jenis_simpanan_id) || empty($jumlah) || $jumlah <= 0) {
        $_SESSION['error'] = "Data tidak lengkap. Pastikan semua field diisi dengan benar.";
        header("Location: setoran.php");
        exit();
    }
    
    try {
        $db->beginTransaction();
        if (!is_scalar($jumlah) || !preg_match('/^\d+(?:\.\d{1,2})?$/D', (string)$jumlah) || !is_finite((float)$jumlah) || (float)$jumlah <= 0 || (float)$jumlah > 999999999999) {
            throw new Exception('Nominal harus berupa angka positif yang valid.');
        }
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
        $_SESSION['success'] = "Setoran berhasil dilakukan. Kode Transaksi: " . $kode_transaksi;
        header("Location: setoran.php");
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

// Get recent setoran - HANYA setoran tabungan (bukan angsuran)
$query_recent = "SELECT t.*, a.nama_lengkap, j.nama_jenis, j.kode_jenis, u.nama_lengkap as teller
                FROM transaksi t 
                JOIN anggota a ON t.anggota_id = a.id 
                JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                JOIN users u ON t.user_id = u.id
                WHERE t.jenis_transaksi = 'setoran' 
                AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
                ORDER BY t.tanggal_transaksi DESC 
                LIMIT 10";
$stmt_recent = $db->prepare($query_recent);
$stmt_recent->execute();
$recent_setoran = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);

// Fungsi format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setoran Tabungan - <?php echo APP_NAME; ?></title>
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
        .sidebar {
            background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%) !important;
            min-height: calc(100vh - 56px);
        }
        main {
            background-color: #f8f9fa;
            min-height: calc(100vh - 56px);
        }
        .form-control:focus, .form-select:focus {
            border-color: #28a745;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
        }
        .saldo-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
            padding: 1rem;
        }
        .badge-tabungan {
            background-color: #28a745;
            color: white;
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 20px;
        }
        .tabungan-badge {
            background-color: #e8f5e9;
            color: #2e7d32;
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 12px;
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
                        <i class="fas fa-plus-circle me-2 text-success"></i>Setoran Tabungan
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <span class="badge-tabungan">
                            <i class="fas fa-piggy-bank me-1"></i>Tabungan Anggota
                        </span>
                    </div>
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
                    <!-- Form Setoran -->
                    <div class="col-lg-8 mb-4">
                        <div class="card transaction-card">
                            <div class="card-header bg-success text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-money-bill-wave me-2"></i>Form Setoran Tabungan
                                </h5>
                                <small class="text-white-50">Setoran untuk simpanan pokok, wajib, sukarela, dll.</small>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="formSetoran"><?php echo layananToken(); ?>
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
                                                <label class="form-label">Jenis Tabungan *</label>
                                                <select name="jenis_simpanan_id" class="form-select" required id="jenisSimpananSelect">
                                                    <option value="">-- Pilih Jenis Tabungan --</option>
                                                    <?php foreach ($jenis_simpanan as $jenis): ?>
                                                    <option value="<?php echo $jenis['id']; ?>" 
                                                            data-minimum="<?php echo $jenis['minimum']; ?>"
                                                            data-maksimum="<?php echo $jenis['maksimum']; ?>"
                                                            data-kode="<?php echo $jenis['kode_jenis']; ?>">
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
                                                       required step="1000" min="0" id="jumlahSetoran"
                                                       placeholder="Masukkan jumlah setoran">
                                                <small class="text-muted" id="validationInfo"></small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Keterangan</label>
                                                <input type="text" name="keterangan" class="form-control" 
                                                       placeholder="Contoh: Setoran bulanan, Setoran pokok, dll."
                                                       id="keterangan">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Informasi Setoran</label>
                                        <div class="alert alert-info">
                                            <div class="row text-center">
                                                <div class="col-4">
                                                    <small>Jenis</small>
                                                    <div class="fw-bold" id="infoJenis">-</div>
                                                </div>
                                                <div class="col-4">
                                                    <small>Jumlah Setoran</small>
                                                    <div class="amount-display text-success" id="displayJumlah">Rp 0</div>
                                                </div>
                                                <div class="col-4">
                                                    <small>Ditangani oleh</small>
                                                    <div class="fw-bold"><?php echo $_SESSION['nama_lengkap']; ?></div>
                                                    <small class="text-muted">Teller</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="proses_setoran" class="btn btn-success btn-lg" onclick="return confirmSubmit()">
                                            <i class="fas fa-check-circle me-2"></i> Proses Setoran
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
                            <div class="card-header bg-primary text-white">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Informasi Saldo Tabungan
                                </h6>
                            </div>
                            <div class="card-body">
                                <div id="saldoInfo">
                                    <div class="text-center py-3">
                                        <i class="fas fa-user fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0">Pilih anggota dan jenis tabungan</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Setoran Tabungan Terbaru -->
                        <div class="card transaction-card">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-history me-2"></i>Setoran Tabungan Terbaru
                                </h6>
                                <small class="text-muted">10 transaksi terakhir</small>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($recent_setoran)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($recent_setoran as $setoran): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1"><?php echo $setoran['nama_lengkap']; ?></h6>
                                                    <small class="text-muted">
                                                        <span class="tabungan-badge">
                                                            <?php echo $setoran['nama_jenis']; ?>
                                                        </span>
                                                    </small>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-user me-1"></i><?php echo $setoran['teller']; ?>
                                                    </small>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-success rounded-pill">
                                                        <?php echo formatRupiah($setoran['jumlah']); ?>
                                                    </span>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo date('d/m H:i', strtotime($setoran['tanggal_transaksi'])); ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <?php if (!empty($setoran['keterangan'])): ?>
                                            <small class="text-muted d-block mt-1">
                                                <i class="fas fa-comment me-1"></i><?php echo $setoran['keterangan']; ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0">Belum ada setoran tabungan</p>
                                        <small class="text-muted">Lakukan setoran pertama sekarang</small>
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
        // Fungsi untuk validasi dan konfirmasi sebelum submit
        function confirmSubmit() {
            // Validasi form terlebih dahulu
            const anggotaSelect = document.getElementById('anggotaSelect');
            const jenisSelect = document.getElementById('jenisSimpananSelect');
            const jumlahInput = document.getElementById('jumlahSetoran');
            
            if (!anggotaSelect.value) {
                alert('Pilih anggota terlebih dahulu');
                anggotaSelect.focus();
                return false;
            }
            
            if (!jenisSelect.value) {
                alert('Pilih jenis tabungan terlebih dahulu');
                jenisSelect.focus();
                return false;
            }
            
            const jumlah = parseInt(jumlahInput.value);
            if (!jumlah || jumlah <= 0) {
                alert('Jumlah setoran harus lebih dari 0');
                jumlahInput.focus();
                return false;
            }
            
            const selectedOption = jenisSelect.options[jenisSelect.selectedIndex];
            const minimum = parseInt(selectedOption.getAttribute('data-minimum')) || 0;
            const maksimum = parseInt(selectedOption.getAttribute('data-maksimum')) || 0;
            
            if (minimum > 0 && jumlah < minimum) {
                alert('Jumlah setoran harus minimal Rp ' + minimum.toLocaleString('id-ID'));
                jumlahInput.focus();
                return false;
            }
            
            if (maksimum > 0 && jumlah > maksimum) {
                alert('Jumlah setoran tidak boleh melebihi Rp ' + maksimum.toLocaleString('id-ID'));
                jumlahInput.focus();
                return false;
            }
            
            // Tampilkan konfirmasi
            const anggotaNama = anggotaSelect.options[anggotaSelect.selectedIndex].text;
            const jenisNama = selectedOption.text.split('(')[0].trim();
            const keterangan = document.getElementById('keterangan').value || '-';
            
            const confirmMessage = 
                'Apakah Anda yakin ingin memproses setoran ini?\n\n' +
                'Anggota: ' + anggotaNama + '\n' +
                'Jenis Tabungan: ' + jenisNama + '\n' +
                'Jumlah: Rp ' + jumlah.toLocaleString('id-ID') + '\n' +
                'Keterangan: ' + keterangan;
            
            return confirm(confirmMessage);
        }

        // Update amount display
        document.getElementById('jumlahSetoran').addEventListener('input', function() {
            const amount = this.value ? parseInt(this.value) : 0;
            document.getElementById('displayJumlah').textContent = 'Rp ' + amount.toLocaleString('id-ID');
            validateAmount(amount);
        });

        // Update info jenis
        document.getElementById('jenisSimpananSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.value) {
                document.getElementById('infoJenis').textContent = selectedOption.text.split('(')[0].trim();
            } else {
                document.getElementById('infoJenis').textContent = '-';
            }
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
            
            if (!selectedOption || !selectedOption.value) {
                validationInfo.innerHTML = '';
                return;
            }
            
            if (minimum > 0 && amount < minimum) {
                validationInfo.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Jumlah kurang dari minimum</span>';
            } else if (maksimum > 0 && amount > maksimum) {
                validationInfo.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Jumlah melebihi maksimum</span>';
            } else if (amount > 0) {
                validationInfo.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Jumlah valid</span>';
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
                        saldoInfo.innerHTML = `
                            <div class="text-center text-danger py-3">
                                <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                                <p class="mb-0">File get_saldo.php tidak ditemukan</p>
                                <small class="text-muted">Pastikan file get_saldo.php ada di folder teller atau admin</small>
                            </div>
                        `;
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
                                if (data.no_record) {
                                    saldoInfo.innerHTML = `
                                        <div class="text-center">
                                            <i class="fas fa-wallet fa-2x text-muted mb-2"></i>
                                            <small class="text-muted d-block">Saldo Saat Ini</small>
                                            <div class="h5 text-primary fw-bold my-2">Rp 0</div>
                                            <small class="text-muted d-block">Belum ada transaksi</small>
                                            <div class="mt-2">
                                                <span class="badge bg-light text-dark">
                                                    <i class="fas fa-info-circle me-1"></i>Tabungan Baru
                                                </span>
                                            </div>
                                        </div>
                                    `;
                                } else {
                                    saldoInfo.innerHTML = `
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
                                                    <i class="fas fa-piggy-bank me-1"></i>Aktif
                                                </span>
                                            </div>
                                        </div>
                                    `;
                                }
                            } else {
                                saldoInfo.innerHTML = `
                                    <div class="text-center text-danger py-3">
                                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                                        <p class="mb-0">${data.message}</p>
                                    </div>
                                `;
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
                saldoInfo.innerHTML = `
                    <div class="text-center py-3">
                        <i class="fas fa-user fa-2x text-muted mb-2"></i>
                        <p class="text-muted mb-0">Pilih anggota dan jenis tabungan</p>
                    </div>
                `;
            }
        }

        // Auto-focus on first input
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('anggotaSelect').focus();
        });
    </script>
</body>
</html>

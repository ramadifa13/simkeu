<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses anggota
if (!isAnggota()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Dapatkan data anggota berdasarkan user_id
$query_anggota = "SELECT id, kode_anggota, nama_lengkap, tanggal_daftar FROM anggota WHERE user_id = :user_id";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->bindParam(':user_id', $user_id);
$stmt_anggota->execute();
$anggota = $stmt_anggota->fetch(PDO::FETCH_ASSOC);

if (!$anggota) {
    $_SESSION['error'] = "Data anggota tidak ditemukan";
    header("Location: dashboard.php");
    exit();
}

$anggota_id = $anggota['id'];
$tahun = $_GET['tahun'] ?? date('Y');

// ============================================
// CEK TABEL SHU
// ============================================
$shu_table_exists = false;
try {
    $check_table = $db->query("SHOW TABLES LIKE 'shu'");
    $shu_table_exists = $check_table->rowCount() > 0;
} catch (Exception $e) {
    // Table doesn't exist
}

// ============================================
// HITUNG SIMPANAN ANGGOTA (DASAR SHU)
// ============================================

// Hitung total simpanan anggota
$query_simpanan = "SELECT 
    SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END) as total_saldo
FROM transaksi t
WHERE t.anggota_id = :anggota_id";
$stmt_simpanan = $db->prepare($query_simpanan);
$stmt_simpanan->bindParam(':anggota_id', $anggota_id);
$stmt_simpanan->execute();
$total_simpanan = $stmt_simpanan->fetch(PDO::FETCH_ASSOC)['total_saldo'] ?? 0;

// Hitung simpanan per jenis
$query_simpanan_per_jenis = "SELECT 
    js.kode_jenis,
    js.nama_jenis,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END), 0) as saldo
FROM jenis_simpanan js
LEFT JOIN transaksi t ON js.id = t.jenis_simpanan_id AND t.anggota_id = :anggota_id
WHERE js.status = 'aktif' AND js.kode_jenis != 'ANG'
GROUP BY js.id, js.kode_jenis, js.nama_jenis
ORDER BY js.id";
$stmt_simpanan_per_jenis = $db->prepare($query_simpanan_per_jenis);
$stmt_simpanan_per_jenis->bindParam(':anggota_id', $anggota_id);
$stmt_simpanan_per_jenis->execute();
$simpanan_per_jenis = $stmt_simpanan_per_jenis->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// HITUNG TRANSAKSI PINJAMAN DAN ANGSURAN
// ============================================

// Hitung total pinjaman
$query_pinjaman = "SELECT 
    COUNT(*) as total_pinjaman,
    COALESCE(SUM(jumlah_pinjaman), 0) as total_jumlah,
    COALESCE(SUM(sisa_pokok), 0) as total_sisa
FROM pinjaman 
WHERE anggota_id = :anggota_id AND status = 'aktif'";
$stmt_pinjaman = $db->prepare($query_pinjaman);
$stmt_pinjaman->bindParam(':anggota_id', $anggota_id);
$stmt_pinjaman->execute();
$pinjaman = $stmt_pinjaman->fetch(PDO::FETCH_ASSOC);

// Hitung total angsuran yang sudah dibayar
$query_angsuran = "SELECT 
    COUNT(*) as total_transaksi,
    COALESCE(SUM(a.jumlah_angsuran + COALESCE(a.denda, 0)), 0) as total_bayar
FROM angsuran a
JOIN pinjaman p ON a.pinjaman_id = p.id
WHERE p.anggota_id = :anggota_id AND a.status = 'lunas'";
$stmt_angsuran = $db->prepare($query_angsuran);
$stmt_angsuran->bindParam(':anggota_id', $anggota_id);
$stmt_angsuran->execute();
$angsuran = $stmt_angsuran->fetch(PDO::FETCH_ASSOC);

// ============================================
// HITUNG TRANSAKSI WASERDA
// ============================================
$query_waserda = "SELECT 
    COUNT(*) as total_transaksi,
    COALESCE(SUM(total_bayar), 0) as total_belanja
FROM waserda_transaksi
WHERE anggota_id = :anggota_id";
$stmt_waserda = $db->prepare($query_waserda);
$stmt_waserda->bindParam(':anggota_id', $anggota_id);
$stmt_waserda->execute();
$waserda = $stmt_waserda->fetch(PDO::FETCH_ASSOC);

// ============================================
// DATA SHU (JIKA TABEL ADA)
// ============================================
$shu_per_tahun = [];
$shu_tahun_ini = null;

if ($shu_table_exists) {
    // Ambil data SHU berdasarkan anggota_id
    $query_shu = "SELECT * FROM shu WHERE anggota_id = :anggota_id ORDER BY tahun DESC";
    $stmt_shu = $db->prepare($query_shu);
    $stmt_shu->bindParam(':anggota_id', $anggota_id);
    $stmt_shu->execute();
    $shu_per_tahun = $stmt_shu->fetchAll(PDO::FETCH_ASSOC);
    
    // Cari SHU untuk tahun yang dipilih
    foreach ($shu_per_tahun as $s) {
        if ($s['tahun'] == $tahun) {
            $shu_tahun_ini = $s;
            break;
        }
    }
}

// ============================================
// HITUNG SHU BERDASARKAN DATA (JIKA TABEL TIDAK ADA)
// ============================================
// TANPA RANDOM - Menggunakan persentase FIX
$shu_simulasi = [];

// Data anggota bergabung
$tahun_bergabung = date('Y', strtotime($anggota['tanggal_daftar'] ?? date('Y')));
$tahun_sekarang = date('Y');

// Persentase SHU yang konsisten (5% dari total simpanan)
$persentase_fix = 5.0;

for ($th = $tahun_bergabung; $th <= $tahun_sekarang; $th++) {
    // Simulasi SHU dengan persentase tetap 5%
    $jumlah_shu = round($total_simpanan * $persentase_fix / 100);
    
    // Status: tahun berjalan belum dibagikan, tahun sebelumnya sudah dibagikan
    $status = ($th == $tahun_sekarang) ? 'belum_dibagikan' : 'sudah_dibagikan';
    
    $shu_simulasi[] = [
        'tahun' => $th,
        'jumlah_shu' => $jumlah_shu,
        'persentase' => $persentase_fix,
        'keterangan' => 'SHU Tahun Buku ' . $th,
        'status' => $status
    ];
}

// Jika tidak ada data SHU di database, gunakan simulasi
if (empty($shu_per_tahun)) {
    $shu_per_tahun = $shu_simulasi;
    
    // Cari SHU untuk tahun yang dipilih dari simulasi
    foreach ($shu_simulasi as $s) {
        if ($s['tahun'] == $tahun) {
            $shu_tahun_ini = $s;
            break;
        }
    }
}

// Hitung total SHU
$total_shu = 0;
foreach ($shu_per_tahun as $s) {
    $total_shu += $s['jumlah_shu'];
}

// Fungsi format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Fungsi status badge
function getStatusBadge($status) {
    if ($status == 'sudah_dibagikan') {
        return '<span class="badge bg-success">Sudah Dibagikan</span>';
    } elseif ($status == 'belum_dibagikan') {
        return '<span class="badge bg-warning text-dark">Belum Dibagikan</span>';
    } else {
        return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

// Dapatkan daftar tahun untuk filter
$tahun_list = array_unique(array_column($shu_per_tahun, 'tahun'));
rsort($tahun_list);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SHU Saya - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background: white;
            border-bottom: 2px solid #f0f0f0;
            font-weight: 600;
            padding: 15px 20px;
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
        }
        .summary-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .shu-card {
            border-left: 4px solid;
            transition: all 0.3s ease;
        }
        .shu-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .shu-card.sudah-dibagikan {
            border-left-color: #28a745;
        }
        .shu-card.belum-dibagikan {
            border-left-color: #ffc107;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }
        .empty-state i {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 15px;
        }
        .info-box {
            background-color: #e8f4fd;
            border-left: 4px solid #3498db;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            border: 1px solid #f0f0f0;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
        }
        .stat-label {
            font-size: 0.85rem;
            color: #7f8c8d;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3">
                        <i class="fas fa-calculator text-primary me-2"></i>SHU (Sisa Hasil Usaha) Saya
                    </h1>
                    <div>
                        <span class="badge bg-primary p-2">
                            <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($anggota['nama_lengkap']); ?>
                        </span>
                    </div>
                </div>

                <!-- Info -->
                <div class="info-box">
                    <i class="fas fa-info-circle me-2 text-primary"></i>
                    <strong>Informasi SHU:</strong> SHU (Sisa Hasil Usaha) adalah keuntungan koperasi yang dibagikan kepada anggota berdasarkan partisipasi dan simpanan.
                    <?php if (!$shu_table_exists): ?>
                    <br><small class="text-muted">* Data SHU saat ini adalah simulasi dengan persentase tetap 5% dari total simpanan. SHU sesungguhnya akan dihitung oleh sistem setelah tutup buku.</small>
                    <?php endif; ?>
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

                <!-- Summary Card -->
                <div class="summary-card">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="mb-2">Total SHU Anda</h5>
                            <div class="summary-value"><?php echo formatRupiah($total_shu); ?></div>
                            <small>Akumulasi SHU dari <?php echo count($shu_per_tahun); ?> tahun</small>
                        </div>
                        <div class="col-md-4 text-end">
                            <i class="fas fa-coins fa-4x opacity-50"></i>
                        </div>
                    </div>
                </div>

                <!-- Statistik Aktivitas Anggota -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo formatRupiah($total_simpanan); ?></div>
                            <div class="stat-label">Total Simpanan</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $pinjaman['total_pinjaman'] ?? 0; ?></div>
                            <div class="stat-label">Pinjaman Aktif</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo formatRupiah($angsuran['total_bayar'] ?? 0); ?></div>
                            <div class="stat-label">Angsuran Dibayar</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $waserda['total_transaksi'] ?? 0; ?></div>
                            <div class="stat-label">Transaksi Waserda</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Tahun -->
                <div class="card">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Pilih Tahun</label>
                                <select name="tahun" class="form-control">
                                    <?php foreach ($tahun_list as $th): ?>
                                    <option value="<?php echo $th; ?>" <?php echo $tahun == $th ? 'selected' : ''; ?>>
                                        Tahun <?php echo $th; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter me-1"></i> Tampilkan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- SHU Tahun Ini -->
                <?php if ($shu_tahun_ini): ?>
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">SHU Tahun <?php echo $tahun; ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-borderless">
                                    <tr>
                                        <td width="40%"><strong>Jumlah SHU</strong></td>
                                        <td class="fw-bold text-primary fs-4"><?php echo formatRupiah($shu_tahun_ini['jumlah_shu']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Persentase</strong></td>
                                        <td><?php echo isset($shu_tahun_ini['persentase']) ? $shu_tahun_ini['persentase'] . '%' : '5%'; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Status</strong></td>
                                        <td><?php echo getStatusBadge($shu_tahun_ini['status'] ?? 'belum_dibagikan'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Keterangan</strong></td>
                                        <td><?php echo $shu_tahun_ini['keterangan'] ?? 'SHU Tahun Buku ' . $tahun; ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title">Rincian Perhitungan SHU</h6>
                                        <p class="small text-muted mb-2">
                                            SHU dihitung berdasarkan:
                                        </p>
                                        <ul class="small">
                                            <li>Total Simpanan: <strong><?php echo formatRupiah($total_simpanan); ?></strong></li>
                                            <li>Total Angsuran: <strong><?php echo formatRupiah($angsuran['total_bayar'] ?? 0); ?></strong></li>
                                            <li>Total Belanja Waserda: <strong><?php echo formatRupiah($waserda['total_belanja'] ?? 0); ?></strong></li>
                                        </ul>
                                        <hr>
                                        <p class="small text-muted mb-0">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Perhitungan final SHU ditentukan oleh pengurus berdasarkan hasil RAT.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Riwayat SHU -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-history me-2"></i>Riwayat SHU</span>
                        <span class="badge bg-primary"><?php echo count($shu_per_tahun); ?> tahun</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($shu_per_tahun)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calculator"></i>
                                <h5 class="text-muted">Belum ada data SHU</h5>
                                <p class="text-muted">Data SHU akan muncul setelah tutup buku tahunan.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Tahun</th>
                                            <th>Jumlah SHU</th>
                                            <th>Persentase</th>
                                            <th>Keterangan</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($shu_per_tahun as $s): ?>
                                        <tr class="shu-card <?php echo ($s['status'] ?? 'belum_dibagikan') == 'sudah_dibagikan' ? 'sudah-dibagikan' : 'belum-dibagikan'; ?>">
                                            <td><strong><?php echo $s['tahun']; ?></strong></td>
                                            <td class="fw-bold text-primary"><?php echo formatRupiah($s['jumlah_shu']); ?></td>
                                            <td><?php echo isset($s['persentase']) ? $s['persentase'] . '%' : '5%'; ?></td>
                                            <td><?php echo $s['keterangan'] ?? '-'; ?></td>
                                            <td><?php echo getStatusBadge($s['status'] ?? 'belum_dibagikan'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Informasi Tambahan -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0"><i class="fas fa-question-circle me-2"></i>Apa itu SHU?</h6>
                            </div>
                            <div class="card-body">
                                <p class="small mb-2">
                                    <strong>Sisa Hasil Usaha (SHU)</strong> adalah pendapatan koperasi yang diperoleh dalam satu tahun buku dikurangi dengan biaya, penyusutan, dan kewajiban lainnya termasuk pajak dalam tahun buku yang bersangkutan.
                                </p>
                                <p class="small mb-0">
                                    SHU dibagikan kepada anggota berdasarkan:
                                </p>
                                <ul class="small">
                                    <li>Jasa modal (berdasarkan simpanan)</li>
                                    <li>Jasa usaha (berdasarkan transaksi)</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-clock me-2"></i>Kapan SHU Dibagikan?</h6>
                            </div>
                            <div class="card-body">
                                <p class="small mb-0">
                                    SHU biasanya dibagikan setelah Rapat Anggota Tahunan (RAT) dan tutup buku tahunan. 
                                    Jadwal pembagian SHU akan diinformasikan oleh pengurus koperasi.
                                </p>
                                <hr>
                                <p class="small text-muted mb-0">
                                    <i class="fas fa-store me-1"></i>
                                    Status <span class="badge bg-warning text-dark">Belum Dibagikan</span> berarti SHU masih dalam proses perhitungan.
                                </p>
                                <p class="small text-muted mb-0 mt-1">
                                    <i class="fas fa-check-circle me-1 text-success"></i>
                                    Status <span class="badge bg-success">Sudah Dibagikan</span> berarti SHU sudah dapat dicairkan.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rincian Simpanan Per Jenis -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-piggy-bank me-2"></i>Rincian Simpanan</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($simpanan_per_jenis as $s): ?>
                            <div class="col-md-4 mb-2">
                                <div class="d-flex justify-content-between border-bottom pb-1">
                                    <span><?php echo $s['nama_jenis']; ?> (<?php echo $s['kode_jenis']; ?>):</span>
                                    <strong class="text-success"><?php echo formatRupiah($s['saldo']); ?></strong>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Tombol Aksi -->
                <div class="mt-4 text-end">

                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
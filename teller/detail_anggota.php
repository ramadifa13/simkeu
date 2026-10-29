<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Pastikan hanya teller yang bisa akses
if ($_SESSION['role'] != 'teller') {
    $_SESSION['error'] = "Akses ditolak!";
    header("Location: ../admin/anggota.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "ID anggota tidak valid!";
    header("Location: anggota.php");
    exit();
}

$id = $_GET['id'];

// Get member data with ALL savings information
$query = "SELECT a.*, u.username
          FROM anggota a 
          LEFT JOIN users u ON a.user_id = u.id 
          WHERE a.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    $_SESSION['error'] = "Anggota tidak ditemukan!";
    header("Location: anggota.php");
    exit();
}

// Get ALL savings data with calculation from transactions
$savings_query = "SELECT 
    js.id,
    js.nama_jenis,
    js.kode_jenis,
    COALESCE(s.saldo, 0) as saldo_simpanan,
    COALESCE((
        SELECT COALESCE(SUM(CASE WHEN jenis_transaksi = 'setoran' THEN jumlah ELSE -jumlah END), 0)
        FROM transaksi t 
        WHERE t.anggota_id = :id AND t.jenis_simpanan_id = js.id
    ), 0) as saldo_transaksi
FROM jenis_simpanan js
LEFT JOIN simpanan s ON js.id = s.jenis_simpanan_id AND s.anggota_id = :id
WHERE js.status = 'aktif'
ORDER BY js.id";
$stmt_savings = $db->prepare($savings_query);
$stmt_savings->bindParam(':id', $id);
$stmt_savings->execute();
$savings_data = $stmt_savings->fetchAll(PDO::FETCH_ASSOC);

// Sinkronisasi dan update saldo yang tidak sesuai
foreach ($savings_data as $saving) {
    if ($saving['saldo_simpanan'] != $saving['saldo_transaksi']) {
        // Update saldo di tabel simpanan
        $update_query = "INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo, updated_at) 
                        VALUES (:anggota_id, :jenis_id, :saldo, NOW())
                        ON DUPLICATE KEY UPDATE saldo = :saldo_update, updated_at = NOW()";
        $stmt_update = $db->prepare($update_query);
        $stmt_update->bindValue(':anggota_id', $id, PDO::PARAM_INT);
        $stmt_update->bindValue(':jenis_id', $saving['id'], PDO::PARAM_INT);
        $stmt_update->bindValue(':saldo', $saving['saldo_transaksi']);
        $stmt_update->bindValue(':saldo_update', $saving['saldo_transaksi']);
        $stmt_update->execute();
    }
}

// Get updated savings data after synchronization
$final_savings_query = "SELECT 
    js.id,
    js.nama_jenis,
    js.kode_jenis,
    COALESCE(s.saldo, 0) as saldo
FROM jenis_simpanan js
LEFT JOIN simpanan s ON js.id = s.jenis_simpanan_id AND s.anggota_id = :id
WHERE js.status = 'aktif'
ORDER BY js.id";
$stmt_final = $db->prepare($final_savings_query);
$stmt_final->bindParam(':id', $id);
$stmt_final->execute();
$final_savings = $stmt_final->fetchAll(PDO::FETCH_ASSOC);

// Organize savings by type
$simpanan_pokok = 0;
$simpanan_wajib = 0;
$simpanan_sukarela = 0;
$simpanan_lainnya = [];

foreach ($final_savings as $saving) {
    switch ($saving['kode_jenis']) {
        case 'POKOK':
            $simpanan_pokok = $saving['saldo'];
            break;
        case 'WAJIB':
            $simpanan_wajib = $saving['saldo'];
            break;
        case 'SUKARELA':
            $simpanan_sukarela = $saving['saldo'];
            break;
        default:
            $simpanan_lainnya[] = [
                'nama' => $saving['nama_jenis'],
                'saldo' => $saving['saldo']
            ];
            break;
    }
}

$total_simpanan = $simpanan_pokok + $simpanan_wajib + $simpanan_sukarela + 
                  array_sum(array_column($simpanan_lainnya, 'saldo'));

// Get loan summary - FIXED query
$loan_query = "SELECT 
    COUNT(*) as total_pinjaman, 
    COALESCE(SUM(jumlah_pinjaman), 0) as total_jumlah,
    COALESCE(SUM(sisa_pokok), 0) as total_sisa
FROM pinjaman 
WHERE anggota_id = :id AND status = 'aktif'";
$stmt_loan = $db->prepare($loan_query);
$stmt_loan->bindParam(':id', $id);
$stmt_loan->execute();
$loan_summary = $stmt_loan->fetch(PDO::FETCH_ASSOC);

// Get recent transactions
$recent_transactions_query = "SELECT 
    t.kode_transaksi,
    t.jenis_transaksi,
    t.jumlah,
    t.tanggal_transaksi,
    js.nama_jenis,
    u.nama_lengkap as teller
FROM transaksi t
JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id
JOIN users u ON t.user_id = u.id
WHERE t.anggota_id = :id
ORDER BY t.tanggal_transaksi DESC
LIMIT 5";
$stmt_transactions = $db->prepare($recent_transactions_query);
$stmt_transactions->bindParam(':id', $id);
$stmt_transactions->execute();
$recent_transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

// Get active loans
$active_loans_query = "SELECT 
    p.kode_pinjaman,
    p.jumlah_pinjaman,
    p.sisa_pokok,
    p.tanggal_pencairan,
    jp.nama_jenis
FROM pinjaman p
JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
WHERE p.anggota_id = :id AND p.status = 'aktif'
ORDER BY p.tanggal_pencairan DESC";
$stmt_loans = $db->prepare($active_loans_query);
$stmt_loans->bindParam(':id', $id);
$stmt_loans->execute();
$active_loans = $stmt_loans->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Anggota - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .member-avatar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        .savings-card {
            border-left: 4px solid #28a745;
        }
        .loan-card {
            border-left: 4px solid #ffc107;
        }
        .transaction-positive {
            border-left: 3px solid #28a745;
        }
        .transaction-negative {
            border-left: 3px solid #dc3545;
        }
    </style>
</head>
<body style="background-color: #f8f9fa;">
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Page Header -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-user text-primary me-2"></i>Detail Anggota
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="anggota.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Kembali
                        </a>
                    </div>
                </div>

                <!-- Member Profile -->
                <div class="row">
                    <div class="col-lg-4">
                        <div class="card mb-4">
                            <div class="card-body text-center">
                                <div class="member-avatar mx-auto mb-3" style="width: 80px; height: 80px; font-size: 2rem;">
                                    <?php echo strtoupper(substr($member['nama_lengkap'], 0, 1)); ?>
                                </div>
                                <h5 class="card-title"><?php echo htmlspecialchars($member['nama_lengkap']); ?></h5>
                                <p class="text-muted mb-1"><?php echo $member['kode_anggota']; ?></p>
                                <span class="badge bg-success"><?php echo ucfirst($member['status']); ?></span>
                                
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar me-1"></i>
                                        Bergabung sejak <?php echo date('d/m/Y', strtotime($member['tanggal_daftar'])); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <?php include __DIR__ . '/../includes/kartu_dokumen_anggota.php'; ?>
                        
                        <!-- Savings Summary -->
                        <div class="card mb-4 savings-card">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-piggy-bank me-2"></i>Ringkasan Simpanan</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-2 d-flex justify-content-between">
                                    <span>Simpanan Pokok:</span>
                                    <strong class="text-primary"><?php echo formatRupiah($simpanan_pokok); ?></strong>
                                </div>
                                <div class="mb-2 d-flex justify-content-between">
                                    <span>Simpanan Wajib:</span>
                                    <strong class="text-primary"><?php echo formatRupiah($simpanan_wajib); ?></strong>
                                </div>
                                <div class="mb-2 d-flex justify-content-between">
                                    <span>Simpanan Sukarela:</span>
                                    <strong class="text-primary"><?php echo formatRupiah($simpanan_sukarela); ?></strong>
                                </div>
                                
                                <?php foreach ($simpanan_lainnya as $lainnya): ?>
                                <div class="mb-2 d-flex justify-content-between">
                                    <span><?php echo $lainnya['nama']; ?>:</span>
                                    <strong class="text-info"><?php echo formatRupiah($lainnya['saldo']); ?></strong>
                                </div>
                                <?php endforeach; ?>
                                
                                <hr>
                                <div class="d-flex justify-content-between fw-bold">
                                    <span>Total Simpanan:</span>
                                    <span class="text-success"><?php echo formatRupiah($total_simpanan); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Loan Summary -->
                        <div class="card loan-card">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Ringkasan Pinjaman</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-2 d-flex justify-content-between">
                                    <span>Total Pinjaman Aktif:</span>
                                    <strong><?php echo $loan_summary['total_pinjaman']; ?></strong>
                                </div>
                                <div class="mb-2 d-flex justify-content-between">
                                    <span>Total Jumlah:</span>
                                    <strong class="text-warning"><?php echo formatRupiah($loan_summary['total_jumlah']); ?></strong>
                                </div>
                                <div class="mb-2 d-flex justify-content-between">
                                    <span>Total Sisa:</span>
                                    <strong class="text-danger"><?php echo formatRupiah($loan_summary['total_sisa']); ?></strong>
                                </div>
                                
                                <?php if ($loan_summary['total_pinjaman'] > 0): ?>
                                <hr>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    <?php echo $loan_summary['total_pinjaman']; ?> pinjaman aktif
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-8">
                        <!-- Personal Information -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Informasi Pribadi</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-borderless">
                                            <tr>
                                                <td width="40%"><strong>Nama Lengkap</strong></td>
                                                <td><?php echo htmlspecialchars($member['nama_lengkap']); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Jenis Kelamin</strong></td>
                                                <td><?php echo $member['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Tempat Lahir</strong></td>
                                                <td><?php echo $member['tempat_lahir'] ?: '-'; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Pekerjaan</strong></td>
                                                <td><?php echo $member['pekerjaan'] ?: '-'; ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-borderless">
                                            <tr>
                                                <td width="40%"><strong>Tanggal Lahir</strong></td>
                                                <td><?php echo $member['tanggal_lahir'] ? date('d/m/Y', strtotime($member['tanggal_lahir'])) : '-'; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Telepon</strong></td>
                                                <td><?php echo $member['telepon'] ?: '-'; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Email</strong></td>
                                                <td><?php echo $member['email'] ?: '-'; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Username</strong></td>
                                                <td><?php echo $member['username']; ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-12">
                                        <strong>Alamat:</strong>
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($member['alamat'])); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Transactions -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-exchange-alt me-2"></i>Transaksi Terakhir</h6>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($recent_transactions)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($recent_transactions as $transaction): ?>
                                        <div class="list-group-item px-0 <?php echo $transaction['jenis_transaksi'] == 'setoran' ? 'transaction-positive' : 'transaction-negative'; ?>">
                                            <div class="d-flex w-100 justify-content-between">
                                                <div>
                                                    <small class="fw-bold"><?php echo $transaction['kode_transaksi']; ?></small>
                                                    <br>
                                                    <small class="text-muted"><?php echo $transaction['nama_jenis']; ?></small>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-user me-1"></i><?php echo $transaction['teller']; ?>
                                                    </small>
                                                </div>
                                                <div class="text-end">
                                                    <small class="<?php echo $transaction['jenis_transaksi'] == 'setoran' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                                        <?php echo $transaction['jenis_transaksi'] == 'setoran' ? '+' : '-'; ?>
                                                        <?php echo formatRupiah($transaction['jumlah']); ?>
                                                    </small>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo date('d/m/Y H:i', strtotime($transaction['tanggal_transaksi'])); ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center mb-0">Belum ada transaksi</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Active Loans -->
                        <?php if (!empty($active_loans)): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-list me-2"></i>Pinjaman Aktif</h6>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <?php foreach ($active_loans as $loan): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex w-100 justify-content-between">
                                            <div>
                                                <small class="fw-bold"><?php echo $loan['kode_pinjaman']; ?></small>
                                                <br>
                                                <small class="text-muted"><?php echo $loan['nama_jenis']; ?></small>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-warning fw-bold">
                                                    <?php echo formatRupiah($loan['jumlah_pinjaman']); ?>
                                                </small>
                                                <br>
                                                <small class="text-danger">
                                                    Sisa: <?php echo formatRupiah($loan['sisa_pokok']); ?>
                                                </small>
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            Cair: <?php echo date('d/m/Y', strtotime($loan['tanggal_pencairan'])); ?>
                                        </small>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Quick Actions -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-bolt me-2"></i>Aksi Cepat</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3 mb-2">
                                        <a href="setoran.php?anggota_id=<?php echo $id; ?>" class="btn btn-success btn-sm w-100">
                                            <i class="fas fa-plus me-1"></i>Setor Simpanan
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <a href="pengajuan.php?anggota_id=<?php echo $id; ?>" class="btn btn-warning btn-sm w-100">
                                            <i class="fas fa-hand-holding-usd me-1"></i>Ajukan Pinjaman
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <a href="penarikan.php?anggota_id=<?php echo $id; ?>" class="btn btn-info btn-sm w-100">
                                            <i class="fas fa-minus me-1"></i>Penarikan Simpanan
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <a href="laporan_anggota.php?id=<?php echo $id; ?>" class="btn btn-secondary btn-sm w-100">
                                            <i class="fas fa-print me-1"></i>Cetak Laporan
                                        </a>
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
    <?php include __DIR__ . '/../includes/modal_dokumen.php'; ?>
</body>
</html>
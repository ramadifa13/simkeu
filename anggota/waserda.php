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
$query_anggota = "SELECT id FROM anggota WHERE user_id = :user_id";
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

// ============================================
// AMBIL DATA TRANSAKSI WASERDA ANGGOTA
// ============================================
$query_transaksi = "SELECT 
    w.*,
    u.nama_lengkap as petugas
FROM waserda_transaksi w
LEFT JOIN users u ON w.user_id = u.id
WHERE w.anggota_id = :anggota_id
ORDER BY w.created_at DESC";

$stmt_transaksi = $db->prepare($query_transaksi);
$stmt_transaksi->bindParam(':anggota_id', $anggota_id);
$stmt_transaksi->execute();
$transaksi = $stmt_transaksi->fetchAll(PDO::FETCH_ASSOC);

// Hitung total pembelian
$total_pembelian = 0;
foreach ($transaksi as $t) {
    $total_pembelian += $t['total_bayar'];
}

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
    <title>Transaksi Waserda Saya - <?php echo APP_NAME; ?></title>
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
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .summary-value {
            font-size: 2rem;
            font-weight: 700;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .badge-waserda {
            background-color: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
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
        .detail-item {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 5px 10px;
            margin: 2px 0;
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
                        <i class="fas fa-store me-2 text-success"></i>Transaksi Waserda Saya
                    </h1>
                    <div>
                        <span class="badge bg-success p-2">
                            <i class="fas fa-shopping-cart me-1"></i> Total: <?php echo count($transaksi); ?> transaksi
                        </span>
                    </div>
                </div>

                <!-- Info -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Halaman ini menampilkan riwayat pembelian Anda di Waserda. Anda tidak dapat melakukan transaksi di sini.
                </div>

                <!-- Summary Card -->
                <div class="summary-card">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="mb-2">Total Pembelian Anda</h5>
                            <div class="summary-value"><?php echo formatRupiah($total_pembelian); ?></div>
                            <small><?php echo count($transaksi); ?> transaksi</small>
                        </div>
                        <div class="col-md-4 text-end">
                            <i class="fas fa-store fa-4x opacity-50"></i>
                        </div>
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

                <!-- Tabel Transaksi -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-history me-2"></i>Riwayat Pembelian Waserda</span>
                        <span class="badge bg-primary"><?php echo count($transaksi); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($transaksi)): ?>
                            <div class="empty-state">
                                <i class="fas fa-store"></i>
                                <h5 class="text-muted">Belum ada transaksi waserda</h5>
                                <p class="text-muted">Anda belum melakukan pembelian di waserda.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Kode Transaksi</th>
                                            <th>Item</th>
                                            <th>Total Bayar</th>
                                            <th>Metode</th>
                                            <th>Petugas</th>
                                            <th>Detail</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transaksi as $t): 
                                            $items = json_decode($t['items'], true);
                                            $jumlah_item = count($items);
                                            $first_item = $items[0]['nama'] ?? 'Barang';
                                        ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                                            <td><small><?php echo $t['kode_transaksi']; ?></small></td>
                                            <td>
                                                <?php echo $first_item; ?>
                                                <?php if ($jumlah_item > 1): ?>
                                                    <br><small class="text-muted">+<?php echo $jumlah_item - 1; ?> item lainnya</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold text-success"><?php echo formatRupiah($t['total_bayar']); ?></td>
                                            <td><span class="badge bg-info"><?php echo ucfirst($t['metode_bayar']); ?></span></td>
                                            <td><?php echo $t['petugas'] ?? '-'; ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-info" onclick="showDetail(<?php echo htmlspecialchars(json_encode($items)); ?>, '<?php echo $t['kode_transaksi']; ?>', <?php echo $t['total_bayar']; ?>)">
                                                    <i class="fas fa-eye"></i> Detail
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Modal Detail Item -->
                <div class="modal fade" id="detailModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-shopping-cart me-2"></i>Detail Pembelian
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p class="fw-bold" id="modalKodeTransaksi"></p>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Nama Barang</th>
                                                <th class="text-end">Harga</th>
                                                <th class="text-center">Jumlah</th>
                                                <th class="text-end">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody id="modalItems">
                                        </tbody>
                                        <tfoot id="modalTotal">
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Catatan -->
                <div class="alert alert-light mt-3">
                    <i class="fas fa-info-circle me-2 text-success"></i>
                    <small>
                        Untuk melakukan pembelian di waserda, silakan datang langsung ke kantor koperasi.
                        Halaman ini hanya menampilkan riwayat transaksi Anda.
                    </small>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showDetail(items, kode, total) {
            const modalItems = document.getElementById('modalItems');
            const modalTotal = document.getElementById('modalTotal');
            document.getElementById('modalKodeTransaksi').textContent = 'Kode: ' + kode;
            
            let html = '';
            
            items.forEach((item, index) => {
                const subtotal = item.harga * item.jumlah;
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${item.nama}</td>
                        <td class="text-end">Rp ${item.harga.toLocaleString('id-ID')}</td>
                        <td class="text-center">${item.jumlah}</td>
                        <td class="text-end">Rp ${subtotal.toLocaleString('id-ID')}</td>
                    </tr>
                `;
            });
            
            modalItems.innerHTML = html;
            modalTotal.innerHTML = `
                <tr>
                    <th colspan="4" class="text-end">TOTAL</th>
                    <th class="text-end text-success">Rp ${total.toLocaleString('id-ID')}</th>
                </tr>
            `;
            
            var detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
            detailModal.show();
        }
    </script>
</body>
</html>
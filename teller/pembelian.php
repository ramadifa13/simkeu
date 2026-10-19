<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses teller
if (!isTeller()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

// ============================================
// BUAT TABEL WASERDA JIKA BELUM ADA
// ============================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS waserda_transaksi (
        id INT PRIMARY KEY AUTO_INCREMENT,
        kode_transaksi VARCHAR(20) UNIQUE NOT NULL,
        anggota_id INT NOT NULL,
        items TEXT NOT NULL, -- JSON array of items
        total_bayar DECIMAL(15,2) NOT NULL,
        metode_bayar ENUM('tunai', 'transfer') NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (anggota_id) REFERENCES anggota(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1");
} catch (Exception $e) {
    // Table might already exist
}

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
if ($_POST && isset($_POST['proses_waserda'])) {
    $anggota_id = $_POST['anggota_id'];
    $metode_bayar = $_POST['metode_bayar'];
    $items = [];
    $total_bayar = 0;
    
    // Proses items dari form
    $nama_barang = $_POST['nama_barang'] ?? [];
    $harga = $_POST['harga'] ?? [];
    $jumlah = $_POST['jumlah'] ?? [];
    
    for ($i = 0; $i < count($nama_barang); $i++) {
        if (!empty($nama_barang[$i]) && !empty($harga[$i]) && !empty($jumlah[$i])) {
            $subtotal = $harga[$i] * $jumlah[$i];
            $items[] = [
                'nama' => $nama_barang[$i],
                'harga' => (float)$harga[$i],
                'jumlah' => (float)$jumlah[$i],
                'subtotal' => $subtotal
            ];
            $total_bayar += $subtotal;
        }
    }
    
    if (empty($items)) {
        $_SESSION['error'] = "Minimal satu barang harus diisi";
        header("Location: pembelian.php");
        exit();
    }
    
    // Generate transaction code
    $kode_transaksi = 'WSD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    try {
        $db->beginTransaction();
        
        // 1. Insert ke tabel waserda_transaksi
        $items_json = json_encode($items);
        $query = "INSERT INTO waserda_transaksi (kode_transaksi, anggota_id, items, total_bayar, metode_bayar, user_id) 
                  VALUES (:kode_transaksi, :anggota_id, :items, :total_bayar, :metode_bayar, :user_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':kode_transaksi', $kode_transaksi);
        $stmt->bindParam(':anggota_id', $anggota_id);
        $stmt->bindParam(':items', $items_json);
        $stmt->bindParam(':total_bayar', $total_bayar);
        $stmt->bindParam(':metode_bayar', $metode_bayar);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        
        $last_id = $db->lastInsertId();
        
        // 2. Dapatkan saldo kas terakhir
        $query_saldo = "SELECT saldo_sesudah FROM kas ORDER BY id DESC LIMIT 1";
        $stmt_saldo = $db->prepare($query_saldo);
        $stmt_saldo->execute();
        $saldo_terakhir = $stmt_saldo->fetch(PDO::FETCH_ASSOC);
        $saldo_sebelum = $saldo_terakhir ? $saldo_terakhir['saldo_sesudah'] : 0;
        $saldo_sesudah = $saldo_sebelum + $total_bayar;
        
        // 3. Insert ke tabel kas (sebagai PEMASUKAN)
        $keterangan = 'Pembelian Waserda - ' . $kode_transaksi . ' (' . count($items) . ' item)';
        $query_kas = "INSERT INTO kas (transaksi_id, jenis, jumlah, saldo_sebelum, saldo_sesudah, keterangan) 
                     VALUES (:transaksi_id, 'masuk', :jumlah, :saldo_sebelum, :saldo_sesudah, :keterangan)";
        $stmt_kas = $db->prepare($query_kas);
        $stmt_kas->bindParam(':transaksi_id', $last_id);
        $stmt_kas->bindParam(':jumlah', $total_bayar);
        $stmt_kas->bindParam(':saldo_sebelum', $saldo_sebelum);
        $stmt_kas->bindParam(':saldo_sesudah', $saldo_sesudah);
        $stmt_kas->bindParam(':keterangan', $keterangan);
        $stmt_kas->execute();
        
        $db->commit();
        $_SESSION['success'] = "Transaksi Waserda berhasil. Kode: " . $kode_transaksi;
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Gagal memproses transaksi: " . $e->getMessage();
    }
    
    header("Location: pembelian.php");
    exit();
}

// ============================================
// HANDLE DELETE TRANSAKSI (HANYA UNTUK ADMIN)
// ============================================
// Teller tidak bisa menghapus transaksi, hanya admin yang bisa
if (isset($_GET['delete'])) {
    $_SESSION['error'] = "Anda tidak memiliki izin untuk menghapus transaksi";
    header("Location: pembelian.php");
    exit();
}

// ============================================
// GET DATA FOR DROPDOWNS
// ============================================
$query_anggota = "SELECT * FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

// Get recent waserda transactions (hanya untuk viewing, tanpa aksi hapus)
$query_recent = "SELECT w.*, a.nama_lengkap, a.kode_anggota, u.nama_lengkap as petugas
                 FROM waserda_transaksi w
                 JOIN anggota a ON w.anggota_id = a.id
                 JOIN users u ON w.user_id = u.id
                 ORDER BY w.created_at DESC
                 LIMIT 20";
$stmt_recent = $db->prepare($query_recent);
$stmt_recent->execute();
$recent_transaksi = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);

// Hitung total pemasukan dari waserda
$total_waserda = array_sum(array_column($recent_transaksi, 'total_bayar'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waserda - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #28a745;
            color: white;
            font-weight: 600;
            padding: 12px 16px;
        }
        .card-body {
            padding: 16px;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .item-row {
            background-color: #f8f9fa;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }
        .btn-add-item {
            background-color: #28a745;
            color: white;
        }
        .btn-add-item:hover {
            background-color: #218838;
            color: white;
        }
        .btn-remove-item {
            background-color: #dc3545;
            color: white;
        }
        .btn-remove-item:hover {
            background-color: #c82333;
            color: white;
        }
        .total-box {
            background-color: #28a745;
            color: white;
            padding: 15px;
            border-radius: 8px;
            font-size: 1.2rem;
            font-weight: bold;
        }
        .info-waserda {
            background-color: #e8f5e9;
            border-left: 4px solid #28a745;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .summary-box {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            background-color: #f8f9fa;
        }
        .summary-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: #28a745;
        }
        .item-detail {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        .btn-detail {
            background-color: #17a2b8;
            color: white;
            border: none;
        }
        .btn-detail:hover {
            background-color: #138496;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3">
                        <i class="fas fa-store me-2 text-success"></i>Waserda (Warung Serba Ada)
                    </h1>
                    <div>
                        <span class="badge bg-success p-2">
                            <i class="fas fa-shopping-cart me-1"></i> Total Transaksi: <?php echo count($recent_transaksi); ?>
                        </span>
                    </div>
                </div>

                <!-- Info Waserda -->
                <div class="info-waserda">
                    <i class="fas fa-info-circle me-2 text-success"></i>
                    <strong>Waserda (Warung Serba Ada)</strong> - Layanan pembelian barang untuk anggota. 
                    Setiap transaksi akan tercatat sebagai <strong class="text-success">PEMASUKAN KAS</strong> (uang masuk dari anggota).
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

                <!-- Summary -->
                <?php if ($total_waserda > 0): ?>
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="summary-box">
                            <div class="text-muted">Total Pemasukan dari Waserda</div>
                            <div class="summary-value">Rp <?php echo number_format($total_waserda, 0, ',', '.'); ?></div>
                            <small><?php echo count($recent_transaksi); ?> transaksi</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="summary-box">
                            <div class="text-muted">Rata-rata per Transaksi</div>
                            <div class="summary-value">
                                Rp <?php echo number_format($total_waserda / max(1, count($recent_transaksi)), 0, ',', '.'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Form Transaksi Waserda -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-cash-register me-2"></i>Form Transaksi Waserda
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formWaserda"><?php echo layananToken(); ?>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Pilih Anggota <span class="text-danger">*</span></label>
                                    <select name="anggota_id" class="form-control" required>
                                        <option value="">-- Pilih Anggota --</option>
                                        <?php foreach ($anggota as $a): ?>
                                        <option value="<?php echo $a['id']; ?>">
                                            <?php echo $a['kode_anggota'] . ' - ' . $a['nama_lengkap']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Metode Bayar <span class="text-danger">*</span></label>
                                    <select name="metode_bayar" class="form-control" required>
                                        <option value="tunai">Tunai</option>
                                        <option value="transfer">Transfer</option>
                                    </select>
                                </div>
                            </div>

                            <h6 class="mb-3">Daftar Barang</h6>
                            <div id="items-container">
                                <!-- Item 1 (default) -->
                                <div class="item-row" id="item-1">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <label class="form-label">Nama Barang</label>
                                            <input type="text" name="nama_barang[]" class="form-control" placeholder="Contoh: Beras 5kg" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Harga Satuan (Rp)</label>
                                            <input type="number" name="harga[]" class="form-control harga-item" step="100" min="0" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Jumlah</label>
                                            <input type="number" name="jumlah[]" class="form-control jumlah-item" step="0.01" min="0.01" value="1" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Subtotal</label>
                                            <input type="text" class="form-control subtotal-item" readonly>
                                        </div>
                                        <div class="col-md-1 d-flex align-items-end">
                                            <button type="button" class="btn btn-remove-item btn-sm" onclick="removeItem(1)" style="display: none;">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <button type="button" class="btn btn-add-item" onclick="addItem()">
                                        <i class="fas fa-plus-circle me-1"></i> Tambah Barang
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <div class="total-box text-end">
                                        Total Bayar: Rp <span id="totalBayar">0</span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <button type="submit" name="proses_waserda" class="btn btn-success btn-lg w-100">
                                    <i class="fas fa-check-circle me-2"></i> Proses Transaksi Waserda
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Riwayat Transaksi (TANPA TOMBOL DELETE) -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-history me-2"></i>Riwayat Transaksi Waserda
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_transaksi)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-store fa-3x text-muted mb-3"></i>
                                <p class="text-muted">Belum ada transaksi waserda</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Kode</th>
                                            <th>Anggota</th>
                                            <th>Jumlah Item</th>
                                            <th>Total Bayar</th>
                                            <th>Metode</th>
                                            <th>Petugas</th>
                                            <th>Detail</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_transaksi as $t): 
                                            $items = json_decode($t['items'], true);
                                            $jumlah_item = count($items);
                                        ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                                            <td><small><?php echo $t['kode_transaksi']; ?></small></td>
                                            <td>
                                                <?php echo $t['nama_lengkap']; ?>
                                                <br><small class="text-muted"><?php echo $t['kode_anggota']; ?></small>
                                            </td>
                                            <td class="text-center"><?php echo $jumlah_item; ?> item</td>
                                            <td class="fw-bold text-success">Rp <?php echo number_format($t['total_bayar'], 0, ',', '.'); ?></td>
                                            <td><span class="badge bg-info"><?php echo ucfirst($t['metode_bayar']); ?></span></td>
                                            <td><?php echo $t['petugas']; ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-detail" onclick="showDetail(<?php echo htmlspecialchars(json_encode($items)); ?>, '<?php echo $t['kode_transaksi']; ?>', <?php echo $t['total_bayar']; ?>)">
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
                            <div class="modal-header bg-info text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-shopping-cart me-2"></i>Detail Transaksi
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

                <!-- Catatan Penting -->
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Catatan:</strong> 
                    <ul class="mb-0 mt-1">
                        <li>Setiap transaksi waserda akan tercatat sebagai <strong class="text-success">PEMASUKAN KAS</strong>.</li>
                        <li>Anda dapat menambahkan beberapa item dalam satu transaksi.</li>
                        <li>Subtotal akan otomatis dihitung berdasarkan harga dan jumlah.</li>
                        <li>Hanya admin yang dapat menghapus transaksi.</li>
                    </ul>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemCount = 1;

        // Fungsi untuk menambah item baru
        function addItem() {
            itemCount++;
            const container = document.getElementById('items-container');
            const newItem = document.createElement('div');
            newItem.className = 'item-row';
            newItem.id = 'item-' + itemCount;
            newItem.innerHTML = `
                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label">Nama Barang</label>
                        <input type="text" name="nama_barang[]" class="form-control" placeholder="Contoh: Beras 5kg" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Harga Satuan (Rp)</label>
                        <input type="number" name="harga[]" class="form-control harga-item" step="100" min="0" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Jumlah</label>
                        <input type="number" name="jumlah[]" class="form-control jumlah-item" step="0.01" min="0.01" value="1" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Subtotal</label>
                        <input type="text" class="form-control subtotal-item" readonly>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="button" class="btn btn-remove-item btn-sm" onclick="removeItem(${itemCount})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(newItem);
            
            // Tambahkan event listener untuk item baru
            attachItemEvents(newItem);
            updateTotal();
        }

        // Fungsi untuk menghapus item
        function removeItem(id) {
            if (itemCount > 1) {
                const item = document.getElementById('item-' + id);
                item.remove();
                updateTotal();
            }
        }

        // Fungsi untuk attach event listener ke item
        function attachItemEvents(item) {
            const harga = item.querySelector('.harga-item');
            const jumlah = item.querySelector('.jumlah-item');
            const subtotal = item.querySelector('.subtotal-item');
            
            function calculateSubtotal() {
                const h = parseFloat(harga.value) || 0;
                const j = parseFloat(jumlah.value) || 0;
                const total = h * j;
                subtotal.value = 'Rp ' + total.toLocaleString('id-ID');
                updateTotal();
            }
            
            harga.addEventListener('input', calculateSubtotal);
            jumlah.addEventListener('input', calculateSubtotal);
        }

        // Fungsi untuk update total keseluruhan
        function updateTotal() {
            const subtotals = document.querySelectorAll('.subtotal-item');
            let total = 0;
            
            subtotals.forEach(sub => {
                const value = sub.value.replace(/[^0-9]/g, '');
                total += parseInt(value) || 0;
            });
            
            document.getElementById('totalBayar').textContent = total.toLocaleString('id-ID');
        }

        // Attach events untuk item pertama
        document.addEventListener('DOMContentLoaded', function() {
            attachItemEvents(document.getElementById('item-1'));
        });

        // Fungsi untuk menampilkan detail transaksi
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
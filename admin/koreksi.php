<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Handle form actions
if ($_POST) {
    if (isset($_POST['koreksi'])) {
        $transaksi_id = $_POST['transaksi_id'];
        $jumlah_baru = $_POST['jumlah_baru'];
        $keterangan_koreksi = $_POST['keterangan_koreksi'];
        
        try {
            $db->beginTransaction();
            
            // Get original transaction data
            $query_transaksi = "SELECT * FROM transaksi WHERE id = :id";
            $stmt_transaksi = $db->prepare($query_transaksi);
            $stmt_transaksi->bindParam(':id', $transaksi_id);
            $stmt_transaksi->execute();
            $transaksi = $stmt_transaksi->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaksi) {
                throw new Exception("Transaksi tidak ditemukan");
            }
            
            $selisih = $jumlah_baru - $transaksi['jumlah'];
            
            // Update transaction
            $query_update = "UPDATE transaksi SET jumlah = :jumlah, saldo_sesudah = saldo_sesudah + :selisih, 
                            keterangan = CONCAT(keterangan, ' | Koreksi: ', :keterangan_koreksi)
                            WHERE id = :id";
            $stmt_update = $db->prepare($query_update);
            $stmt_update->bindParam(':jumlah', $jumlah_baru);
            $stmt_update->bindParam(':selisih', $selisih);
            $stmt_update->bindParam(':keterangan_koreksi', $keterangan_koreksi);
            $stmt_update->bindParam(':id', $transaksi_id);
            $stmt_update->execute();
            
            // Update simpanan
            $query_simpanan = "UPDATE simpanan SET saldo = saldo + :selisih, updated_at = NOW()
                              WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_simpanan_id";
            $stmt_simpanan = $db->prepare($query_simpanan);
            $stmt_simpanan->bindParam(':selisih', $selisih);
            $stmt_simpanan->bindParam(':anggota_id', $transaksi['anggota_id']);
            $stmt_simpanan->bindParam(':jenis_simpanan_id', $transaksi['jenis_simpanan_id']);
            $stmt_simpanan->execute();
            
            // Update kas
            $jenis_kas = $transaksi['jenis_transaksi'] == 'setoran' ? 'masuk' : 'keluar';
            $query_kas = "UPDATE kas SET jumlah = jumlah + :selisih, saldo_sesudah = saldo_sesudah + :selisih
                         WHERE transaksi_id = :transaksi_id";
            $stmt_kas = $db->prepare($query_kas);
            $stmt_kas->bindParam(':selisih', $selisih);
            $stmt_kas->bindParam(':transaksi_id', $transaksi_id);
            $stmt_kas->execute();
            
            // Log koreksi
            $query_log = "INSERT INTO log_koreksi (transaksi_id, jumlah_lama, jumlah_baru, selisih, 
                         keterangan, user_id) 
                         VALUES (:transaksi_id, :jumlah_lama, :jumlah_baru, :selisih, 
                         :keterangan, :user_id)";
            $stmt_log = $db->prepare($query_log);
            $stmt_log->bindParam(':transaksi_id', $transaksi_id);
            $stmt_log->bindParam(':jumlah_lama', $transaksi['jumlah']);
            $stmt_log->bindParam(':jumlah_baru', $jumlah_baru);
            $stmt_log->bindParam(':selisih', $selisih);
            $stmt_log->bindParam(':keterangan', $keterangan_koreksi);
            $stmt_log->bindParam(':user_id', $_SESSION['user_id']);
            $stmt_log->execute();
            
            $db->commit();
            $_SESSION['success'] = "Koreksi transaksi berhasil dilakukan";
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Gagal melakukan koreksi: " . $e->getMessage();
        }
    }
}

// Get transactions for correction
$query_transaksi = "SELECT t.*, a.nama_lengkap, j.nama_jenis, u.nama_lengkap as teller
                   FROM transaksi t 
                   JOIN anggota a ON t.anggota_id = a.id 
                   JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                   JOIN users u ON t.user_id = u.id
                   ORDER BY t.tanggal_transaksi DESC 
                   LIMIT 50";
$stmt_transaksi = $db->prepare($query_transaksi);
$stmt_transaksi->execute();
$transaksi_list = $stmt_transaksi->fetchAll(PDO::FETCH_ASSOC);

// Create log_koreksi table if not exists
$db->exec("CREATE TABLE IF NOT EXISTS log_koreksi (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaksi_id INT NOT NULL,
    jumlah_lama DECIMAL(15,2) NOT NULL,
    jumlah_baru DECIMAL(15,2) NOT NULL,
    selisih DECIMAL(15,2) NOT NULL,
    keterangan TEXT,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaksi_id) REFERENCES transaksi(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
)");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Koreksi Transaksi - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-edit me-2"></i>Koreksi Transaksi
                    </h1>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Perhatian:</strong> Koreksi transaksi akan mempengaruhi saldo simpanan dan kas. 
                    Pastikan data yang dikoreksi sudah benar.
                </div>

                <!-- Daftar Transaksi -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>Daftar Transaksi Terbaru
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Tanggal</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th>Tipe</th>
                                        <th>Jumlah</th>
                                        <th>Keterangan</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transaksi_list as $transaksi): ?>
                                    <tr>
                                        <td>
                                            <small class="fw-bold"><?php echo $transaksi['kode_transaksi']; ?></small>
                                        </td>
                                        <td>
                                            <small><?php echo date('d/m/Y', strtotime($transaksi['tanggal_transaksi'])); ?></small>
                                            <br>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($transaksi['tanggal_transaksi'])); ?></small>
                                        </td>
                                        <td><?php echo $transaksi['nama_lengkap']; ?></td>
                                        <td><?php echo $transaksi['nama_jenis']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($transaksi['jenis_transaksi']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo formatRupiah($transaksi['jumlah']); ?></td>
                                        <td>
                                            <small><?php echo $transaksi['keterangan']; ?></small>
                                            <br>
                                            <small class="text-muted">Oleh: <?php echo $transaksi['teller']; ?></small>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" 
                                                    data-bs-target="#koreksiModal" 
                                                    data-id="<?php echo $transaksi['id']; ?>"
                                                    data-kode="<?php echo $transaksi['kode_transaksi']; ?>"
                                                    data-jumlah="<?php echo $transaksi['jumlah']; ?>"
                                                    data-jenis="<?php echo $transaksi['jenis_transaksi']; ?>"
                                                    data-anggota="<?php echo $transaksi['nama_lengkap']; ?>">
                                                <i class="fas fa-edit"></i> Koreksi
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Koreksi Modal -->
    <div class="modal fade" id="koreksiModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Koreksi Transaksi</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="transaksi_id" id="koreksi_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Kode Transaksi</label>
                            <input type="text" id="koreksi_kode" class="form-control" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Anggota</label>
                            <input type="text" id="koreksi_anggota" class="form-control" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Jenis Transaksi</label>
                            <input type="text" id="koreksi_jenis" class="form-control" readonly>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Jumlah Lama</label>
                                    <input type="text" id="koreksi_jumlah_lama" class="form-control" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Jumlah Baru *</label>
                                    <input type="number" name="jumlah_baru" class="form-control" 
                                           required step="1000" min="0" id="koreksi_jumlah_baru">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Selisih</label>
                            <input type="text" id="koreksi_selisih" class="form-control" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Keterangan Koreksi *</label>
                            <textarea name="keterangan_koreksi" class="form-control" rows="3" required
                                      placeholder="Alasan koreksi transaksi"></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                Koreksi akan mempengaruhi saldo simpanan dan kas secara otomatis.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="koreksi" class="btn btn-warning">Proses Koreksi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var koreksiModal = document.getElementById('koreksiModal');
        koreksiModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('koreksi_id').value = button.getAttribute('data-id');
            document.getElementById('koreksi_kode').value = button.getAttribute('data-kode');
            document.getElementById('koreksi_anggota').value = button.getAttribute('data-anggota');
            document.getElementById('koreksi_jenis').value = button.getAttribute('data-jenis');
            
            const jumlahLama = parseFloat(button.getAttribute('data-jumlah'));
            document.getElementById('koreksi_jumlah_lama').value = 'Rp ' + jumlahLama.toLocaleString('id-ID');
            document.getElementById('koreksi_jumlah_baru').value = jumlahLama;
            
            // Calculate selisih
            updateSelisih();
        });

        // Update selisih when jumlah baru changes
        document.getElementById('koreksi_jumlah_baru').addEventListener('input', updateSelisih);

        function updateSelisih() {
            const jumlahLama = parseFloat(document.getElementById('koreksi_jumlah_lama').value.replace(/[^\d]/g, '')) || 0;
            const jumlahBaru = parseFloat(document.getElementById('koreksi_jumlah_baru').value) || 0;
            const selisih = jumlahBaru - jumlahLama;
            
            const selisihElement = document.getElementById('koreksi_selisih');
            selisihElement.value = 'Rp ' + Math.abs(selisih).toLocaleString('id-ID');
            
            if (selisih > 0) {
                selisihElement.className = 'form-control text-success';
            } else if (selisih < 0) {
                selisihElement.className = 'form-control text-danger';
            } else {
                selisihElement.className = 'form-control';
            }
        }
    </script>
</body>
</html>
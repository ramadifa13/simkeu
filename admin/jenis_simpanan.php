<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Handle form actions
if ($_POST) {
    if (isset($_POST['add'])) {
        $kode_jenis = $_POST['kode_jenis'];
        $nama_jenis = $_POST['nama_jenis'];
        $deskripsi = $_POST['deskripsi'];
        $minimum = $_POST['minimum'] ?? 0;
        $maksimum = $_POST['maksimum'] ?? 0;
        $bagi_hasil = $_POST['bagi_hasil'] ?? 0;
        
        try {
            // Check if kode jenis already exists
            $check_query = "SELECT id FROM jenis_simpanan WHERE kode_jenis = :kode_jenis";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':kode_jenis', $kode_jenis);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Kode jenis sudah digunakan");
            }
            
            $query = "INSERT INTO jenis_simpanan (kode_jenis, nama_jenis, deskripsi, minimum, maksimum, bagi_hasil) 
                      VALUES (:kode_jenis, :nama_jenis, :deskripsi, :minimum, :maksimum, :bagi_hasil)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':kode_jenis', $kode_jenis);
            $stmt->bindParam(':nama_jenis', $nama_jenis);
            $stmt->bindParam(':deskripsi', $deskripsi);
            $stmt->bindParam(':minimum', $minimum);
            $stmt->bindParam(':maksimum', $maksimum);
            $stmt->bindParam(':bagi_hasil', $bagi_hasil);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Jenis simpanan berhasil ditambahkan";
            } else {
                throw new Exception("Gagal menambahkan jenis simpanan");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        
        header("Location: jenis_simpanan.php");
        exit();
    }
    
    if (isset($_POST['edit'])) {
        $id = $_POST['id'];
        $kode_jenis = $_POST['kode_jenis'];
        $nama_jenis = $_POST['nama_jenis'];
        $deskripsi = $_POST['deskripsi'];
        $minimum = $_POST['minimum'] ?? 0;
        $maksimum = $_POST['maksimum'] ?? 0;
        $bagi_hasil = $_POST['bagi_hasil'] ?? 0;
        $status = $_POST['status'];
        
        try {
            // Check if kode jenis already exists (excluding current record)
            $check_query = "SELECT id FROM jenis_simpanan WHERE kode_jenis = :kode_jenis AND id != :id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':kode_jenis', $kode_jenis);
            $check_stmt->bindParam(':id', $id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Kode jenis sudah digunakan");
            }
        
            $query = "UPDATE jenis_simpanan SET kode_jenis = :kode_jenis, nama_jenis = :nama_jenis, 
                      deskripsi = :deskripsi, minimum = :minimum, maksimum = :maksimum, 
                      bagi_hasil = :bagi_hasil, status = :status WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':kode_jenis', $kode_jenis);
            $stmt->bindParam(':nama_jenis', $nama_jenis);
            $stmt->bindParam(':deskripsi', $deskripsi);
            $stmt->bindParam(':minimum', $minimum);
            $stmt->bindParam(':maksimum', $maksimum);
            $stmt->bindParam(':bagi_hasil', $bagi_hasil);
            $stmt->bindParam(':status', $status);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Jenis simpanan berhasil diupdate";
            } else {
                throw new Exception("Gagal mengupdate jenis simpanan");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        
        header("Location: jenis_simpanan.php");
        exit();
    }
    
    // Handle delete action
    if (isset($_POST['delete'])) {
        $id = $_POST['id'];
        
        try {
            // Check if this jenis simpanan is being used in simpanan table
            $check_query = "SELECT COUNT(*) as total FROM simpanan WHERE jenis_simpanan_id = :id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':id', $id);
            $check_stmt->execute();
            $used = $check_stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($used > 0) {
                throw new Exception("Tidak dapat menghapus jenis simpanan karena masih digunakan oleh $used data simpanan");
            }
            
            // Check if this jenis simpanan is being used in transaksi table
            $check_transaksi_query = "SELECT COUNT(*) as total FROM transaksi WHERE jenis_simpanan_id = :id";
            $check_transaksi_stmt = $db->prepare($check_transaksi_query);
            $check_transaksi_stmt->bindParam(':id', $id);
            $check_transaksi_stmt->execute();
            $used_in_transaksi = $check_transaksi_stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($used_in_transaksi > 0) {
                throw new Exception("Tidak dapat menghapus jenis simpanan karena masih digunakan oleh $used_in_transaksi data transaksi");
            }
            
            $query = "DELETE FROM jenis_simpanan WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Jenis simpanan berhasil dihapus";
            } else {
                throw new Exception("Gagal menghapus jenis simpanan");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        
        header("Location: jenis_simpanan.php");
        exit();
    }
}

// Get all jenis simpanan
$query = "SELECT * FROM jenis_simpanan ORDER BY 
          CASE WHEN status = 'aktif' THEN 1 ELSE 2 END, 
          nama_jenis";
$stmt = $db->prepare($query);
$stmt->execute();
$jenis_simpanan = $stmt->fetchAll(PDO::FETCH_ASSOC);

// PERBAIKAN: Hitung statistik dengan cara yang kompatibel
$total_jenis = count($jenis_simpanan);
$aktif_count = 0;
$nonaktif_count = 0;
$minimum_count = 0;

foreach ($jenis_simpanan as $jenis) {
    if ($jenis['status'] == 'aktif') {
        $aktif_count++;
    } else {
        $nonaktif_count++;
    }
    if ($jenis['minimum'] > 0) {
        $minimum_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jenis Simpanan - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .table-actions {
            white-space: nowrap;
        }
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn-delete {
            color: #dc3545;
            border-color: #dc3545;
        }
        .btn-delete:hover {
            background-color: #dc3545;
            color: white;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-list me-2"></i>Jenis Simpanan
                    </h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="fas fa-plus"></i> Tambah Jenis
                    </button>
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

                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="mb-0"><?php echo $total_jenis; ?></h4>
                                        <p class="mb-0">Total Jenis</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-list fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="mb-0"><?php echo $aktif_count; ?></h4>
                                        <p class="mb-0">Aktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="mb-0"><?php echo $nonaktif_count; ?></h4>
                                        <p class="mb-0">Nonaktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-times-circle fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="mb-0"><?php echo $minimum_count; ?></h4>
                                        <p class="mb-0">Ada Minimum</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-coins fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-table me-2 text-primary"></i>Daftar Jenis Simpanan
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Kode</th>
                                        <th>Nama Jenis</th>
                                        <th>Deskripsi</th>
                                        <th>Minimum</th>
                                        <th>Maksimum</th>
                                        <th>Bagi Hasil</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($jenis_simpanan)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">
                                            <i class="fas fa-list fa-3x mb-3"></i>
                                            <p class="mb-2">Belum ada jenis simpanan</p>
                                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                                                <i class="fas fa-plus me-1"></i> Tambah Jenis Pertama
                                            </button>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($jenis_simpanan as $index => $jenis): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo $jenis['kode_jenis']; ?></span>
                                            </td>
                                            <td><?php echo $jenis['nama_jenis']; ?></td>
                                            <td>
                                                <small class="text-muted"><?php echo $jenis['deskripsi'] ?: '-'; ?></small>
                                            </td>
                                            <td><?php echo formatRupiah($jenis['minimum']); ?></td>
                                            <td><?php echo $jenis['maksimum'] > 0 ? formatRupiah($jenis['maksimum']) : '-'; ?></td>
                                            <td>
                                                <?php if ($jenis['bagi_hasil'] > 0): ?>
                                                    <span class="badge bg-success"><?php echo $jenis['bagi_hasil']; ?>%</span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $jenis['status'] == 'aktif' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($jenis['status']); ?>
                                                </span>
                                            </td>
                                            <td class="table-actions">
                                                <button class="btn btn-sm btn-warning me-1" data-bs-toggle="modal" 
                                                        data-bs-target="#editModal" 
                                                        data-id="<?php echo $jenis['id']; ?>"
                                                        data-kode="<?php echo $jenis['kode_jenis']; ?>"
                                                        data-nama="<?php echo $jenis['nama_jenis']; ?>"
                                                        data-deskripsi="<?php echo $jenis['deskripsi']; ?>"
                                                        data-minimum="<?php echo $jenis['minimum']; ?>"
                                                        data-maksimum="<?php echo $jenis['maksimum']; ?>"
                                                        data-bagihasil="<?php echo $jenis['bagi_hasil']; ?>"
                                                        data-status="<?php echo $jenis['status']; ?>"
                                                        title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" 
                                                        onclick="confirmDelete(<?php echo $jenis['id']; ?>, '<?php echo $jenis['nama_jenis']; ?>')"
                                                        title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Modal -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-plus-circle text-primary me-2"></i>Tambah Jenis Simpanan
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Kode Jenis *</label>
                            <input type="text" name="kode_jenis" class="form-control" required 
                                   placeholder="Contoh: POKOK, WAJIB" maxlength="10">
                            <small class="text-muted">Kode unik untuk jenis simpanan</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Jenis *</label>
                            <input type="text" name="nama_jenis" class="form-control" required 
                                   placeholder="Contoh: Simpanan Pokok">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="deskripsi" class="form-control" rows="3" 
                                      placeholder="Deskripsi jenis simpanan"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Minimum Setoran</label>
                                    <input type="number" name="minimum" class="form-control" value="0" step="1000" min="0">
                                    <small class="text-muted">0 = tidak ada minimum</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Maksimum Setoran</label>
                                    <input type="number" name="maksimum" class="form-control" value="0" step="1000" min="0">
                                    <small class="text-muted">0 = tidak ada batas</small>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Bagi Hasil (%)</label>
                            <input type="number" name="bagi_hasil" class="form-control" value="0" step="0.01" min="0" max="100">
                            <small class="text-muted">Persentase bagi hasil per tahun</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-edit text-warning me-2"></i>Edit Jenis Simpanan
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">Kode Jenis *</label>
                            <input type="text" name="kode_jenis" id="edit_kode" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Jenis *</label>
                            <input type="text" name="nama_jenis" id="edit_nama" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="deskripsi" id="edit_deskripsi" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Minimum Setoran</label>
                                    <input type="number" name="minimum" id="edit_minimum" class="form-control" step="1000" min="0">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Maksimum Setoran</label>
                                    <input type="number" name="maksimum" id="edit_maksimum" class="form-control" step="1000" min="0">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Bagi Hasil (%)</label>
                            <input type="number" name="bagi_hasil" id="edit_bagihasil" class="form-control" step="0.01" min="0" max="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="aktif">Aktif</option>
                                <option value="nonaktif">Nonaktif</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit" class="btn btn-warning">
                            <i class="fas fa-edit me-1"></i> Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="delete_id">
                        <input type="hidden" name="delete" value="1">
                        <p class="mb-2">Apakah Anda yakin ingin menghapus jenis simpanan:</p>
                        <p class="fw-bold text-danger" id="delete_nama"></p>
                        <p class="text-muted small">
                            <i class="fas fa-info-circle me-1"></i>
                            Tindakan ini tidak dapat dibatalkan dan hanya dapat dilakukan jika jenis simpanan tidak memiliki data terkait.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> Batal
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-1"></i> Hapus
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit Modal handler
        var editModal = document.getElementById('editModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                document.getElementById('edit_id').value = button.getAttribute('data-id');
                document.getElementById('edit_kode').value = button.getAttribute('data-kode');
                document.getElementById('edit_nama').value = button.getAttribute('data-nama');
                document.getElementById('edit_deskripsi').value = button.getAttribute('data-deskripsi');
                document.getElementById('edit_minimum').value = button.getAttribute('data-minimum');
                document.getElementById('edit_maksimum').value = button.getAttribute('data-maksimum');
                document.getElementById('edit_bagihasil').value = button.getAttribute('data-bagihasil');
                document.getElementById('edit_status').value = button.getAttribute('data-status');
            });
        }

        // Delete confirmation function
        function confirmDelete(id, nama) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_nama').innerHTML = '<strong>"' + nama + '"</strong>';
            
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }
    </script>
</body>
</html>
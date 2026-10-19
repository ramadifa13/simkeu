<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Handle form actions
if ($_POST) {
    if (isset($_POST['add'])) {
        $username = $_POST['username'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $nama_lengkap = $_POST['nama_lengkap'];
        $role = 'teller';
        $status = $_POST['status'];
        
        $query = "INSERT INTO users (username, password, nama_lengkap, role, status) 
                  VALUES (:username, :password, :nama_lengkap, :role, :status)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':password', $password);
        $stmt->bindParam(':nama_lengkap', $nama_lengkap);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':status', $status);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Teller berhasil ditambahkan";
        } else {
            $_SESSION['error'] = "Gagal menambahkan teller";
        }
    }
    
    if (isset($_POST['edit'])) {
        $id = $_POST['id'];
        $username = $_POST['username'];
        $nama_lengkap = $_POST['nama_lengkap'];
        $status = $_POST['status'];
        
        $query = "UPDATE users SET username = :username, nama_lengkap = :nama_lengkap, 
                  status = :status WHERE id = :id AND role = 'teller'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':nama_lengkap', $nama_lengkap);
        $stmt->bindParam(':status', $status);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Data teller berhasil diupdate";
        } else {
            $_SESSION['error'] = "Gagal mengupdate data teller";
        }
    }
    
    if (isset($_POST['reset_password'])) {
        $id = $_POST['id'];
        $new_password = password_hash('123456', PASSWORD_DEFAULT);
        
        $query = "UPDATE users SET password = :password WHERE id = :id AND role = 'teller'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':password', $new_password);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Password berhasil direset ke '123456'";
        } else {
            $_SESSION['error'] = "Gagal reset password";
        }
    }
}

// Get all tellers
$query = "SELECT * FROM users WHERE role = 'teller' ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$tellers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$query_stats = "SELECT 
    COUNT(*) as total_teller,
    COUNT(CASE WHEN status = 'aktif' THEN 1 END) as aktif,
    COUNT(CASE WHEN status = 'nonaktif' THEN 1 END) as nonaktif
    FROM users WHERE role = 'teller'";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Teller - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #007bff;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
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
                        <i class="fas fa-user-tie me-2"></i>Data Teller
                    </h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="fas fa-plus"></i> Tambah Teller
                    </button>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['total_teller']; ?></h4>
                                        <p>Total Teller</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-users fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['aktif']; ?></h4>
                                        <p>Teller Aktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user-check fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['nonaktif']; ?></h4>
                                        <p>Teller Nonaktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user-times fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Teller Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-table me-2"></i>Daftar Teller
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Username</th>
                                        <th>Nama Lengkap</th>
                                        <th>Status</th>
                                        <th>Terakhir Login</th>
                                        <th>Tanggal Dibuat</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tellers as $index => $teller): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <strong><?php echo $teller['username']; ?></strong>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar me-3">
                                                    <?php echo getInitials($teller['nama_lengkap']); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold"><?php echo $teller['nama_lengkap']; ?></div>
                                                    <small class="text-muted">Teller</small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $teller['status'] == 'aktif' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($teller['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $teller['last_login'] ? date('d/m/Y H:i', strtotime($teller['last_login'])) : 'Belum login'; ?>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($teller['created_at'])); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" 
                                                    data-bs-target="#editModal" 
                                                    data-id="<?php echo $teller['id']; ?>"
                                                    data-username="<?php echo $teller['username']; ?>"
                                                    data-nama="<?php echo $teller['nama_lengkap']; ?>"
                                                    data-status="<?php echo $teller['status']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" class="d-inline"><?php echo layananToken(); ?>
                                                <input type="hidden" name="id" value="<?php echo $teller['id']; ?>">
                                                <button type="submit" name="reset_password" class="btn btn-sm btn-info" 
                                                        onclick="return confirm('Reset password ke 123456?')">
                                                    <i class="fas fa-key"></i>
                                                </button>
                                            </form>
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

    <!-- Add Modal -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Teller Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" required 
                                   placeholder="Username untuk login">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" required 
                                   placeholder="Password untuk login">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Lengkap *</label>
                            <input type="text" name="nama_lengkap" class="form-control" required 
                                   placeholder="Nama lengkap teller">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <option value="aktif">Aktif</option>
                                <option value="nonaktif">Nonaktif</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add" class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Data Teller</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" id="edit_username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Lengkap *</label>
                            <input type="text" name="nama_lengkap" id="edit_nama" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="edit_status" class="form-control">
                                <option value="aktif">Aktif</option>
                                <option value="nonaktif">Nonaktif</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var editModal = document.getElementById('editModal');
        editModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('edit_id').value = button.getAttribute('data-id');
            document.getElementById('edit_username').value = button.getAttribute('data-username');
            document.getElementById('edit_nama').value = button.getAttribute('data-nama');
            document.getElementById('edit_status').value = button.getAttribute('data-status');
        });
    </script>
</body>
</html>
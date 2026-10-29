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

$ui = ui_query_params(['status']);
$filter_status = $ui['filters']['status'] ?? '';
$filter_q = $ui['q'];
$where = " WHERE role = 'teller'";
$params = [];
if ($filter_status !== '') {
    $where .= ' AND status = :status';
    $params[':status'] = $filter_status;
}
if ($filter_q !== '') {
    $where .= ' AND (username LIKE :q1 OR nama_lengkap LIKE :q2)';
    $params[':q1'] = $params[':q2'] = '%' . $filter_q . '%';
}
$countStmt = $db->prepare('SELECT COUNT(*) FROM users' . $where);
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$pager = ui_paginate((int)$countStmt->fetchColumn(), $ui['page'], $ui['per_page']);
$query = "SELECT * FROM users $where ORDER BY created_at DESC LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";
$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
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
        .avatar{width:36px;height:36px;border-radius:50%;background:#087f83;color:#fff;display:grid;place-items:center;font-weight:700;font-size:12px}
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <?php ui_page_header([
                    'title' => 'Data Teller',
                    'icon' => 'fas fa-user-tie text-primary',
                    'subtitle' => 'Kelola akun petugas teller',
                    'actions' => '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="fas fa-plus me-1"></i>Tambah Teller</button>',
                ]); ?>
                <?php ui_flash(); ?>

                <?php ui_stat_grid([
                    ['label' => 'Total teller', 'value' => (int)$stats['total_teller'], 'tone' => 'primary', 'icon' => 'fas fa-users', 'hint' => 'Semua akun'],
                    ['label' => 'Aktif', 'value' => (int)$stats['aktif'], 'tone' => 'success', 'icon' => 'fas fa-user-check', 'hint' => 'Dapat login'],
                    ['label' => 'Nonaktif', 'value' => (int)$stats['nonaktif'], 'tone' => 'warning', 'icon' => 'fas fa-user-times', 'hint' => 'Diblokir'],
                ]); ?>

                <?php
                ui_table_toolbar([
                    'action' => 'teller.php',
                    'q' => $filter_q,
                    'placeholder' => 'Cari username atau nama…',
                    'per_page' => $pager['per_page'],
                    'reset_href' => 'teller.php',
                    'filters' => [[
                        'name' => 'status',
                        'label' => 'Status',
                        'type' => 'select',
                        'value' => $filter_status,
                        'options' => ['' => 'Semua', 'aktif' => 'Aktif', 'nonaktif' => 'Nonaktif'],
                    ]],
                ]);
                ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-table me-2"></i>Daftar Teller
                            <span class="text-muted small fw-normal">(<?= (int)$pager['total'] ?> data)</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Username</th>
                                        <th>Nama Lengkap</th>
                                        <th>Status</th>
                                        <th>Terakhir Login</th>
                                        <th>Tanggal Dibuat</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$tellers): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data teller</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($tellers as $index => $teller): ?>
                                    <tr>
                                        <td><?php echo $pager['offset'] + $index + 1; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($teller['username']); ?></strong>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar me-3">
                                                    <?php echo getInitials($teller['nama_lengkap']); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold"><?= ui_cell_ellipsis($teller['nama_lengkap'], 28) ?></div>
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
                                            <?php echo $teller['last_login'] ? formatTanggalUi($teller['last_login'], true) : 'Belum login'; ?>
                                        </td>
                                        <td><?php echo formatTanggalUi($teller['created_at']); ?></td>
                                        <td class="table-actions text-end">
                                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" 
                                                    data-bs-target="#editModal" 
                                                    data-id="<?php echo $teller['id']; ?>"
                                                    data-username="<?php echo htmlspecialchars($teller['username']); ?>"
                                                    data-nama="<?php echo htmlspecialchars($teller['nama_lengkap']); ?>"
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
                        <?php ui_pagination($pager, 'teller.php'); ?>
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
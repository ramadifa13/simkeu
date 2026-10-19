<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isLoggedIn() || !isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

/**
 * Ambil & validasi field jenis pinjaman dari POST (tarif per tenor + frekuensi).
 * Validasi yang sama juga dilakukan di JS (form).
 */
function ambilInputJenisPinjaman() {
    $is_gadai = !empty($_POST['memerlukan_jaminan']);
    $data = [
        'kode_jenis' => trim($_POST['kode_jenis']),
        'nama_jenis' => trim($_POST['nama_jenis']),
        'maksimal_pinjaman' => parseRupiah($_POST['maksimal_pinjaman']),
        'deskripsi' => isset($_POST['deskripsi']) ? $_POST['deskripsi'] : '',
    ];
    if ($data['maksimal_pinjaman'] === false) {
        throw new Exception("Maksimal pinjaman harus berupa angka rupiah penuh (tanpa desimal)");
    }

    $frekuensi = $is_gadai
        ? ['bulanan', 'mingguan', 'dua_mingguan']
        : (isset($_POST['frekuensi']) ? (array) $_POST['frekuensi'] : []);
    $frekuensi = array_values(array_intersect(['bulanan', 'mingguan', 'dua_mingguan'], $frekuensi));
    if (empty($frekuensi)) throw new Exception("Pilih minimal satu frekuensi angsuran");
    $data['frekuensi_diizinkan'] = implode(',', $frekuensi);
    $data['memerlukan_jaminan'] = $is_gadai ? 1 : 0;

    $tenors = isset($_POST['tarif_tenor']) ? (array) $_POST['tarif_tenor'] : [];
    $bungas = isset($_POST['tarif_bunga']) ? (array) $_POST['tarif_bunga'] : [];
    $tarif = $is_gadai ? [1 => 15, 2 => 15, 3 => 15, 4 => 15, 5 => 15, 6 => 15] : [];
    foreach ($is_gadai ? [] : $tenors as $i => $tenor) {
        $tenor = trim($tenor);
        $bunga = isset($bungas[$i]) ? trim($bungas[$i]) : '';
        if ($tenor === '' && $bunga === '') {
            continue;
        }
        if (!ctype_digit($tenor) || (int) $tenor < 1 || (int) $tenor > 36) {
            throw new Exception("Tenor harus bilangan bulat 1–36 bulan");
        }
        if (!is_numeric($bunga) || (float) $bunga < 0 || (float) $bunga > BUNGA_MAKS) {
            throw new Exception("Bunga tenor $tenor bulan harus antara 0 dan " . BUNGA_MAKS . "%");
        }
        if (isset($tarif[(int) $tenor])) {
            throw new Exception("Tenor $tenor bulan diisi lebih dari sekali");
        }
        $tarif[(int) $tenor] = round((float) $bunga, 2);
    }
    if (empty($tarif)) {
        throw new Exception("Isi minimal satu tarif bunga per tenor");
    }
    ksort($tarif);
    $data['tarif'] = $tarif;
    $data['tenor_max'] = max(array_keys($tarif));
    if ($data['memerlukan_jaminan']) {
        if ($data['tenor_max'] > 6) {
            throw new Exception("Tenor pinjaman gadai maksimal 6 bulan");
        }
        foreach ($tarif as $tenor_gadai => $bunga_gadai) {
            if ((float) $bunga_gadai != 15.0) {
                throw new Exception("Bunga dasar gadai untuk tenor $tenor_gadai bulan harus 15%");
            }
        }
    } elseif (in_array('dua_mingguan', $frekuensi, true)) {
        if (!isset($tarif[6]) || (float) $tarif[6] != 45.0) {
            throw new Exception("Pinjaman tanpa jaminan 2 minggu sekali wajib memiliki tenor 6 bulan dengan bunga 45%");
        }
    }
    return $data;
}

function simpanTarifJenisPinjaman($db, $jenis_id, $tarif) {
    $db->prepare("DELETE FROM jenis_pinjaman_tarif WHERE jenis_pinjaman_id = ?")->execute([$jenis_id]);
    $stmt = $db->prepare("INSERT INTO jenis_pinjaman_tarif (jenis_pinjaman_id, tenor_bulan, bunga) VALUES (?, ?, ?)");
    foreach ($tarif as $tenor => $bunga) {
        $stmt->execute([$jenis_id, $tenor, $bunga]);
    }
}

// Handle form actions
if ($_POST) {
    if (isset($_POST['add'])) {
        try {
            $data = ambilInputJenisPinjaman();

            // Check if kode jenis already exists
            $check_stmt = $db->prepare("SELECT id FROM jenis_pinjaman WHERE kode_jenis = ?");
            $check_stmt->execute([$data['kode_jenis']]);
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Kode jenis sudah digunakan");
            }

            $db->beginTransaction();
            // Kolom bunga (per tahun) hanya untuk skema lama; jenis baru memakai tarif flat
            $stmt = $db->prepare("INSERT INTO jenis_pinjaman
                (kode_jenis, nama_jenis, maksimal_pinjaman, tenor_max, bunga, frekuensi_diizinkan, memerlukan_jaminan, deskripsi)
                VALUES (?, ?, ?, ?, 0, ?, ?, ?)");
            $stmt->execute([$data['kode_jenis'], $data['nama_jenis'], $data['maksimal_pinjaman'],
                            $data['tenor_max'], $data['frekuensi_diizinkan'], $data['memerlukan_jaminan'], $data['deskripsi']]);
            simpanTarifJenisPinjaman($db, $db->lastInsertId(), $data['tarif']);
            $db->commit();
            $_SESSION['success'] = "Jenis pinjaman berhasil ditambahkan";
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = $e->getMessage();
        }
        
        header("Location: jenis_pinjaman.php");
        exit();
    }
    
    if (isset($_POST['edit'])) {
        $id = $_POST['id'];
        $status = $_POST['status'] === 'nonaktif' ? 'nonaktif' : 'aktif';
        
        try {
            $data = ambilInputJenisPinjaman();

            // Check if kode jenis already exists (excluding current record)
            $check_stmt = $db->prepare("SELECT id FROM jenis_pinjaman WHERE kode_jenis = ? AND id != ?");
            $check_stmt->execute([$data['kode_jenis'], $id]);
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Kode jenis sudah digunakan");
            }

            // Kolom bunga lama tidak diubah; pinjaman yang sudah ada menyimpan bunganya sendiri
            $db->beginTransaction();
            $stmt = $db->prepare("UPDATE jenis_pinjaman SET kode_jenis = ?, nama_jenis = ?,
                      maksimal_pinjaman = ?, tenor_max = ?, frekuensi_diizinkan = ?, memerlukan_jaminan = ?,
                      deskripsi = ?, status = ? WHERE id = ?");
            $stmt->execute([$data['kode_jenis'], $data['nama_jenis'], $data['maksimal_pinjaman'],
                            $data['tenor_max'], $data['frekuensi_diizinkan'], $data['memerlukan_jaminan'], $data['deskripsi'], $status, $id]);
            simpanTarifJenisPinjaman($db, $id, $data['tarif']);
            $db->commit();
            $_SESSION['success'] = "Jenis pinjaman berhasil diupdate";
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = $e->getMessage();
        }
        
        header("Location: jenis_pinjaman.php");
        exit();
    }
    
    // Handle delete action
    if (isset($_POST['delete'])) {
        $id = $_POST['id'];
        
        try {
            // Check if this jenis pinjaman is being used in pengajuan_pinjaman table
            $check_pengajuan_query = "SELECT COUNT(*) as total FROM pengajuan_pinjaman WHERE jenis_pinjaman_id = :id";
            $check_pengajuan_stmt = $db->prepare($check_pengajuan_query);
            $check_pengajuan_stmt->bindParam(':id', $id);
            $check_pengajuan_stmt->execute();
            $used_in_pengajuan = $check_pengajuan_stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($used_in_pengajuan > 0) {
                throw new Exception("Tidak dapat menghapus jenis pinjaman karena masih digunakan oleh $used_in_pengajuan data pengajuan");
            }
            
            // Check if this jenis pinjaman is being used in pinjaman table
            $check_pinjaman_query = "SELECT COUNT(*) as total FROM pinjaman WHERE jenis_pinjaman_id = :id";
            $check_pinjaman_stmt = $db->prepare($check_pinjaman_query);
            $check_pinjaman_stmt->bindParam(':id', $id);
            $check_pinjaman_stmt->execute();
            $used_in_pinjaman = $check_pinjaman_stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($used_in_pinjaman > 0) {
                throw new Exception("Tidak dapat menghapus jenis pinjaman karena masih digunakan oleh $used_in_pinjaman data pinjaman");
            }
            
            $query = "DELETE FROM jenis_pinjaman WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Jenis pinjaman berhasil dihapus";
            } else {
                throw new Exception("Gagal menghapus jenis pinjaman");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        
        header("Location: jenis_pinjaman.php");
        exit();
    }
}

// Get all jenis pinjaman
$query = "SELECT * FROM jenis_pinjaman ORDER BY 
          CASE WHEN status = 'aktif' THEN 1 ELSE 2 END, 
          created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$jenis_pinjaman = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($jenis_pinjaman as &$jp) {
    $jp['tarif'] = getTarifJenisPinjaman($db, $jp['id']);
}
unset($jp);

// Get statistics
$query_stats = "SELECT 
    COUNT(*) as total_jenis,
    COUNT(CASE WHEN status = 'aktif' THEN 1 END) as aktif,
    COUNT(CASE WHEN status = 'nonaktif' THEN 1 END) as nonaktif
    FROM jenis_pinjaman";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jenis Pinjaman - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .feature-badge {
            font-size: 0.75rem;
        }
        .btn-delete {
            color: #dc3545;
            border-color: #dc3545;
        }
        .btn-delete:hover {
            background-color: #dc3545;
            color: white;
        }
        .table-actions {
            white-space: nowrap;
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
                        <i class="fas fa-hand-holding-usd me-2"></i>Jenis Pinjaman
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

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="mb-0"><?php echo $stats['total_jenis']; ?></h4>
                                        <p class="mb-0">Total Jenis</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-list fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="mb-0"><?php echo $stats['aktif']; ?></h4>
                                        <p class="mb-0">Jenis Aktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="mb-0"><?php echo $stats['nonaktif']; ?></h4>
                                        <p class="mb-0">Jenis Nonaktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-times-circle fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Jenis Pinjaman Table -->
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-table me-2 text-primary"></i>Daftar Jenis Pinjaman
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
                                        <th>Maksimal Pinjaman</th>
                                        <th>Bunga (flat per tenor)</th>
                                        <th>Frekuensi</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($jenis_pinjaman)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            <i class="fas fa-hand-holding-usd fa-3x mb-3"></i>
                                            <p class="mb-2">Belum ada jenis pinjaman</p>
                                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                                                <i class="fas fa-plus me-1"></i> Tambah Jenis Pertama
                                            </button>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($jenis_pinjaman as $index => $jenis): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo $jenis['kode_jenis']; ?></span>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo $jenis['nama_jenis']; ?></div>
                                                <?php if ($jenis['deskripsi']): ?>
                                                    <small class="text-muted"><?php echo $jenis['deskripsi']; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo formatRupiah($jenis['maksimal_pinjaman']); ?></td>
                                            <td>
                                                <?php if (empty($jenis['tarif'])): ?>
                                                    <span class="badge bg-secondary"><?php echo labelBunga($jenis['bunga'], 'tahunan'); ?> (skema lama)</span>
                                                    <div><small class="text-danger">Belum ada tarif flat &mdash; tidak muncul di form pengajuan</small></div>
                                                <?php else: ?>
                                                    <?php foreach ($jenis['tarif'] as $tenor => $bunga): ?>
                                                        <span class="badge bg-success mb-1"><?php echo $tenor; ?> bln: <?php echo labelBunga($bunga, 'flat'); ?></span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php foreach (explode(',', isset($jenis['frekuensi_diizinkan']) ? $jenis['frekuensi_diizinkan'] : 'bulanan') as $f): ?>
                                                    <span class="badge bg-info"><?php echo labelFrekuensi($f); ?></span>
                                                <?php endforeach; ?>
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
                                                        data-maksimal="<?php echo $jenis['maksimal_pinjaman']; ?>"
                                                        data-tarif="<?php echo htmlspecialchars(json_encode((object) $jenis['tarif']), ENT_QUOTES); ?>"
                                                        data-frekuensi="<?php echo isset($jenis['frekuensi_diizinkan']) ? $jenis['frekuensi_diizinkan'] : 'bulanan'; ?>"
                                                        data-jaminan="<?php echo !empty($jenis['memerlukan_jaminan']) ? '1' : '0'; ?>"
                                                        data-deskripsi="<?php echo $jenis['deskripsi']; ?>"
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
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" class="form-jenis-pinjaman"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-plus-circle text-primary me-2"></i>Tambah Jenis Pinjaman
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Kode Jenis *</label>
                            <input type="text" name="kode_jenis" class="form-control" required 
                                   placeholder="Contoh: UMUM, USAHA" maxlength="10">
                            <small class="text-muted">Kode unik untuk jenis pinjaman</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Jenis *</label>
                            <input type="text" name="nama_jenis" class="form-control" required 
                                   placeholder="Contoh: Pinjaman Umum">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Maksimal Pinjaman (Rp) *</label>
                            <input type="number" name="maksimal_pinjaman" class="form-control"
                                   required step="1" min="0" value="1000000">
                        </div>
                        <?php $tarif_prefix = 'add'; include __DIR__ . '/../includes/form_tarif_pinjaman.php'; ?>
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="deskripsi" class="form-control" rows="3" 
                                      placeholder="Deskripsi jenis pinjaman"></textarea>
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
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" class="form-jenis-pinjaman"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-edit text-warning me-2"></i>Edit Jenis Pinjaman
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
                            <label class="form-label">Maksimal Pinjaman (Rp) *</label>
                            <input type="number" name="maksimal_pinjaman" id="edit_maksimal"
                                   class="form-control" required step="1" min="0">
                        </div>
                        <?php $tarif_prefix = 'edit'; include __DIR__ . '/../includes/form_tarif_pinjaman.php'; ?>
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="deskripsi" id="edit_deskripsi" class="form-control" rows="3"></textarea>
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
                <form method="POST" id="deleteForm"><?php echo layananToken(); ?>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="delete_id">
                        <input type="hidden" name="delete" value="1">
                        <p class="mb-2">Apakah Anda yakin ingin menghapus jenis pinjaman:</p>
                        <p class="fw-bold text-danger" id="delete_nama"></p>
                        <p class="text-muted small">
                            <i class="fas fa-info-circle me-1"></i>
                            Tindakan ini tidak dapat dibatalkan dan hanya dapat dilakukan jika jenis pinjaman tidak memiliki data terkait (pengajuan/pinjaman).
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
        // ---- Tarif bunga flat per tenor (form tambah & edit) ----
        var BUNGA_MAKS = <?php echo BUNGA_MAKS; ?>;

        function tambahBarisTarif(prefix, tenor, bunga) {
            var tbody = document.getElementById(prefix + '_tarif_rows');
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><div class="input-group input-group-sm"><input type="number" name="tarif_tenor[]" class="form-control" required min="1" max="36" step="1"><span class="input-group-text">bulan</span></div></td>' +
                '<td><div class="input-group input-group-sm"><input type="number" name="tarif_bunga[]" class="form-control" required min="0" max="' + BUNGA_MAKS + '" step="0.01"><span class="input-group-text">%</span></div></td>' +
                '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="hapusBarisTarif(this)" title="Hapus"><i class="fas fa-times"></i></button></td>';
            tr.querySelector('input[name="tarif_tenor[]"]').value = tenor || '';
            tr.querySelector('input[name="tarif_bunga[]"]').value = (bunga !== undefined) ? bunga : '';
            tbody.appendChild(tr);
        }

        function hapusBarisTarif(btn) {
            var tbody = btn.closest('tbody');
            if (tbody.rows.length > 1) {
                btn.closest('tr').remove();
            }
        }

        function isiFormTarif(prefix, tarif, frekuensi) {
            var tbody = document.getElementById(prefix + '_tarif_rows');
            tbody.innerHTML = '';
            var tenors = Object.keys(tarif);
            if (tenors.length === 0) {
                tambahBarisTarif(prefix);
            }
            tenors.forEach(function (t) { tambahBarisTarif(prefix, t, tarif[t]); });
            document.getElementById(prefix + '_frek_bulanan').checked = frekuensi.indexOf('bulanan') !== -1;
            document.getElementById(prefix + '_frek_mingguan').checked = frekuensi.indexOf('mingguan') !== -1;
            document.getElementById(prefix + '_frek_dua_mingguan').checked = frekuensi.indexOf('dua_mingguan') !== -1;
        }

        function aturFormGadai(prefix) {
            var checked = document.getElementById(prefix + '_jaminan').checked;
            var tarifSection = document.getElementById(prefix + '_tarif_section');
            var frekuensiSection = document.getElementById(prefix + '_frekuensi_section');
            tarifSection.style.display = checked ? 'none' : '';
            frekuensiSection.style.display = checked ? 'none' : '';

            // Input yang disembunyikan tidak boleh tetap required. Jika masih required,
            // browser membatalkan submit gadai tanpa dapat memfokuskan kolom tersebut.
            tarifSection.querySelectorAll('input').forEach(function (input) {
                input.required = !checked;
                input.disabled = checked;
            });
            frekuensiSection.querySelectorAll('input').forEach(function (input) {
                input.disabled = checked;
            });
        }

        ['add', 'edit'].forEach(function (prefix) {
            document.getElementById(prefix + '_jaminan').addEventListener('change', function () {
                aturFormGadai(prefix);
            });
            aturFormGadai(prefix);
        });

        // Validasi sisi klien (server memvalidasi ulang): min. 1 frekuensi, tenor tidak dobel
        function validasiFormTarif(form) {
            if (form.querySelector('input[name="memerlukan_jaminan"]').checked) {
                return true;
            }
            if (form.querySelectorAll('input[name="frekuensi[]"]:checked').length === 0) {
                alert('Pilih minimal satu frekuensi angsuran.');
                return false;
            }
            var seen = {};
            var tenors = form.querySelectorAll('input[name="tarif_tenor[]"]');
            for (var i = 0; i < tenors.length; i++) {
                if (seen[tenors[i].value]) {
                    alert('Tenor ' + tenors[i].value + ' bulan diisi lebih dari sekali.');
                    return false;
                }
                seen[tenors[i].value] = true;
            }
            return true;
        }

        document.querySelectorAll('form.form-jenis-pinjaman').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!validasiFormTarif(form)) {
                    e.preventDefault();
                }
            });
        });
        isiFormTarif('add', {}, ['bulanan']);

        // Edit Modal handler
        var editModal = document.getElementById('editModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                document.getElementById('edit_id').value = button.getAttribute('data-id');
                document.getElementById('edit_kode').value = button.getAttribute('data-kode');
                document.getElementById('edit_nama').value = button.getAttribute('data-nama');
                document.getElementById('edit_maksimal').value = button.getAttribute('data-maksimal');
                isiFormTarif('edit', JSON.parse(button.getAttribute('data-tarif') || '{}'),
                             (button.getAttribute('data-frekuensi') || 'bulanan').split(','));
                document.getElementById('edit_jaminan').checked = button.getAttribute('data-jaminan') === '1';
                aturFormGadai('edit');
                document.getElementById('edit_deskripsi').value = button.getAttribute('data-deskripsi');
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

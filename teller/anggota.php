<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Upload melebihi post_max_size membuat $_POST kosong; tampilkan pesan yang jelas
cekPostMelebihiBatas();

// Kode anggota berikutnya untuk form tambah (sama dengan Admin: buatKodeAnggota)
$kode_otomatis = buatKodeAnggota($db);
$kode_jaminan_otomatis = buatKodeAnggotaJaminan($db);

// Handle form actions
if ($_POST) {
    if (isset($_POST['add'])) {
        // Kode dibuat server (kiriman form diabaikan); dikunci sampai selesai simpan agar tidak kembar
        kunciKodeAnggota($db);
        $kategori_anggota = ($_POST['kategori_anggota'] ?? 'biasa') === 'jaminan' ? 'jaminan' : 'biasa';
        $kode_anggota = buatKodeAnggotaSesuaiKategori($db, $kategori_anggota);
        $nama_lengkap = $_POST['nama_lengkap'];
        $alamat = $_POST['alamat'];
        $telepon = $_POST['telepon'];
        $email = isset($_POST['email']) ? $_POST['email'] : ''; // form tambah teller tidak memakai email
        $tempat_lahir = $_POST['tempat_lahir'];
        $tanggal_lahir = tanggalAtauNull(isset($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : '');
        $jenis_kelamin = $_POST['jenis_kelamin'];
        $pekerjaan = $_POST['pekerjaan'];
        $ktp = $_POST['ktp'];
        $rekening_bank = $_POST['rekening_bank'];
        $tanggal_daftar = $_POST['tanggal_daftar'];
        
        $foto_ktp = null; // diisi simpanDokumenAnggota()
        
        try {
            $db->beginTransaction();
            
            // Validasi dokumen (finfo, maks. 10 MB) sebelum data disimpan
            $dokumen = validasiDokumenAnggota(null, $kategori_anggota === 'jaminan');

            // No. KTP wajib & unik (sama dengan Admin)
            if (trim($ktp) === '') {
                throw new Exception("No. KTP harus diisi");
            }
            $cek_ktp = $db->prepare("SELECT id FROM anggota WHERE ktp = ?");
            $cek_ktp->execute([trim($ktp)]);
            if ($cek_ktp->rowCount() > 0) {
                throw new Exception("No. KTP sudah terdaftar");
            }

            // Check if kode anggota already exists
            $check_query = "SELECT id FROM anggota WHERE kode_anggota = :kode_anggota";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':kode_anggota', $kode_anggota);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Kode anggota sudah digunakan");
            }
            
            // Insert user account for anggota
            $password = password_hash('123456', PASSWORD_DEFAULT);
            $query_user = "INSERT INTO users (username, password, nama_lengkap, role, status) 
                          VALUES (:username, :password, :nama_lengkap, 'anggota', 'aktif')";
            $stmt_user = $db->prepare($query_user);
            $stmt_user->bindParam(':username', $kode_anggota);
            $stmt_user->bindParam(':password', $password);
            $stmt_user->bindParam(':nama_lengkap', $nama_lengkap);
            $stmt_user->execute();
            $user_id = $db->lastInsertId();
            
            // Insert anggota data
            $query_anggota = "INSERT INTO anggota (kode_anggota, nama_lengkap, alamat, telepon, email, 
                            tempat_lahir, tanggal_lahir, jenis_kelamin, pekerjaan, ktp, foto_ktp, 
                            rekening_bank, user_id, tanggal_daftar) 
                            VALUES (:kode_anggota, :nama_lengkap, :alamat, :telepon, :email, 
                            :tempat_lahir, :tanggal_lahir, :jenis_kelamin, :pekerjaan, :ktp, :foto_ktp, 
                            :rekening_bank, :user_id, :tanggal_daftar)";
            $stmt_anggota = $db->prepare($query_anggota);
            $stmt_anggota->bindParam(':kode_anggota', $kode_anggota);
            $stmt_anggota->bindParam(':nama_lengkap', $nama_lengkap);
            $stmt_anggota->bindParam(':alamat', $alamat);
            $stmt_anggota->bindParam(':telepon', $telepon);
            $stmt_anggota->bindParam(':email', $email);
            $stmt_anggota->bindParam(':tempat_lahir', $tempat_lahir);
            $stmt_anggota->bindParam(':tanggal_lahir', $tanggal_lahir);
            $stmt_anggota->bindParam(':jenis_kelamin', $jenis_kelamin);
            $stmt_anggota->bindParam(':pekerjaan', $pekerjaan);
            $stmt_anggota->bindParam(':ktp', $ktp);
            $stmt_anggota->bindParam(':foto_ktp', $foto_ktp);
            $stmt_anggota->bindParam(':rekening_bank', $rekening_bank);
            $stmt_anggota->bindParam(':user_id', $user_id);
            $stmt_anggota->bindParam(':tanggal_daftar', $tanggal_daftar);
            $stmt_anggota->execute();
            
            $anggota_id = $db->lastInsertId();
            
            simpanDokumenAnggota($db, $anggota_id, $dokumen);
            
            // Create default simpanan records
            $query_jenis = "SELECT id FROM jenis_simpanan WHERE status = 'aktif'";
            $stmt_jenis = $db->prepare($query_jenis);
            $stmt_jenis->execute();
            $jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($jenis_simpanan as $jenis) {
                $query_simpanan = "INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo) 
                                  VALUES (:anggota_id, :jenis_simpanan_id, 0)";
                $stmt_simpanan = $db->prepare($query_simpanan);
                $stmt_simpanan->bindParam(':anggota_id', $anggota_id);
                $stmt_simpanan->bindParam(':jenis_simpanan_id', $jenis['id']);
                $stmt_simpanan->execute();
            }
            
            $db->commit();
            $_SESSION['success'] = "Anggota berhasil ditambahkan. Password default: 123456";
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Gagal menambahkan anggota: " . $e->getMessage();
        }
        lepasKodeAnggota($db);
    }
    
    if (isset($_POST['edit'])) {
        $id = $_POST['id'];
        // Kode anggota TIDAK bisa diubah: selalu diambil dari database berdasarkan ID (kiriman form diabaikan)
        $stmt_kode = $db->prepare("SELECT kode_anggota FROM anggota WHERE id = ?");
        $stmt_kode->execute([$id]);
        $kode_anggota = (string) $stmt_kode->fetchColumn();
        $nama_lengkap = $_POST['nama_lengkap'];
        $alamat = $_POST['alamat'];
        $telepon = $_POST['telepon'];
        $tempat_lahir = $_POST['tempat_lahir'];
        $tanggal_lahir = tanggalAtauNull(isset($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : '');
        $jenis_kelamin = $_POST['jenis_kelamin'];
        $pekerjaan = $_POST['pekerjaan'];
        $ktp = $_POST['ktp'];
        $rekening_bank = $_POST['rekening_bank'];
        $status = $_POST['status'];
        
        try {
            $db->beginTransaction();
            
            // Dokumen lama (untuk cek wajib & hapus file yang diganti)
            $stmt_dok = $db->prepare("SELECT foto_ktp, foto_kk, foto_ktp_pasangan FROM anggota WHERE id = ?");
            $stmt_dok->execute([$id]);
            $dokumen_lama = $stmt_dok->fetch(PDO::FETCH_ASSOC);
            $dokumen = validasiDokumenAnggota($dokumen_lama);
            
            if ($kode_anggota === '') {
                throw new Exception("Anggota tidak ditemukan");
            }
            // No. KTP wajib & unik (sama dengan Admin)
            if (trim($ktp) === '') {
                throw new Exception("No. KTP harus diisi");
            }
            $cek_ktp = $db->prepare("SELECT id FROM anggota WHERE ktp = ? AND id != ?");
            $cek_ktp->execute([trim($ktp), $id]);
            if ($cek_ktp->rowCount() > 0) {
                throw new Exception("No. KTP sudah terdaftar");
            }

            // kode_anggota & email sengaja tidak di-UPDATE (kode tetap, email lama tidak tertimpa)
            $query = "UPDATE anggota SET nama_lengkap = :nama_lengkap,
                      alamat = :alamat, telepon = :telepon, tempat_lahir = :tempat_lahir,
                      tanggal_lahir = :tanggal_lahir, jenis_kelamin = :jenis_kelamin, pekerjaan = :pekerjaan,
                      ktp = :ktp, rekening_bank = :rekening_bank, status = :status
                      WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':nama_lengkap', $nama_lengkap);
            $stmt->bindParam(':alamat', $alamat);
            $stmt->bindParam(':telepon', $telepon);
            $stmt->bindParam(':tempat_lahir', $tempat_lahir);
            $stmt->bindParam(':tanggal_lahir', $tanggal_lahir);
            $stmt->bindParam(':jenis_kelamin', $jenis_kelamin);
            $stmt->bindParam(':pekerjaan', $pekerjaan);
            $stmt->bindParam(':ktp', $ktp);
            $stmt->bindParam(':rekening_bank', $rekening_bank);
            $stmt->bindParam(':status', $status);
            
            if ($stmt->execute()) {
                // Update user data
                $query_user = "UPDATE users SET username = :username, nama_lengkap = :nama_lengkap, 
                              status = :status WHERE id = (SELECT user_id FROM anggota WHERE id = :id)";
                $stmt_user = $db->prepare($query_user);
                $stmt_user->bindParam(':username', $kode_anggota);
                $stmt_user->bindParam(':nama_lengkap', $nama_lengkap);
                $stmt_user->bindParam(':status', $status);
                $stmt_user->bindParam(':id', $id);
                $stmt_user->execute();
                
                simpanDokumenAnggota($db, $id, $dokumen, $dokumen_lama);
                
                $db->commit();
                $_SESSION['success'] = "Data anggota berhasil diupdate";
            } else {
                throw new Exception("Gagal mengupdate data anggota");
            }
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    }
    
    if (isset($_POST['reset_password'])) {
        $id = $_POST['id'];
        $new_password = password_hash('123456', PASSWORD_DEFAULT);
        
        $query = "UPDATE users SET password = :password 
                  WHERE id = (SELECT user_id FROM anggota WHERE id = :id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':password', $new_password);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Password berhasil direset ke '123456'";
        } else {
            $_SESSION['error'] = "Gagal reset password";
        }
    }
    
    // Untuk teller, hapus fungsi mungkin dibatasi atau dihilangkan
    if (isset($_POST['delete']) && $_SESSION['role'] == 'admin') {
        $id = $_POST['id'];
        
        try {
            hapusAnggota($db, $id);
            $_SESSION['success'] = "Anggota berhasil dihapus";
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal menghapus anggota: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_search = $_GET['search'] ?? '';

// Build query with filters
$query = "SELECT a.*, u.status as user_status 
          FROM anggota a 
          LEFT JOIN users u ON a.user_id = u.id 
          WHERE 1=1";

$params = [];

if (!empty($filter_status)) {
    $query .= " AND a.status = :status";
    $params[':status'] = $filter_status;
}

if (!empty($filter_search)) {
    $query .= " AND (a.kode_anggota LIKE :search OR a.nama_lengkap LIKE :search OR a.telepon LIKE :search OR a.ktp LIKE :search)";
    $params[':search'] = "%$filter_search%";
}

$query .= " ORDER BY a.created_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$anggota_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$query_stats = "SELECT 
    COUNT(*) as total_anggota,
    COUNT(CASE WHEN status = 'aktif' THEN 1 END) as aktif,
    COUNT(CASE WHEN status = 'nonaktif' THEN 1 END) as nonaktif
    FROM anggota";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Anggota - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .table-actions {
            white-space: nowrap;
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
        .filter-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
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
                        <i class="fas fa-users me-2"></i>Data Anggota
                    </h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="fas fa-plus"></i> Tambah Anggota
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
                    <div class="col-md-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['total_anggota']; ?></h4>
                                        <p>Total Anggota</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-users fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['aktif']; ?></h4>
                                        <p>Anggota Aktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user-check fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['nonaktif']; ?></h4>
                                        <p>Anggota Nonaktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user-times fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo count($anggota_list); ?></h4>
                                        <p>Ditampilkan</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-filter fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">Semua Status</option>
                                    <option value="aktif" <?php echo $filter_status == 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                    <option value="nonaktif" <?php echo $filter_status == 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Pencarian</label>
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Cari berdasarkan kode, nama, telepon, atau KTP..."
                                       value="<?php echo htmlspecialchars($filter_search); ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-1"></i> Filter
                                </button>
                            </div>
                        </form>
                        <?php if ($filter_status || $filter_search): ?>
                        <div class="mt-3">
                            <a href="anggota.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-times me-1"></i> Hapus Filter
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Members Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>Daftar Anggota
                        </h5>
                        <div>
                            <span class="text-muted me-2">
                                <?php echo count($anggota_list); ?> data ditemukan
                            </span>
                            <?php if ($_SESSION['role'] == 'admin'): ?>
                            <a href="export_anggota.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success btn-sm">
                                <i class="fas fa-file-excel me-1"></i> Export
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="anggotaTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Kode</th>
                                        <th>Nama Lengkap</th>
                                        <th>Telepon</th>
                                        <th>KTP</th>
                                        <th>Rekening</th>
                                        <th>Status</th>
                                        <th>Tanggal Daftar</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($anggota_list)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">
                                            <i class="fas fa-users fa-3x mb-3"></i>
                                            <p>Tidak ada data anggota</p>
                                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                                                <i class="fas fa-plus me-1"></i> Tambah Anggota Pertama
                                            </button>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($anggota_list as $index => $anggota): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($anggota['kode_anggota']); ?></strong>
                                                <?php if (!empty($anggota['kode_anggota_jaminan'])): ?>
                                                    <br><span class="badge bg-warning text-dark">Gadai: <?php echo htmlspecialchars($anggota['kode_anggota_jaminan']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar me-3">
                                                        <?php echo strtoupper(substr($anggota['nama_lengkap'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-semibold"><?php echo $anggota['nama_lengkap']; ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo $anggota['telepon'] ?: '-'; ?></td>
                                            <td>
                                                <?php $dok_url = array_filter(array_map('urlDokumenAnggota', array_intersect_key($anggota, daftarDokumenAnggota()))); ?>
                                                <?php if (!empty($dok_url)): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-info"
                                                            onclick="lihatDokumenAnggota(<?php echo htmlspecialchars(json_encode($anggota['nama_lengkap']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((object) $dok_url), ENT_QUOTES); ?>)"
                                                            title="Lihat KTP, KK &amp; KTP suami/istri">
                                                        <i class="fas fa-eye"></i> Lihat <span class="badge bg-info"><?php echo count($dok_url); ?>/3</span>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($anggota['rekening_bank']): ?>
                                                    <span class="badge bg-secondary"><?php echo $anggota['rekening_bank']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $anggota['status'] == 'aktif' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($anggota['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($anggota['tanggal_daftar'])); ?></td>
                                            <td class="table-actions">
                                                <a href="detail_anggota.php?id=<?php echo $anggota['id']; ?>" class="btn btn-sm btn-primary" title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" 
                                                        data-bs-target="#editModal" 
                                                        data-id="<?php echo $anggota['id']; ?>"
                                                        data-kode="<?php echo $anggota['kode_anggota']; ?>"
                                                        data-nama="<?php echo $anggota['nama_lengkap']; ?>"
                                                        data-alamat="<?php echo $anggota['alamat']; ?>"
                                                        data-telepon="<?php echo $anggota['telepon']; ?>"
                                                        data-email="<?php echo $anggota['email']; ?>"
                                                        data-tempat_lahir="<?php echo $anggota['tempat_lahir']; ?>"
                                                        data-tanggal_lahir="<?php echo $anggota['tanggal_lahir']; ?>"
                                                        data-jenis_kelamin="<?php echo $anggota['jenis_kelamin']; ?>"
                                                        data-pekerjaan="<?php echo $anggota['pekerjaan']; ?>"
                                                        data-ktp="<?php echo $anggota['ktp']; ?>"
                                                        data-dokumen="<?php echo htmlspecialchars(json_encode(array_map('urlDokumenAnggota', array_intersect_key($anggota, daftarDokumenAnggota()))), ENT_QUOTES); ?>"
                                                        data-rekening_bank="<?php echo $anggota['rekening_bank']; ?>"
                                                        data-status="<?php echo $anggota['status']; ?>"
                                                        data-tanggal_daftar="<?php echo $anggota['tanggal_daftar']; ?>"
                                                        title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="id" value="<?php echo $anggota['id']; ?>">
                                                    <button type="submit" name="reset_password" class="btn btn-sm btn-info" 
                                                            onclick="return confirm('Reset password ke 123456?')" title="Reset Password">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                </form>
                                                <?php if ($_SESSION['role'] == 'admin'): ?>
                                                <button class="btn btn-sm btn-danger" data-bs-toggle="modal" 
                                                        data-bs-target="#deleteModal" 
                                                        data-id="<?php echo $anggota['id']; ?>"
                                                        data-nama="<?php echo $anggota['nama_lengkap']; ?>"
                                                        title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
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
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Anggota Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Kategori Anggota *</label>
                            <select name="kategori_anggota" class="form-select kategori-anggota" required>
                                <option value="biasa">Anggota Biasa (kode AGT)</option>
                                <option value="jaminan">Anggota Jaminan/Gadai (kode AGTJ)</option>
                            </select>
                            <small class="text-muted">Akun login dibuat satu kali menggunakan kode saat pertama didaftarkan.</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Kode Anggota *</label>
                                    <input type="text" name="kode_anggota" id="add_kode" class="form-control" required readonly
                                           value="<?php echo htmlspecialchars($kode_otomatis); ?>">
                                    <small class="text-muted">Dibuat otomatis oleh sistem</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Nama Lengkap *</label>
                                    <input type="text" name="nama_lengkap" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Alamat</label>
                                    <textarea name="alamat" class="form-control" rows="3" placeholder="Alamat lengkap"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Telepon</label>
                                    <input type="text" name="telepon" class="form-control" placeholder="08xxxxxxxxxx">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tempat Lahir</label>
                                    <input type="text" name="tempat_lahir" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Tanggal Lahir</label>
                                    <input type="date" name="tanggal_lahir" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Jenis Kelamin</label>
                                    <select name="jenis_kelamin" class="form-control">
                                        <option value="L">Laki-laki</option>
                                        <option value="P">Perempuan</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Pekerjaan</label>
                                    <input type="text" name="pekerjaan" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">No. KTP *</label>
                                    <input type="text" name="ktp" class="form-control" required
                                           placeholder="16 digit nomor KTP">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                        </div>
                        <?php $dok_prefix = 'add'; $dok_anggota = null; include __DIR__ . '/../includes/form_dokumen_anggota.php'; ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">No. Rekening Bank</label>
                                    <input type="text" name="rekening_bank" class="form-control"
                                           placeholder="Nomor rekening bank">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tanggal Daftar *</label>
                            <input type="date" name="tanggal_daftar" class="form-control" required 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="alert alert-info">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                Password default untuk login anggota: <strong>123456</strong>
                            </small>
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
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Data Anggota</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Kode Anggota *</label>
                                    <input type="text" name="kode_anggota" id="edit_kode" class="form-control" readonly
                                           style="background-color:#e9ecef">
                                    <small class="text-muted">Kode anggota digenerate otomatis dan tidak bisa diubah</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Nama Lengkap *</label>
                                    <input type="text" name="nama_lengkap" id="edit_nama" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Alamat</label>
                                    <textarea name="alamat" id="edit_alamat" class="form-control" rows="3"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Telepon</label>
                                    <input type="text" name="telepon" id="edit_telepon" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tempat Lahir</label>
                                    <input type="text" name="tempat_lahir" id="edit_tempat_lahir" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Tanggal Lahir</label>
                                    <input type="date" name="tanggal_lahir" id="edit_tanggal_lahir" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Jenis Kelamin</label>
                                    <select name="jenis_kelamin" id="edit_jenis_kelamin" class="form-control">
                                        <option value="L">Laki-laki</option>
                                        <option value="P">Perempuan</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Pekerjaan</label>
                                    <input type="text" name="pekerjaan" id="edit_pekerjaan" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">No. KTP *</label>
                                    <input type="text" name="ktp" id="edit_ktp" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                        </div>
                        <?php $dok_prefix = 'edit'; $dok_anggota = null; include __DIR__ . '/../includes/form_dokumen_anggota.php'; ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">No. Rekening Bank</label>
                                    <input type="text" name="rekening_bank" id="edit_rekening_bank" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" id="edit_status" class="form-control">
                                        <option value="aktif">Aktif</option>
                                        <option value="nonaktif">Nonaktif</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tanggal Daftar *</label>
                                    <input type="date" name="tanggal_daftar" id="edit_tanggal_daftar" class="form-control" readonly>
                                </div>
                            </div>
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

    <!-- Delete Confirmation Modal (Hanya untuk Admin) -->
    <?php if ($_SESSION['role'] == 'admin'): ?>
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title text-danger">Konfirmasi Hapus</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="delete_id">
                        <p>Apakah Anda yakin ingin menghapus anggota:</p>
                        <p class="fw-bold" id="delete_nama"></p>
                        <div class="alert alert-warning">
                            <small>
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Data yang dihapus tidak dapat dikembalikan. Anggota yang sudah memiliki transaksi, simpanan bersaldo, atau pinjaman tidak bisa dihapus &mdash; ubah statusnya menjadi Nonaktif.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="delete" class="btn btn-danger">Hapus</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../includes/modal_dokumen.php'; ?>
    <script>var DOKUMEN_LABEL = <?php echo json_encode(daftarDokumenAnggota()); ?>;</script>
    <script>
        var kategori = document.querySelector('#addModal .kategori-anggota');
        var kodeInput = document.getElementById('add_kode');
        var kodeBiasa = <?php echo json_encode($kode_otomatis); ?>;
        var kodeJaminan = <?php echo json_encode($kode_jaminan_otomatis); ?>;
        function sesuaikanKategoriAnggota() {
            var jaminan = kategori && kategori.value === 'jaminan';
            if (kodeInput) kodeInput.value = jaminan ? kodeJaminan : kodeBiasa;
            document.querySelectorAll('#addModal .input-dokumen').forEach(function(input) {
                var wajib = !jaminan || input.dataset.field === 'foto_ktp';
                var kolom = input.closest('.col-md-4');
                if (kolom) kolom.style.display = (jaminan && input.dataset.field !== 'foto_ktp') ? 'none' : '';
                input.required = wajib;
                input.dataset.wajib = wajib ? '1' : '0';
                var label = kolom ? kolom.querySelector('.label-dokumen') : null;
                if (label) label.textContent = label.dataset.label + (wajib ? ' *' : ' (opsional)');
            });
        }
        if (kategori) kategori.addEventListener('change', sesuaikanKategoriAnggota);
        sesuaikanKategoriAnggota();

        // Edit Modal
        var editModal = document.getElementById('editModal');
        editModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('edit_id').value = button.getAttribute('data-id');
            document.getElementById('edit_kode').value = button.getAttribute('data-kode');
            document.getElementById('edit_nama').value = button.getAttribute('data-nama');
            document.getElementById('edit_alamat').value = button.getAttribute('data-alamat');
            document.getElementById('edit_telepon').value = button.getAttribute('data-telepon');
            document.getElementById('edit_tempat_lahir').value = button.getAttribute('data-tempat_lahir');
            document.getElementById('edit_tanggal_lahir').value = button.getAttribute('data-tanggal_lahir');
            document.getElementById('edit_jenis_kelamin').value = button.getAttribute('data-jenis_kelamin');
            document.getElementById('edit_pekerjaan').value = button.getAttribute('data-pekerjaan');
            document.getElementById('edit_ktp').value = button.getAttribute('data-ktp');
            document.getElementById('edit_rekening_bank').value = button.getAttribute('data-rekening_bank');
            document.getElementById('edit_status').value = button.getAttribute('data-status');
            document.getElementById('edit_tanggal_daftar').value = button.getAttribute('data-tanggal_daftar');
            
            isiPreviewDokumen('edit', JSON.parse(button.getAttribute('data-dokumen') || '{}'), DOKUMEN_LABEL);
            editModal.querySelectorAll('.input-dokumen').forEach(function (i) { i.value = ''; });
        });

        // Delete Modal (Hanya untuk Admin)
        <?php if ($_SESSION['role'] == 'admin'): ?>
        var deleteModal = document.getElementById('deleteModal');
        deleteModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('delete_id').value = button.getAttribute('data-id');
            document.getElementById('delete_nama').textContent = button.getAttribute('data-nama');
        });
        <?php endif; ?>

        // Auto-generate kode anggota
        document.addEventListener('DOMContentLoaded', function() {
            // You can add auto-generation logic here if needed
        });
    </script>
</body>
</html>

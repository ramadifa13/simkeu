<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Upload melebihi post_max_size membuat $_POST kosong; tampilkan pesan yang jelas
cekPostMelebihiBatas();

// Kode anggota berikutnya untuk form tambah
$kode_otomatis = buatKodeAnggota($db);
$kode_jaminan_otomatis = buatKodeAnggotaJaminan($db);

// Handle form actions
if ($_POST) {
    if (isset($_POST['add'])) {
        // Kode dibuat server (kiriman form diabaikan agar tidak bisa bentrok/diubah)
        kunciKodeAnggota($db); // dilepas otomatis saat skrip selesai (redirect di akhir proses)
        $kategori_anggota = ($_POST['kategori_anggota'] ?? 'biasa') === 'jaminan' ? 'jaminan' : 'biasa';
        $kode_anggota = buatKodeAnggotaSesuaiKategori($db, $kategori_anggota);
        $nama_lengkap = trim($_POST['nama_lengkap']);
        $alamat = trim($_POST['alamat']);
        $telepon = trim($_POST['telepon']);
        $email = ''; // form tambah tidak memakai email
        $tempat_lahir = trim($_POST['tempat_lahir']);
        $tanggal_lahir = tanggalAtauNull(isset($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : '');
        $jenis_kelamin = $_POST['jenis_kelamin'];
        $pekerjaan = trim($_POST['pekerjaan']);
        $ktp = trim($_POST['ktp']);
        $rekening_bank = trim($_POST['rekening_bank']);
        $tanggal_daftar = $_POST['tanggal_daftar'];
        list($kategori_shu_input,$ketua_kelompok_input)=shuProfilUntukKode($kode_anggota,$_POST['kategori_shu'] ?? 'anggota',$_POST['ketua_kelompok_id'] ?? 0);
        
        // Validasi input
        $errors = [];
        if (empty($kode_anggota)) $errors[] = "Kode anggota harus diisi";
        if (empty($nama_lengkap)) $errors[] = "Nama lengkap harus diisi";
        if (empty($ktp)) $errors[] = "No. KTP harus diisi";
        if (empty($tanggal_daftar)) $errors[] = "Tanggal daftar harus diisi";
        
        $foto_ktp = null; // diisi simpanDokumenAnggota()
        
        // Jika tidak ada error, proses simpan
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                list($kategori_shu, $ketua_kelompok_id) = shuValidasiProfil($db, 0, $kategori_shu_input, $ketua_kelompok_input);
                
                // Validasi dokumen (finfo, maks. 10 MB) sebelum data disimpan
                $dokumen = validasiDokumenAnggota(null, $kategori_anggota === 'jaminan');
                
                // Check if kode anggota already exists
                $check_query = "SELECT id FROM anggota WHERE kode_anggota = :kode_anggota";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':kode_anggota', $kode_anggota);
                $check_stmt->execute();
                
                if ($check_stmt->rowCount() > 0) {
                    throw new Exception("Kode anggota sudah digunakan");
                }
                
                // Check if KTP already exists
                $check_ktp_query = "SELECT id FROM anggota WHERE ktp = :ktp";
                $check_ktp_stmt = $db->prepare($check_ktp_query);
                $check_ktp_stmt->bindParam(':ktp', $ktp);
                $check_ktp_stmt->execute();
                
                if ($check_ktp_stmt->rowCount() > 0) {
                    throw new Exception("No. KTP sudah terdaftar");
                }
                
                // Insert user account for anggota
                $password = password_hash('123456', PASSWORD_DEFAULT);
                $query_user = "INSERT INTO users (username, password, nama_lengkap, role, status, created_at) 
                              VALUES (:username, :password, :nama_lengkap, 'anggota', 'aktif', NOW())";
                $stmt_user = $db->prepare($query_user);
                $stmt_user->bindParam(':username', $kode_anggota);
                $stmt_user->bindParam(':password', $password);
                $stmt_user->bindParam(':nama_lengkap', $nama_lengkap);
                
                if (!$stmt_user->execute()) {
                    throw new Exception("Gagal membuat akun user");
                }
                
                $user_id = $db->lastInsertId();
                
                // Insert anggota data
                $query_anggota = "INSERT INTO anggota (
                    kode_anggota, nama_lengkap, alamat, telepon, email, 
                    tempat_lahir, tanggal_lahir, jenis_kelamin, pekerjaan, 
                    ktp, foto_ktp, rekening_bank, user_id, tanggal_daftar, kategori_shu, ketua_kelompok_id, status, created_at
                ) VALUES (
                    :kode_anggota, :nama_lengkap, :alamat, :telepon, :email,
                    :tempat_lahir, :tanggal_lahir, :jenis_kelamin, :pekerjaan,
                    :ktp, :foto_ktp, :rekening_bank, :user_id, :tanggal_daftar, :kategori_shu, :ketua_kelompok_id, 'aktif', NOW()
                )";
                
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
                $stmt_anggota->bindValue(':kategori_shu', $kategori_shu);
                $stmt_anggota->bindValue(':ketua_kelompok_id', $ketua_kelompok_id, $ketua_kelompok_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                
                if (!$stmt_anggota->execute()) {
                    throw new Exception("Gagal menyimpan data anggota");
                }
                
                $anggota_id = $db->lastInsertId();
                
                simpanDokumenAnggota($db, $anggota_id, $dokumen);
                
                // Create default simpanan records
                $query_jenis = "SELECT id FROM jenis_simpanan WHERE status = 'aktif'";
                $stmt_jenis = $db->prepare($query_jenis);
                $stmt_jenis->execute();
                $jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($jenis_simpanan)) {
                    $query_simpanan = "INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo, created_at) 
                                      VALUES (:anggota_id, :jenis_simpanan_id, 0, NOW())";
                    $stmt_simpanan = $db->prepare($query_simpanan);
                    
                    foreach ($jenis_simpanan as $jenis) {
                        $stmt_simpanan->bindParam(':anggota_id', $anggota_id);
                        $stmt_simpanan->bindParam(':jenis_simpanan_id', $jenis['id']);
                        
                        if (!$stmt_simpanan->execute()) {
                            throw new Exception("Gagal membuat data simpanan");
                        }
                    }
                }
                
                $db->commit();
                $_SESSION['success'] = "Anggota berhasil ditambahkan. Password default: 123456";
                
                // Redirect to refresh page
                header("Location: anggota.php");
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = "Gagal menambahkan anggota: " . $e->getMessage();
                
                // Redirect to refresh page
                header("Location: anggota.php");
                exit();
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
            header("Location: anggota.php");
            exit();
        }
    }
    
    if (isset($_POST['edit'])) {
        $id = $_POST['id'];
        // Kode anggota TIDAK bisa diubah: selalu diambil dari database berdasarkan ID (kiriman form diabaikan)
        $stmt_kode = $db->prepare("SELECT kode_anggota FROM anggota WHERE id = ?");
        $stmt_kode->execute([$id]);
        $kode_anggota = (string) $stmt_kode->fetchColumn();
        $nama_lengkap = trim($_POST['nama_lengkap']);
        $alamat = trim($_POST['alamat']);
        $telepon = trim($_POST['telepon']);
        $tempat_lahir = trim($_POST['tempat_lahir']);
        $tanggal_lahir = tanggalAtauNull(isset($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : '');
        $jenis_kelamin = $_POST['jenis_kelamin'];
        $pekerjaan = trim($_POST['pekerjaan']);
        $ktp = trim($_POST['ktp']);
        $rekening_bank = trim($_POST['rekening_bank']);
        $status = $_POST['status'];
        list($kategori_shu_input,$ketua_kelompok_input)=shuProfilUntukKode($kode_anggota,$_POST['kategori_shu'] ?? 'anggota',$_POST['ketua_kelompok_id'] ?? 0);
        
        // Validasi
        $errors = [];
        if (empty($kode_anggota)) $errors[] = "Kode anggota harus diisi";
        if (empty($nama_lengkap)) $errors[] = "Nama lengkap harus diisi";
        if (empty($ktp)) $errors[] = "No. KTP harus diisi";
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                list($kategori_shu, $ketua_kelompok_id) = shuValidasiProfil($db, $id, $kategori_shu_input, $ketua_kelompok_input);
                
                // Dokumen lama (untuk cek wajib & hapus file yang diganti)
                $stmt_dok = $db->prepare("SELECT foto_ktp, foto_kk, foto_ktp_pasangan FROM anggota WHERE id = ?");
                $stmt_dok->execute([$id]);
                $dokumen_lama = $stmt_dok->fetch(PDO::FETCH_ASSOC);
                $dokumen = validasiDokumenAnggota($dokumen_lama);
                
                // Check if KTP already exists (excluding current record)
                $check_ktp_query = "SELECT id FROM anggota WHERE ktp = :ktp AND id != :id";
                $check_ktp_stmt = $db->prepare($check_ktp_query);
                $check_ktp_stmt->bindParam(':ktp', $ktp);
                $check_ktp_stmt->bindParam(':id', $id);
                $check_ktp_stmt->execute();
                
                if ($check_ktp_stmt->rowCount() > 0) {
                    throw new Exception("No. KTP sudah terdaftar");
                }
            
                // kode_anggota & email sengaja tidak di-UPDATE (kode tetap, email lama tidak tertimpa)
                $query = "UPDATE anggota SET
                    nama_lengkap = :nama_lengkap,
                    alamat = :alamat,
                    telepon = :telepon,
                    tempat_lahir = :tempat_lahir,
                    tanggal_lahir = :tanggal_lahir, 
                    jenis_kelamin = :jenis_kelamin, 
                    pekerjaan = :pekerjaan,
                    ktp = :ktp, 
                    rekening_bank = :rekening_bank, 
                    status = :status,
                    kategori_shu = :kategori_shu,
                    ketua_kelompok_id = :ketua_kelompok_id
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
                $stmt->bindValue(':kategori_shu', $kategori_shu);
                $stmt->bindValue(':ketua_kelompok_id', $ketua_kelompok_id, $ketua_kelompok_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

                if (!$stmt->execute()) {
                    throw new Exception("Gagal mengupdate data anggota");
                }
                
                // Update user data
                $query_user = "UPDATE users SET 
                    username = :username, 
                    nama_lengkap = :nama_lengkap, 
                    status = :status 
                    WHERE id = (SELECT user_id FROM anggota WHERE id = :id)";
                    
                $stmt_user = $db->prepare($query_user);
                $stmt_user->bindParam(':username', $kode_anggota);
                $stmt_user->bindParam(':nama_lengkap', $nama_lengkap);
                $stmt_user->bindParam(':status', $status);
                $stmt_user->bindParam(':id', $id);
                
                if (!$stmt_user->execute()) {
                    throw new Exception("Gagal mengupdate data user");
                }
                
                simpanDokumenAnggota($db, $id, $dokumen, $dokumen_lama);
                
                $db->commit();
                $_SESSION['success'] = "Data anggota berhasil diupdate";
                
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = "Gagal mengupdate anggota: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
        }
        
        header("Location: anggota.php");
        exit();
    }
    
    if (isset($_POST['reset_password'])) {
        $id = $_POST['id'];
        $new_password = password_hash('123456', PASSWORD_DEFAULT);
        
        try {
            $query = "UPDATE users SET password = :password 
                      WHERE id = (SELECT user_id FROM anggota WHERE id = :id)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':password', $new_password);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Password berhasil direset ke '123456'";
            } else {
                throw new Exception("Gagal reset password");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        
        header("Location: anggota.php");
        exit();
    }
    
    if (isset($_POST['delete'])) {
        $id = $_POST['id'];
        
        try {
            hapusAnggota($db, $id);
            $_SESSION['success'] = "Anggota berhasil dihapus";
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal menghapus anggota: " . $e->getMessage();
        }
        
        header("Location: anggota.php");
        exit();
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
    $query .= " AND (a.kode_anggota LIKE :search_kode OR a.nama_lengkap LIKE :search_nama OR a.telepon LIKE :search_telepon OR a.ktp LIKE :search_ktp)";
    $nilai_search = "%$filter_search%";
    $params += [':search_kode'=>$nilai_search, ':search_nama'=>$nilai_search, ':search_telepon'=>$nilai_search, ':search_ktp'=>$nilai_search];
}

$query .= " ORDER BY a.created_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$anggota_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
$ketua_shu_list = array_values(array_filter($anggota_list, function($a){ return ($a['kategori_shu'] ?? 'anggota') === 'ketua_kelompok'; }));

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Data Anggota - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            overflow-x: hidden;
        }
        
        .container-fluid {
            padding-left: 0;
            padding-right: 0;
        }
        
        @media (max-width: 767.98px) {
            main {
                margin-left: 0 !important;
                padding: 10px 12px !important;
                width: 100%;
                margin-top: 60px !important;
            }
            
            .row {
                margin-left: -5px;
                margin-right: -5px;
            }
            
            .col-md-3, .col-md-4, .col-md-6 {
                padding-left: 5px;
                padding-right: 5px;
            }
            
            .stat-card .card-body {
                padding: 12px 10px;
            }
            
            .stat-card h4 {
                font-size: 1.2rem;
            }
            
            .stat-card p {
                font-size: 0.8rem;
                margin-bottom: 0;
            }
            
            .filter-card {
                padding: 1rem !important;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 10px;
            }
            
            .table-responsive {
                margin-bottom: 0;
            }
            
            .table td, .table th {
                padding: 0.5rem 0.3rem;
                font-size: 0.75rem;
                white-space: nowrap;
            }
            
            .table-actions {
                display: flex;
                gap: 2px;
            }
            
            .table-actions .btn-sm {
                padding: 0.2rem 0.4rem;
                font-size: 0.7rem;
            }
            
            .avatar {
                width: 30px !important;
                height: 30px !important;
                font-size: 0.8rem !important;
                margin-right: 0.5rem !important;
            }
            
            @media (max-width: 575.98px) {
                .table th:nth-child(6),
                .table td:nth-child(6),
                .table th:nth-child(7),
                .table td:nth-child(7) {
                    display: none;
                }
            }
            
            @media (max-width: 480px) {
                .table th:nth-child(5),
                .table td:nth-child(5) {
                    display: none;
                }
            }
        }
        
        @media (min-width: 768px) and (max-width: 991.98px) {
            main {
                margin-left: 0 !important;
                padding: 15px 20px !important;
            }
        }
        
        @media (min-width: 992px) {
            main {
                margin-left: 280px !important;
                padding: 10px 20px !important;
            }
        }
        
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            background: white;
            height: 100%;
            margin-bottom: 10px;
            overflow: hidden;
        }
        
        .stat-card.bg-primary { background: linear-gradient(135deg, #2c3e50, #3498db) !important; }
        .stat-card.bg-success { background: linear-gradient(135deg, #27ae60, #2ecc71) !important; }
        .stat-card.bg-warning { background: linear-gradient(135deg, #f39c12, #f1c40f) !important; }
        .stat-card.bg-info { background: linear-gradient(135deg, #17a2b8, #3498db) !important; }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .filter-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.03);
        }
        
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: white;
            border-bottom: 1px solid #f0f0f0;
            padding: 1rem 1.25rem;
        }
        
        .card-body {
            padding: 1.25rem;
        }
        
        @media (max-width: 767.98px) {
            .card-body {
                padding: 0.75rem;
            }
        }
        
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: #7f8c8d;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 1px solid #dee2e6;
            background-color: #f8f9fa;
        }
        
        .table td {
            font-size: 0.8rem;
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .table-actions {
            white-space: nowrap;
            display: flex;
            gap: 4px;
        }
        
        .badge {
            padding: 0.4rem 0.6rem;
            font-weight: 500;
            font-size: 0.7rem;
            border-radius: 20px;
        }
        
        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
            border-radius: 6px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
        }
        
        .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
        }
        
        /* Form berada di antara .modal-content dan body/footer: tanpa ini footer (tombol
           Simpan/Update) terpotong saat isi modal tinggi di modal-dialog-scrollable */
        .modal-dialog-scrollable .modal-content > form {
            display: flex;
            flex-direction: column;
            min-height: 0;
            max-height: 100%;
            overflow: hidden;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            border-bottom: none;
            padding: 1.2rem 1.5rem;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .modal-footer {
            border-top: 1px solid #f0f0f0;
            padding: 1rem 1.5rem;
        }
        
        .form-label {
            font-size: 0.85rem;
            font-weight: 500;
            color: #495057;
            margin-bottom: 0.3rem;
        }
        
        .form-control, .form-select {
            font-size: 0.9rem;
            padding: 0.6rem 0.75rem;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
        }
        
        @media (max-width: 767.98px) {
            .form-control, .form-select {
                font-size: 0.85rem;
                padding: 0.5rem 0.65rem;
            }
            
            .modal-dialog {
                margin: 0.5rem;
            }
            
            .modal-body {
                padding: 1rem;
            }
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #95a5a6;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #bdc3c7;
        }
        
        @media (max-width: 767.98px) {
            .empty-state {
                padding: 30px 15px;
            }
            
            .empty-state i {
                font-size: 3rem;
            }
        }
        
        .btn:active {
            transform: scale(0.98);
        }
        
        @media screen and (-webkit-min-device-pixel-ratio:0) { 
            select:focus,
            textarea:focus,
            input:focus {
                font-size: 16px;
            }
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
                    <h1 class="h4">
                        <i class="fas fa-users me-2"></i>Data Anggota
                    </h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="fas fa-plus"></i> 
                        <span class="d-none d-sm-inline">Tambah Anggota</span>
                        <span class="d-sm-none">Tambah</span>
                    </button>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-6 col-md-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="mb-0"><?php echo $stats['total_anggota']; ?></h4>
                                        <p class="mb-0 small">Total Anggota</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-users fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="mb-0"><?php echo $stats['aktif']; ?></h4>
                                        <p class="mb-0 small">Aktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mt-2 mt-md-0">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="mb-0"><?php echo $stats['nonaktif']; ?></h4>
                                        <p class="mb-0 small">Nonaktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-times-circle fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mt-2 mt-md-0">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4 class="mb-0"><?php echo count($anggota_list); ?></h4>
                                        <p class="mb-0 small">Ditampilkan</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-filter fa-2x opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">Semua Status</option>
                                    <option value="aktif" <?php echo $filter_status == 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                    <option value="nonaktif" <?php echo $filter_status == 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Pencarian</label>
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Cari kode, nama, telepon, KTP..."
                                       value="<?php echo htmlspecialchars($filter_search); ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-1"></i> 
                                    <span class="d-none d-sm-inline">Filter</span>
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
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted small d-none d-sm-inline">
                                <?php echo count($anggota_list); ?> data
                            </span>
                            <a href="export_anggota.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success btn-sm">
                                <i class="fas fa-file-excel me-1"></i> 
                                <span class="d-none d-sm-inline">Export</span>
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-0 p-md-3">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 ui-members-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Kode</th>
                                        <th>Nama Lengkap</th>
                                        <th>Peran SHU</th>
                                        <th>Telepon</th>
                                        <th class="mobile-hide">KTP</th>
                                        <th class="mobile-hide">Rekening</th>
                                        <th>Status</th>
                                        <th class="mobile-hide">Tgl Daftar</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($anggota_list)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-5">
                                            <div class="empty-state">
                                                <i class="fas fa-users mb-3"></i>
                                                <p class="mb-3">Tidak ada data anggota</p>
                                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                                                    <i class="fas fa-plus me-1"></i> Tambah Anggota Pertama
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($anggota_list as $index => $anggota): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <span class="fw-semibold"><?php echo htmlspecialchars($anggota['kode_anggota']); ?></span>
                                                <?php if (!empty($anggota['kode_anggota_jaminan'])): ?>
                                                    <br><span class="badge bg-warning text-dark">Gadai: <?php echo htmlspecialchars($anggota['kode_anggota_jaminan']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar me-2">
                                                        <?php echo strtoupper(substr($anggota['nama_lengkap'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-semibold text-truncate" style="max-width: 150px;">
                                                            <?php echo htmlspecialchars($anggota['nama_lengkap']); ?>
                                                        </div>

                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php if(strpos($anggota['kode_anggota'],'AGTJ')===0): ?><span class="text-muted">Tidak berlaku</span><?php else: ?><span class="badge <?= ($anggota['kategori_shu'] ?? 'anggota')==='ketua_kelompok'?'bg-primary':'bg-secondary' ?>"><?= ($anggota['kategori_shu'] ?? 'anggota')==='ketua_kelompok'?'Ketua Kelompok':'Anggota' ?></span><?php endif; ?></td>
                                            <td class="d-none d-sm-table-cell"><?php echo layananEscape($anggota['telepon'] ?: '-'); ?><br><?php echo tombolWhatsApp($anggota['telepon']); ?></td>
                                            <td class="mobile-hide">
                                                <?php $dok_url = array_filter(array_map('urlDokumenAnggota', array_intersect_key($anggota, daftarDokumenAnggota()))); ?>
                                                <?php if (!empty($dok_url)): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-info"
                                                            onclick="lihatDokumenAnggota(<?php echo htmlspecialchars(json_encode($anggota['nama_lengkap']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((object) $dok_url), ENT_QUOTES); ?>)"
                                                            title="Lihat KTP, KK &amp; KTP suami/istri">
                                                        <i class="fas fa-eye"></i> <span class="d-none d-xl-inline">Lihat</span> <span class="badge bg-info"><?php echo count($dok_url); ?>/3</span>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="mobile-hide">
                                                <?php if ($anggota['rekening_bank']): ?>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($anggota['rekening_bank']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $anggota['status'] == 'aktif' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($anggota['status']); ?>
                                                </span>
                                            </td>
                                            <td class="mobile-hide"><?php echo date('d/m/Y', strtotime($anggota['tanggal_daftar'])); ?></td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="detail_anggota.php?id=<?php echo $anggota['id']; ?>" class="btn btn-sm btn-primary" title="Detail">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" 
                                                            data-bs-target="#editModal" 
                                                            data-id="<?php echo $anggota['id']; ?>"
                                                            data-kode="<?php echo htmlspecialchars($anggota['kode_anggota']); ?>"
                                                            data-nama="<?php echo htmlspecialchars($anggota['nama_lengkap']); ?>"
                                                            data-alamat="<?php echo htmlspecialchars($anggota['alamat']); ?>"
                                                            data-telepon="<?php echo htmlspecialchars($anggota['telepon']); ?>"
                                                            data-email="<?php echo htmlspecialchars($anggota['email']); ?>"
                                                            data-tempat_lahir="<?php echo htmlspecialchars($anggota['tempat_lahir']); ?>"
                                                            data-tanggal_lahir="<?php echo $anggota['tanggal_lahir']; ?>"
                                                            data-jenis_kelamin="<?php echo $anggota['jenis_kelamin']; ?>"
                                                            data-pekerjaan="<?php echo htmlspecialchars($anggota['pekerjaan']); ?>"
                                                            data-ktp="<?php echo htmlspecialchars($anggota['ktp']); ?>"
                                                            data-dokumen="<?php echo htmlspecialchars(json_encode(array_map('urlDokumenAnggota', array_intersect_key($anggota, daftarDokumenAnggota()))), ENT_QUOTES); ?>"
                                                            data-rekening_bank="<?php echo htmlspecialchars($anggota['rekening_bank']); ?>"
                                                            data-status="<?php echo $anggota['status']; ?>"
                                                            data-tanggal_daftar="<?php echo $anggota['tanggal_daftar']; ?>"
                                                            data-kategori_shu="<?= htmlspecialchars($anggota['kategori_shu'] ?? 'anggota') ?>"
                                                            data-ketua_kelompok_id="<?= (int)($anggota['ketua_kelompok_id'] ?? 0) ?>"
                                                            title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" class="d-inline"><?php echo layananToken(); ?>
                                                        <input type="hidden" name="id" value="<?php echo $anggota['id']; ?>">
                                                        <button type="submit" name="reset_password" class="btn btn-sm btn-info" 
                                                                onclick="return confirm('Reset password ke 123456?')" title="Reset Password">
                                                            <i class="fas fa-key"></i>
                                                        </button>
                                                    </form>
                                                    <button class="btn btn-sm btn-danger" data-bs-toggle="modal" 
                                                            data-bs-target="#deleteModal" 
                                                            data-id="<?php echo $anggota['id']; ?>"
                                                            data-nama="<?php echo htmlspecialchars($anggota['nama_lengkap']); ?>"
                                                            title="Hapus">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
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
    <div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-user-plus me-2"></i>Tambah Anggota Baru
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Kategori Anggota <span class="text-danger">*</span></label>
                            <select name="kategori_anggota" class="form-select kategori-anggota" required>
                                <option value="biasa">Anggota Biasa (kode AGT)</option>
                                <option value="jaminan">Anggota Jaminan/Gadai (kode AGTJ)</option>
                            </select>
                            <small class="text-muted">Akun login dibuat satu kali menggunakan kode saat pertama didaftarkan.</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Kode Anggota <span class="text-danger">*</span></label>
                                    <input type="text" name="kode_anggota" id="add_kode" class="form-control" required readonly
                                           value="<?php echo htmlspecialchars($kode_otomatis); ?>">
                                    <small class="text-muted">Dibuat otomatis oleh sistem</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                    <input type="text" name="nama_lengkap" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Alamat</label>
                                    <textarea name="alamat" class="form-control" rows="2" placeholder="Alamat lengkap"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Telepon</label>
                                    <input type="tel" name="telepon" class="form-control" placeholder="08xxxxxxxxxx">
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
                                    <select name="jenis_kelamin" class="form-select">
                                        <option value="L">Laki-laki</option>
                                        <option value="P">Perempuan</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Pekerjaan</label>
                                    <input type="text" name="pekerjaan" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">No. KTP <span class="text-danger">*</span></label>
                                    <input type="text" name="ktp" class="form-control" required
                                           placeholder="16 digit nomor KTP" maxlength="16">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6 shu-role-wrap"><label class="form-label">Peran SHU *</label><select name="kategori_shu" id="add_kategori_shu" class="form-select shu-role" required><option value="anggota">Anggota (1%)</option><option value="ketua_kelompok">Ketua Kelompok (2%)</option></select></div>
                            <div class="col-md-6 shu-leader-wrap"><label class="form-label">Ketua yang mengajak</label><select name="ketua_kelompok_id" class="form-select shu-leader"><option value="">Tidak ada</option><?php foreach($ketua_shu_list as $k): ?><option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['nama_lengkap'].' · '.$k['kode_anggota']) ?></option><?php endforeach; ?></select></div>
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
                            <label class="form-label">Tanggal Daftar <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_daftar" class="form-control" required 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            Password default untuk login: <strong>123456</strong>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-edit me-2"></i>Edit Data Anggota
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Kode Anggota <span class="text-danger">*</span></label>
                                    <input type="text" name="kode_anggota" id="edit_kode" class="form-control" readonly
                                           style="background-color:#e9ecef">
                                    <small class="text-muted">Kode anggota digenerate otomatis dan tidak bisa diubah</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                    <input type="text" name="nama_lengkap" id="edit_nama" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Alamat</label>
                                    <textarea name="alamat" id="edit_alamat" class="form-control" rows="2"></textarea>
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
                                    <select name="jenis_kelamin" id="edit_jenis_kelamin" class="form-select">
                                        <option value="L">Laki-laki</option>
                                        <option value="P">Perempuan</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Pekerjaan</label>
                                    <input type="text" name="pekerjaan" id="edit_pekerjaan" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">No. KTP <span class="text-danger">*</span></label>
                                    <input type="text" name="ktp" id="edit_ktp" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6 shu-role-wrap"><label class="form-label">Peran SHU *</label><select name="kategori_shu" id="edit_kategori_shu" class="form-select shu-role" required><option value="anggota">Anggota (1%)</option><option value="ketua_kelompok">Ketua Kelompok (2%)</option></select></div>
                            <div class="col-md-6 shu-leader-wrap"><label class="form-label">Ketua yang mengajak</label><select name="ketua_kelompok_id" id="edit_ketua_kelompok_id" class="form-select shu-leader"><option value="">Tidak ada</option><?php foreach($ketua_shu_list as $k): ?><option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['nama_lengkap'].' · '.$k['kode_anggota']) ?></option><?php endforeach; ?></select></div>
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
                                    <select name="status" id="edit_status" class="form-select">
                                        <option value="aktif">Aktif</option>
                                        <option value="nonaktif">Nonaktif</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tanggal Daftar</label>
                                    <input type="date" name="tanggal_daftar" id="edit_tanggal_daftar" class="form-control" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="delete_id">
                        <p class="mb-3">Apakah Anda yakin ingin menghapus anggota:</p>
                        <p class="fw-bold text-center p-3 bg-light rounded" id="delete_nama"></p>
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Data yang dihapus tidak dapat dikembalikan. Anggota yang sudah memiliki transaksi, simpanan bersaldo, atau pinjaman tidak bisa dihapus &mdash; ubah statusnya menjadi Nonaktif.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="delete" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Hapus
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../includes/modal_dokumen.php'; ?>
    <script>var DOKUMEN_LABEL = <?php echo json_encode(daftarDokumenAnggota()); ?>;</script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
                var addForm=document.querySelector('#addModal form');
                if(addForm)tampilkanKetua(addForm);
            }
            if (kategori) kategori.addEventListener('change', sesuaikanKategoriAnggota);
            sesuaikanKategoriAnggota();

            function tampilkanKetua(form) {
                var role=form.querySelector('.shu-role'), wrap=form.querySelector('.shu-leader-wrap'), leader=form.querySelector('.shu-leader');
                var code=form.querySelector('[name="kode_anggota"]');
                var khususJaminan=code && code.value.indexOf('AGTJ')===0;
                var ketua=role && role.value==='ketua_kelompok';
                var roleWrap=role && role.closest('.col-md-6');
                if(roleWrap)roleWrap.style.display=khususJaminan?'none':'';
                if(role){role.disabled=khususJaminan;if(khususJaminan)role.value='anggota';}
                if(khususJaminan)ketua=false;
                if(wrap) wrap.style.display=ketua?'none':'';
                if(wrap && khususJaminan)wrap.style.display='none';
                if(leader){leader.disabled=ketua||khususJaminan;if(ketua||khususJaminan)leader.value='';}
            }
            document.querySelectorAll('#addModal form,#editModal form').forEach(function(form){var role=form.querySelector('.shu-role');if(role)role.addEventListener('change',function(){tampilkanKetua(form);});tampilkanKetua(form);});

            // Edit Modal
            var editModal = document.getElementById('editModal');
            if (editModal) {
                editModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    document.getElementById('edit_id').value = button.getAttribute('data-id') || '';
                    document.getElementById('edit_kode').value = button.getAttribute('data-kode') || '';
                    document.getElementById('edit_nama').value = button.getAttribute('data-nama') || '';
                    document.getElementById('edit_alamat').value = button.getAttribute('data-alamat') || '';
                    document.getElementById('edit_telepon').value = button.getAttribute('data-telepon') || '';
                    document.getElementById('edit_tempat_lahir').value = button.getAttribute('data-tempat_lahir') || '';
                    document.getElementById('edit_tanggal_lahir').value = button.getAttribute('data-tanggal_lahir') || '';
                    document.getElementById('edit_jenis_kelamin').value = button.getAttribute('data-jenis_kelamin') || 'L';
                    document.getElementById('edit_pekerjaan').value = button.getAttribute('data-pekerjaan') || '';
                    document.getElementById('edit_ktp').value = button.getAttribute('data-ktp') || '';
                    document.getElementById('edit_rekening_bank').value = button.getAttribute('data-rekening_bank') || '';
                    document.getElementById('edit_status').value = button.getAttribute('data-status') || 'aktif';
                    document.getElementById('edit_tanggal_daftar').value = button.getAttribute('data-tanggal_daftar') || '';
                    document.getElementById('edit_kategori_shu').value = button.getAttribute('data-kategori_shu') || 'anggota';
                    document.getElementById('edit_ketua_kelompok_id').value = button.getAttribute('data-ketua_kelompok_id') || '';
                    tampilkanKetua(editModal.querySelector('form'));
                    
                    isiPreviewDokumen('edit', JSON.parse(button.getAttribute('data-dokumen') || '{}'), DOKUMEN_LABEL);
                    var khususJaminan = (button.getAttribute('data-kode') || '').indexOf('AGTJ') === 0;
                    editModal.querySelectorAll('.input-dokumen').forEach(function (input) {
                        var sembunyikan = khususJaminan && input.dataset.field !== 'foto_ktp';
                        input.value = '';
                        input.required = false;
                        input.disabled = sembunyikan;
                        var kolom = input.closest('.col-md-4');
                        if (kolom) kolom.style.display = sembunyikan ? 'none' : '';
                    });
                });
            }

            // Delete Modal
            var deleteModal = document.getElementById('deleteModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    document.getElementById('delete_id').value = button.getAttribute('data-id') || '';
                    document.getElementById('delete_nama').textContent = button.getAttribute('data-nama') || '';
                });
            }

            // Auto-dismiss alerts after 5 seconds
            var alerts = document.querySelectorAll('.alert-success'); // pesan error tetap tampil sampai ditutup
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    var bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });

            // Form validation untuk add
            var addForm = document.querySelector('#addModal form');
            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                    var kode = this.querySelector('[name="kode_anggota"]').value;
                    var nama = this.querySelector('[name="nama_lengkap"]').value;
                    var ktp = this.querySelector('[name="ktp"]').value;
                    
                    if (!kode || !nama || !ktp) {
                        e.preventDefault();
                        alert('Harap isi field yang wajib (Kode Anggota, Nama Lengkap, No. KTP)');
                        return false;
                    }
                });
            }
        });
    </script>
</body>
</html>

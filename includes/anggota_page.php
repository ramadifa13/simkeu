<?php
/**
 * Single implementation — Data Anggota (Admin & Teller).
 * Entry: admin|teller/anggota.php (thin wrappers).
 * Hapus anggota: Admin only.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}
if (!isAdmin() && !isTeller()) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$bolehHapusAnggota = isAdmin();

// Upload melebihi post_max_size membuat $_POST kosong; tampilkan pesan yang jelas
redirectJikaPostMelebihiBatas('anggota.php');

// Kode anggota berikutnya untuk form tambah
$kode_otomatis = buatKodeAnggota($db);
$kode_jaminan_otomatis = buatKodeAnggotaJaminan($db);

// Handle form actions
if ($_POST) {
    if (isset($_POST['add'])) {
        try {
            layananValidasiToken();
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: anggota.php');
            exit;
        }
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
    
    if (($_POST['aksi'] ?? '') === 'edit' || isset($_POST['edit'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $nama_lengkap = trim((string) ($_POST['nama_lengkap'] ?? ''));
        $alamat = trim((string) ($_POST['alamat'] ?? ''));
        $telepon = trim((string) ($_POST['telepon'] ?? ''));
        $tempat_lahir = trim((string) ($_POST['tempat_lahir'] ?? ''));
        $tanggal_lahir = tanggalAtauNull(isset($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : '');
        // Data lama dapat belum memiliki jenis kelamin; gunakan pilihan awal form agar tetap dapat diperbarui.
        $jenis_kelamin = $_POST['jenis_kelamin'] ?? 'L';
        if ($jenis_kelamin === '') $jenis_kelamin = 'L';
        $pekerjaan = trim((string) ($_POST['pekerjaan'] ?? ''));
        $ktp = trim((string) ($_POST['ktp'] ?? ''));
        $rekening_bank = trim((string) ($_POST['rekening_bank'] ?? ''));
        $status = $_POST['status'] ?? '';
        
        try {
            layananValidasiToken();
            if ($id < 1) throw new Exception('Anggota tidak ditemukan.');
            if ($nama_lengkap === '') throw new Exception('Nama lengkap wajib diisi.');
            if (!in_array($status, ['aktif','nonaktif'], true)) throw new Exception('Status anggota tidak valid.');
            if (!in_array($jenis_kelamin, ['L','P'], true)) throw new Exception('Jenis kelamin tidak valid.');
            $db->beginTransaction();
            // Ambil kode dan akun asli dari database agar tidak dapat dimanipulasi dari form.
            $stmt_anggota = $db->prepare("SELECT id, kode_anggota, user_id, foto_ktp, foto_kk, foto_ktp_pasangan FROM anggota WHERE id = ? FOR UPDATE");
            $stmt_anggota->execute([$id]);
            $anggota_lama = $stmt_anggota->fetch(PDO::FETCH_ASSOC);
            if (!$anggota_lama) throw new Exception('Anggota tidak ditemukan.');
            $kode_anggota = (string) $anggota_lama['kode_anggota'];
            list($kategori_shu_input,$ketua_kelompok_input)=shuProfilUntukKode($kode_anggota,$_POST['kategori_shu'] ?? 'anggota',$_POST['ketua_kelompok_id'] ?? 0);
            list($kategori_shu, $ketua_kelompok_id) = shuValidasiProfil($db, $id, $kategori_shu_input, $ketua_kelompok_input);
            
            // Dokumen lama (untuk cek wajib & hapus file yang diganti)
            $dokumen_lama = $anggota_lama;
            $dokumen = validasiDokumenAnggota($dokumen_lama);
            
            // No. KTP wajib & unik (sama dengan Teller)
            if (trim($ktp) === '') {
                throw new Exception("No. KTP harus diisi");
            }
            $check_ktp_stmt = $db->prepare("SELECT id FROM anggota WHERE ktp = :ktp AND id != :id");
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
            
            // Nama/status di akun login anggota harus selalu sama dengan daftar anggota.
            $user_id = (int) ($anggota_lama['user_id'] ?? 0);
            if ($user_id <= 0) {
                $cari_user = $db->prepare("SELECT id, role FROM users WHERE username = ? FOR UPDATE");
                $cari_user->execute([$kode_anggota]);
                $akun_lama = $cari_user->fetch(PDO::FETCH_ASSOC);
                if ($akun_lama) {
                    if ($akun_lama['role'] !== 'anggota') throw new Exception('Kode anggota sudah dipakai akun selain anggota.');
                    $user_id = (int) $akun_lama['id'];
                } else {
                    $buat_user = $db->prepare("INSERT INTO users (username, password, nama_lengkap, role, status) VALUES (?, ?, ?, 'anggota', ?)");
                    $buat_user->execute([$kode_anggota, password_hash('123456', PASSWORD_DEFAULT), $nama_lengkap, $status]);
                    $user_id = (int) $db->lastInsertId();
                }
                $tautkan_user = $db->prepare("UPDATE anggota SET user_id = ? WHERE id = ?");
                if (!$tautkan_user->execute([$user_id, $id])) throw new Exception('Gagal menautkan akun login anggota.');
            }
            $stmt_user = $db->prepare("UPDATE users SET username = ?, nama_lengkap = ?, status = ? WHERE id = ? AND role = 'anggota'");
            $stmt_user->execute([$kode_anggota, $nama_lengkap, $status, $user_id]);
            if ($stmt_user->rowCount() === 0) {
                $cek_user = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'anggota'");
                $cek_user->execute([$user_id]);
                if (!$cek_user->fetchColumn()) throw new Exception('Akun login anggota tidak ditemukan atau bukan akun anggota.');
            }
            
            simpanDokumenAnggota($db, $id, $dokumen, $dokumen_lama);
            
            $db->commit();
            $_SESSION['success'] = "Data anggota dan akun login anggota berhasil diupdate";
            
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['error'] = "Gagal mengupdate anggota: " . $e->getMessage();
        }
        
        header("Location: anggota.php");
        exit();
    }
    
    if (isset($_POST['reset_password'])) {
        try {
            $hasil = resetPasswordAnggota($db, (int)($_POST['id'] ?? 0));
            $extra = [];
            if (!empty($hasil['dibuat'])) {
                $extra[] = 'akun login baru dibuat';
            }
            if (!empty($hasil['diaktifkan'])) {
                $extra[] = 'akun diaktifkan kembali';
            }
            $_SESSION['success'] = "Password {$hasil['username']} direset ke 123456"
                . ($extra ? ' (' . implode(', ', $extra) . ')' : '');
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        header('Location: anggota.php');
        exit();
    }
    
    if (isset($_POST['delete'])) {
        if (!$bolehHapusAnggota) {
            http_response_code(403);
            exit('Akses ditolak. Hanya Admin yang dapat menghapus anggota.');
        }
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

// Get filter parameters (standar tabel: q + status + pagination)
$ui = ui_query_params(['status']);
$filter_status = $ui['filters']['status'] ?? '';
$filter_search = $ui['q'] !== '' ? $ui['q'] : trim((string)($_GET['search'] ?? '')); // backward compat ?search=

$where = ' WHERE 1=1';
$params = [];
if ($filter_status !== '') {
    $where .= ' AND a.status = :status';
    $params[':status'] = $filter_status;
}
if ($filter_search !== '') {
    $where .= ' AND (a.kode_anggota LIKE :search_kode OR a.nama_lengkap LIKE :search_nama OR a.telepon LIKE :search_telepon OR a.ktp LIKE :search_ktp)';
    $nilai_search = '%' . $filter_search . '%';
    $params += [':search_kode' => $nilai_search, ':search_nama' => $nilai_search, ':search_telepon' => $nilai_search, ':search_ktp' => $nilai_search];
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM anggota a LEFT JOIN users u ON a.user_id = u.id" . $where);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalAnggota = (int)$countStmt->fetchColumn();
$pager = ui_paginate($totalAnggota, $ui['page'], $ui['per_page']);

$query = "SELECT a.*, u.status as user_status 
          FROM anggota a 
          LEFT JOIN users u ON a.user_id = u.id 
          $where
          ORDER BY a.created_at DESC
          LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$anggota_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ketua_shu_list = $db->query("SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE kategori_shu = 'ketua_kelompok' AND status = 'aktif' ORDER BY nama_lengkap")->fetchAll(PDO::FETCH_ASSOC);

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
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
<?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-members-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon" aria-hidden="true"><i class="ti ti-users"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Master data</p>
                        <h1 class="ui-dash-title">Data Anggota</h1>
                        <p class="ui-dash-meta mb-0">Kelola data, status, dan dokumen anggota koperasi</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <button type="button" class="btn ui-dash-btn-add" data-bs-toggle="modal" data-bs-target="#addModal">
                            <i class="ti ti-plus me-1"></i><span class="d-none d-sm-inline">Tambah Anggota</span><span class="d-sm-none">Tambah</span>
                        </button>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="card ui-members-table-card ui-dash-panel mb-0">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Daftar Anggota</span>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge bg-primary-lt"><?php echo (int)$stats['total_anggota']; ?> total</span>
                            <span class="badge bg-success-lt"><?php echo (int)$stats['aktif']; ?> aktif</span>
                            <span class="badge bg-warning-lt"><?php echo (int)$stats['nonaktif']; ?> nonaktif</span>
                            <?php if (isAdmin()): ?>
                            <a href="export_anggota.php" class="ui-dash-sec-link">
                                <i class="ti ti-file-spreadsheet me-1"></i>Export
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover align-middle mb-0 js-datatable" data-dt-order="0,asc" data-dt-nosort="3,6">
                                <thead>
                                    <tr>
                                        <th>Anggota</th>
                                        <th>Kontak</th>
                                        <th>Peran SHU</th>
                                        <th class="no-sort">Dokumen</th>
                                        <th>Status</th>
                                        <th>Tgl daftar</th>
                                        <th class="text-end no-sort">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($anggota_list)): ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="ui-members-empty">
                                                <i class="ti ti-users" aria-hidden="true"></i>
                                                <div class="fw-semibold text-dark">Belum ada data anggota</div>
                                                <p class="mb-0 small">Tambahkan anggota pertama untuk mulai mengelola simpanan dan pinjaman.</p>
                                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                                                    <i class="ti ti-plus me-1"></i>Tambah Anggota Pertama
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: foreach ($anggota_list as $anggota):
                                        $dok_url = array_filter(array_map('urlDokumenAnggota', array_intersect_key($anggota, daftarDokumenAnggota())));
                                        $editBtnId = 'editBtn' . (int)$anggota['id'];
                                        $delBtnId = 'delBtn' . (int)$anggota['id'];
                                        $initials = function_exists('getInitials') ? getInitials($anggota['nama_lengkap']) : strtoupper(substr($anggota['nama_lengkap'], 0, 1));
                                        $isJaminan = strpos($anggota['kode_anggota'], 'AGTJ') === 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="ui-members-person">
                                                <span class="ui-members-avatar" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></span>
                                                <div class="ui-members-person-meta">
                                                    <div class="ui-members-person-name"><?php echo htmlspecialchars($anggota['nama_lengkap']); ?></div>
                                                    <div class="ui-members-person-code">
                                                        <code><?php echo htmlspecialchars($anggota['kode_anggota']); ?></code>
                                                        <?php if (!empty($anggota['kode_anggota_jaminan'])): ?>
                                                            <span class="badge bg-warning-lt">Gadai: <?php echo htmlspecialchars($anggota['kode_anggota_jaminan']); ?></span>
                                                        <?php elseif ($isJaminan): ?>
                                                            <span class="badge bg-warning-lt">Jaminan</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="ui-members-contact">
                                                <span class="ui-members-phone"><?php echo layananEscape($anggota['telepon'] ?: '—'); ?></span>
                                                <?php echo tombolWhatsApp($anggota['telepon'], '', ''); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($isJaminan): ?>
                                                <span class="text-muted small">Tidak berlaku</span>
                                            <?php else: ?>
                                                <span class="badge <?php echo ($anggota['kategori_shu'] ?? 'anggota') === 'ketua_kelompok' ? 'bg-primary-lt' : 'bg-secondary-lt'; ?>">
                                                    <?php echo ($anggota['kategori_shu'] ?? 'anggota') === 'ketua_kelompok' ? 'Ketua' : 'Anggota'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($dok_url)): ?>
                                                <button type="button" class="ui-members-doc"
                                                    onclick="lihatDokumenAnggota(<?php echo htmlspecialchars(json_encode($anggota['nama_lengkap']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((object) $dok_url), ENT_QUOTES); ?>)">
                                                    <i class="ti ti-paperclip" aria-hidden="true"></i><?php echo count($dok_url); ?> file
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">Belum ada</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $anggota['status'] === 'aktif' ? 'success-lt' : 'danger-lt'; ?>">
                                                <?php echo ucfirst($anggota['status']); ?>
                                            </span>
                                        </td>
                                        <td><span class="text-nowrap"><?php echo formatTanggalUi($anggota['tanggal_daftar'], false, true); ?></span></td>
                                        <td class="text-end">
                                            <button type="button" id="<?= $editBtnId ?>" class="d-none"
                                                    data-bs-toggle="modal"
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
                                                    data-ketua_kelompok_id="<?= (int)($anggota['ketua_kelompok_id'] ?? 0) ?>"></button>
                                            <?php if ($bolehHapusAnggota): ?>
                                            <button type="button" id="<?= $delBtnId ?>" class="d-none"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteModal"
                                                    data-id="<?php echo $anggota['id']; ?>"
                                                    data-nama="<?php echo htmlspecialchars($anggota['nama_lengkap']); ?>"></button>
                                            <?php endif; ?>
                                            <form method="POST" id="resetForm<?= (int)$anggota['id'] ?>" class="d-none"><?php echo layananToken(); ?>
                                                <input type="hidden" name="id" value="<?php echo $anggota['id']; ?>">
                                                <input type="hidden" name="reset_password" value="1">
                                            </form>
                                            <?php
                                            $menuAnggota = [
                                                ['label' => 'Detail', 'icon' => 'ti ti-eye', 'href' => 'detail_anggota.php?id=' . (int)$anggota['id']],
                                                ['label' => 'Edit', 'icon' => 'ti ti-edit', 'onclick' => "document.getElementById('{$editBtnId}').click()"],
                                                ['label' => 'Reset password', 'icon' => 'ti ti-key', 'onclick' => "if(confirm('Reset password ke 123456?')) document.getElementById('resetForm" . (int)$anggota['id'] . "').submit()"],
                                            ];
                                            if ($bolehHapusAnggota) {
                                                $menuAnggota[] = ['divider' => true];
                                                $menuAnggota[] = ['label' => 'Hapus', 'icon' => 'ti ti-trash', 'class' => 'text-danger', 'onclick' => "document.getElementById('{$delBtnId}').click()"];
                                            }
                                            echo ui_action_menu($menuAnggota);
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
<?php include __DIR__ . '/layout/shell_end.php'; ?>

    <!-- Add Modal -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down ui-members-modal">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data"><?php echo layananToken(); ?>
                    <input type="hidden" name="add" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="ti ti-user-plus me-2"></i>Tambah Anggota Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <section class="ui-form-section">
                            <h6 class="ui-form-section-title"><i class="ti ti-id" aria-hidden="true"></i>Kategori &amp; kode</h6>
                            <div class="mb-3">
                                <label class="form-label">Kategori Anggota <span class="text-danger">*</span></label>
                                <select name="kategori_anggota" class="form-select kategori-anggota" required>
                                    <option value="biasa">Anggota Biasa (kode AGT)</option>
                                    <option value="jaminan">Anggota Jaminan/Gadai (kode AGTJ)</option>
                                </select>
                                <small class="text-muted">Akun login dibuat sekali memakai kode saat pertama didaftarkan.</small>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Kode Anggota <span class="text-danger">*</span></label>
                                <input type="text" name="kode_anggota" id="add_kode" class="form-control" required readonly
                                       value="<?php echo htmlspecialchars($kode_otomatis); ?>">
                                <small class="text-muted">Dibuat otomatis oleh sistem</small>
                            </div>
                            <div class="alert alert-info mt-3 mb-0 py-2">
                                <i class="ti ti-info-circle me-1"></i>Password default login: <strong>123456</strong>
                            </div>
                        </section>

                        <section class="ui-form-section">
                            <h6 class="ui-form-section-title"><i class="ti ti-user" aria-hidden="true"></i>Identitas</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                    <input type="text" name="nama_lengkap" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">No. KTP <span class="text-danger">*</span></label>
                                    <input type="text" name="ktp" class="form-control" required placeholder="16 digit nomor KTP" maxlength="16">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tempat Lahir</label>
                                    <input type="text" name="tempat_lahir" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tanggal Lahir</label>
                                    <input type="date" name="tanggal_lahir" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Jenis Kelamin</label>
                                    <select name="jenis_kelamin" class="form-select">
                                        <option value="L">Laki-laki</option>
                                        <option value="P">Perempuan</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Pekerjaan</label>
                                    <input type="text" name="pekerjaan" class="form-control">
                                </div>
                            </div>
                        </section>

                        <section class="ui-form-section">
                            <h6 class="ui-form-section-title"><i class="ti ti-map-pin" aria-hidden="true"></i>Kontak</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Telepon</label>
                                    <input type="tel" name="telepon" class="form-control" placeholder="08xxxxxxxxxx">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">No. Rekening Bank</label>
                                    <input type="text" name="rekening_bank" class="form-control" placeholder="Nomor rekening bank">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Alamat</label>
                                    <textarea name="alamat" class="form-control" rows="2" placeholder="Alamat lengkap"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tanggal Daftar <span class="text-danger">*</span></label>
                                    <input type="date" name="tanggal_daftar" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </section>

                        <section class="ui-form-section">
                            <h6 class="ui-form-section-title"><i class="ti ti-percentage" aria-hidden="true"></i>Peran SHU</h6>
                            <div class="row g-3">
                                <div class="col-md-6 shu-role-wrap">
                                    <label class="form-label">Peran SHU *</label>
                                    <select name="kategori_shu" id="add_kategori_shu" class="form-select shu-role" required>
                                        <option value="anggota">Anggota (1%)</option>
                                        <option value="ketua_kelompok">Ketua Kelompok (2%)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 shu-leader-wrap">
                                    <label class="form-label">Ketua yang mengajak</label>
                                    <select name="ketua_kelompok_id" class="form-select shu-leader">
                                        <option value="">Tidak ada</option>
                                        <?php foreach ($ketua_shu_list as $k): ?>
                                            <option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['nama_lengkap'] . ' · ' . $k['kode_anggota']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="ui-form-section mb-0">
                            <h6 class="ui-form-section-title"><i class="ti ti-photo" aria-hidden="true"></i>Dokumen</h6>
                            <?php $dok_prefix = 'add'; $dok_anggota = null; include __DIR__ . '/form_dokumen_anggota.php'; ?>
                        </section>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down ui-members-modal">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data"><?php echo layananToken(); ?>
                    <input type="hidden" name="aksi" value="edit">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="ti ti-edit me-2"></i>Edit Data Anggota</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">

                        <section class="ui-form-section">
                            <h6 class="ui-form-section-title"><i class="ti ti-id" aria-hidden="true"></i>Kode anggota</h6>
                            <label class="form-label">Kode Anggota <span class="text-danger">*</span></label>
                            <input type="text" name="kode_anggota" id="edit_kode" class="form-control" readonly style="background-color:#e9ecef">
                            <small class="text-muted">Kode digenerate otomatis dan tidak bisa diubah</small>
                        </section>

                        <section class="ui-form-section">
                            <h6 class="ui-form-section-title"><i class="ti ti-user" aria-hidden="true"></i>Identitas</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                    <input type="text" name="nama_lengkap" id="edit_nama" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">No. KTP <span class="text-danger">*</span></label>
                                    <input type="text" name="ktp" id="edit_ktp" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tempat Lahir</label>
                                    <input type="text" name="tempat_lahir" id="edit_tempat_lahir" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tanggal Lahir</label>
                                    <input type="date" name="tanggal_lahir" id="edit_tanggal_lahir" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Jenis Kelamin</label>
                                    <select name="jenis_kelamin" id="edit_jenis_kelamin" class="form-select">
                                        <option value="L">Laki-laki</option>
                                        <option value="P">Perempuan</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Pekerjaan</label>
                                    <input type="text" name="pekerjaan" id="edit_pekerjaan" class="form-control">
                                </div>
                            </div>
                        </section>

                        <section class="ui-form-section">
                            <h6 class="ui-form-section-title"><i class="ti ti-map-pin" aria-hidden="true"></i>Kontak &amp; status</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Telepon</label>
                                    <input type="text" name="telepon" id="edit_telepon" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">No. Rekening Bank</label>
                                    <input type="text" name="rekening_bank" id="edit_rekening_bank" class="form-control">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Alamat</label>
                                    <textarea name="alamat" id="edit_alamat" class="form-control" rows="2"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Status</label>
                                    <select name="status" id="edit_status" class="form-select">
                                        <option value="aktif">Aktif</option>
                                        <option value="nonaktif">Nonaktif</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tanggal Daftar</label>
                                    <input type="date" name="tanggal_daftar" id="edit_tanggal_daftar" class="form-control" readonly>
                                </div>
                            </div>
                        </section>

                        <section class="ui-form-section">
                            <h6 class="ui-form-section-title"><i class="ti ti-percentage" aria-hidden="true"></i>Peran SHU</h6>
                            <div class="row g-3">
                                <div class="col-md-6 shu-role-wrap">
                                    <label class="form-label">Peran SHU *</label>
                                    <select name="kategori_shu" id="edit_kategori_shu" class="form-select shu-role" required>
                                        <option value="anggota">Anggota (1%)</option>
                                        <option value="ketua_kelompok">Ketua Kelompok (2%)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 shu-leader-wrap">
                                    <label class="form-label">Ketua yang mengajak</label>
                                    <select name="ketua_kelompok_id" id="edit_ketua_kelompok_id" class="form-select shu-leader">
                                        <option value="">Tidak ada</option>
                                        <?php foreach ($ketua_shu_list as $k): ?>
                                            <option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['nama_lengkap'] . ' · ' . $k['kode_anggota']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="ui-form-section mb-0">
                            <h6 class="ui-form-section-title"><i class="ti ti-photo" aria-hidden="true"></i>Dokumen</h6>
                            <?php $dok_prefix = 'edit'; $dok_anggota = null; include __DIR__ . '/form_dokumen_anggota.php'; ?>
                        </section>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($bolehHapusAnggota): ?>
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog ui-members-modal ui-members-modal-danger">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="ti ti-alert-triangle me-2"></i>Konfirmasi Hapus</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="delete_id">
                        <p class="mb-3">Apakah Anda yakin ingin menghapus anggota:</p>
                        <p class="fw-bold text-center p-3 bg-light rounded" id="delete_nama"></p>
                        <div class="alert alert-warning mb-0">
                            <i class="ti ti-alert-triangle me-2"></i>
                            Data yang dihapus tidak dapat dikembalikan. Hanya anggota berstatus Nonaktif tanpa pinjaman aktif, macet, atau pengajuan berjalan yang dapat dihapus. Riwayat pinjaman lunas, angsuran, laporan, transaksi, dan kas anggota ini ikut dihapus.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="delete" class="btn btn-danger"><i class="ti ti-trash me-1"></i>Hapus</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/modal_dokumen.php'; ?>

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
                    var jenisKelamin = button.getAttribute('data-jenis_kelamin');
                    document.getElementById('edit_jenis_kelamin').value = jenisKelamin === 'P' ? 'P' : 'L';
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

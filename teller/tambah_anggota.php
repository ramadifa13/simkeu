<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Pastikan hanya teller yang bisa akses
if ($_SESSION['role'] !== 'teller') {
    header('Location: ../dashboard.php');
    exit();
}

// Kode anggota otomatis (nomor terbesar + 1, lihat includes/anggota_helper.php)
$kode_otomatis = buatKodeAnggota($db);
$kode_jaminan_otomatis = buatKodeAnggotaJaminan($db);
$ketua_shu_list = $db->query("SELECT id,kode_anggota,nama_lengkap FROM anggota WHERE kategori_shu='ketua_kelompok' ORDER BY nama_lengkap")->fetchAll(PDO::FETCH_ASSOC);

// Upload melebihi post_max_size membuat $_POST kosong; tampilkan pesan yang jelas
cekPostMelebihiBatas();

// Handle tambah anggota
if ($_POST) {
    if (isset($_POST['tambah_anggota'])) {
        kunciKodeAnggota($db); // cegah kode kembar bila Admin/Teller menambah bersamaan
        $kategori_anggota = ($_POST['kategori_anggota'] ?? 'biasa') === 'jaminan' ? 'jaminan' : 'biasa';
        $kode_anggota = buatKodeAnggotaSesuaiKategori($db, $kategori_anggota); // dibuat server, kiriman form diabaikan
        $nama_lengkap = $_POST['nama_lengkap'];
        $alamat = $_POST['alamat'] ?? '';
        $telepon = $_POST['telepon'] ?? '';
        $email = $_POST['email'] ?? '';
        $tempat_lahir = $_POST['tempat_lahir'] ?? '';
        $tanggal_lahir = tanggalAtauNull(isset($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : '');
        $jenis_kelamin = $_POST['jenis_kelamin'] ?? 'L';
        $pekerjaan = $_POST['pekerjaan'] ?? '';
        $ktp = $_POST['ktp'] ?? '';
        $rekening_bank = $_POST['rekening_bank'] ?? '';
        $tanggal_daftar = $_POST['tanggal_daftar'];
        $status = $_POST['status'];
        $kategori_shu_input = $_POST['kategori_shu'] ?? 'anggota';
        $ketua_kelompok_input = $_POST['ketua_kelompok_id'] ?? 0;

        try {
            $db->beginTransaction();
            list($kategori_shu, $ketua_kelompok_id) = shuValidasiProfil($db, 0, $kategori_shu_input, $ketua_kelompok_input);

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

            $check_stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $check_stmt->execute([$kode_anggota]);
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Kode anggota sudah dipakai sebagai username");
            }

            // Akun login milik anggota sendiri (sebelumnya user_id diisi id teller yang login,
            // sehingga edit anggota ikut mengubah akun teller). Sama dengan teller/anggota.php.
            $password = password_hash('123456', PASSWORD_DEFAULT);
            $stmt_user = $db->prepare("INSERT INTO users (username, password, nama_lengkap, role, status)
                                       VALUES (?, ?, ?, 'anggota', ?)");
            $stmt_user->execute([$kode_anggota, $password, $nama_lengkap, $status]);
            $user_id = $db->lastInsertId();

            // Insert data anggota
            $query = "INSERT INTO anggota (kode_anggota, nama_lengkap, alamat, telepon, email, tempat_lahir, tanggal_lahir, jenis_kelamin, pekerjaan, ktp, rekening_bank, tanggal_daftar, kategori_shu, ketua_kelompok_id, status, user_id)
                     VALUES (:kode_anggota, :nama_lengkap, :alamat, :telepon, :email, :tempat_lahir, :tanggal_lahir, :jenis_kelamin, :pekerjaan, :ktp, :rekening_bank, :tanggal_daftar, :kategori_shu, :ketua_kelompok_id, :status, :user_id)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':kode_anggota', $kode_anggota);
            $stmt->bindParam(':nama_lengkap', $nama_lengkap);
            $stmt->bindParam(':alamat', $alamat);
            $stmt->bindParam(':telepon', $telepon);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':tempat_lahir', $tempat_lahir);
            $stmt->bindParam(':tanggal_lahir', $tanggal_lahir);
            $stmt->bindParam(':jenis_kelamin', $jenis_kelamin);
            $stmt->bindParam(':pekerjaan', $pekerjaan);
            $stmt->bindParam(':ktp', $ktp);
            $stmt->bindParam(':rekening_bank', $rekening_bank);
            $stmt->bindParam(':tanggal_daftar', $tanggal_daftar);
            $stmt->bindValue(':kategori_shu', $kategori_shu);
            $stmt->bindValue(':ketua_kelompok_id', $ketua_kelompok_id, $ketua_kelompok_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':user_id', $user_id);
            
            if ($stmt->execute()) {
                $anggota_id = $db->lastInsertId();
                simpanDokumenAnggota($db, $anggota_id, $dokumen);

                // Rekening simpanan default (sama dengan teller/anggota.php)
                $stmt_simpanan = $db->prepare("INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo)
                                               SELECT ?, id, 0 FROM jenis_simpanan WHERE status = 'aktif'");
                $stmt_simpanan->execute([$anggota_id]);

                $db->commit();
                $_SESSION['success'] = "Anggota berhasil ditambahkan. Username: " . htmlspecialchars($kode_anggota) . ", password default: 123456";
                header("Location: tambah_anggota.php");
                exit();
            } else {
                throw new Exception("Gagal menambahkan anggota");
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = "Gagal menambahkan anggota: " . $e->getMessage();
        }
        lepasKodeAnggota($db);
    }
}

// Handle cetak laporan
if (isset($_GET['cetak_laporan'])) {
    // Query untuk mendapatkan semua data anggota
    $query = "SELECT * FROM anggota ORDER BY tanggal_daftar DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $anggota_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Data Anggota - <?php echo APP_NAME; ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            color: #333;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .footer {
            margin-top: 30px;
            text-align: right;
            font-size: 12px;
            color: #666;
        }
        .status-aktif {
            color: green;
            font-weight: bold;
        }
        .status-nonaktif {
            color: red;
            font-weight: bold;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>LAPORAN DATA ANGGOTA</h1>
        <p><?php echo APP_NAME; ?></p>
        <p>Periode: <?php echo date('d F Y'); ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Kode Anggota</th>
                <th>Nama Lengkap</th>
                <th>Alamat</th>
                <th>Telepon</th>
                <th>Email</th>
                <th>Tempat Lahir</th>
                <th>Tanggal Lahir</th>
                <th>Jenis Kelamin</th>
                <th>Pekerjaan</th>
                <th>KTP</th>
                <th>Tanggal Daftar</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($anggota_list)): ?>
                <?php $no = 1; ?>
                <?php foreach ($anggota_list as $anggota): ?>
                <tr>
                    <td><?php echo $no++; ?></td>
                    <td><?php echo $anggota['kode_anggota']; ?></td>
                    <td><?php echo $anggota['nama_lengkap']; ?></td>
                    <td><?php echo $anggota['alamat']; ?></td>
                    <td><?php echo $anggota['telepon']; ?></td>
                    <td><?php echo $anggota['email']; ?></td>
                    <td><?php echo $anggota['tempat_lahir']; ?></td>
                    <td><?php echo $anggota['tanggal_lahir'] ? date('d/m/Y', strtotime($anggota['tanggal_lahir'])) : '-'; ?></td>
                    <td><?php echo $anggota['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?></td>
                    <td><?php echo $anggota['pekerjaan']; ?></td>
                    <td><?php echo $anggota['ktp']; ?></td>
                    <td><?php echo date('d/m/Y', strtotime($anggota['tanggal_daftar'])); ?></td>
                    <td class="<?php echo $anggota['status'] == 'aktif' ? 'status-aktif' : 'status-nonaktif'; ?>">
                        <?php echo strtoupper($anggota['status']); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="13" style="text-align: center;">Tidak ada data anggota</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footer">
        <p>Dicetak pada: <?php echo date('d F Y H:i:s'); ?></p>
        <p>Oleh: <?php echo $_SESSION['nama_lengkap']; ?> (Teller)</p>
    </div>

    <div class="no-print" style="margin-top: 20px; text-align: center;">
        <button onclick="window.print()" class="btn btn-primary">Cetak Laporan</button>
        <button onclick="window.close()" class="btn btn-secondary">Tutup</button>
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
<?php
    exit();
}

// Query untuk mendapatkan data anggota (untuk tampilan utama)
$query = "SELECT * FROM anggota ORDER BY tanggal_daftar DESC LIMIT 10";
$stmt = $db->prepare($query);
$stmt->execute();
$anggota_terbaru = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Tambah Anggota - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .table-actions {
            white-space: nowrap;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.5rem 0.75rem;
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
                        <i class="fas fa-user-plus me-2"></i>Tambah Anggota
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="?cetak_laporan=1" target="_blank" class="btn btn-success me-2">
                            <i class="fas fa-print me-1"></i> Cetak Laporan
                        </a>
                    </div>
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
                    <div class="col-md-4">
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
                    <div class="col-md-4">
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
                </div>

                <div class="row">
                    <div class="col-lg-6">
                        <!-- Form Tambah Anggota -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-user-plus me-2"></i>Form Tambah Anggota Baru
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data"><?php echo layananToken(); ?>
                                    <div class="mb-3">
                                        <label class="form-label">Kategori Anggota *</label>
                                        <select name="kategori_anggota" class="form-select kategori-anggota" required>
                                            <option value="biasa">Anggota Biasa (kode AGT)</option>
                                            <option value="jaminan">Anggota Jaminan/Gadai (kode AGTJ)</option>
                                        </select>
                                        <div class="form-text">Anggota jaminan hanya wajib mengunggah foto KTP dan tetap memperoleh satu akun login.</div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Kode Anggota *</label>
                                                <input type="text" name="kode_anggota" class="form-control" 
                                                       value="<?php echo $kode_otomatis; ?>" required readonly>
                                                <div class="form-text">Kode anggota akan digenerate otomatis</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Tanggal Daftar *</label>
                                                <input type="date" name="tanggal_daftar" class="form-control" 
                                                       value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Nama Lengkap *</label>
                                        <input type="text" name="nama_lengkap" class="form-control" required 
                                               placeholder="Masukkan nama lengkap">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Alamat</label>
                                        <textarea name="alamat" class="form-control" rows="3" 
                                                  placeholder="Masukkan alamat lengkap"></textarea>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label class="form-label">Telepon</label>
                                                <input type="text" name="telepon" class="form-control" 
                                                       placeholder="Contoh: 081234567890">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Tempat Lahir</label>
                                                <input type="text" name="tempat_lahir" class="form-control" 
                                                       placeholder="Contoh: Jakarta">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Tanggal Lahir</label>
                                                <input type="date" name="tanggal_lahir" class="form-control">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Jenis Kelamin</label>
                                                <select name="jenis_kelamin" class="form-control">
                                                    <option value="L">Laki-laki</option>
                                                    <option value="P">Perempuan</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Pekerjaan</label>
                                                <input type="text" name="pekerjaan" class="form-control" 
                                                       placeholder="Contoh: Karyawan Swasta">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">No. KTP *</label>
                                                <input type="text" name="ktp" class="form-control" required maxlength="16"
                                                       placeholder="16 digit nomor KTP">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Rekening Bank</label>
                                                <input type="text" name="rekening_bank" class="form-control" 
                                                       placeholder="Contoh: BCA 1234567890">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label">Peran SHU *</label><select name="kategori_shu" id="kategori_shu" class="form-select" required><option value="anggota">Anggota (1%)</option><option value="ketua_kelompok">Ketua Kelompok (2%)</option></select></div><div class="col-md-6" id="ketua_shu_wrap"><label class="form-label">Ketua yang mengajak</label><select name="ketua_kelompok_id" id="ketua_kelompok_id" class="form-select"><option value="">Tidak ada</option><?php foreach($ketua_shu_list as $k): ?><option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['nama_lengkap'].' · '.$k['kode_anggota']) ?></option><?php endforeach; ?></select></div></div>
                                    <?php $dok_prefix = 'tambah'; $dok_anggota = null; include __DIR__ . '/../includes/form_dokumen_anggota.php'; ?>

                                    <div class="mb-3">
                                        <label class="form-label">Status *</label>
                                        <select name="status" class="form-control" required>
                                            <option value="aktif">Aktif</option>
                                            <option value="nonaktif">Nonaktif</option>
                                        </select>
                                    </div>

                                    <div class="alert alert-info">
                                        <small>
                                            <i class="fas fa-info-circle me-1"></i>
                                            Field yang bertanda (*) wajib diisi.
                                        </small>
                                    </div>

                                    <button type="submit" name="tambah_anggota" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Simpan Anggota
                                    </button>
                                    <button type="reset" class="btn btn-secondary">
                                        <i class="fas fa-undo me-1"></i> Reset Form
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <!-- Daftar Anggota Terbaru -->
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-list me-2"></i>Anggota Terbaru
                                </h5>
                                <span class="badge bg-primary"><?php echo count($anggota_terbaru); ?> anggota</span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($anggota_terbaru)): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-sm">
                                        <thead>
                                            <tr>
                                                <th>Kode</th>
                                                <th>Nama</th>
                                                <th>Telepon</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($anggota_terbaru as $anggota): ?>
                                            <tr>
                                                <td>
                                                    <strong class="text-primary"><?php echo $anggota['kode_anggota']; ?></strong>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo $anggota['nama_lengkap']; ?></div>
                                                    <small class="text-muted"><?php echo date('d/m/Y', strtotime($anggota['tanggal_daftar'])); ?></small>
                                                </td>
                                                <td><?php echo $anggota['telepon'] ?: '-'; ?></td>
                                                <td>
                                                    <?php if ($anggota['status'] == 'aktif'): ?>
                                                        <span class="badge bg-success">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Nonaktif</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-users fa-2x mb-2"></i>
                                    <p>Belum ada data anggota</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-bolt me-2"></i>Quick Actions
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="?cetak_laporan=1" target="_blank" class="btn btn-outline-success">
                                        <i class="fas fa-print me-1"></i> Cetak Laporan Anggota
                                    </a>
                                    <button type="button" class="btn btn-outline-info" onclick="resetForm()">
                                        <i class="fas fa-sync me-1"></i> Reset Form
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../includes/modal_dokumen.php'; ?>
    <script>
        var kategori = document.querySelector('.kategori-anggota');
        var kodeInput = document.querySelector('input[name="kode_anggota"]');
        var kodeBiasa = <?php echo json_encode($kode_otomatis); ?>;
        var kodeJaminan = <?php echo json_encode($kode_jaminan_otomatis); ?>;
        function sesuaikanKategoriAnggota() {
            var jaminan = kategori && kategori.value === 'jaminan';
            if (kodeInput) kodeInput.value = jaminan ? kodeJaminan : kodeBiasa;
            document.querySelectorAll('.input-dokumen').forEach(function(input) {
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
        var peranShu=document.getElementById('kategori_shu'),ketuaWrap=document.getElementById('ketua_shu_wrap'),ketuaShu=document.getElementById('ketua_kelompok_id');
        function sesuaikanPeranShu(){var ketua=peranShu&&peranShu.value==='ketua_kelompok';if(ketuaWrap)ketuaWrap.style.display=ketua?'none':'';if(ketuaShu){ketuaShu.disabled=ketua;if(ketua)ketuaShu.value='';}}
        if(peranShu)peranShu.addEventListener('change',sesuaikanPeranShu);sesuaikanPeranShu();

        function resetForm() {
            document.querySelector('form').reset();
            // Set kembali kode anggota yang otomatis
            sesuaikanKategoriAnggota();
            sesuaikanPeranShu();
            // Set kembali tanggal hari ini
            document.querySelector('input[name="tanggal_daftar"]').value = '<?php echo date('Y-m-d'); ?>';
        }

        // Auto capitalize nama lengkap
        document.querySelector('input[name="nama_lengkap"]').addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });

        // Auto capitalize tempat lahir
        document.querySelector('input[name="tempat_lahir"]').addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });

        // Auto capitalize pekerjaan
        document.querySelector('input[name="pekerjaan"]').addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });

        // Format nomor telepon
        document.querySelector('input[name="telepon"]').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Format nomor KTP
        document.querySelector('input[name="ktp"]').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    </script>
</body>
</html>

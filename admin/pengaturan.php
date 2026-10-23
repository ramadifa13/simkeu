<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Only admin can access this page
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini!";
    header("Location: dashboard.php");
    exit();
}

if ($_POST && isset($_POST['update_perjanjian'])) {
    try {
        $jabatan = trim($_POST['jabatan_admin'] ?? '');
        $nama_pihak_pertama = trim($_POST['nama_pihak_pertama'] ?? '');
        $nik_pihak_pertama = trim($_POST['nik_pihak_pertama'] ?? '');
        $alamat_pihak_pertama = trim($_POST['alamat_pihak_pertama'] ?? '');
        $telepon_pihak_pertama = trim($_POST['telepon_pihak_pertama'] ?? '');
        $lama = pengaturanPerjanjian($db);
        $versi = 'lengkap';
        $ringkas = (string)$lama['isi_ringkas'];
        $lengkap = trim($_POST['isi_lengkap'] ?? '');
        if ($jabatan === '' || $nama_pihak_pertama === '' || $lengkap === '') throw new Exception('Nama pihak pertama, jabatan, dan template surat wajib diisi');
        $ttd = bacaTtdRequest('ttd_admin', 'Tanda tangan Admin');
        if ($ttd === '') $ttd = (string) ($lama['ttd_admin'] ?? '');
        $ttd = validasiTtdDataUrl($ttd, 'Tanda tangan Admin');
        $stmt = $db->prepare("UPDATE pengaturan_perjanjian SET nama_pihak_pertama=?,nik_pihak_pertama=?,alamat_pihak_pertama=?,telepon_pihak_pertama=?,jabatan_admin=?, template_default=?, isi_ringkas=?, isi_lengkap=?, ttd_admin=?, updated_by=? WHERE id=1");
        $stmt->execute([$nama_pihak_pertama,$nik_pihak_pertama,$alamat_pihak_pertama,$telepon_pihak_pertama,$jabatan, $versi, $ringkas, $lengkap, $ttd, $_SESSION['user_id']]);
        $_SESSION['success'] = 'Pengaturan surat perjanjian dan TTD Admin berhasil disimpan. Perubahan berlaku untuk pencairan berikutnya.';
    } catch (Exception $e) { $_SESSION['error'] = 'Gagal menyimpan pengaturan perjanjian: ' . $e->getMessage(); }
    header('Location: pengaturan.php'); exit();
}

// Handle form submission for koperasi settings
if ($_POST && isset($_POST['update_koperasi'])) {
    $nama = $_POST['nama'];
    $alamat = $_POST['alamat'];
    $telepon = $_POST['telepon'];
    $email = $_POST['email'];
    $rekening_bank = $_POST['rekening_bank'];
    
    try {
        // Check if koperasi data exists
        $check_query = "SELECT id FROM koperasi LIMIT 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            // Update existing data
            $update_query = "UPDATE koperasi SET 
                            nama = :nama, 
                            alamat = :alamat, 
                            telepon = :telepon, 
                            email = :email, 
                            rekening_bank = :rekening_bank 
                            WHERE id = 1";
            $stmt = $db->prepare($update_query);
        } else {
            // Insert new data
            $update_query = "INSERT INTO koperasi (nama, alamat, telepon, email, rekening_bank) 
                            VALUES (:nama, :alamat, :telepon, :email, :rekening_bank)";
            $stmt = $db->prepare($update_query);
        }
        
        $stmt->bindParam(':nama', $nama);
        $stmt->bindParam(':alamat', $alamat);
        $stmt->bindParam(':telepon', $telepon);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':rekening_bank', $rekening_bank);
        $stmt->execute();
        
        $_SESSION['success'] = "Profil koperasi berhasil diperbarui!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Gagal memperbarui profil koperasi: " . $e->getMessage();
    }
    
    // Redirect to refresh page
    header("Location: pengaturan.php");
    exit();
}

// Handle logo upload
if ($_POST && isset($_POST['upload_logo']) && isset($_FILES['logo'])) {
    $logo = $_FILES['logo'];
    
    if ($logo['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        if (in_array($logo['type'], $allowed_types) && $logo['size'] <= $max_size) {
            // Create uploads directory if not exists
            $upload_dir = '../uploads/logo/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($logo['name'], PATHINFO_EXTENSION);
            $filename = 'logo_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $filename;
            
            if (move_uploaded_file($logo['tmp_name'], $upload_path)) {
                // Delete old logo if exists
                $old_logo_query = "SELECT logo FROM koperasi WHERE id = 1";
                $old_logo_stmt = $db->prepare($old_logo_query);
                $old_logo_stmt->execute();
                $old_logo = $old_logo_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($old_logo && $old_logo['logo'] && file_exists($upload_dir . $old_logo['logo'])) {
                    unlink($upload_dir . $old_logo['logo']);
                }
                
                // Update database
                $logo_query = "UPDATE koperasi SET logo = :logo WHERE id = 1";
                $logo_stmt = $db->prepare($logo_query);
                $logo_stmt->bindParam(':logo', $filename);
                $logo_stmt->execute();
                
                $_SESSION['success'] = "Logo berhasil diupload!";
            } else {
                $_SESSION['error'] = "Gagal mengupload logo! Pastikan folder uploads memiliki permission yang tepat.";
            }
        } else {
            $_SESSION['error'] = "File harus berupa gambar (JPEG, PNG, GIF) dan maksimal 2MB!";
        }
    } else {
        $_SESSION['error'] = "Terjadi kesalahan saat upload logo! Error code: " . $logo['error'];
    }
    
    header("Location: pengaturan.php");
    exit();
}

// Handle password change
if ($_POST && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $user_id = $_SESSION['user_id'];
    
    // Validasi input
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $_SESSION['error'] = "Semua field password harus diisi!";
    } elseif ($new_password !== $confirm_password) {
        $_SESSION['error'] = "Konfirmasi password baru tidak cocok!";
    } elseif (strlen($new_password) < 6) {
        $_SESSION['error'] = "Password baru minimal 6 karakter!";
    } else {
        try {
            // Verifikasi password lama
            $query = "SELECT password FROM users WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $user_id);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($current_password, $user['password'])) {
                // Update password baru
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_query = "UPDATE users SET password = :password WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':password', $hashed_password);
                $update_stmt->bindParam(':id', $user_id);
                $update_stmt->execute();
                
                $_SESSION['success'] = "Password berhasil diubah!";
            } else {
                $_SESSION['error'] = "Password saat ini salah!";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal mengubah password: " . $e->getMessage();
        }
    }
    
    header("Location: pengaturan.php");
    exit();
}

// Get koperasi data
$koperasi_query = "SELECT * FROM koperasi WHERE id = 1";
$koperasi_stmt = $db->prepare($koperasi_query);
$koperasi_stmt->execute();
$koperasi = $koperasi_stmt->fetch(PDO::FETCH_ASSOC);
$pengaturan_perjanjian = pengaturanPerjanjian($db);

// If no data exists, create default
if (!$koperasi) {
    $koperasi = [
        'nama' => 'Koperasi Simpan Pinjam Sejahtera',
        'alamat' => '',
        'telepon' => '',
        'email' => '',
        'rekening_bank' => '',
        'logo' => null
    ];
}

// Get system statistics
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM anggota WHERE status = 'aktif') as total_anggota,
    (SELECT COUNT(*) FROM users WHERE status = 'aktif' AND role != 'anggota') as total_users,
    (SELECT COUNT(*) FROM pinjaman WHERE status = 'aktif') as total_pinjaman_aktif,
    (SELECT COALESCE(SUM(saldo), 0) FROM simpanan s 
     JOIN jenis_simpanan j ON s.jenis_simpanan_id = j.id 
     WHERE j.kode_jenis NOT IN ('ANG','BONUS','SHU')) as total_simpanan,
    (SELECT COALESCE(SUM(sisa_pokok), 0) FROM pinjaman WHERE status = 'aktif') as total_pinjaman_berjalan";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get current admin info
$admin_query = "SELECT username, nama_lengkap FROM users WHERE id = :id";
$admin_stmt = $db->prepare($admin_query);
$admin_stmt->bindParam(':id', $_SESSION['user_id']);
$admin_stmt->execute();
$admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);

// Fungsi format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .settings-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stats-card {
            border-left: 4px solid #007bff;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-cog me-2"></i>Pengaturan Sistem
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <span class="badge bg-primary p-2">
                                <i class="fas fa-user-shield me-1"></i> <?php echo htmlspecialchars($admin['nama_lengkap']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- System Statistics -->
                    <div class="col-lg-3 mb-4">
                        <div class="card settings-card stats-card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Statistik Sistem</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <small class="text-muted">Total Anggota Aktif</small>
                                    <div class="h5 text-primary"><?php echo number_format($stats['total_anggota']); ?></div>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Total Pengguna (Admin/Teller)</small>
                                    <div class="h5 text-info"><?php echo number_format($stats['total_users']); ?></div>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Total Simpanan</small>
                                    <div class="h5 text-success"><?php echo formatRupiah($stats['total_simpanan']); ?></div>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Pinjaman Aktif</small>
                                    <div class="h5 text-warning"><?php echo number_format($stats['total_pinjaman_aktif']); ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Total Piutang</small>
                                    <div class="h5 text-danger"><?php echo formatRupiah($stats['total_pinjaman_berjalan']); ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Change Password -->
                        <div class="card settings-card mt-4">
                            <div class="card-header bg-warning text-white">
                                <h6 class="mb-0"><i class="fas fa-key me-2"></i>Ubah Password</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST"><?php echo layananToken(); ?>
                                    <div class="mb-3">
                                        <label class="form-label">Password Saat Ini</label>
                                        <input type="password" name="current_password" class="form-control" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Password Baru</label>
                                        <input type="password" name="new_password" class="form-control" 
                                               placeholder="Minimal 6 karakter" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Konfirmasi Password Baru</label>
                                        <input type="password" name="confirm_password" class="form-control" required>
                                    </div>
                                    
                                    <button type="submit" name="change_password" class="btn btn-warning w-100">
                                        <i class="fas fa-save me-2"></i>Ubah Password
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Koperasi Profile Settings -->
                    <div class="col-lg-9">
                        <div class="card settings-card mb-4">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-building me-2"></i>Profil Koperasi</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST"><?php echo layananToken(); ?>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Nama Koperasi <span class="text-danger">*</span></label>
                                                <input type="text" name="nama" class="form-control" 
                                                       value="<?php echo htmlspecialchars($koperasi['nama']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Telepon</label>
                                                <input type="text" name="telepon" class="form-control" 
                                                       value="<?php echo htmlspecialchars($koperasi['telepon']); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Alamat</label>
                                        <textarea name="alamat" class="form-control" rows="3"><?php echo htmlspecialchars($koperasi['alamat']); ?></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Email</label>
                                                <input type="email" name="email" class="form-control" 
                                                       value="<?php echo htmlspecialchars($koperasi['email']); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Rekening Bank</label>
                                                <input type="text" name="rekening_bank" class="form-control" 
                                                       value="<?php echo htmlspecialchars($koperasi['rekening_bank']); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" name="update_koperasi" class="btn btn-success">
                                        <i class="fas fa-save me-2"></i>Simpan Perubahan
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="card settings-card mb-4">
                            <div class="card-header bg-primary text-white"><h6 class="mb-0"><i class="fas fa-file-signature me-2"></i>Surat Perjanjian & TTD Admin</h6></div>
                            <div class="card-body">
                                <div class="alert alert-info">Pengaturan ini berlaku untuk surat baru. Surat yang sudah ditandatangani tetap memakai data saat transaksi.</div>
                                <form method="POST" enctype="multipart/form-data" id="formPerjanjian"><?php echo layananToken(); ?>
                                    <div class="row">
                                        <div class="col-md-6 mb-3"><label class="form-label">Nama Pihak Pertama *</label><input class="form-control" name="nama_pihak_pertama" required value="<?php echo htmlspecialchars($pengaturan_perjanjian['nama_pihak_pertama'] ?: $koperasi['nama']); ?>"></div>
                                        <div class="col-md-6 mb-3"><label class="form-label">NIK Pihak Pertama</label><input class="form-control" name="nik_pihak_pertama" value="<?php echo htmlspecialchars($pengaturan_perjanjian['nik_pihak_pertama'] ?? ''); ?>"></div>
                                        <div class="col-md-8 mb-3"><label class="form-label">Alamat Pihak Pertama</label><input class="form-control" name="alamat_pihak_pertama" value="<?php echo htmlspecialchars($pengaturan_perjanjian['alamat_pihak_pertama'] ?: $koperasi['alamat']); ?>"></div>
                                        <div class="col-md-4 mb-3"><label class="form-label">No. HP Pihak Pertama</label><input class="form-control" name="telepon_pihak_pertama" value="<?php echo htmlspecialchars($pengaturan_perjanjian['telepon_pihak_pertama'] ?: $koperasi['telepon']); ?>"></div>
                                        <div class="col-md-6 mb-3"><label class="form-label">Jabatan penandatangan Admin</label><input class="form-control" name="jabatan_admin" required value="<?php echo htmlspecialchars($pengaturan_perjanjian['jabatan_admin']); ?>"></div>
                                        <div class="col-md-6 mb-3"><label class="form-label">Versi Surat</label><input class="form-control" value="Lengkap" readonly><input type="hidden" name="template_default" value="lengkap"></div>
                                    </div>
                                    <div class="mb-3"><label class="form-label">Isi Surat Perjanjian Lengkap</label><textarea class="form-control" rows="14" name="isi_lengkap" required><?php echo htmlspecialchars($pengaturan_perjanjian['isi_lengkap']); ?></textarea></div>
                                    <div class="signature-field"><div class="signature-field-head"><div><label class="signature-field-title mb-0">TTD Admin</label><small class="signature-field-help">Gambar langsung menggunakan mouse atau layar sentuh.</small></div><span class="signature-status" id="ttdAdminStatus"><i class="fas fa-pen-nib"></i> Belum diisi</span></div><div class="signature-canvas-wrap"><canvas id="ttdAdminCanvas" class="signature" width="700" height="180"></canvas></div>
                                    <input type="hidden" name="ttd_admin" id="ttd_admin">
                                    <div class="signature-field-foot"><span class="signature-field-note">Tanda tangan tersimpan bersama pengaturan surat.</span><button type="button" class="signature-clear btn btn-outline-danger btn-sm" id="hapusTtdAdmin"><i class="fas fa-eraser me-1"></i>Hapus / gambar ulang</button></div></div><div class="mt-2"><button type="submit" name="update_perjanjian" class="btn btn-primary"><i class="fas fa-save me-1"></i>Simpan Pengaturan</button></div>
                                </form>
                            </div>
                        </div>

                        <!-- Logo Upload -->
                        <div class="card settings-card">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0"><i class="fas fa-image me-2"></i>Logo Koperasi</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <?php if ($koperasi['logo'] && file_exists('../uploads/logo/' . $koperasi['logo'])): ?>
                                            <div class="text-center mb-3">
                                                <img src="../uploads/logo/<?php echo $koperasi['logo']; ?>" 
                                                     alt="Logo Koperasi" class="img-thumbnail" style="max-height: 150px;">
                                                <div class="mt-2">
                                                    <small class="text-muted">Logo Saat Ini</small>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center text-muted mb-3">
                                                <i class="fas fa-image fa-3x mb-2"></i>
                                                <div>Belum ada logo</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-8">
                                        <form method="POST" enctype="multipart/form-data"><?php echo layananToken(); ?>
                                            <div class="mb-3">
                                                <label class="form-label">Upload Logo Baru</label>
                                                <input type="file" name="logo" class="form-control" accept="image/*" required>
                                                <div class="form-text">
                                                    Format: JPEG, PNG, GIF. Maksimal: 2MB
                                                </div>
                                            </div>
                                            <button type="submit" name="upload_logo" class="btn btn-info">
                                                <i class="fas fa-upload me-2"></i>Upload Logo
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function(){
      var c=document.getElementById('ttdAdminCanvas'); if(!c)return; var x=c.getContext('2d'), drawing=false, dirty=false;
      function size(){var old=dirty?c.toDataURL():null;c.width=c.clientWidth*devicePixelRatio;c.height=180*devicePixelRatio;x.scale(devicePixelRatio,devicePixelRatio);x.lineWidth=2;x.lineCap='round';if(old){var i=new Image;i.onload=function(){x.drawImage(i,0,0,c.clientWidth,180)};i.src=old;}}
      function p(e){var r=c.getBoundingClientRect();return [e.clientX-r.left,e.clientY-r.top]}
      function setStatus(ready){var s=document.getElementById('ttdAdminStatus'),w=c.closest('.signature-canvas-wrap');s.classList.toggle('is-ready',ready);s.innerHTML=ready?'<i class="fas fa-check-circle"></i> Siap disimpan':'<i class="fas fa-pen-nib"></i> Belum diisi';w.classList.toggle('has-signature',ready)}
      c.addEventListener('pointerdown',function(e){e.preventDefault();drawing=true;dirty=true;setStatus(true);var a=p(e);x.beginPath();x.moveTo(a[0],a[1]);c.setPointerCapture(e.pointerId)},{passive:false});
      c.addEventListener('pointermove',function(e){if(!drawing)return;e.preventDefault();var a=p(e);x.lineTo(a[0],a[1]);x.stroke()},{passive:false});
      function stopTtd(e){drawing=false;if(c.hasPointerCapture && c.hasPointerCapture(e.pointerId))c.releasePointerCapture(e.pointerId)}
      c.addEventListener('pointerup',stopTtd);c.addEventListener('pointercancel',stopTtd);c.addEventListener('lostpointercapture',stopTtd);
      size(); var existing=<?php echo json_encode((string)($pengaturan_perjanjian['ttd_admin'] ?? '')); ?>;if(existing){var i=new Image;i.onload=function(){x.drawImage(i,0,0,c.clientWidth,180);dirty=true;setStatus(true)};i.src=existing}
      document.getElementById('hapusTtdAdmin').onclick=function(){x.clearRect(0,0,c.width,c.height);dirty=false;setStatus(false);document.getElementById('ttd_admin').value=''};
      var saving=false;
      document.getElementById('formPerjanjian').addEventListener('submit',async function(e){
        e.preventDefault();
        if(saving)return;
        if(!dirty&&!existing){alert('TTD Admin wajib dibuat.');return;}
        var form=this, button=form.querySelector('button[type="submit"]');
        saving=true;button.disabled=true;
        try {
          var data=new FormData(form);
          data.delete('ttd_admin');
          data.set('update_perjanjian','1');
          if(dirty){
            var blob=await new Promise(function(resolve){c.toBlob(resolve,'image/png')});
            if(!blob)throw new Error('Tanda tangan gagal dibuat. Silakan coba lagi.');
            if(blob.size>2*1024*1024)throw new Error('Tanda tangan melebihi 2 MB.');
            data.append('ttd_admin_file',blob,'ttd-admin.png');
          }
          var response=await fetch(form.action,{method:'POST',body:data,credentials:'same-origin'});
          if(!response.ok)throw new Error(response.status===403
            ?'Server menolak penyimpanan (403). Periksa log keamanan hosting pada waktu penyimpanan ini.'
            :'Penyimpanan gagal (HTTP '+response.status+'). Silakan coba lagi.');
          if(!response.redirected)throw new Error('Respons penyimpanan tidak sesuai. Periksa sesi login dan konfigurasi hosting.');
          window.location.assign(response.url);
        } catch(error) {
          alert(error.message || 'Koneksi gagal. Silakan coba lagi.');
        } finally {saving=false;button.disabled=false;}
      });
    })();
    </script>
</body>
</html>

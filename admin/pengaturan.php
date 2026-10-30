<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Only admin can access this page
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini!";
    header("Location: dashboard.php");
    exit();
}

redirectJikaPostMelebihiBatas('pengaturan.php');

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_logo'])) {
    try {
        $logo = validasiFotoUpload('logo', 'Logo koperasi', 1024 * 1024, true);
        if (!$logo) {
            throw new Exception('Logo koperasi wajib diunggah');
        }

        $upload_dir = pastikanFolderUpload('logo') . DIRECTORY_SEPARATOR;
        $filename = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $logo['ext'];
        $upload_path = $upload_dir . $filename;
        if (!kompresDanSimpanGambar($logo['tmp'], $upload_path, $logo['ext'])) {
            throw new Exception('Gagal menyimpan logo (hasil akhir maksimal 1 MB).');
        }
        // kompres bisa mengubah ekstensi (mis. PNG → JPG); simpan nama file aktual.
        $filename = basename($upload_path);
        if (!is_file($upload_path)) {
            throw new Exception('File logo tidak tersimpan di server.');
        }

        $exists = (int)$db->query('SELECT COUNT(*) FROM koperasi WHERE id = 1')->fetchColumn();
        if ($exists === 0) {
            $db->prepare(
                'INSERT INTO koperasi (id, nama, alamat, telepon, email, rekening_bank, logo)
                 VALUES (1, :nama, \'\', \'\', \'\', \'\', :logo)'
            )->execute([
                ':nama' => defined('APP_NAME') ? APP_NAME : 'Koperasi',
                ':logo' => $filename,
            ]);
        } else {
            $old_logo_stmt = $db->prepare('SELECT logo FROM koperasi WHERE id = 1');
            $old_logo_stmt->execute();
            $old_logo = $old_logo_stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($old_logo['logo'])) {
                $oldName = basename((string) $old_logo['logo']);
                $oldPath = $upload_dir . $oldName;
                if (is_file($oldPath) && $oldName !== $filename) {
                    @unlink($oldPath);
                }
            }
            $logo_stmt = $db->prepare('UPDATE koperasi SET logo = :logo WHERE id = 1');
            $logo_stmt->execute([':logo' => $filename]);
        }

        $_SESSION['success'] = 'Logo berhasil diupload!';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal mengunggah logo: ' . $e->getMessage();
    }

    header('Location: pengaturan.php');
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

$logoPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logo' . DIRECTORY_SEPARATOR . basename((string)($koperasi['logo'] ?? ''));
$logoAda = !empty($koperasi['logo']) && is_file($logoPath);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Koperasi - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
    <link rel="stylesheet" href="../assets/css/tanda-tangan.css?v=20261029-2">
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-set" aria-hidden="true"><i class="ti ti-building-bank"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Pengaturan</p>
                        <h1 class="ui-dash-title">Profil Koperasi</h1>
                        <p class="ui-dash-meta mb-0">Identitas, logo, surat perjanjian, dan akun admin</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <span class="badge bg-primary"><i class="ti ti-user-shield me-1" aria-hidden="true"></i><?php echo htmlspecialchars($admin['nama_lengkap']); ?></span>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-set-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Anggota aktif', 'value' => number_format((int)$stats['total_anggota']), 'tone' => 'primary', 'icon' => 'ti ti-users', 'hint' => 'Status aktif'],
                    ['label' => 'Staff login', 'value' => number_format((int)$stats['total_users']), 'tone' => 'info', 'icon' => 'ti ti-user-star', 'hint' => 'Admin & teller'],
                    ['label' => 'Total simpanan', 'value' => formatRupiah($stats['total_simpanan']), 'tone' => 'success', 'icon' => 'ti ti-pig-money', 'hint' => 'Tanpa ANG/BONUS/SHU'],
                    ['label' => 'Piutang aktif', 'value' => formatRupiah($stats['total_pinjaman_berjalan']), 'tone' => 'warning', 'icon' => 'ti ti-report-money', 'hint' => number_format((int)$stats['total_pinjaman_aktif']) . ' pinjaman'],
                ]); ?>
                </div>

                <div class="ui-set-grid">
                    <div class="ui-set-main">
                        <div class="card ui-set-card mb-3">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-building" aria-hidden="true"></i>Profil koperasi</span>
                                <span class="ui-dash-sec-note">Ditampilkan di surat &amp; kwitansi</span>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="ui-set-form"><?php echo layananToken(); ?>
                                    <div class="ui-set-fields">
                                        <div class="ui-set-field">
                                            <label class="form-label" for="kop_nama">Nama koperasi <span class="text-danger">*</span></label>
                                            <input type="text" id="kop_nama" name="nama" class="form-control" value="<?php echo htmlspecialchars($koperasi['nama']); ?>" required>
                                        </div>
                                        <div class="ui-set-field">
                                            <label class="form-label" for="kop_telp">Telepon</label>
                                            <input type="text" id="kop_telp" name="telepon" class="form-control" value="<?php echo htmlspecialchars($koperasi['telepon']); ?>">
                                        </div>
                                        <div class="ui-set-field ui-set-field-full">
                                            <label class="form-label" for="kop_alamat">Alamat</label>
                                            <textarea id="kop_alamat" name="alamat" class="form-control" rows="3"><?php echo htmlspecialchars($koperasi['alamat']); ?></textarea>
                                        </div>
                                        <div class="ui-set-field">
                                            <label class="form-label" for="kop_email">Email</label>
                                            <input type="email" id="kop_email" name="email" class="form-control" value="<?php echo htmlspecialchars($koperasi['email']); ?>">
                                        </div>
                                        <div class="ui-set-field">
                                            <label class="form-label" for="kop_rek">Rekening bank</label>
                                            <input type="text" id="kop_rek" name="rekening_bank" class="form-control" value="<?php echo htmlspecialchars($koperasi['rekening_bank']); ?>">
                                        </div>
                                    </div>
                                    <div class="ui-set-actions">
                                        <button type="submit" name="update_koperasi" class="btn btn-primary">
                                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Simpan profil
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card ui-set-card mb-3">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-file-pencil" aria-hidden="true"></i>Surat perjanjian &amp; TTD admin</span>
                                <span class="ui-dash-sec-note">Berlaku untuk surat baru</span>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info ui-set-info mb-3" role="status" data-ui-toast-skip>
                                    Surat yang sudah ditandatangani tetap memakai data saat transaksi.
                                </div>
                                <form method="POST" enctype="multipart/form-data" id="formPerjanjian" class="ui-set-form"><?php echo layananToken(); ?>
                                    <div class="ui-set-fields">
                                        <div class="ui-set-field">
                                            <label class="form-label">Nama pihak pertama *</label>
                                            <input class="form-control" name="nama_pihak_pertama" required value="<?php echo htmlspecialchars($pengaturan_perjanjian['nama_pihak_pertama'] ?: $koperasi['nama']); ?>">
                                        </div>
                                        <div class="ui-set-field">
                                            <label class="form-label">NIK pihak pertama</label>
                                            <input class="form-control" name="nik_pihak_pertama" value="<?php echo htmlspecialchars($pengaturan_perjanjian['nik_pihak_pertama'] ?? ''); ?>">
                                        </div>
                                        <div class="ui-set-field ui-set-field-wide">
                                            <label class="form-label">Alamat pihak pertama</label>
                                            <input class="form-control" name="alamat_pihak_pertama" value="<?php echo htmlspecialchars($pengaturan_perjanjian['alamat_pihak_pertama'] ?? ''); ?>">
                                            <div class="form-text">Boleh dikosongkan</div>
                                        </div>
                                        <div class="ui-set-field">
                                            <label class="form-label">No. HP pihak pertama</label>
                                            <input class="form-control" name="telepon_pihak_pertama" value="<?php echo htmlspecialchars($pengaturan_perjanjian['telepon_pihak_pertama'] ?? ''); ?>">
                                            <div class="form-text">Boleh dikosongkan</div>
                                        </div>
                                        <div class="ui-set-field">
                                            <label class="form-label">Jabatan penandatangan</label>
                                            <input class="form-control" name="jabatan_admin" required value="<?php echo htmlspecialchars($pengaturan_perjanjian['jabatan_admin']); ?>">
                                        </div>
                                        <div class="ui-set-field">
                                            <label class="form-label">Versi surat</label>
                                            <input class="form-control" value="Lengkap" readonly>
                                            <input type="hidden" name="template_default" value="lengkap">
                                        </div>
                                        <div class="ui-set-field ui-set-field-full">
                                            <label class="form-label">Isi surat perjanjian lengkap</label>
                                            <textarea class="form-control" rows="12" name="isi_lengkap" required><?php echo htmlspecialchars($pengaturan_perjanjian['isi_lengkap']); ?></textarea>
                                        </div>
                                    </div>
                                    <div class="signature-field mt-2">
                                        <div class="signature-field-head">
                                            <div>
                                                <label class="signature-field-title mb-0">TTD Admin</label>
                                                <small class="signature-field-help">Gambar langsung dengan mouse / layar sentuh</small>
                                            </div>
                                            <span class="signature-status" id="ttdAdminStatus"><i class="ti ti-signature"></i> Belum diisi</span>
                                        </div>
                                        <div class="signature-canvas-wrap">
                                            <canvas id="ttdAdminCanvas" class="signature" data-target="ttd_admin" width="700" height="220"></canvas>
                                        </div>
                                        <input type="hidden" name="ttd_admin" id="ttd_admin" value="<?php echo htmlspecialchars((string)($pengaturan_perjanjian['ttd_admin'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="signature-field-foot">
                                            <span class="signature-field-note">Tanda tangan tersimpan bersama pengaturan surat.</span>
                                            <button type="button" class="signature-clear clear-sign btn btn-outline-danger btn-sm" id="hapusTtdAdmin"><i class="ti ti-eraser me-1"></i>Hapus / gambar ulang</button>
                                        </div>
                                    </div>
                                    <div class="ui-set-actions mt-3">
                                        <button type="submit" name="update_perjanjian" class="btn btn-primary">
                                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Simpan perjanjian
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <aside class="ui-set-side">
                        <div class="card ui-set-card mb-3">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-photo" aria-hidden="true"></i>Logo</span>
                            </div>
                            <div class="card-body">
                                <div class="ui-set-logo-preview">
                                    <?php if ($logoAda): ?>
                                    <img src="<?= htmlspecialchars(urlLogoKoperasi($koperasi['logo'], '../') ?: ('../uploads/logo/' . basename((string)$koperasi['logo']))) ?>" alt="Logo koperasi">
                                    <small>Logo saat ini</small>
                                    <?php else: ?>
                                    <div class="ui-set-logo-empty"><i class="ti ti-photo" aria-hidden="true"></i><span>Belum ada logo</span></div>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" enctype="multipart/form-data" action="pengaturan.php" class="mt-3"><?php echo layananToken(); ?>
                                    <input type="hidden" name="upload_logo" value="1">
                                    <label class="form-label" for="kop_logo">Upload logo baru</label>
                                    <input type="file" id="kop_logo" name="logo" class="form-control" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" required>
                                    <div class="form-text mb-3">JPEG / PNG / WEBP · hasil akhir dikompres maks. 1 MB</div>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="ti ti-upload me-1" aria-hidden="true"></i>Upload logo
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="card ui-set-card">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-key" aria-hidden="true"></i>Ubah password</span>
                            </div>
                            <div class="card-body">
                                <form method="POST"><?php echo layananToken(); ?>
                                    <div class="mb-3">
                                        <label class="form-label" for="pw_cur">Password saat ini</label>
                                        <input type="password" id="pw_cur" name="current_password" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="pw_new">Password baru</label>
                                        <input type="password" id="pw_new" name="new_password" class="form-control" placeholder="Minimal 6 karakter" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="pw_conf">Konfirmasi password</label>
                                        <input type="password" id="pw_conf" name="confirm_password" class="form-control" required>
                                    </div>
                                    <button type="submit" name="change_password" class="btn btn-warning w-100">
                                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Ubah password
                                    </button>
                                </form>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
    <script src="../assets/js/tanda-tangan-layanan.js?v=20261029-2"></script>
    <script>
    (function(){
      var c=document.getElementById('ttdAdminCanvas'); if(!c)return;
      var existing=<?php echo json_encode((string)($pengaturan_perjanjian['ttd_admin'] ?? '')); ?>;
      if(window.SimkeuSignaturePad){ SimkeuSignaturePad.bind(c); SimkeuSignaturePad.resize(document); }
      var saving=false;
      document.getElementById('formPerjanjian').addEventListener('submit',async function(e){
        e.preventDefault();
        if(saving)return;
        var field=document.getElementById('ttd_admin');
        var hasTtd=(field&&field.value)||existing;
        if(!hasTtd){alert('TTD Admin wajib dibuat.');return;}
        var form=this, button=form.querySelector('button[type="submit"]');
        saving=true;button.disabled=true;
        try {
          var data=new FormData(form);
          data.delete('ttd_admin');
          data.set('update_perjanjian','1');
          var dirty=window.SimkeuSignaturePad ? SimkeuSignaturePad.isDirty(c) : !!field.value;
          if(dirty || (field && field.value && field.value !== existing)){
            var blob=await new Promise(function(resolve){c.toBlob(resolve,'image/png')});
            if(!blob)throw new Error('Tanda tangan gagal dibuat. Silakan coba lagi.');
            if(blob.size>1024*1024)throw new Error('Tanda tangan melebihi 1 MB.');
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


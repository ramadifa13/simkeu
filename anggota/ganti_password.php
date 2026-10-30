<?php
require_once '../includes/auth.php';
checkAnggota();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password_lama = $_POST['password_lama'] ?? '';
    $password_baru = $_POST['password_baru'] ?? '';
    $konfirmasi_password = $_POST['konfirmasi_password'] ?? '';

    if ($password_baru !== $konfirmasi_password) {
        $error = 'Password baru dan konfirmasi tidak cocok';
    } elseif (strlen($password_baru) < 6) {
        $error = 'Password baru minimal 6 karakter';
    } else {
        $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password_lama, $user['password'])) {
            $password_hash = password_hash($password_baru, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
            if ($stmt->execute([$password_hash, $_SESSION['user_id']])) {
                $_SESSION['success'] = 'Password berhasil diubah';
                header('Location: ganti_password.php');
                exit();
            }
            $error = 'Gagal mengubah password';
        } else {
            $error = 'Password lama salah';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ganti Password - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-agtpass" aria-hidden="true"><i class="ti ti-lock"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Akun anggota</p>
                        <h1 class="ui-dash-title">Ganti Password</h1>
                        <p class="ui-dash-meta mb-0">Perbarui kata sandi login Anda secara berkala</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="profil.php" class="btn btn-sm ui-set-btn-ghost">
                            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Kembali ke profil
                        </a>
                    </div>
                </header>

                <?php ui_flash(); ?>
                <?php if ($error !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
                </div>
                <?php endif; ?>

                <div class="row g-3 justify-content-center">
                    <div class="col-12 col-lg-7 col-xl-6">
                        <div class="card ui-agt-pass-card">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-key" aria-hidden="true"></i>Formulir password</span>
                                <span class="ui-dash-sec-note">Minimal 6 karakter</span>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="ui-agt-pass-form" autocomplete="off">
                                    <div class="mb-3">
                                        <label class="form-label" for="password_lama">Password lama</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="ti ti-lock" aria-hidden="true"></i></span>
                                            <input type="password" class="form-control" id="password_lama" name="password_lama" required autocomplete="current-password">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="password_baru">Password baru</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="ti ti-key" aria-hidden="true"></i></span>
                                            <input type="password" class="form-control" id="password_baru" name="password_baru" required minlength="6" autocomplete="new-password">
                                        </div>
                                        <div class="form-hint">Gunakan kombinasi huruf dan angka yang mudah Anda ingat.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="konfirmasi_password">Konfirmasi password baru</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="ti ti-check" aria-hidden="true"></i></span>
                                            <input type="password" class="form-control" id="konfirmasi_password" name="konfirmasi_password" required minlength="6" autocomplete="new-password">
                                        </div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Simpan password
                                        </button>
                                        <a href="profil.php" class="btn btn-outline-secondary">Batal</a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="ui-agt-note mt-3" role="note">
                            <i class="ti ti-shield-check" aria-hidden="true"></i>
                            <div>
                                <strong>Keamanan akun</strong>
                                <span>Jangan bagikan password kepada orang lain. Setelah diganti, gunakan password baru saat login berikutnya.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
</body>
</html>

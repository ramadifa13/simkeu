<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__.'/includes/brand.php';
require_once 'config/config.php';
require_once 'includes/auth.php';

if (isLoggedIn()) {
    redirectBasedOnRole();
}

$koperasi = [];
try {
    require_once 'config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    $koperasi_stmt = $db->prepare('SELECT * FROM koperasi WHERE id = 1');
    $koperasi_stmt->execute();
    $koperasi = $koperasi_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$koperasi) {
        $koperasi = ['nama' => APP_NAME, 'logo' => null, 'alamat' => '', 'telepon' => '', 'email' => ''];
    }
} catch (Exception $e) {
    error_log('Error mengambil data koperasi: ' . $e->getMessage());
    $koperasi = ['nama' => APP_NAME, 'logo' => null, 'alamat' => '', 'telepon' => '', 'email' => ''];
}

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}
$error = '';
$success = '';
if (isset($_SESSION['error'])) {
    $error = (string)$_SESSION['error'];
    unset($_SESSION['error']);
}
if (isset($_SESSION['success'])) {
    $success = (string)$_SESSION['success'];
    unset($_SESSION['success']);
}
$namaKop = htmlspecialchars((string)($koperasi['nama'] ?? APP_NAME), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= $namaKop ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/public-ui.css?v=20261029-login6">
    <script src="assets/js/brand.js?v=20261019-6" defer></script>
</head>
<body class="role-unified">
<div class="login-layout">
    <aside class="login-intro" aria-label="Pengantar">
        <a class="login-home" href="index.php"><i class="fas fa-hand-holding-usd" aria-hidden="true"></i> <?= $namaKop ?></a>
        <span class="login-eyebrow">LAYANAN KOPERASI</span>
        <h1>Satu ruang kerja untuk melayani anggota.</h1>
        <p>Masuk dengan akun Anda. Menu dan dashboard menyesuaikan peran Admin, Teller, atau Anggota.</p>
        <div class="login-intro-items">
            <span><i class="fas fa-users" aria-hidden="true"></i> Data anggota tersusun</span>
            <span><i class="fas fa-receipt" aria-hidden="true"></i> Transaksi &amp; bukti pembayaran</span>
            <span><i class="fas fa-chart-line" aria-hidden="true"></i> Pemantauan tagihan</span>
        </div>
        <small>Akses hanya untuk pengguna yang terdaftar.</small>
    </aside>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-container"><?= brandLogo($koperasi['logo'] ?? '') ?></div>
                <div class="koperasi-name"><?= $namaKop ?></div>
                <div class="sub-title"><i class="fas fa-lock me-1" aria-hidden="true"></i> Masuk ke akun</div>
            </div>
            <div class="login-body">
                <?php if ($error !== ''): ?>
                <div class="alert alert-danger login-alert" role="alert">
                    <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                    <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                <div class="alert alert-success login-alert" role="status">
                    <i class="fas fa-check-circle" aria-hidden="true"></i>
                    <div><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>

                <form method="POST" action="login_process.php" id="loginForm">
                    <input type="hidden" name="login_csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES) ?>">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control login-input" id="username" name="username" autocomplete="username"
                               placeholder="Masukkan username" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="login-password-wrap">
                            <input type="password" class="form-control login-input" id="password" name="password" autocomplete="current-password"
                                   placeholder="Masukkan password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Tampilkan password" aria-pressed="false">
                                <i class="fas fa-eye" id="passwordIcon" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" name="login" class="btn btn-login text-white w-100" id="loginButton">
                        <i class="fas fa-sign-in-alt me-2" aria-hidden="true"></i><span id="loginText">Masuk</span>
                    </button>
                </form>

                <div class="support-info">
                    <i class="fas fa-headset" aria-hidden="true"></i>
                    <span>Butuh bantuan? Hubungi pengurus koperasi.</span>
                </div>
                <div class="text-center mt-3">
                    <a href="index.php" class="back-link"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Kembali ke Beranda</a>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePassword() {
    var input = document.getElementById('password');
    var icon = document.getElementById('passwordIcon');
    var btn = document.querySelector('.password-toggle');
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    icon.classList.toggle('fa-eye', !show);
    icon.classList.toggle('fa-eye-slash', show);
}
document.getElementById('loginForm').addEventListener('submit', function () {
    var button = document.getElementById('loginButton');
    if (button.disabled) return;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';
});
</script>
</body>
</html>

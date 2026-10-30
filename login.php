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
    <?php $uiAssetPrefix = ''; include __DIR__ . '/includes/layout/assets.php'; ?>
    <link rel="stylesheet" href="assets/css/public-theme.css?v=<?= filemtime(__DIR__ . '/assets/css/public-theme.css') ?>">
    <script src="assets/js/brand.js?v=20261019-6" defer></script>
</head>
<body class="sk-auth">
<a class="visually-hidden-focusable" href="#loginForm">Lewati ke formulir masuk</a>
<div class="sk-auth-layout">
    <aside class="sk-auth-intro" aria-label="Pengantar">
        <div class="sk-auth-intro-inner">
            <a class="sk-auth-home" href="index.php">
                <span class="sk-auth-home-logo"><?= brandLogo($koperasi['logo'] ?? '') ?></span>
                <span><?= $namaKop ?></span>
            </a>
            <span class="sk-eyebrow">LAYANAN KOPERASI</span>
            <h1>Satu ruang kerja untuk melayani anggota.</h1>
            <p>Masuk sekali — Admin, Teller, dan Anggota memakai halaman login yang sama. Setelah masuk, navbar dan sidebar menyesuaikan peran Anda.</p>
            <ul class="sk-auth-points">
                <li><i class="ti ti-shield-lock" aria-hidden="true"></i> Satu login untuk semua peran</li>
                <li><i class="ti ti-layout-sidebar" aria-hidden="true"></i> Menu &amp; dashboard per peran</li>
                <li><i class="ti ti-receipt-2" aria-hidden="true"></i> Transaksi &amp; bukti pembayaran</li>
            </ul>
            <small>Akses hanya untuk pengguna yang terdaftar.</small>
        </div>
    </aside>
    <main class="sk-auth-main">
        <div class="card sk-auth-card">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="sk-auth-logo mx-auto mb-2"><?= brandLogo($koperasi['logo'] ?? '') ?></div>
                    <h2 class="h3 mb-1"><?= $namaKop ?></h2>
                    <div class="text-secondary"><i class="ti ti-lock me-1" aria-hidden="true"></i>Masuk ke akun</div>
                </div>
                <?php if ($error !== ''): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                    <i class="ti ti-alert-circle fs-2" aria-hidden="true"></i>
                    <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                <div class="alert alert-success d-flex align-items-start gap-2" role="status">
                    <i class="ti ti-circle-check fs-2" aria-hidden="true"></i>
                    <div><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>

                <form method="POST" action="login_process.php" id="loginForm">
                    <input type="hidden" name="login_csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES) ?>">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-icon">
                            <span class="input-icon-addon"><i class="ti ti-user"></i></span>
                            <input type="text" class="form-control form-control-lg" id="username" name="username" autocomplete="username" placeholder="Masukkan username" required autofocus>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group input-group-flat">
                            <input type="password" class="form-control form-control-lg" id="password" name="password" autocomplete="current-password" placeholder="Masukkan password" required>
                            <span class="input-group-text">
                                <button type="button" class="link-secondary border-0 bg-transparent p-0 password-toggle" onclick="togglePassword()" aria-label="Tampilkan password" aria-pressed="false">
                                    <i class="ti ti-eye fs-3" id="passwordIcon" aria-hidden="true"></i>
                                </button>
                            </span>
                        </div>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary btn-lg w-100" id="loginButton">
                        <i class="ti ti-login me-2" aria-hidden="true"></i><span id="loginText">Masuk</span>
                    </button>
                </form>

                <div class="text-secondary small text-center mt-4"><i class="ti ti-headset me-1" aria-hidden="true"></i>Butuh bantuan? Hubungi pengurus koperasi.</div>
                <div class="text-center mt-3">
                    <a href="index.php"><i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Kembali ke Beranda</a>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="assets/vendor/tabler/tabler.min.js"></script>
<script>
function togglePassword() {
    var input = document.getElementById('password');
    var icon = document.getElementById('passwordIcon');
    var btn = document.querySelector('.password-toggle');
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    icon.classList.toggle('ti-eye', !show);
    icon.classList.toggle('ti-eye-off', show);
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
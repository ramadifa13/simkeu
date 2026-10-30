<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/includes/brand.php';
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
    $error = (string) $_SESSION['error'];
    unset($_SESSION['error']);
}
if (isset($_SESSION['success'])) {
    $success = (string) $_SESSION['success'];
    unset($_SESSION['success']);
}
$namaKop = htmlspecialchars((string) ($koperasi['nama'] ?? APP_NAME), ENT_QUOTES, 'UTF-8');
$cssV = @filemtime(__DIR__ . '/assets/css/public-theme.css') ?: time();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — <?= $namaKop ?></title>
    <meta name="description" content="Masuk ke layanan <?= $namaKop ?> untuk Admin, Teller, dan Anggota.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php $uiAssetPrefix = ''; include __DIR__ . '/includes/layout/assets.php'; ?>
    <link rel="stylesheet" href="assets/css/public-theme.css?v=<?= (int) $cssV ?>">
    <script src="assets/js/brand.js?v=20261019-6" defer></script>
</head>
<body class="lp-auth">
<a class="visually-hidden-focusable" href="#loginForm">Lewati ke formulir masuk</a>

<div class="lp-auth-shell">
    <aside class="lp-auth-panel" aria-label="Pengantar">
        <div class="lp-auth-panel-bg" aria-hidden="true">
            <div class="lp-auth-glow lp-auth-glow-a"></div>
            <div class="lp-auth-glow lp-auth-glow-b"></div>
            <svg class="lp-auth-art" viewBox="0 0 640 720" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
                <circle cx="480" cy="160" r="110" stroke="rgba(255,255,255,.12)" stroke-width="2"/>
                <circle cx="480" cy="160" r="68" stroke="rgba(255,255,255,.18)" stroke-width="2"/>
                <path d="M40 420c70-36 130-18 190 16s130 42 200 6 150-34 220 8" stroke="rgba(167,243,208,.3)" stroke-width="2.5" stroke-linecap="round"/>
                <rect x="70" y="480" width="200" height="120" rx="24" fill="rgba(255,255,255,.08)" stroke="rgba(255,255,255,.14)"/>
                <rect x="94" y="512" width="64" height="10" rx="5" fill="rgba(167,243,208,.55)"/>
                <rect x="94" y="534" width="110" height="8" rx="4" fill="rgba(255,255,255,.28)"/>
                <circle cx="230" cy="540" r="22" fill="rgba(45,212,191,.35)"/>
            </svg>
        </div>
        <div class="lp-auth-panel-inner">
            <a class="lp-auth-brand" href="index.php">
                <span class="lp-auth-brand-logo"><?= brandLogo($koperasi['logo'] ?? '') ?></span>
                <span class="lp-auth-brand-name"><?= $namaKop ?></span>
            </a>
            <h1 class="lp-auth-title">Masuk untuk melanjutkan layanan.</h1>
            <p class="lp-auth-lead">Satu halaman login untuk Admin, Teller, dan Anggota. Menu menyesuaikan peran setelah Anda masuk.</p>
            <ul class="lp-auth-points">
                <li><i class="ti ti-shield-lock" aria-hidden="true"></i><span>Akses aman sesuai peran</span></li>
                <li><i class="ti ti-layout-sidebar" aria-hidden="true"></i><span>Dashboard &amp; menu yang relevan</span></li>
                <li><i class="ti ti-receipt-2" aria-hidden="true"></i><span>Transaksi dan bukti pembayaran</span></li>
            </ul>
        </div>
    </aside>

    <main class="lp-auth-main">
        <div class="lp-auth-card">
            <header class="lp-auth-card-head">
                <div class="lp-auth-card-logo"><?= brandLogo($koperasi['logo'] ?? '') ?></div>
                <h2>Masuk ke akun</h2>
                <p>Gunakan username dan password yang terdaftar.</p>
            </header>

            <?php if ($error !== ''): ?>
            <div class="lp-auth-alert is-error" role="alert">
                <i class="ti ti-alert-circle" aria-hidden="true"></i>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
            <div class="lp-auth-alert is-ok" role="status">
                <i class="ti ti-circle-check" aria-hidden="true"></i>
                <span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="login_process.php" id="loginForm" class="lp-auth-form" autocomplete="on">
                <input type="hidden" name="login_csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES) ?>">

                <div class="lp-auth-field">
                    <label for="username">Username</label>
                    <div class="lp-auth-input">
                        <i class="ti ti-user" aria-hidden="true"></i>
                        <input type="text" id="username" name="username" autocomplete="username" placeholder="Masukkan username" required autofocus>
                    </div>
                </div>

                <div class="lp-auth-field">
                    <label for="password">Password</label>
                    <div class="lp-auth-input">
                        <i class="ti ti-lock" aria-hidden="true"></i>
                        <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Masukkan password" required>
                        <button type="button" class="lp-auth-eye" id="passwordToggle" aria-label="Tampilkan password" aria-pressed="false">
                            <i class="ti ti-eye" id="passwordIcon" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" name="login" class="lp-auth-submit" id="loginButton">
                    <span id="loginText">Masuk</span>
                    <i class="ti ti-arrow-right" aria-hidden="true"></i>
                </button>
            </form>

            <p class="lp-auth-help"><i class="ti ti-headset" aria-hidden="true"></i>Butuh bantuan? Hubungi pengurus koperasi.</p>
            <a class="lp-auth-back" href="index.php"><i class="ti ti-arrow-left" aria-hidden="true"></i>Kembali ke beranda</a>
        </div>
    </main>
</div>

<script>
(function () {
    var toggle = document.getElementById('passwordToggle');
    var input = document.getElementById('password');
    var icon = document.getElementById('passwordIcon');
    if (toggle && input && icon) {
        toggle.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            icon.classList.toggle('ti-eye', !show);
            icon.classList.toggle('ti-eye-off', show);
        });
    }
    var form = document.getElementById('loginForm');
    var button = document.getElementById('loginButton');
    if (form && button) {
        form.addEventListener('submit', function () {
            if (button.disabled) return;
            button.disabled = true;
            button.innerHTML = '<span class="lp-auth-spinner" aria-hidden="true"></span><span>Memproses…</span>';
        });
    }
})();
</script>
</body>
</html>

<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isTeller()) {
    header('Location: ../dashboard.php');
    exit();
}

$page_title = 'Ubah Password';
$error = '';
$success = '';

$user_id = (int) $_SESSION['user_id'];
$stmt_user = $db->prepare('SELECT nama_lengkap, username, last_login, status FROM users WHERE id = ?');
$stmt_user->execute([$user_id]);
$akun = $stmt_user->fetch(PDO::FETCH_ASSOC) ?: [
    'nama_lengkap' => $_SESSION['nama_lengkap'] ?? 'Teller',
    'username' => '',
    'last_login' => null,
    'status' => 'aktif',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ubah_password']) || isset($_POST['current_password']))) {
    try {
        $lama = (string) ($_POST['current_password'] ?? '');
        $baru = (string) ($_POST['new_password'] ?? '');
        $konfirmasi = (string) ($_POST['confirm_password'] ?? '');
        ubahPasswordPengguna($db, $user_id, $lama, $baru, $konfirmasi);
        $_SESSION['success'] = 'Password berhasil diubah. Gunakan password baru saat login berikutnya.';
        header('Location: ubah_password.php');
        exit();
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: ubah_password.php');
        exit();
    }
}

if (!empty($_SESSION['success'])) {
    $success = (string) $_SESSION['success'];
    unset($_SESSION['success']);
}
if (!empty($_SESSION['error'])) {
    $error = (string) $_SESSION['error'];
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-tpass" aria-hidden="true"><i class="ti ti-lock"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Keamanan akun</p>
                        <h1 class="ui-dash-title">Ubah Password</h1>
                        <p class="ui-dash-meta mb-0">Perbarui kata sandi akun teller secara berkala</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="profil.php" class="btn btn-sm ui-set-btn-ghost">
                            <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Kembali ke profil
                        </a>
                    </div>
                </header>

                <?php if ($error !== ''): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                    <i class="ti ti-alert-triangle fs-2" aria-hidden="true"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                <div class="alert alert-success d-flex align-items-start gap-2" role="status">
                    <i class="ti ti-circle-check fs-2" aria-hidden="true"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
                <?php endif; ?>

                <section class="ui-tpass-hero mb-3" aria-label="Ringkasan keamanan">
                    <div class="ui-tpass-hero-ico" aria-hidden="true"><i class="ti ti-shield-lock"></i></div>
                    <div class="ui-tpass-hero-copy">
                        <p class="ui-tpass-eyebrow mb-0">Akun · <?php echo htmlspecialchars($akun['username'] ?: 'teller'); ?></p>
                        <h2 class="ui-tpass-title"><?php echo htmlspecialchars($akun['nama_lengkap']); ?></h2>
                        <p class="ui-tpass-meta mb-0">Gunakan password unik. Jangan memakai password yang sama dengan akun lain.</p>
                    </div>
                    <div class="ui-tpass-hero-stats">
                        <div>
                            <span>Login terakhir</span>
                            <strong><?php echo !empty($akun['last_login']) ? htmlspecialchars(formatTanggalUi($akun['last_login'], true)) : '—'; ?></strong>
                        </div>
                        <div>
                            <span>Status akun</span>
                            <strong class="<?php echo ($akun['status'] ?? '') === 'aktif' ? 'is-ok' : 'is-off'; ?>">
                                <?php echo ($akun['status'] ?? '') === 'aktif' ? 'Aktif' : 'Nonaktif'; ?>
                            </strong>
                        </div>
                    </div>
                </section>

                <div class="ui-tpass-grid">
                    <div class="card ui-tpass-form-card">
                        <div class="card-header ui-dash-sec">
                            <span class="ui-dash-sec-title"><i class="ti ti-key" aria-hidden="true"></i>Form ubah password</span>
                            <span class="ui-dash-sec-note">Minimal 8 karakter</span>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="ubah_password.php" id="changePasswordForm" class="ui-tpass-form" autocomplete="off">
                                <input type="hidden" name="ubah_password" value="1">
                                <?php echo layananToken(); ?>
                                <div class="ui-tpass-field">
                                    <label class="form-label" for="current_password">Password saat ini</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" name="current_password" id="current_password"
                                               placeholder="Masukkan password saat ini" required autocomplete="current-password">
                                        <button type="button" class="btn btn-outline-secondary ui-tpass-toggle" data-target="current_password" aria-label="Tampilkan password">
                                            <i class="ti ti-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="ui-tpass-field">
                                    <label class="form-label" for="new_password">Password baru</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" name="new_password" id="new_password"
                                               placeholder="Minimal 8 karakter" required minlength="8" autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary ui-tpass-toggle" data-target="new_password" aria-label="Tampilkan password">
                                            <i class="ti ti-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="ui-tpass-strength" id="passwordStrength" aria-hidden="true"></div>
                                    <div class="form-hint" id="passwordFeedback"></div>
                                </div>
                                <div class="ui-tpass-field">
                                    <label class="form-label" for="confirm_password">Konfirmasi password baru</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" name="confirm_password" id="confirm_password"
                                               placeholder="Ketik ulang password baru" required minlength="8" autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary ui-tpass-toggle" data-target="confirm_password" aria-label="Tampilkan password">
                                            <i class="ti ti-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="form-hint" id="passwordMatch"></div>
                                </div>
                                <div class="ui-tpass-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Simpan password baru
                                    </button>
                                    <button type="reset" class="btn btn-outline-secondary" id="btnResetPass">
                                        <i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i>Reset
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <aside class="ui-tpass-side">
                        <div class="card ui-tpass-criteria-card mb-3">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-list-check" aria-hidden="true"></i>Kriteria password</span>
                            </div>
                            <div class="card-body">
                                <ul class="ui-tpass-criteria" id="passwordCriteria">
                                    <li data-key="length"><i class="ti ti-circle" aria-hidden="true"></i><span>Minimal 8 karakter</span></li>
                                    <li data-key="upper"><i class="ti ti-circle" aria-hidden="true"></i><span>Huruf besar (A–Z)</span></li>
                                    <li data-key="lower"><i class="ti ti-circle" aria-hidden="true"></i><span>Huruf kecil (a–z)</span></li>
                                    <li data-key="number"><i class="ti ti-circle" aria-hidden="true"></i><span>Angka (0–9)</span></li>
                                    <li data-key="special"><i class="ti ti-circle" aria-hidden="true"></i><span>Simbol (!@#$…)</span></li>
                                </ul>
                            </div>
                        </div>
                        <div class="card ui-tpass-tips-card">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-bulb" aria-hidden="true"></i>Tips</span>
                            </div>
                            <div class="card-body">
                                <ul class="ui-tpass-tips">
                                    <li>Jangan pakai tanggal lahir atau nama sendiri</li>
                                    <li>Hindari password yang sama di banyak akun</li>
                                    <li>Ubah password secara berkala</li>
                                    <li>Jangan bagikan password ke siapapun</li>
                                </ul>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
<script>
(function () {
    function togglePassword(id) {
        var field = document.getElementById(id);
        if (!field) return;
        var btn = document.querySelector('.ui-tpass-toggle[data-target="' + id + '"]');
        var icon = btn ? btn.querySelector('i') : null;
        var show = field.type === 'password';
        field.type = show ? 'text' : 'password';
        if (btn) {
            btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
        }
        if (icon) {
            icon.classList.toggle('ti-eye', !show);
            icon.classList.toggle('ti-eye-off', show);
        }
    }

    document.querySelectorAll('.ui-tpass-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            togglePassword(btn.getAttribute('data-target'));
        });
    });

    var newPass = document.getElementById('new_password');
    var confirmPass = document.getElementById('confirm_password');
    var strengthBar = document.getElementById('passwordStrength');
    var feedback = document.getElementById('passwordFeedback');
    var matchText = document.getElementById('passwordMatch');
    var criteria = document.getElementById('passwordCriteria');

    function setCriterion(key, ok) {
        if (!criteria) return;
        var li = criteria.querySelector('[data-key="' + key + '"]');
        if (!li) return;
        var icon = li.querySelector('i');
        li.classList.toggle('is-met', ok);
        if (icon) {
            icon.className = ok ? 'ti ti-circle-check' : 'ti ti-circle';
        }
    }

    function checkStrength() {
        var password = newPass ? newPass.value : '';
        var checks = {
            length: password.length >= 8,
            upper: /[A-Z]/.test(password),
            lower: /[a-z]/.test(password),
            number: /[0-9]/.test(password),
            special: /[^A-Za-z0-9]/.test(password)
        };
        Object.keys(checks).forEach(function (k) { setCriterion(k, checks[k]); });

        var score = Object.keys(checks).reduce(function (n, k) { return n + (checks[k] ? 1 : 0); }, 0);
        if (!strengthBar) return;
        strengthBar.className = 'ui-tpass-strength';
        if (!password) {
            if (feedback) feedback.textContent = '';
            return;
        }
        var map = [
            '',
            'is-weak',
            'is-fair',
            'is-good',
            'is-strong',
            'is-strong'
        ];
        var labels = ['', 'Lemah', 'Cukup', 'Baik', 'Kuat', 'Sangat kuat'];
        strengthBar.classList.add(map[score] || 'is-weak');
        if (feedback) feedback.textContent = labels[score] || '';
    }

    function checkMatch() {
        if (!matchText || !confirmPass) return;
        var a = newPass ? newPass.value : '';
        var b = confirmPass.value;
        if (!b) {
            matchText.textContent = '';
            matchText.className = 'form-hint';
            return;
        }
        if (a === b) {
            matchText.textContent = 'Password cocok';
            matchText.className = 'form-hint text-success';
        } else {
            matchText.textContent = 'Password tidak cocok';
            matchText.className = 'form-hint text-danger';
        }
    }

    if (newPass) newPass.addEventListener('input', function () { checkStrength(); checkMatch(); });
    if (confirmPass) confirmPass.addEventListener('input', checkMatch);

    var form = document.getElementById('changePasswordForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var password = newPass.value;
            var confirmPassword = confirmPass.value;
            if (password.length < 8) {
                e.preventDefault();
                alert('Password baru harus minimal 8 karakter.');
                return;
            }
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Password baru dan konfirmasi tidak cocok.');
            }
        });
        form.addEventListener('reset', function () {
            setTimeout(function () { checkStrength(); checkMatch(); }, 0);
        });
    }

    checkStrength();
    checkMatch();
})();
</script>
</body>
</html>

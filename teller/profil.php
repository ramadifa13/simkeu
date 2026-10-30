<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isTeller()) {
    header('Location: ../dashboard.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$query = 'SELECT * FROM users WHERE id = :user_id';
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    $_SESSION['error'] = 'Data akun tidak ditemukan.';
    header('Location: dashboard.php');
    exit();
}

redirectJikaPostMelebihiBatas('profil.php');

// Deteksi submit lewat hidden field (lebih andal daripada name tombol saja).
$submitProfil = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['update_profil']) || isset($_POST['nama_lengkap']));

if ($submitProfil) {
    $errmode = $db->getAttribute(PDO::ATTR_ERRMODE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $foto_baru = null;
    try {
        $nama_lengkap = trim((string) ($_POST['nama_lengkap'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $telepon = trim((string) ($_POST['telepon'] ?? ''));

        if ($nama_lengkap === '' || strlen($nama_lengkap) > 100) {
            throw new Exception('Nama lengkap wajib diisi (maksimal 100 karakter)');
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100)) {
            throw new Exception('Format email tidak valid');
        }
        if ($telepon !== '' && !preg_match('/^[0-9+\-\s()]{5,20}$/', $telepon)) {
            throw new Exception('Nomor telepon tidak valid (5–20 digit)');
        }

        $punyaTelepon = kolomAda($db, 'users', 'telepon');
        $punyaFoto = kolomAda($db, 'users', 'foto_profil');
        $foto = null;
        if ($punyaFoto) {
            $foto = validasiFotoUpload('foto_profil', 'Foto profil', 1024 * 1024, false);
        } elseif (!empty($_FILES['foto_profil']['name'])) {
            throw new Exception('Kolom foto profil belum tersedia. Jalankan migrasi database.');
        }

        $set = ['nama_lengkap = ?', 'email = ?'];
        $nilai = [$nama_lengkap, $email !== '' ? $email : null];
        if ($punyaTelepon) {
            $set[] = 'telepon = ?';
            $nilai[] = $telepon !== '' ? $telepon : null;
        }
        if ($foto) {
            $foto_baru = simpanFotoUpload($foto, 'profil');
            $set[] = 'foto_profil = ?';
            $nilai[] = $foto_baru;
        }
        $nilai[] = $user_id;
        $db->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ? AND role = \'teller\'')->execute($nilai);

        if ($foto_baru && !empty($user['foto_profil'])) {
            hapusFotoUpload($user['foto_profil']);
        }
        $_SESSION['nama_lengkap'] = $nama_lengkap;
        $_SESSION['email'] = $email;
        $_SESSION['success'] = 'Profil berhasil diperbarui';
    } catch (Throwable $e) {
        if ($foto_baru) {
            hapusFotoUpload($foto_baru);
        }
        $_SESSION['error'] = 'Gagal memperbarui profil: ' . $e->getMessage();
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, $errmode);
    header('Location: profil.php');
    exit();
}

$stmt_stats = $db->prepare(
    "SELECT
        (SELECT COUNT(*) FROM transaksi WHERE user_id = :user_id) AS total_transaksi,
        (SELECT COUNT(*) FROM pinjaman WHERE status = 'aktif') AS total_pinjaman_aktif,
        (SELECT COUNT(*) FROM anggota WHERE status = 'aktif') AS total_anggota_aktif"
);
$stmt_stats->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC) ?: [
    'total_transaksi' => 0,
    'total_pinjaman_aktif' => 0,
    'total_anggota_aktif' => 0,
];

$stmt_activities = $db->prepare(
    'SELECT jenis_transaksi, jumlah, tanggal_transaksi
     FROM transaksi
     WHERE user_id = :user_id
     ORDER BY tanggal_transaksi DESC
     LIMIT 8'
);
$stmt_activities->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$stmt_activities->execute();
$activities = $stmt_activities->fetchAll(PDO::FETCH_ASSOC);

$jenisLabel = [
    'setoran' => 'Setoran',
    'penarikan' => 'Penarikan',
    'pelunasan' => 'Pelunasan',
];
$statusAktif = ($user['status'] ?? '') === 'aktif';
$fotoUrl = !empty($user['foto_profil']) ? urlFoto($user['foto_profil']) : '';
$inisial = getInitials($user['nama_lengkap'] ?? 'T');
$page_title = 'Profil Teller';
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
                    <div class="ui-dash-header-icon ui-tx-icon-tprof" aria-hidden="true"><i class="ti ti-user-circle"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Akun Teller</p>
                        <h1 class="ui-dash-title">Profil Saya</h1>
                        <p class="ui-dash-meta mb-0">Data akun, foto, dan aktivitas transaksi Anda</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="ubah_password.php" class="btn btn-sm ui-set-btn-ghost">
                            <i class="ti ti-lock me-1" aria-hidden="true"></i>Ubah password
                        </a>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <section class="ui-tprof-hero mb-3" aria-label="Identitas teller">
                    <div class="ui-tprof-hero-main">
                        <div class="ui-tprof-avatar" aria-hidden="true">
                            <?php if ($fotoUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($fotoUrl); ?>" alt="">
                            <?php else: ?>
                                <span><?php echo htmlspecialchars($inisial); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="ui-tprof-hero-copy">
                            <p class="ui-tprof-eyebrow mb-0">Teller koperasi</p>
                            <h2 class="ui-tprof-name"><?php echo htmlspecialchars($user['nama_lengkap']); ?></h2>
                            <div class="ui-tprof-chips">
                                <span class="ui-tprof-chip"><i class="ti ti-at" aria-hidden="true"></i><?php echo htmlspecialchars($user['username']); ?></span>
                                <span class="ui-tprof-chip is-role">TELLER</span>
                                <span class="ui-tprof-chip <?php echo $statusAktif ? 'is-ok' : 'is-off'; ?>">
                                    <?php echo $statusAktif ? 'Aktif' : 'Nonaktif'; ?>
                                </span>
                            </div>
                            <p class="ui-tprof-contact mb-0">
                                <span><i class="ti ti-mail" aria-hidden="true"></i><?php echo htmlspecialchars($user['email'] ?: '—'); ?></span>
                                <span><i class="ti ti-phone" aria-hidden="true"></i><?php echo htmlspecialchars($user['telepon'] ?? '—'); ?></span>
                            </p>
                        </div>
                    </div>
                    <div class="ui-tprof-hero-meta">
                        <div>
                            <span>Bergabung</span>
                            <strong><?php echo !empty($user['created_at']) ? htmlspecialchars(formatTanggalUi($user['created_at'])) : '—'; ?></strong>
                        </div>
                        <div>
                            <span>Login terakhir</span>
                            <strong><?php echo !empty($user['last_login']) ? htmlspecialchars(formatTanggalUi($user['last_login'], true)) : '—'; ?></strong>
                        </div>
                    </div>
                </section>

                <div class="ui-tprof-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Transaksi saya', 'value' => (int) $stats['total_transaksi'], 'tone' => 'primary', 'icon' => 'ti ti-receipt-2', 'hint' => 'Semua waktu'],
                    ['label' => 'Pinjaman aktif', 'value' => (int) $stats['total_pinjaman_aktif'], 'tone' => 'warning', 'icon' => 'ti ti-cash', 'hint' => 'Seluruh koperasi'],
                    ['label' => 'Anggota aktif', 'value' => (int) $stats['total_anggota_aktif'], 'tone' => 'success', 'icon' => 'ti ti-users', 'hint' => 'Seluruh koperasi'],
                ]); ?>
                </div>

                <div class="ui-tprof-grid">
                    <div class="ui-tprof-main">
                        <div class="card ui-tprof-form-card mb-3">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-edit" aria-hidden="true"></i>Edit profil</span>
                                <span class="ui-dash-sec-note">Username &amp; role tidak dapat diubah</span>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="profil.php" enctype="multipart/form-data" class="ui-tprof-form" id="formProfilTeller">
                                    <input type="hidden" name="update_profil" value="1">
                                    <?php echo layananToken(); ?>
                                    <div class="ui-tprof-form-grid">
                                        <div class="ui-tprof-field">
                                            <label class="form-label" for="nama_lengkap">Nama lengkap <span class="text-danger">*</span></label>
                                            <input type="text" id="nama_lengkap" name="nama_lengkap" class="form-control" maxlength="100"
                                                   value="<?php echo htmlspecialchars($user['nama_lengkap']); ?>" required autocomplete="name">
                                        </div>
                                        <div class="ui-tprof-field">
                                            <label class="form-label" for="username_ro">Username</label>
                                            <input type="text" id="username_ro" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                                            <div class="form-hint">Username tidak dapat diubah</div>
                                        </div>
                                        <div class="ui-tprof-field">
                                            <label class="form-label" for="email">Email</label>
                                            <input type="email" id="email" name="email" class="form-control" maxlength="100"
                                                   value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" placeholder="email@contoh.com" autocomplete="email">
                                        </div>
                                        <div class="ui-tprof-field">
                                            <label class="form-label" for="telepon">Nomor telepon</label>
                                            <input type="text" id="telepon" name="telepon" class="form-control" maxlength="20"
                                                   value="<?php echo htmlspecialchars($user['telepon'] ?? ''); ?>" placeholder="08xxxxxxxxxx" autocomplete="tel">
                                        </div>
                                        <div class="ui-tprof-field ui-tprof-field-full">
                                            <label class="form-label" for="fotoProfil">Foto profil</label>
                                            <div class="ui-tprof-foto-row">
                                                <div class="ui-tprof-foto-preview" id="fotoPreview" aria-hidden="true">
                                                    <?php if ($fotoUrl !== ''): ?>
                                                        <img src="<?php echo htmlspecialchars($fotoUrl); ?>" alt="">
                                                    <?php else: ?>
                                                        <span><?php echo htmlspecialchars($inisial); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ui-tprof-foto-input">
                                                    <input type="file" name="foto_profil" id="fotoProfil" class="form-control"
                                                           accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                                    <div class="form-hint">JPG / PNG / WEBP · dikompres otomatis maksimal 1 MB. Kosongkan bila tidak diganti.</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="ui-tprof-form-actions">
                                        <button type="submit" class="btn btn-primary" id="btnSimpanProfil">
                                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Simpan perubahan
                                        </button>
                                        <a href="ubah_password.php" class="btn btn-outline-secondary">
                                            <i class="ti ti-lock me-1" aria-hidden="true"></i>Ubah password
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card ui-tprof-tips mb-3">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-shield-check" aria-hidden="true"></i>Tips keamanan</span>
                            </div>
                            <div class="card-body">
                                <ul class="ui-tprof-tips-list">
                                    <li><i class="ti ti-circle-check" aria-hidden="true"></i><span><strong>Password kuat</strong> — kombinasi huruf, angka, dan simbol</span></li>
                                    <li><i class="ti ti-circle-check" aria-hidden="true"></i><span><strong>Jangan bagikan</strong> — jaga kerahasiaan akun teller</span></li>
                                    <li><i class="ti ti-circle-check" aria-hidden="true"></i><span><strong>Logout selesai kerja</strong> — terutama di komputer bersama</span></li>
                                    <li><i class="ti ti-circle-check" aria-hidden="true"></i><span><strong>Update kontak</strong> — email &amp; telepon selalu benar</span></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <aside class="ui-tprof-side">
                        <div class="card ui-tprof-activity">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-history" aria-hidden="true"></i>Aktivitas terbaru</span>
                                <span class="ui-dash-sec-note"><?php echo (int) count($activities); ?> terakhir</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!$activities): ?>
                                <div class="ui-tprof-empty">
                                    <i class="ti ti-clock" aria-hidden="true"></i>
                                    <strong>Belum ada aktivitas</strong>
                                    <p>Transaksi yang Anda proses akan muncul di sini.</p>
                                </div>
                                <?php else: ?>
                                <ul class="ui-tprof-activity-list">
                                    <?php foreach ($activities as $activity):
                                        $jenis = (string) ($activity['jenis_transaksi'] ?? '');
                                        $isIn = $jenis === 'setoran';
                                        ?>
                                    <li>
                                        <span class="ui-tprof-act-ico <?php echo $isIn ? 'is-in' : 'is-out'; ?>" aria-hidden="true">
                                            <i class="ti ti-<?php echo $isIn ? 'arrow-down-left' : 'arrow-up-right'; ?>"></i>
                                        </span>
                                        <div class="ui-tprof-act-copy">
                                            <strong><?php echo htmlspecialchars($jenisLabel[$jenis] ?? ucfirst($jenis)); ?></strong>
                                            <span><?php echo htmlspecialchars(formatTanggalUi($activity['tanggal_transaksi'], true)); ?></span>
                                        </div>
                                        <strong class="ui-tprof-act-amt <?php echo $isIn ? 'is-in' : 'is-out'; ?>">
                                            <?php echo formatRupiah($activity['jumlah']); ?>
                                        </strong>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
<script>
(function () {
    var form = document.getElementById('formProfilTeller');
    var nama = document.getElementById('nama_lengkap');
    if (nama) {
        nama.addEventListener('input', function () {
            this.value = this.value.toUpperCase();
        });
    }
    if (form) {
        form.addEventListener('submit', function (e) {
            if (!nama || !nama.value.trim()) {
                e.preventDefault();
                alert('Nama lengkap wajib diisi.');
                if (nama) nama.focus();
                return;
            }
            var email = document.getElementById('email');
            if (email && email.value.trim() !== '' && !email.checkValidity()) {
                e.preventDefault();
                alert('Format email tidak valid.');
                email.focus();
            }
        });
    }
    var foto = document.getElementById('fotoProfil');
    var preview = document.getElementById('fotoPreview');
    if (!foto || !preview) return;
    foto.addEventListener('change', function () {
        if (!this.files.length) return;
        var f = this.files[0];
        if (['image/jpeg', 'image/png', 'image/webp'].indexOf(f.type) === -1) {
            alert('Foto profil harus berupa JPG, PNG, atau WEBP.');
            this.value = '';
            return;
        }
        // Ukuran asal boleh besar; pilihan-foto.js mengompres ke ≤ 1 MB sebelum unggah.
        var url = URL.createObjectURL(f);
        preview.innerHTML = '<img src="' + url + '" alt="">';
    });
})();
</script>
</body>
</html>

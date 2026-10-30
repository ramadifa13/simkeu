<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    exit('Akses ditolak. Data teller khusus Admin.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        try {
            layananValidasiToken();
            $username = trim((string)($_POST['username'] ?? ''));
            $passwordPlain = (string)($_POST['password'] ?? '');
            $nama_lengkap = trim((string)($_POST['nama_lengkap'] ?? ''));
            $role = 'teller';
            $status = ($_POST['status'] ?? '') === 'nonaktif' ? 'nonaktif' : 'aktif';

            if ($username === '' || $nama_lengkap === '' || $passwordPlain === '') {
                throw new Exception('Username, nama lengkap, dan password wajib diisi.');
            }
            if (strlen($passwordPlain) < 6) {
                throw new Exception('Password minimal 6 karakter.');
            }

            $check = $db->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetchColumn()) {
                throw new Exception('Username sudah digunakan.');
            }

            $password = password_hash($passwordPlain, PASSWORD_DEFAULT);
            $query = "INSERT INTO users (username, password, nama_lengkap, role, status)
                      VALUES (:username, :password, :nama_lengkap, :role, :status)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $password);
            $stmt->bindParam(':nama_lengkap', $nama_lengkap);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':status', $status);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Teller berhasil ditambahkan";
            } else {
                throw new Exception("Gagal menambahkan teller");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        header('Location: teller.php');
        exit;
    }

    if (isset($_POST['edit'])) {
        try {
            layananValidasiToken();
            $id = (int)($_POST['id'] ?? 0);
            $username = trim((string)($_POST['username'] ?? ''));
            $nama_lengkap = trim((string)($_POST['nama_lengkap'] ?? ''));
            $status = ($_POST['status'] ?? '') === 'nonaktif' ? 'nonaktif' : 'aktif';

            if ($id < 1 || $username === '' || $nama_lengkap === '') {
                throw new Exception('Data teller tidak lengkap.');
            }

            $check = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check->execute([$username, $id]);
            if ($check->fetchColumn()) {
                throw new Exception('Username sudah digunakan.');
            }

            $query = "UPDATE users SET username = :username, nama_lengkap = :nama_lengkap,
                      status = :status WHERE id = :id AND role = 'teller'";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':nama_lengkap', $nama_lengkap);
            $stmt->bindParam(':status', $status);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Data teller berhasil diupdate";
            } else {
                throw new Exception("Gagal mengupdate data teller");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        header('Location: teller.php');
        exit;
    }

    if (isset($_POST['reset_password'])) {
        try {
            layananValidasiToken();
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new Exception('Teller tidak valid.');
            }
            $new_password = password_hash('123456', PASSWORD_DEFAULT);

            $query = "UPDATE users SET password = :password WHERE id = :id AND role = 'teller'";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':password', $new_password);
            $stmt->bindParam(':id', $id);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Password berhasil direset ke '123456'";
            } else {
                throw new Exception("Gagal reset password");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        header('Location: teller.php');
        exit;
    }

    if (isset($_POST['hapus'])) {
        try {
            layananValidasiToken();
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new Exception('Teller tidak valid.');
            }
            $cek = $db->prepare("SELECT id, nama_lengkap, status FROM users WHERE id = ? AND role = 'teller'");
            $cek->execute([$id]);
            $row = $cek->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Teller tidak ditemukan.');
            }
            // Lindungi teller aktif terakhir agar aplikasi tidak kehilangan petugas.
            if (($row['status'] ?? '') === 'aktif') {
                $aktifLain = (int) $db->query(
                    "SELECT COUNT(*) FROM users WHERE role = 'teller' AND status = 'aktif' AND id <> " . (int) $id
                )->fetchColumn();
                if ($aktifLain < 1) {
                    throw new Exception('Tidak dapat menghapus teller aktif terakhir. Nonaktifkan dulu atau buat teller lain.');
                }
            }
            $del = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'teller'");
            $del->execute([$id]);
            if ($del->rowCount() < 1) {
                throw new Exception('Gagal menghapus teller.');
            }
            $_SESSION['success'] = 'Teller "' . $row['nama_lengkap'] . '" berhasil dihapus.';
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        header('Location: teller.php');
        exit;
    }
}

$tellers = $db->query(
    "SELECT * FROM users WHERE role = 'teller' ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$total = count($tellers);
$aktif = 0;
$nonaktif = 0;
$belumLogin = 0;
foreach ($tellers as $t) {
    if (($t['status'] ?? '') === 'aktif') {
        $aktif++;
    } else {
        $nonaktif++;
    }
    if (empty($t['last_login'])) {
        $belumLogin++;
    }
}

$page_title = 'Data Teller';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Teller - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-tlr" aria-hidden="true"><i class="ti ti-user-star"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Master data</p>
                        <h1 class="ui-dash-title">Data Teller</h1>
                        <p class="ui-dash-meta mb-0">Kelola akun petugas — login, status, dan reset password</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <button type="button" class="btn ui-dash-btn-add" data-bs-toggle="modal" data-bs-target="#addModal">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Tambah teller</span><span class="d-sm-none">Tambah</span>
                        </button>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="alert alert-info ui-tlr-info" role="status" data-ui-toast-skip>
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                    Reset password mengembalikan ke <strong>123456</strong>. Minta teller mengganti setelah login.
                </div>

                <div class="ui-tlr-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total teller', 'value' => $total, 'tone' => 'primary', 'icon' => 'ti ti-users', 'hint' => 'Semua akun'],
                    ['label' => 'Aktif', 'value' => $aktif, 'tone' => 'success', 'icon' => 'ti ti-user-check', 'hint' => 'Dapat login'],
                    ['label' => 'Nonaktif', 'value' => $nonaktif, 'tone' => 'warning', 'icon' => 'ti ti-user-x', 'hint' => 'Diblokir'],
                    ['label' => 'Belum login', 'value' => $belumLogin, 'tone' => 'info', 'icon' => 'ti ti-clock', 'hint' => 'Belum pernah masuk'],
                ]); ?>
                </div>

                <div class="card ui-tlr-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Daftar teller</span>
                        <span class="ui-dash-sec-note"><?php echo (int)$total; ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="4,desc"
                                   data-dt-filter="3"
                                   data-empty-message="Belum ada data teller.">
                                <thead>
                                    <tr>
                                        <th class="text-muted">#</th>
                                        <th>Petugas</th>
                                        <th>Username</th>
                                        <th>Status</th>
                                        <th>Terakhir login</th>
                                        <th>Dibuat</th>
                                        <th class="text-end no-sort">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($tellers as $index => $teller):
                                    $isAktif = ($teller['status'] ?? '') === 'aktif';
                                    $lastLogin = $teller['last_login'] ?? null;
                                    $created = $teller['created_at'] ?? null;
                                ?>
                                    <tr>
                                        <td class="text-muted"><?php echo (int)($index + 1); ?></td>
                                        <td>
                                            <div class="ui-tlr-person">
                                                <span class="ui-tlr-avatar" aria-hidden="true"><?php echo htmlspecialchars(getInitials($teller['nama_lengkap'])); ?></span>
                                                <span class="ui-tlr-person-copy">
                                                    <strong><?php echo htmlspecialchars($teller['nama_lengkap']); ?></strong>
                                                    <small>Teller</small>
                                                </span>
                                            </div>
                                        </td>
                                        <td><code class="ui-tlr-user"><?php echo htmlspecialchars($teller['username']); ?></code></td>
                                        <td data-filter="<?php echo $isAktif ? 'Aktif' : 'Nonaktif'; ?>">
                                            <span class="badge <?php echo $isAktif ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $isAktif ? 'Aktif' : 'Nonaktif'; ?>
                                            </span>
                                        </td>
                                        <td data-order="<?php echo htmlspecialchars((string)($lastLogin ?: '')); ?>">
                                            <?php echo $lastLogin ? htmlspecialchars(formatTanggalUi($lastLogin, true)) : '<span class="text-muted">Belum login</span>'; ?>
                                        </td>
                                        <td data-order="<?php echo htmlspecialchars((string)($created ?: '')); ?>">
                                            <?php echo $created ? htmlspecialchars(formatTanggalUi($created)) : '—'; ?>
                                        </td>
                                        <td class="table-actions text-end">
                                            <?php echo ui_action_menu([
                                                [
                                                    'label' => 'Edit',
                                                    'icon' => 'ti ti-edit',
                                                    'class' => 'text-warning',
                                                    'attrs' => [
                                                        'data-bs-toggle' => 'modal',
                                                        'data-bs-target' => '#editModal',
                                                        'data-id' => (int)$teller['id'],
                                                        'data-username' => $teller['username'],
                                                        'data-nama' => $teller['nama_lengkap'],
                                                        'data-status' => $teller['status'],
                                                    ],
                                                ],
                                                [
                                                    'label' => 'Reset password',
                                                    'icon' => 'ti ti-key',
                                                    'class' => 'text-info',
                                                    'attrs' => [
                                                        'data-bs-toggle' => 'modal',
                                                        'data-bs-target' => '#resetModal',
                                                        'data-id' => (int)$teller['id'],
                                                        'data-nama' => $teller['nama_lengkap'],
                                                        'data-username' => $teller['username'],
                                                    ],
                                                ],
                                                [
                                                    'label' => 'Hapus',
                                                    'icon' => 'ti ti-trash',
                                                    'class' => 'text-danger',
                                                    'attrs' => [
                                                        'data-bs-toggle' => 'modal',
                                                        'data-bs-target' => '#hapusModal',
                                                        'data-id' => (int) $teller['id'],
                                                        'data-nama' => $teller['nama_lengkap'],
                                                        'data-username' => $teller['username'],
                                                    ],
                                                ],
                                            ]); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>

    <div class="modal fade ui-tlr-modal" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" class="ui-tlr-form-root" data-tlr-form="add" novalidate><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <div>
                            <p class="ui-tlr-modal-eyebrow mb-0">Akun petugas</p>
                            <h2 class="modal-title h5" id="addModalLabel">Tambah teller</h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="ui-tlr-form">
                            <section class="ui-tlr-section">
                                <header class="ui-tlr-section-head">
                                    <i class="ti ti-user" aria-hidden="true"></i>
                                    <div>
                                        <strong>Identitas</strong>
                                        <span>Nama yang tampil di laporan &amp; kwitansi</span>
                                    </div>
                                </header>
                                <div class="ui-tlr-field">
                                    <label class="form-label" for="add_nama">Nama lengkap *</label>
                                    <input type="text" name="nama_lengkap" id="add_nama" class="form-control form-control-lg"
                                           required placeholder="Contoh: Siti Aminah" autocomplete="name" data-tlr="nama">
                                </div>
                            </section>

                            <section class="ui-tlr-section">
                                <header class="ui-tlr-section-head">
                                    <i class="ti ti-lock" aria-hidden="true"></i>
                                    <div>
                                        <strong>Login</strong>
                                        <span>Kredensial untuk masuk ke aplikasi</span>
                                    </div>
                                </header>
                                <div class="ui-tlr-field">
                                    <label class="form-label" for="add_username">Username *</label>
                                    <div class="ui-tlr-user-wrap">
                                        <span class="ui-tlr-user-prefix" aria-hidden="true"><i class="ti ti-at"></i></span>
                                        <input type="text" name="username" id="add_username" class="form-control ui-tlr-user-input"
                                               required placeholder="teller01" autocomplete="username" maxlength="50" data-tlr="username">
                                    </div>
                                    <p class="form-text mb-0">Huruf kecil otomatis · tanpa spasi</p>
                                </div>
                                <div class="ui-tlr-field">
                                    <label class="form-label" for="add_password">Password *</label>
                                    <div class="input-group ui-tlr-pass-group">
                                        <input type="password" name="password" id="add_password" class="form-control"
                                               required minlength="6" placeholder="Minimal 6 karakter" autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary" data-tlr-toggle-pass="#add_password" aria-label="Tampilkan password">
                                            <i class="ti ti-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                            </section>

                            <section class="ui-tlr-section">
                                <header class="ui-tlr-section-head">
                                    <i class="ti ti-toggle-left" aria-hidden="true"></i>
                                    <div>
                                        <strong>Status akun</strong>
                                        <span>Nonaktif = tidak bisa login</span>
                                    </div>
                                </header>
                                <div class="ui-tlr-status" role="radiogroup" aria-label="Status teller">
                                    <label class="ui-tlr-status-opt">
                                        <input type="radio" name="status" value="aktif" checked>
                                        <span><i class="ti ti-user-check" aria-hidden="true"></i>Aktif</span>
                                    </label>
                                    <label class="ui-tlr-status-opt">
                                        <input type="radio" name="status" value="nonaktif">
                                        <span><i class="ti ti-user-x" aria-hidden="true"></i>Nonaktif</span>
                                    </label>
                                </div>
                            </section>

                            <aside class="ui-tlr-preview" data-tlr-preview aria-live="polite">
                                <span class="ui-tlr-preview-label">Pratinjau</span>
                                <div class="ui-tlr-preview-person">
                                    <span class="ui-tlr-avatar is-lg" data-preview="initials">?</span>
                                    <div>
                                        <strong data-preview="nama">Nama teller</strong>
                                        <code data-preview="username">@username</code>
                                    </div>
                                </div>
                            </aside>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add" value="1" class="btn btn-primary">
                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade ui-tlr-modal is-edit" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" class="ui-tlr-form-root" data-tlr-form="edit" novalidate><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <div>
                            <p class="ui-tlr-modal-eyebrow mb-0">Ubah akun</p>
                            <h2 class="modal-title h5" id="editModalLabel">Edit teller</h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="ui-tlr-form">
                            <section class="ui-tlr-section">
                                <header class="ui-tlr-section-head">
                                    <i class="ti ti-user" aria-hidden="true"></i>
                                    <div>
                                        <strong>Identitas &amp; login</strong>
                                        <span>Password diubah lewat Reset password</span>
                                    </div>
                                </header>
                                <div class="ui-tlr-field">
                                    <label class="form-label" for="edit_nama">Nama lengkap *</label>
                                    <input type="text" name="nama_lengkap" id="edit_nama" class="form-control form-control-lg"
                                           required autocomplete="name" data-tlr="nama">
                                </div>
                                <div class="ui-tlr-field">
                                    <label class="form-label" for="edit_username">Username *</label>
                                    <div class="ui-tlr-user-wrap">
                                        <span class="ui-tlr-user-prefix" aria-hidden="true"><i class="ti ti-at"></i></span>
                                        <input type="text" name="username" id="edit_username" class="form-control ui-tlr-user-input"
                                               required maxlength="50" autocomplete="username" data-tlr="username">
                                    </div>
                                </div>
                            </section>

                            <section class="ui-tlr-section">
                                <header class="ui-tlr-section-head">
                                    <i class="ti ti-toggle-left" aria-hidden="true"></i>
                                    <div>
                                        <strong>Status akun</strong>
                                        <span>Nonaktifkan bila petugas tidak lagi bertugas</span>
                                    </div>
                                </header>
                                <div class="ui-tlr-status" role="radiogroup" aria-label="Status teller">
                                    <label class="ui-tlr-status-opt">
                                        <input type="radio" name="status" id="edit_status_aktif" value="aktif" checked>
                                        <span><i class="ti ti-user-check" aria-hidden="true"></i>Aktif</span>
                                    </label>
                                    <label class="ui-tlr-status-opt">
                                        <input type="radio" name="status" id="edit_status_nonaktif" value="nonaktif">
                                        <span><i class="ti ti-user-x" aria-hidden="true"></i>Nonaktif</span>
                                    </label>
                                </div>
                            </section>

                            <aside class="ui-tlr-preview" data-tlr-preview aria-live="polite">
                                <span class="ui-tlr-preview-label">Pratinjau</span>
                                <div class="ui-tlr-preview-person">
                                    <span class="ui-tlr-avatar is-lg" data-preview="initials">?</span>
                                    <div>
                                        <strong data-preview="nama">—</strong>
                                        <code data-preview="username">@—</code>
                                    </div>
                                </div>
                            </aside>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit" value="1" class="btn btn-warning">
                            <i class="ti ti-check me-1" aria-hidden="true"></i>Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade ui-tlr-modal is-reset" id="resetModal" tabindex="-1" aria-labelledby="resetModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="resetModalLabel">Reset password</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="reset_id">
                        <input type="hidden" name="reset_password" value="1">
                        <div class="ui-tlr-reset">
                            <i class="ti ti-key" aria-hidden="true"></i>
                            <div>
                                <p class="mb-1">Reset password untuk:</p>
                                <strong id="reset_nama"></strong>
                                <code class="d-block mt-1" id="reset_username"></code>
                                <p class="text-muted small mb-0 mt-2">Password baru: <strong>123456</strong></p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-info text-white">
                            <i class="ti ti-refresh me-1" aria-hidden="true"></i>Reset sekarang
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade ui-tlr-modal" id="hapusModal" tabindex="-1" aria-labelledby="hapusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="hapusModalLabel">Hapus teller</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="hapus_id">
                        <input type="hidden" name="hapus" value="1">
                        <p class="mb-1">Hapus akun teller:</p>
                        <strong id="hapus_nama"></strong>
                        <code class="d-block mt-1" id="hapus_username"></code>
                        <p class="text-muted small mb-0 mt-3">Akun yang dihapus tidak bisa login lagi. Teller aktif terakhir tidak dapat dihapus.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="ti ti-trash me-1" aria-hidden="true"></i>Hapus
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function () {
        ['addModal', 'editModal', 'resetModal', 'hapusModal'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el && el.parentElement !== document.body) document.body.appendChild(el);
        });

        function initials(name) {
            var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            if (!parts.length) return '?';
            var out = parts[0].charAt(0);
            if (parts.length > 1) out += parts[parts.length - 1].charAt(0);
            return out.toUpperCase();
        }

        function syncPreview(form) {
            var box = form.querySelector('[data-tlr-preview]');
            if (!box) return;
            var nama = (form.querySelector('[data-tlr="nama"]') || {}).value || '';
            var user = (form.querySelector('[data-tlr="username"]') || {}).value || '';
            var n = box.querySelector('[data-preview="nama"]');
            var u = box.querySelector('[data-preview="username"]');
            var i = box.querySelector('[data-preview="initials"]');
            if (n) n.textContent = nama.trim() || 'Nama teller';
            if (u) u.textContent = user.trim() ? ('@' + user.trim()) : '@username';
            if (i) i.textContent = initials(nama);
        }

        function bindForm(form) {
            if (!form || form.dataset.tlrReady) return;
            form.dataset.tlrReady = '1';

            var user = form.querySelector('[data-tlr="username"]');
            if (user) {
                user.addEventListener('input', function () {
                    var start = user.selectionStart;
                    user.value = user.value.toLowerCase().replace(/\s+/g, '').replace(/[^a-z0-9._-]/g, '');
                    try { user.setSelectionRange(start, start); } catch (e) {}
                    syncPreview(form);
                });
            }
            var nama = form.querySelector('[data-tlr="nama"]');
            if (nama) nama.addEventListener('input', function () { syncPreview(form); });

            form.querySelectorAll('[data-tlr-toggle-pass]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var input = document.querySelector(btn.getAttribute('data-tlr-toggle-pass'));
                    if (!input) return;
                    var show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    var icon = btn.querySelector('i');
                    if (icon) icon.className = show ? 'ti ti-eye-off' : 'ti ti-eye';
                });
            });

            syncPreview(form);
        }

        document.querySelectorAll('.ui-tlr-form-root').forEach(bindForm);

        var addModal = document.getElementById('addModal');
        if (addModal) {
            addModal.addEventListener('show.bs.modal', function () {
                var form = addModal.querySelector('.ui-tlr-form-root');
                if (!form) return;
                form.reset();
                var aktif = form.querySelector('input[name="status"][value="aktif"]');
                if (aktif) aktif.checked = true;
                syncPreview(form);
            });
        }

        var editModal = document.getElementById('editModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var form = editModal.querySelector('.ui-tlr-form-root');
                if (!button || !form) return;
                document.getElementById('edit_id').value = button.getAttribute('data-id') || '';
                document.getElementById('edit_username').value = button.getAttribute('data-username') || '';
                document.getElementById('edit_nama').value = button.getAttribute('data-nama') || '';
                var st = button.getAttribute('data-status') || 'aktif';
                var aktif = document.getElementById('edit_status_aktif');
                var non = document.getElementById('edit_status_nonaktif');
                if (aktif) aktif.checked = st === 'aktif';
                if (non) non.checked = st !== 'aktif';
                syncPreview(form);
            });
        }

        var resetModal = document.getElementById('resetModal');
        if (resetModal) {
            resetModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                if (!button) return;
                document.getElementById('reset_id').value = button.getAttribute('data-id') || '';
                document.getElementById('reset_nama').textContent = button.getAttribute('data-nama') || '';
                document.getElementById('reset_username').textContent = '@' + (button.getAttribute('data-username') || '');
            });
        }

        var hapusModal = document.getElementById('hapusModal');
        if (hapusModal) {
            hapusModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                if (!button) return;
                document.getElementById('hapus_id').value = button.getAttribute('data-id') || '';
                document.getElementById('hapus_nama').textContent = button.getAttribute('data-nama') || '';
                document.getElementById('hapus_username').textContent = '@' + (button.getAttribute('data-username') || '');
            });
        }
    })();
    </script>
</body>
</html>

<?php
require_once '../includes/auth.php';
checkAnggota();

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(403);
    $_SESSION['error'] = 'Profil anggota tidak dapat diubah dari sini. Hubungi Admin/Teller.';
    header('Location: profil.php');
    exit();
}

$stmt = $db->prepare(
    'SELECT a.*, u.username
     FROM anggota a
     JOIN users u ON a.user_id = u.id
     WHERE a.user_id = ?'
);
$stmt->execute([$_SESSION['user_id']]);
$anggota_data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$anggota_data) {
    $anggota_data = [
        'id' => 0,
        'kode_anggota' => '-',
        'nama_lengkap' => $_SESSION['nama_lengkap'] ?? 'Anggota',
        'status' => 'aktif',
        'tanggal_daftar' => null,
        'username' => '',
        'alamat' => '',
        'telepon' => '',
    ];
}
$foto_nasabah = $anggota_data['id'] ? fotoNasabahAnggota($db, $anggota_data['id']) : '';
$statusAktif = ($anggota_data['status'] ?? '') === 'aktif';
$inisial = getInitials($anggota_data['nama_lengkap'] ?? 'A');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-agtprof" aria-hidden="true"><i class="ti ti-user"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Akun anggota</p>
                        <h1 class="ui-dash-title">Profil Saya</h1>
                        <p class="ui-dash-meta mb-0">Data keanggotaan bersifat hanya-lihat · ubah lewat Admin/Teller</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="ganti_password.php" class="btn btn-sm ui-set-btn-ghost">
                            <i class="ti ti-lock me-1" aria-hidden="true"></i>Ganti password
                        </a>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <section class="ui-agt-profile-hero mb-3" aria-label="Identitas anggota">
                    <div class="ui-agt-profile-avatar" aria-hidden="true">
                        <?php if ($foto_nasabah): ?>
                            <img src="<?php echo htmlspecialchars(urlFoto($foto_nasabah)); ?>" alt="">
                        <?php else: ?>
                            <span><?php echo htmlspecialchars($inisial); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="ui-agt-profile-copy">
                        <p class="ui-agt-profile-eyebrow mb-0">Anggota koperasi</p>
                        <h2 class="ui-agt-profile-name"><?php echo htmlspecialchars($anggota_data['nama_lengkap']); ?></h2>
                        <div class="ui-agt-profile-chips">
                            <span class="ui-agt-profile-chip"><i class="ti ti-id" aria-hidden="true"></i><?php echo htmlspecialchars($anggota_data['kode_anggota']); ?></span>
                            <?php if (!empty($anggota_data['username'])): ?>
                            <span class="ui-agt-profile-chip"><i class="ti ti-at" aria-hidden="true"></i><?php echo htmlspecialchars($anggota_data['username']); ?></span>
                            <?php endif; ?>
                            <span class="ui-agt-profile-chip <?php echo $statusAktif ? 'is-ok' : 'is-off'; ?>">
                                <?php echo htmlspecialchars(ucfirst((string) $anggota_data['status'])); ?>
                            </span>
                        </div>
                        <?php if (!$foto_nasabah): ?>
                        <p class="ui-agt-profile-hint mb-0">Foto tampil setelah pinjaman Anda dicairkan.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <div class="card ui-agt-daftar mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-id-badge" aria-hidden="true"></i>Data keanggotaan</span>
                        <span class="ui-dash-sec-note">Hanya lihat</span>
                    </div>
                    <div class="card-body">
                        <dl class="ui-agt-dl">
                            <div><dt>Kode anggota</dt><dd><code><?php echo htmlspecialchars($anggota_data['kode_anggota']); ?></code></dd></div>
                            <div><dt>Nama lengkap</dt><dd><?php echo htmlspecialchars($anggota_data['nama_lengkap']); ?></dd></div>
                            <div><dt>Username</dt><dd><?php echo htmlspecialchars($anggota_data['username'] ?: '—'); ?></dd></div>
                            <div><dt>Status</dt><dd><?php echo htmlspecialchars(ucfirst((string) $anggota_data['status'])); ?></dd></div>
                            <div><dt>Tanggal daftar</dt><dd><?php echo !empty($anggota_data['tanggal_daftar']) ? htmlspecialchars(formatTanggalUi($anggota_data['tanggal_daftar'])) : '—'; ?></dd></div>
                            <div><dt>Telepon</dt><dd><?php echo htmlspecialchars($anggota_data['telepon'] ?? '—'); ?></dd></div>
                            <div class="is-full"><dt>Alamat</dt><dd><?php echo htmlspecialchars($anggota_data['alamat'] ?? '—'); ?></dd></div>
                        </dl>
                    </div>
                </div>

                <div class="ui-agt-note" role="note">
                    <i class="ti ti-shield-lock" aria-hidden="true"></i>
                    <div>
                        <strong>Perubahan data</strong>
                        <span>Untuk memperbarui data diri atau dokumen, hubungi Admin/Teller koperasi.</span>
                    </div>
                </div>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
</body>
</html>

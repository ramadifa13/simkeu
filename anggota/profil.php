<?php
require_once '../includes/auth.php';
checkAnggota();

// Profil anggota HANYA-LIHAT (revisi tahap 2, poin D). Anggota tidak bisa mengubah data apa pun
// dari sini; data diubah oleh Admin/Teller. Aksi ubah profil/upload KTP lewat POST ditolak.
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(403);
    $_SESSION['error'] = "Profil anggota tidak dapat diubah dari sini. Hubungi Admin/Teller.";
    header("Location: profil.php");
    exit();
}

$stmt = $db->prepare("SELECT a.*, u.username
                      FROM anggota a
                      JOIN users u ON a.user_id = u.id
                      WHERE a.user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$anggota_data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$anggota_data) {
    $anggota_data = [
        'id' => 0, 'kode_anggota' => '-', 'nama_lengkap' => $_SESSION['nama_lengkap'],
        'status' => 'aktif', 'tanggal_daftar' => null,
    ];
}
$foto_nasabah = $anggota_data['id'] ? fotoNasabahAnggota($db, $anggota_data['id']) : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .main-content { margin-left: 280px; margin-top: 10px; padding: 15px; }
        .page-header { padding-top: 5px; padding-bottom: 10px; margin-bottom: 15px; }
        .profile-card { border: none; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .foto-profil { width: 160px; height: 160px; object-fit: cover; border-radius: 50%; border: 4px solid #e9ecef; }
        .avatar-inisial { width: 160px; height: 160px; border-radius: 50%; display: inline-flex; align-items: center;
                          justify-content: center; font-size: 3.5rem; font-weight: 600; color: #fff;
                          background: linear-gradient(135deg, #2c3e50, #3498db); }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center page-header border-bottom">
                    <h1 class="h3"><i class="fas fa-user me-2"></i>Profil Saya</h1>
                </div>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row justify-content-center">
                    <div class="col-lg-6">
                        <div class="card profile-card">
                            <div class="card-body p-4 text-center">
                                <?php if ($foto_nasabah): ?>
                                    <img src="<?php echo htmlspecialchars(urlFoto($foto_nasabah)); ?>" class="foto-profil mb-3" alt="Foto nasabah">
                                <?php else: ?>
                                    <div class="avatar-inisial mb-3"><?php echo htmlspecialchars(getInitials($anggota_data['nama_lengkap'])); ?></div>
                                <?php endif; ?>
                                <h4 class="mb-1"><?php echo htmlspecialchars($anggota_data['nama_lengkap']); ?></h4>
                                <div class="mb-3">
                                    <span class="badge bg-<?php echo $anggota_data['status'] === 'aktif' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst($anggota_data['status']); ?>
                                    </span>
                                </div>

                                <table class="table table-sm table-borderless text-start mb-0">
                                    <tr>
                                        <td width="40%" class="text-muted">Kode Anggota</td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($anggota_data['kode_anggota']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Tanggal Daftar</td>
                                        <td class="fw-semibold"><?php echo $anggota_data['tanggal_daftar'] ? htmlspecialchars(formatDateIndonesian($anggota_data['tanggal_daftar'])) : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Status</td>
                                        <td class="fw-semibold"><?php echo ucfirst($anggota_data['status']); ?></td>
                                    </tr>
                                </table>
                                <?php if (!$foto_nasabah): ?>
                                    <p class="text-muted small mt-3 mb-0">Foto akan tampil setelah pinjaman Anda dicairkan.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-center mt-3">
                            <a href="ganti_password.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-key me-1"></i> Ganti Password
                            </a>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

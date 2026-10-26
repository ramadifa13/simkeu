<?php
require_once '../config/config.php';

// Ambil data koperasi dari database untuk logo
$koperasi = [];
$logo_path = '';

try {
    require_once '../config/database.php';
    if (!isset($db) || !$db) { $database = new Database(); $db = $database->getConnection(); }
    
    $koperasi_query = "SELECT * FROM koperasi WHERE id = 1";
    $koperasi_stmt = $db->prepare($koperasi_query);
    $koperasi_stmt->execute();
    $koperasi = $koperasi_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$koperasi) {
        $koperasi = [
            'nama' => APP_NAME,
            'logo' => null
        ];
    }
    
    // Tentukan path logo yang benar
    if (!empty($koperasi['logo'])) {
        $possible_paths = [
            '../admin/uploads/logo/' . $koperasi['logo'],
            'admin/uploads/logo/' . $koperasi['logo'],
            '../uploads/logo/' . $koperasi['logo'],
            'uploads/logo/' . $koperasi['logo'],
            '../assets/uploads/logo/' . $koperasi['logo'],
            'assets/uploads/logo/' . $koperasi['logo']
        ];
        
        foreach ($possible_paths as $path) {
            $full_path = dirname(__FILE__) . '/../' . ltrim(str_replace('../', '', $path), '/');
            if (file_exists($full_path)) {
                $logo_path = $path;
                break;
            }
        }
        
        if (empty($logo_path)) {
            $logo_path = '../admin/uploads/logo/' . $koperasi['logo'];
        }
    }
} catch (Exception $e) {
    error_log("Error mengambil data koperasi di sidebar: " . $e->getMessage());
    $koperasi = [
        'nama' => APP_NAME,
        'logo' => null
    ];
}

$current_role = $_SESSION['role'];
$current_page = basename($_SERVER['PHP_SELF']);

// Foto sidebar per level (fallback avatar inisial):
//  - anggota: foto pencairan sesuai pinjaman berjalan + status anggota
//  - teller : foto profil teller (poin F)
$sidebar_foto = '';
$sidebar_status_anggota = '';
if ($current_role === 'anggota') {
    $stmt_sb = $db->prepare("SELECT id, status FROM anggota WHERE user_id = ?");
    $stmt_sb->execute([$_SESSION['user_id']]);
    $row_sb = $stmt_sb->fetch(PDO::FETCH_ASSOC);
    if ($row_sb) {
        $sidebar_status_anggota = $row_sb['status'];
        $sidebar_foto = fotoNasabahAnggota($db, $row_sb['id']);
    }
} elseif ($current_role === 'teller' && kolomAda($db, 'users', 'foto_profil')) {
    $stmt_sb = $db->prepare("SELECT foto_profil FROM users WHERE id = ?");
    $stmt_sb->execute([$_SESSION['user_id']]);
    $sidebar_foto = (string) $stmt_sb->fetchColumn();
}
$sidebar_pakai_avatar = in_array($current_role, ['anggota', 'teller'], true);
?>
<!-- Sidebar -->
<div class="sidebar bg-dark" id="sidebarMenu" role="navigation" aria-label="Menu utama">
    <div class="p-3">
        <!-- Brand Logo -->
        <div class="text-center mb-4">
            <div class="logo-container mx-auto mb-2">
                <?php if ($sidebar_pakai_avatar && $sidebar_foto): ?>
                    <img src="<?php echo htmlspecialchars(urlFoto($sidebar_foto)); ?>" alt="Foto profil">
                <?php elseif ($sidebar_pakai_avatar): ?>
                    <span class="text-white fw-bold" style="font-size:1.5rem"><?php echo htmlspecialchars(getInitials($_SESSION['nama_lengkap'])); ?></span>
                <?php elseif (!empty($logo_path)): ?>
                    <img src="<?php echo $logo_path; ?>" 
                         alt="Logo <?php echo htmlspecialchars($koperasi['nama']); ?>"
                         onerror="this.style.display='none'; this.parentNode.innerHTML='<i class=\'fas fa-hand-holding-usd text-white fa-2x\'></i>';">
                <?php else: ?>
                    <i class="fas fa-hand-holding-usd text-white fa-2x"></i>
                <?php endif; ?>
            </div>
            <h6 class="text-white mb-0"><?php echo htmlspecialchars($koperasi['nama']); ?></h6>
            <?php if ($current_role === 'anggota' && $sidebar_status_anggota !== ''): ?>
                <span class="badge bg-<?php echo $sidebar_status_anggota === 'aktif' ? 'success' : 'secondary'; ?>">
                    <?php echo ucfirst($sidebar_status_anggota); ?>
                </span>
            <?php else: ?>
                <small class="text-white-50">Management System</small>
            <?php endif; ?>
        </div>

        <hr class="bg-light">

        <!-- MENU SEDERHANA - TANPA JAVASCRIPT RUMIT -->
        <ul class="nav nav-pills flex-column">
            <li class="nav-item mb-1">
                <a class="nav-link text-white <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" 
                   href="dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                </a>
            </li>
            
            <!-- Menu khusus untuk Teller -->
            <?php if ($current_role == 'teller'): ?>
            
            <!-- Menu Transaksi -->
            <li class="nav-item mb-1">
                <a class="nav-link text-white" 
                   data-bs-toggle="collapse" 
                   href="#menuTransaksi" 
                   role="button">
                    <i class="fas fa-exchange-alt me-2"></i> Transaksi
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['setoran.php', 'penarikan.php', 'pembelian.php']) ? 'show' : ''; ?>" id="menuTransaksi">
                    <ul class="nav flex-column ms-3 mt-1">
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'setoran.php' ? 'active text-white' : ''; ?>" 
                               href="setoran.php">
                                <i class="fas fa-plus-circle me-2"></i> Setoran
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'penarikan.php' ? 'active text-white' : ''; ?>" 
                               href="penarikan.php">
                                <i class="fas fa-minus-circle me-2"></i> Penarikan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'pembelian.php' ? 'active text-white' : ''; ?>" 
                               href="pembelian.php">
                                <i class="fas fa-shopping-cart me-2"></i> Pembelian
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Menu Peminjaman -->
            <li class="nav-item mb-1">
                <a class="nav-link text-white" 
                   data-bs-toggle="collapse" 
                   href="#menuPeminjaman" 
                   role="button">
                    <i class="fas fa-hand-holding-usd me-2"></i> Peminjaman
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['pengajuan.php', 'persetujuan.php', 'pencairan.php', 'angsuran.php', 'denda.php', 'pelunasan.php', 'layanan.php']) ? 'show' : ''; ?>" id="menuPeminjaman">
                    <ul class="nav flex-column ms-3 mt-1">
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'pengajuan.php' ? 'active text-white' : ''; ?>" 
                               href="pengajuan.php">
                                <i class="fas fa-file-alt me-2"></i> Pengajuan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'persetujuan.php' ? 'active text-white' : ''; ?>" 
                               href="persetujuan.php">
                                <i class="fas fa-check-circle me-2"></i> Persetujuan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'pencairan.php' ? 'active text-white' : ''; ?>" 
                               href="pencairan.php">
                                <i class="fas fa-money-bill-wave me-2"></i> Pencairan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'angsuran.php' ? 'active text-white' : ''; ?>" 
                               href="angsuran.php">
                                <i class="fas fa-calendar-check me-2"></i> Angsuran
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link sidebar-dev-link text-white-50 py-1 <?php echo ($current_page === 'layanan.php' && in_array($_GET['tab'] ?? 'macet',['macet','pengaturan'],true)) ? 'active text-white' : ''; ?>"
                               href="layanan.php?tab=macet">
                                <i class="fas fa-exclamation-circle"></i><span class="sidebar-dev-title">Macet &amp; Layanan</span>
                                <span class="badge bg-warning text-dark sidebar-dev-badge" title="Fitur masih dalam pengembangan">Dalam Pengembangan</span>
                            </a>
                        </li>
                        <li class="nav-item"><a class="nav-link sidebar-dev-link text-white-50 py-1 <?php echo ($current_page === 'denda.php' || ($current_page === 'layanan.php' && ($_GET['tab'] ?? 'macet') === 'denda')) ? 'active text-white' : ''; ?>" href="denda.php">
                                <i class="fas fa-exclamation-triangle"></i><span class="sidebar-dev-title">Denda</span>
                                <span class="badge bg-warning text-dark sidebar-dev-badge" title="Fitur masih dalam pengembangan">Dalam Pengembangan</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo ($current_page === 'pelunasan.php') ? 'active text-white' : ''; ?>"
                               href="pelunasan.php">
                                <i class="fas fa-flag-checkered me-2"></i> Pelunasan
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Menu Data Anggota -->
            <li class="nav-item mb-1">
                <a class="nav-link text-white" 
                   data-bs-toggle="collapse" 
                   href="#menuAnggota" 
                   role="button">
                    <i class="fas fa-users me-2"></i> Data Anggota
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse <?php echo $current_page === 'anggota.php' ? 'show' : ''; ?>" id="menuAnggota">
                    <ul class="nav flex-column ms-3 mt-1">
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'anggota.php' ? 'active text-white' : ''; ?>" 
                               href="anggota.php">
                                <i class="fas fa-list me-2"></i> Daftar
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Menu Laporan -->
            <li class="nav-item mb-1">
                <a class="nav-link text-white" 
                   data-bs-toggle="collapse" 
                   href="#menuLaporanTeller" 
                   role="button">
                    <i class="fas fa-chart-bar me-2"></i> Laporan
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['laporan_harian.php', 'laporan_simpanan.php', 'laporan_lunas.php', 'laporan_denda.php']) ? 'show' : ''; ?>" id="menuLaporanTeller">
                    <ul class="nav flex-column ms-3 mt-1">
                        <li class="nav-item"><a class="nav-link text-white-50 py-1 <?= $current_page==='laporan_lunas.php'?'active text-white':'' ?>" href="laporan_lunas.php"><i class="fas fa-check-circle me-2"></i>Laporan Lunas</a></li>
                        <li class="nav-item"><a class="nav-link text-white-50 py-1 <?= $current_page==='laporan_denda.php'?'active text-white':'' ?>" href="laporan_denda.php"><i class="fas fa-clock me-2"></i>Laporan Denda</a></li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'laporan_harian.php' ? 'active text-white' : ''; ?>" 
                               href="laporan_harian.php">
                                <i class="fas fa-calendar-day me-2"></i> Harian
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'laporan_simpanan.php' ? 'active text-white' : ''; ?>" 
                               href="laporan_simpanan.php">
                                <i class="fas fa-piggy-bank me-2"></i> Simpanan
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Menu Profil -->
            <li class="nav-item mb-1">
                <a class="nav-link text-white" 
                   data-bs-toggle="collapse" 
                   href="#menuProfilTeller" 
                   role="button">
                    <i class="fas fa-user-cog me-2"></i> Profil
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['ubah_password.php', 'profil.php']) ? 'show' : ''; ?>" id="menuProfilTeller">
                    <ul class="nav flex-column ms-3 mt-1">
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'profil.php' ? 'active text-white' : ''; ?>" 
                               href="profil.php">
                                <i class="fas fa-user me-2"></i> Profil
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'ubah_password.php' ? 'active text-white' : ''; ?>" 
                               href="ubah_password.php">
                                <i class="fas fa-key me-2"></i> Password
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Menu untuk Admin -->
            <?php elseif ($current_role == 'admin'): ?>
            
            <li class="nav-item mb-1">
                <a class="nav-link text-white <?php echo $current_page == 'anggota.php' ? 'active' : ''; ?>" 
                   href="anggota.php">
                    <i class="fas fa-users me-2"></i> Data Anggota
                </a>
            </li>
            
            <!-- Laporan Admin -->
            <li class="nav-item mb-1">
                <a class="nav-link text-white"
                   data-bs-toggle="collapse"
                   href="#menuLaporanAdmin"
                   role="button">
                    <i class="fas fa-chart-bar me-2"></i> Laporan
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['laporan_simpanan.php', 'laporan_transaksi.php', 'laporan_kas.php', 'laporan_pinjaman.php', 'laporan_angsuran.php', 'laporan_lunas.php', 'laporan_denda.php']) ? 'show' : ''; ?>" id="menuLaporanAdmin">
                    <ul class="nav flex-column ms-3 mt-1">
                        <li class="nav-item"><a class="nav-link text-white-50 py-1 <?= $current_page==='laporan_lunas.php'?'active text-white':'' ?>" href="laporan_lunas.php"><i class="fas fa-check-circle me-2"></i>Laporan Lunas</a></li>
                        <li class="nav-item"><a class="nav-link text-white-50 py-1 <?= $current_page==='laporan_denda.php'?'active text-white':'' ?>" href="laporan_denda.php"><i class="fas fa-clock me-2"></i>Laporan Denda</a></li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'laporan_simpanan.php' ? 'active text-white' : ''; ?>"
                               href="laporan_simpanan.php">
                                <i class="fas fa-piggy-bank me-2"></i> Simpanan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'laporan_pinjaman.php' ? 'active text-white' : ''; ?>"
                               href="laporan_pinjaman.php">
                                <i class="fas fa-hand-holding-usd me-2"></i> Pinjaman
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'laporan_angsuran.php' ? 'active text-white' : ''; ?>"
                               href="laporan_angsuran.php">
                                <i class="fas fa-calendar-check me-2"></i> Angsuran
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'laporan_transaksi.php' ? 'active text-white' : ''; ?>"
                               href="laporan_transaksi.php">
                                <i class="fas fa-exchange-alt me-2"></i> Transaksi
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'laporan_kas.php' ? 'active text-white' : ''; ?>"
                               href="laporan_kas.php">
                                <i class="fas fa-wallet me-2"></i> Kas
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Data Master -->
            <li class="nav-item mb-1">
                <a class="nav-link text-white" 
                   data-bs-toggle="collapse" 
                   href="#menuDataMaster" 
                   role="button">
                    <i class="fas fa-database me-2"></i> Data Master
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['jenis_simpanan.php', 'teller.php', 'jenis_pinjaman.php']) ? 'show' : ''; ?>" id="menuDataMaster">
                    <ul class="nav flex-column ms-3 mt-1">
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'jenis_simpanan.php' ? 'active text-white' : ''; ?>" 
                               href="jenis_simpanan.php">
                                <i class="fas fa-list me-2"></i> Jenis Simpanan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'jenis_pinjaman.php' ? 'active text-white' : ''; ?>" 
                               href="jenis_pinjaman.php">
                                <i class="fas fa-hand-holding-usd me-2"></i> Jenis Pinjaman
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'teller.php' ? 'active text-white' : ''; ?>" 
                               href="teller.php">
                                <i class="fas fa-user-tie me-2"></i> Data Teller
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- Transaksi Admin -->
            <li class="nav-item mb-1">
                <a class="nav-link text-white" 
                   data-bs-toggle="collapse" 
                   href="#menuTransaksiAdmin" 
                   role="button">
                    <i class="fas fa-exchange-alt me-2"></i> Transaksi
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['setoran.php', 'penarikan.php', 'koreksi.php', 'pembelian.php']) ? 'show' : ''; ?>" id="menuTransaksiAdmin">
                    <ul class="nav flex-column ms-3 mt-1">
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'setoran.php' ? 'active text-white' : ''; ?>" 
                               href="setoran.php">
                                <i class="fas fa-plus-circle me-2"></i> Setoran
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'penarikan.php' ? 'active text-white' : ''; ?>" 
                               href="penarikan.php">
                                <i class="fas fa-minus-circle me-2"></i> Penarikan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'pembelian.php' ? 'active text-white' : ''; ?>" 
                               href="pembelian.php">
                                <i class="fas fa-shopping-cart me-2"></i> Pembelian
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'koreksi.php' ? 'active text-white' : ''; ?>" 
                               href="koreksi.php">
                                <i class="fas fa-edit me-2"></i> Koreksi
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Pinjaman Admin -->
            <li class="nav-item mb-1">
                <a class="nav-link text-white" 
                   data-bs-toggle="collapse" 
                   href="#menuPinjamanAdmin" 
                   role="button">
                    <i class="fas fa-hand-holding-usd me-2"></i> Pinjaman
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['pengajuan.php', 'persetujuan.php', 'pencairan.php', 'angsuran.php', 'denda.php', 'pelunasan.php', 'layanan.php']) ? 'show' : ''; ?>" id="menuPinjamanAdmin">
                    <ul class="nav flex-column ms-3 mt-1">
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'pengajuan.php' ? 'active text-white' : ''; ?>" 
                               href="pengajuan.php">
                                <i class="fas fa-file-alt me-2"></i> Pengajuan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'persetujuan.php' ? 'active text-white' : ''; ?>" 
                               href="persetujuan.php">
                                <i class="fas fa-check-circle me-2"></i> Persetujuan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'pencairan.php' ? 'active text-white' : ''; ?>" 
                               href="pencairan.php">
                                <i class="fas fa-money-bill-wave me-2"></i> Pencairan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'angsuran.php' ? 'active text-white' : ''; ?>" 
                               href="angsuran.php">
                                <i class="fas fa-calendar-check me-2"></i> Angsuran
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link sidebar-dev-link text-white-50 py-1 <?php echo ($current_page === 'layanan.php' && in_array($_GET['tab'] ?? 'macet',['macet','pengaturan'],true)) ? 'active text-white' : ''; ?>"
                               href="layanan.php?tab=macet">
                                <i class="fas fa-exclamation-circle"></i><span class="sidebar-dev-title">Macet &amp; Layanan</span>
                                <span class="badge bg-warning text-dark sidebar-dev-badge" title="Fitur masih dalam pengembangan">Dalam Pengembangan</span>
                            </a>
                        </li>
                        <li class="nav-item"><a class="nav-link sidebar-dev-link text-white-50 py-1 <?php echo ($current_page === 'denda.php' || ($current_page === 'layanan.php' && ($_GET['tab'] ?? 'macet') === 'denda')) ? 'active text-white' : ''; ?>" href="denda.php">
                                <i class="fas fa-exclamation-triangle"></i><span class="sidebar-dev-title">Denda</span>
                                <span class="badge bg-warning text-dark sidebar-dev-badge" title="Fitur masih dalam pengembangan">Dalam Pengembangan</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo ($current_page === 'pelunasan.php') ? 'active text-white' : ''; ?>"
                               href="pelunasan.php">
                                <i class="fas fa-flag-checkered me-2"></i> Pelunasan
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- SHU -->
            <li class="nav-item mb-1">
                <a class="nav-link text-white" 
                   data-bs-toggle="collapse" 
                   href="#menuSHU" 
                   role="button">
                    <i class="fas fa-calculator me-2"></i> SHU
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['shu.php', 'shu_anggota.php', 'shu_laporan.php']) ? 'show' : ''; ?>" id="menuSHU">
                    <ul class="nav flex-column ms-3 mt-1">
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'shu.php' ? 'active text-white' : ''; ?>" 
                               href="shu.php">
                                <i class="fas fa-chart-line me-2"></i> Dashboard SHU
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'shu_anggota.php' ? 'active text-white' : ''; ?>" 
                               href="shu_anggota.php">
                                <i class="fas fa-money-bill-wave me-2"></i> Pembayaran SHU
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'shu_laporan.php' ? 'active text-white' : ''; ?>" 
                               href="shu_laporan.php">
                                <i class="fas fa-file-export me-2"></i> Laporan SHU
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- Pengaturan -->
            <li class="nav-item mb-1">
                <a class="nav-link text-white" 
                   data-bs-toggle="collapse" 
                   href="#menuPengaturan" 
                   role="button">
                    <i class="fas fa-cog me-2"></i> Pengaturan
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse <?php echo in_array($current_page, ['pengaturan.php', 'backup.php', 'pengaturan_pinjaman.php', 'import_migrasi.php']) ? 'show' : ''; ?>" id="menuPengaturan">
                    <ul class="nav flex-column ms-3 mt-1">
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'pengaturan.php' ? 'active text-white' : ''; ?>" 
                               href="pengaturan.php">
                                <i class="fas fa-building me-2"></i> Profil Koperasi
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'pengaturan_pinjaman.php' ? 'active text-white' : ''; ?>" 
                               href="pengaturan_pinjaman.php">
                                <i class="fas fa-percentage me-2"></i> Pinjaman
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'backup.php' ? 'active text-white' : ''; ?>" 
                               href="backup.php">
                                <i class="fas fa-database me-2"></i> Backup
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white-50 py-1 <?php echo $current_page == 'import_migrasi.php' ? 'active text-white' : ''; ?>"
                               href="import_migrasi.php">
                                <i class="fas fa-file-import me-2"></i> Import Migrasi
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Menu untuk Anggota -->
            <?php elseif ($current_role == 'anggota'): ?>
            <li class="nav-item mb-1">
                <a class="nav-link text-white <?php echo $current_page == 'simpanan.php' ? 'active' : ''; ?>" 
                   href="simpanan.php">
                    <i class="fas fa-piggy-bank me-2"></i> Simpanan Saya
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link text-white <?php echo $current_page == 'pinjaman.php' ? 'active' : ''; ?>" 
                   href="pinjaman.php">
                    <i class="fas fa-hand-holding-usd me-2"></i> Pinjaman Saya
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link text-white <?php echo $current_page == 'waserda.php' ? 'active' : ''; ?>" 
                   href="waserda.php">
                    <i class="fas fa-store me-2"></i> Waserda Saya
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link text-white <?php echo $current_page == 'shu_saya.php' ? 'active' : ''; ?>" 
                   href="shu_saya.php">
                    <i class="fas fa-calculator me-2"></i> SHU Saya
                </a>
            </li>
            <li class="nav-item mb-1">
                <a class="nav-link text-white <?php echo $current_page == 'profil.php' ? 'active' : ''; ?>" 
                   href="profil.php">
                    <i class="fas fa-user me-2"></i> Profil Saya
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <hr class="bg-light mt-3">

        <!-- Logout -->
        <a class="btn btn-danger btn-sm w-100" href="../logout.php" 
           onclick="return confirm('Apakah Anda yakin ingin logout?')">
            <i class="fas fa-sign-out-alt me-2"></i> Logout
        </a>
        
        <div class="text-center mt-2">
            <small class="text-white-50">v<?php echo APP_VERSION; ?></small>
            <br>
            <small class="text-white-50">
                <i class="fas fa-user me-1"></i>
                <?php echo ucfirst($current_role); ?>
            </small>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Notifikasi alur pinjaman: polling ringan tiap 2 detik, berhenti saat tab tidak aktif.
    let notifTimer = null;
    let notifBusy = false;
    let notifGagal = 0;
    const notifUrl = '../api/notifikasi_pinjaman.php';

    function jadwalkanNotif(ms) {
        clearTimeout(notifTimer);
        if (!document.hidden) notifTimer = setTimeout(ambilNotifPinjaman, ms);
    }

    function tampilkanNotif(data) {
        ['pengajuan', 'persetujuan', 'pencairan', 'tunda_cair', 'pembatalan', 'hapus_denda', 'pelunasan_dini', 'jaminan_kembali', 'prioritas', 'laporan_macet', 'pembayaran_denda', 'pelunasan_disetujui', 'jaminan_disetujui', 'tc_ditolak', 'jatuh_tempo', 'terlambat', 'denda_tertunggak', 'anggota_pengajuan', 'anggota_pencairan', 'anggota_batal_cair', 'anggota_jatuh_tempo', 'anggota_terlambat', 'anggota_denda', 'anggota_jaminan', 'layanan', 'total'].forEach(function(key) {
            document.querySelectorAll('[data-notif-pinjaman="' + key + '"]').forEach(function(el) {
                const nilai = Number(data[key] || 0);
                const lama = Number(el.textContent || 0);
                el.textContent = nilai;
                el.style.display = nilai > 0 ? '' : 'none';
                if(key==='total') document.querySelector('.ui-notifications summary')?.setAttribute('aria-label', 'Buka ' + nilai + ' notifikasi');
                if (nilai !== lama) {
                    el.classList.remove('notif-pulse');
                    void el.offsetWidth;
                    el.classList.add('notif-pulse');
                }
            });
        });
        document.querySelectorAll('[data-notif-row]').forEach(function(row) {
            row.style.display = Number(data[row.dataset.notifRow] || 0) > 0 ? '' : 'none';
        });
        const total = Number(data.total || 0);
        document.querySelector('.ui-notifications')?.classList.toggle('has-notifications', total > 0);
        document.querySelectorAll('[data-notif-summary]').forEach(function(el) {
            el.textContent = total + (el.dataset.notifRole === 'anggota' ? ' informasi baru' : ' perlu ditindaklanjuti');
        });
        document.querySelectorAll('.ui-notification-empty').forEach(function(el) {
            el.hidden = total > 0;
        });
    }

    async function ambilNotifPinjaman() {
        if (notifBusy || document.hidden) return;
        notifBusy = true;
        try {
            const response = await fetch(notifUrl, {cache: 'no-store', credentials: 'same-origin'});
            if (response.status === 401) { clearTimeout(notifTimer); return; }
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const data = await response.json();
            if (data.ok) tampilkanNotif(data);
            notifGagal = 0;
        } catch (error) {
            notifGagal++;
        } finally {
            notifBusy = false;
            // Normal 2 detik; bila server bermasalah perlambat agar request tidak membebani hosting.
            jadwalkanNotif(notifGagal ? Math.min(30000, 5000 * notifGagal) : 2000);
        }
    }

    document.addEventListener('visibilitychange', function() {
        clearTimeout(notifTimer);
        if (!document.hidden) ambilNotifPinjaman();
    });
    ambilNotifPinjaman();
});
</script>

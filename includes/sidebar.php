<?php
require_once '../config/config.php';
require_once __DIR__ . '/menu.php';

$koperasi = [];
$logo_path = '';

try {
    require_once '../config/database.php';
    if (!isset($db) || !$db) {
        $database = new Database();
        $db = $database->getConnection();
    }

    $koperasi_stmt = $db->prepare('SELECT * FROM koperasi WHERE id = 1');
    $koperasi_stmt->execute();
    $koperasi = $koperasi_stmt->fetch(PDO::FETCH_ASSOC) ?: ['nama' => APP_NAME, 'logo' => null];

    if (!empty($koperasi['logo'])) {
        $possible_paths = [
            '../admin/uploads/logo/' . $koperasi['logo'],
            'admin/uploads/logo/' . $koperasi['logo'],
            '../uploads/logo/' . $koperasi['logo'],
            'uploads/logo/' . $koperasi['logo'],
            '../assets/uploads/logo/' . $koperasi['logo'],
            'assets/uploads/logo/' . $koperasi['logo'],
        ];
        foreach ($possible_paths as $path) {
            $full_path = dirname(__FILE__) . '/../' . ltrim(str_replace('../', '', $path), '/');
            if (file_exists($full_path)) {
                $logo_path = $path;
                break;
            }
        }
        if ($logo_path === '') {
            $logo_path = '../admin/uploads/logo/' . $koperasi['logo'];
        }
    }
} catch (Exception $e) {
    error_log('Error mengambil data koperasi di sidebar: ' . $e->getMessage());
    $koperasi = ['nama' => APP_NAME, 'logo' => null];
}

$current_role = $_SESSION['role'] ?? '';
$current_page = basename($_SERVER['PHP_SELF']);
$menu_items = simkeu_menu_for_role($current_role);

$sidebar_foto = '';
$sidebar_status_anggota = '';
if ($current_role === 'anggota') {
    $stmt_sb = $db->prepare('SELECT id, status FROM anggota WHERE user_id = ?');
    $stmt_sb->execute([$_SESSION['user_id']]);
    $row_sb = $stmt_sb->fetch(PDO::FETCH_ASSOC);
    if ($row_sb) {
        $sidebar_status_anggota = $row_sb['status'];
        $sidebar_foto = fotoNasabahAnggota($db, $row_sb['id']);
    }
} elseif ($current_role === 'teller' && function_exists('kolomAda') && kolomAda($db, 'users', 'foto_profil')) {
    $stmt_sb = $db->prepare('SELECT foto_profil FROM users WHERE id = ?');
    $stmt_sb->execute([$_SESSION['user_id']]);
    $sidebar_foto = (string)$stmt_sb->fetchColumn();
}
$sidebar_pakai_avatar = in_array($current_role, ['anggota', 'teller'], true);

$is_active_pages = static function (array $pages) use ($current_page): bool {
    return in_array($current_page, $pages, true);
};
?>
<div class="sidebar bg-dark" id="sidebarMenu" role="navigation" aria-label="Menu utama">
    <div class="p-3">
        <div class="text-center mb-4">
            <div class="logo-container mx-auto mb-2">
                <?php if ($sidebar_pakai_avatar && $sidebar_foto): ?>
                    <img src="<?php echo htmlspecialchars(urlFoto($sidebar_foto)); ?>" alt="Foto profil">
                <?php elseif ($sidebar_pakai_avatar): ?>
                    <span class="text-white fw-bold" style="font-size:1.5rem"><?php echo htmlspecialchars(getInitials($_SESSION['nama_lengkap'] ?? 'U')); ?></span>
                <?php elseif (!empty($logo_path)): ?>
                    <img src="<?php echo htmlspecialchars($logo_path); ?>"
                         alt="Logo <?php echo htmlspecialchars($koperasi['nama'] ?? APP_NAME); ?>"
                         onerror="this.style.display='none'; this.parentNode.innerHTML='<i class=\'fas fa-hand-holding-usd text-white fa-2x\'></i>';">
                <?php else: ?>
                    <i class="fas fa-hand-holding-usd text-white fa-2x"></i>
                <?php endif; ?>
            </div>
            <h6 class="text-white mb-0"><?php echo htmlspecialchars($koperasi['nama'] ?? APP_NAME); ?></h6>
            <?php if ($current_role === 'anggota' && $sidebar_status_anggota !== ''): ?>
                <span class="badge bg-<?php echo $sidebar_status_anggota === 'aktif' ? 'success' : 'secondary'; ?>">
                    <?php echo htmlspecialchars(ucfirst($sidebar_status_anggota)); ?>
                </span>
            <?php else: ?>
                <small class="text-white-50">Management System</small>
            <?php endif; ?>
        </div>

        <hr class="bg-light">

        <ul class="nav nav-pills flex-column sidebar-nav">
            <?php foreach ($menu_items as $idx => $item):
                $pages = $item['pages'] ?? [];
                $children = $item['children'] ?? [];
                $active = $is_active_pages($pages);
                $itemIcon = htmlspecialchars($item['icon'] ?? 'ti ti-circle', ENT_QUOTES, 'UTF-8');
                if ($children):
                    $collapseId = 'menuNav' . $idx;
                    ?>
            <li class="nav-item mb-1">
                <a class="nav-link sidebar-link text-white<?= $active ? ' is-open' : '' ?>" data-bs-toggle="collapse" href="#<?= $collapseId ?>" role="button" aria-expanded="<?= $active ? 'true' : 'false' ?>">
                    <i class="<?= $itemIcon ?> sidebar-link-icon" aria-hidden="true"></i>
                    <span class="sidebar-link-text"><?= htmlspecialchars($item['title']) ?></span>
                    <i class="ti ti-chevron-down sidebar-link-caret" aria-hidden="true"></i>
                </a>
                <div class="collapse<?= $active ? ' show' : '' ?>" id="<?= $collapseId ?>">
                    <ul class="nav flex-column sidebar-subnav">
                        <?php foreach ($children as $child):
                            $cActive = $is_active_pages($child['pages'] ?? []);
                            $badge = $child['badge'] ?? '';
                            $childIcon = htmlspecialchars($child['icon'] ?? 'ti ti-point', ENT_QUOTES, 'UTF-8');
                            ?>
                        <li class="nav-item">
                            <a class="nav-link sidebar-link sidebar-sublink text-white-50<?= $cActive ? ' active text-white' : '' ?><?= $badge ? ' sidebar-dev-link' : '' ?>"
                               href="<?= htmlspecialchars($child['href']) ?>">
                                <i class="<?= $childIcon ?> sidebar-link-icon" aria-hidden="true"></i>
                                <span class="sidebar-link-text"><?= htmlspecialchars($child['title']) ?></span>
                                <?php if ($badge): ?>
                                    <span class="badge bg-warning text-dark sidebar-dev-badge" title="Fitur masih dalam pengembangan"><?= htmlspecialchars($badge) ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </li>
                <?php else: ?>
            <li class="nav-item mb-1">
                <a class="nav-link sidebar-link text-white<?= $active ? ' active' : '' ?>" href="<?= htmlspecialchars($item['href'] ?? '#') ?>">
                    <i class="<?= $itemIcon ?> sidebar-link-icon" aria-hidden="true"></i>
                    <span class="sidebar-link-text"><?= htmlspecialchars($item['title']) ?></span>
                </a>
            </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>

        <hr class="bg-light mt-3">

        <a class="btn btn-danger btn-sm w-100" href="../logout.php"
           onclick="return confirm('Apakah Anda yakin ingin logout?')">
            <i class="ti ti-logout me-2" aria-hidden="true"></i> Logout
        </a>

        <div class="text-center mt-2">
            <small class="text-white-50">v<?php echo APP_VERSION; ?></small>
            <br>
            <small class="text-white-50">
                <i class="fas fa-user me-1"></i>
                <?php echo htmlspecialchars(ucfirst($current_role)); ?>
            </small>
        </div>
    </div>
</div>

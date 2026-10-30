<?php
require_once '../config/config.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/brand.php';

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
        $logo_path = urlLogoKoperasi($koperasi['logo'], '../');
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
$is_active_pages = static function (array $pages) use ($current_page): bool {
    return in_array($current_page, $pages, true);
};

$brandSub = 'Management System';
if ($current_role === 'teller') {
    $brandSub = 'Portal Teller';
} elseif ($current_role === 'anggota') {
    $brandSub = $sidebar_status_anggota !== ''
        ? ('Anggota · ' . ucfirst($sidebar_status_anggota))
        : 'Portal Anggota';
} elseif ($current_role === 'admin') {
    $brandSub = 'Portal Admin';
}
?>
<aside class="navbar navbar-vertical navbar-expand-lg d-print-none simkeu-sidebar" data-bs-theme="dark" role="navigation" aria-label="Menu utama">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Buka navigasi">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="navbar-brand navbar-brand-autodark sk-brand">
            <a href="dashboard.php" class="d-flex align-items-center gap-2 text-decoration-none">
                <span class="simkeu-logo">
                    <?php if (!empty($logo_path)): ?>
                        <img src="<?= htmlspecialchars($logo_path) ?>" alt="Logo <?= htmlspecialchars($koperasi['nama'] ?? APP_NAME) ?>" onerror="this.style.display='none'; this.parentNode.innerHTML='<i class=\'ti ti-building-bank\'></i>';">
                    <?php else: ?>
                        <i class="ti ti-building-bank"></i>
                    <?php endif; ?>
                </span>
                <span class="d-flex flex-column lh-sm text-start">
                    <span class="sk-brand-name text-truncate"><?= htmlspecialchars($koperasi['nama'] ?? APP_NAME) ?></span>
                    <span class="sk-brand-sub"><?= htmlspecialchars($brandSub) ?></span>
                </span>
            </a>
        </div>
        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-3">
                <?php foreach ($menu_items as $idx => $item):
                    $pages = $item['pages'] ?? [];
                    $children = $item['children'] ?? [];
                    $active = $is_active_pages($pages);
                    $itemIcon = htmlspecialchars($item['icon'] ?? 'ti ti-circle', ENT_QUOTES, 'UTF-8');
                    if ($children): ?>
                <li class="nav-item dropdown<?= $active ? ' active' : '' ?>">
                    <a class="nav-link dropdown-toggle<?= $active ? ' show' : '' ?>" href="#menuNav<?= $idx ?>" data-bs-toggle="dropdown" data-bs-auto-close="false" role="button" aria-expanded="<?= $active ? 'true' : 'false' ?>">
                        <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="<?= $itemIcon ?>" aria-hidden="true"></i></span>
                        <span class="nav-link-title"><?= htmlspecialchars($item['title']) ?></span>
                    </a>
                    <div class="dropdown-menu<?= $active ? ' show' : '' ?>" id="menuNav<?= $idx ?>">
                        <?php foreach ($children as $child):
                            $cActive = $is_active_pages($child['pages'] ?? []);
                            $badge = $child['badge'] ?? '';
                            $childIcon = htmlspecialchars($child['icon'] ?? 'ti ti-point', ENT_QUOTES, 'UTF-8'); ?>
                        <a class="dropdown-item<?= $cActive ? ' active' : '' ?>" href="<?= htmlspecialchars($child['href']) ?>">
                            <i class="<?= $childIcon ?> me-2 opacity-75" aria-hidden="true"></i><?= htmlspecialchars($child['title']) ?>
                            <?php if ($badge): ?><span class="badge bg-yellow-lt ms-auto" title="Fitur masih dalam pengembangan"><?= htmlspecialchars($badge) ?></span><?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </li>
                    <?php else: ?>
                <li class="nav-item<?= $active ? ' active' : '' ?>">
                    <a class="nav-link" href="<?= htmlspecialchars($item['href'] ?? '#') ?>">
                        <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="<?= $itemIcon ?>" aria-hidden="true"></i></span>
                        <span class="nav-link-title"><?= htmlspecialchars($item['title']) ?></span>
                    </a>
                </li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="nav-item mt-2 d-lg-none">
                    <a class="nav-link text-danger" href="../logout.php" onclick="return confirm('Apakah Anda yakin ingin logout?')">
                        <span class="nav-link-icon"><i class="ti ti-logout"></i></span><span class="nav-link-title">Logout</span>
                    </a>
                </li>
            </ul>
            <div class="sk-side-user d-none d-lg-flex"><span class="sk-side-avatar"><?= htmlspecialchars(getInitials($_SESSION['nama_lengkap'] ?? 'U')) ?></span><span class="sk-side-meta"><strong class="text-truncate"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Pengguna') ?></strong><small><?= htmlspecialchars(ucfirst($current_role)) ?> &middot; v<?= APP_VERSION ?></small></span></div>
        </div>
    </div>
</aside>

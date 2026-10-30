<?php
require_once '../includes/auth.php';
checkAnggota();

$anggota_id = $_SESSION['user_id'];
$query_anggota = 'SELECT a.* FROM anggota a WHERE a.user_id = :user_id';
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->bindParam(':user_id', $anggota_id);
$stmt_anggota->execute();
if ($stmt_anggota->rowCount() == 0) {
    die('Data anggota tidak ditemukan');
}
$anggota_data = $stmt_anggota->fetch(PDO::FETCH_ASSOC);

$query_simpanan = "SELECT s.*, js.nama_jenis, js.deskripsi, js.kode_jenis, js.minimum, js.maksimum, js.bagi_hasil
                   FROM simpanan s
                   JOIN jenis_simpanan js ON s.jenis_simpanan_id = js.id
                   WHERE s.anggota_id = :anggota_id
                     AND js.kode_jenis NOT IN ('ANG','BONUS','SHU')
                     AND s.saldo > 0
                   ORDER BY js.id";
$stmt_simpanan = $db->prepare($query_simpanan);
$stmt_simpanan->bindParam(':anggota_id', $anggota_data['id']);
$stmt_simpanan->execute();
$simpanan_list = $stmt_simpanan->fetchAll(PDO::FETCH_ASSOC);

$query_total = "SELECT COALESCE(SUM(s.saldo), 0) AS total_simpanan
                FROM simpanan s
                JOIN jenis_simpanan js ON s.jenis_simpanan_id = js.id
                WHERE s.anggota_id = :anggota_id AND js.kode_jenis NOT IN ('ANG','BONUS','SHU')";
$stmt_total = $db->prepare($query_total);
$stmt_total->bindParam(':anggota_id', $anggota_data['id']);
$stmt_total->execute();
$total_simpanan = $stmt_total->fetch(PDO::FETCH_ASSOC)['total_simpanan'];

$badgeJenis = static function ($kode) {
    $map = ['POKOK' => 'is-pokok', 'WAJIB' => 'is-wajib', 'SUKARELA' => 'is-sukarela', 'DEPOSITO' => 'is-deposito', 'HAJI' => 'is-haji', 'UMROH' => 'is-umroh'];
    return $map[$kode] ?? 'is-other';
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simpanan Saya - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-agtsimp" aria-hidden="true"><i class="ti ti-pig-money"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Akun anggota</p>
                        <h1 class="ui-dash-title">Simpanan Saya</h1>
                        <p class="ui-dash-meta mb-0">Saldo simpanan real per jenis · tanpa jurnal ANG / BONUS / SHU</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="riwayat_simpanan.php" class="btn btn-sm ui-set-btn-ghost">
                            <i class="ti ti-history me-1" aria-hidden="true"></i>Riwayat
                        </a>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-agt-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total simpanan', 'value' => formatRupiah($total_simpanan), 'tone' => 'success', 'icon' => 'ti ti-pig-money', 'hint' => 'Semua jenis aktif'],
                    ['label' => 'Jenis aktif', 'value' => count($simpanan_list), 'tone' => 'primary', 'icon' => 'ti ti-stack-2', 'hint' => 'Saldo > 0'],
                    ['label' => 'Anggota', 'value' => htmlspecialchars($anggota_data['kode_anggota']), 'tone' => 'info', 'icon' => 'ti ti-id', 'hint' => 'Kode Anda'],
                ]); ?>
                </div>

                <?php if (!$simpanan_list): ?>
                <div class="card">
                    <div class="card-body">
                        <div class="ui-agt-empty">
                            <i class="ti ti-pig-money" aria-hidden="true"></i>
                            <strong>Belum ada simpanan</strong>
                            <p>Anda belum memiliki simpanan aktif. Lakukan setoran melalui teller koperasi.</p>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="ui-agt-simpanan-grid">
                    <?php foreach ($simpanan_list as $s): ?>
                    <article class="ui-agt-simpanan-card">
                        <div class="ui-agt-simpanan-head">
                            <span class="ui-lsimp-kode <?php echo htmlspecialchars($badgeJenis($s['kode_jenis'])); ?>"><?php echo htmlspecialchars($s['kode_jenis']); ?></span>
                            <strong class="ui-agt-name"><?php echo htmlspecialchars($s['nama_jenis']); ?></strong>
                        </div>
                        <div class="ui-agt-simpanan-saldo ui-agt-money is-in"><?php echo formatRupiah($s['saldo']); ?></div>
                        <?php if (!empty($s['deskripsi'])): ?>
                        <p class="ui-agt-simpanan-desc"><?php echo htmlspecialchars($s['deskripsi']); ?></p>
                        <?php endif; ?>
                        <div class="ui-agt-simpanan-meta">
                            <div><span>Minimal</span><strong class="ui-agt-text"><?php echo formatRupiah($s['minimum']); ?></strong></div>
                            <div><span>Maksimal</span><strong class="ui-agt-text"><?php echo formatRupiah($s['maksimum']); ?></strong></div>
                            <div><span>Bagi hasil</span><strong class="ui-agt-text"><?php echo htmlspecialchars((string) $s['bagi_hasil']); ?>%</strong></div>
                        </div>
                        <small class="ui-agt-simpanan-update"><i class="ti ti-clock" aria-hidden="true"></i>Update <?php echo htmlspecialchars(formatTanggalUi($s['updated_at'], true)); ?></small>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
</body>
</html>

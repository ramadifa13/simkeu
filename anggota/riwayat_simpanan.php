<?php
require_once '../includes/auth.php';
checkAnggota();

$anggota_id = $_SESSION['user_id'];
$query_anggota = 'SELECT a.* FROM anggota a WHERE a.user_id = :user_id';
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->bindParam(':user_id', $anggota_id);
$stmt_anggota->execute();
$anggota_data = $stmt_anggota->fetch(PDO::FETCH_ASSOC);
if (!$anggota_data) {
    die('Data anggota tidak ditemukan');
}

$query_tanggal = "SELECT MIN(DATE(tanggal_transaksi)) AS earliest, MAX(DATE(tanggal_transaksi)) AS latest
                  FROM transaksi t
                  JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id
                  WHERE t.anggota_id = :anggota_id AND js.kode_jenis NOT IN ('ANG','BONUS','SHU')";
$stmt_tanggal = $db->prepare($query_tanggal);
$stmt_tanggal->bindParam(':anggota_id', $anggota_data['id']);
$stmt_tanggal->execute();
$tanggal_range = $stmt_tanggal->fetch(PDO::FETCH_ASSOC);
$earliest_date = $tanggal_range['earliest'] ?? null;
$latest_date = $tanggal_range['latest'] ?? null;

$query_saldo_saat_ini = "SELECT js.id, js.kode_jenis, js.nama_jenis, COALESCE(s.saldo, 0) AS saldo
                         FROM jenis_simpanan js
                         LEFT JOIN simpanan s ON s.jenis_simpanan_id = js.id AND s.anggota_id = :anggota_id
                         WHERE js.status = 'aktif' AND js.kode_jenis NOT IN ('ANG','BONUS','SHU')
                         ORDER BY js.id";
$stmt_saldo = $db->prepare($query_saldo_saat_ini);
$stmt_saldo->bindParam(':anggota_id', $anggota_data['id']);
$stmt_saldo->execute();
$saldo_saat_ini = $stmt_saldo->fetchAll(PDO::FETCH_ASSOC);

$total_saldo = 0;
$jenis_dengan_saldo = [];
foreach ($saldo_saat_ini as $s) {
    if ((float) $s['saldo'] > 0) {
        $total_saldo += (float) $s['saldo'];
        $jenis_dengan_saldo[] = $s;
    }
}

$query = "SELECT t.*, js.nama_jenis, js.kode_jenis
          FROM transaksi t
          JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id
          WHERE t.anggota_id = :anggota_id AND js.kode_jenis NOT IN ('ANG','BONUS','SHU')
          ORDER BY t.tanggal_transaksi DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':anggota_id', $anggota_data['id']);
$stmt->execute();
$riwayat = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_setoran = 0;
$total_penarikan = 0;
foreach ($riwayat as $t) {
    if (($t['jenis_transaksi'] ?? '') === 'setoran') {
        $total_setoran += (float) $t['jumlah'];
    } else {
        $total_penarikan += (float) $t['jumlah'];
    }
}

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
    <title>Riwayat Simpanan - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-agtrih" aria-hidden="true"><i class="ti ti-history"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Akun anggota</p>
                        <h1 class="ui-dash-title">Riwayat Simpanan</h1>
                        <p class="ui-dash-meta mb-0">
                            Mutasi setoran &amp; penarikan
                            <?php if ($earliest_date && $latest_date): ?>
                            · <?php echo htmlspecialchars(formatTanggalUi($earliest_date)); ?> – <?php echo htmlspecialchars(formatTanggalUi($latest_date)); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="simpanan.php" class="btn btn-sm ui-set-btn-ghost">
                            <i class="ti ti-pig-money me-1" aria-hidden="true"></i>Saldo simpanan
                        </a>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-agt-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Saldo saat ini', 'value' => formatRupiah($total_saldo), 'tone' => 'info', 'icon' => 'ti ti-wallet', 'hint' => 'Semua jenis'],
                    ['label' => 'Total setoran', 'value' => formatRupiah($total_setoran), 'tone' => 'success', 'icon' => 'ti ti-arrow-down', 'hint' => 'Semua waktu'],
                    ['label' => 'Total penarikan', 'value' => formatRupiah($total_penarikan), 'tone' => 'danger', 'icon' => 'ti ti-arrow-up', 'hint' => 'Semua waktu'],
                    ['label' => 'Transaksi', 'value' => count($riwayat), 'tone' => 'primary', 'icon' => 'ti ti-arrows-exchange', 'hint' => 'Jumlah mutasi'],
                ]); ?>
                </div>

                <?php if ($jenis_dengan_saldo): ?>
                <div class="ui-agt-saldo-strip mb-3" aria-label="Saldo per jenis">
                    <?php foreach ($jenis_dengan_saldo as $s): ?>
                    <div class="ui-agt-saldo-chip">
                        <span class="ui-lsimp-kode <?php echo htmlspecialchars($badgeJenis($s['kode_jenis'])); ?>"><?php echo htmlspecialchars($s['kode_jenis']); ?></span>
                        <div>
                            <strong><?php echo formatRupiah($s['saldo']); ?></strong>
                            <span><?php echo htmlspecialchars($s['nama_jenis']); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="card ui-agt-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Daftar mutasi</span>
                        <span class="ui-dash-sec-note"><?php echo (int) count($riwayat); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="15"
                                   data-dt-order="0,desc"
                                   data-dt-filter="2,3"
                                   data-empty-message="Belum ada transaksi simpanan.">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Kode</th>
                                        <th>Jenis</th>
                                        <th>Transaksi</th>
                                        <th class="text-end">Jumlah</th>
                                        <th class="text-end">Saldo sesudah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($riwayat as $t):
                                    $isSetoran = ($t['jenis_transaksi'] ?? '') === 'setoran';
                                    ?>
                                    <tr>
                                        <td data-order="<?php echo htmlspecialchars((string) $t['tanggal_transaksi']); ?>">
                                            <span class="ui-agt-text"><?php echo htmlspecialchars(formatTanggalUi($t['tanggal_transaksi'], true)); ?></span>
                                        </td>
                                        <td><code class="ui-agt-code"><?php echo htmlspecialchars($t['kode_transaksi']); ?></code></td>
                                        <td data-filter="<?php echo htmlspecialchars($t['kode_jenis']); ?>">
                                            <span class="ui-lsimp-kode <?php echo htmlspecialchars($badgeJenis($t['kode_jenis'])); ?>"><?php echo htmlspecialchars($t['kode_jenis']); ?></span>
                                            <span class="ui-agt-meta"><?php echo htmlspecialchars($t['nama_jenis']); ?></span>
                                        </td>
                                        <td data-filter="<?php echo htmlspecialchars($t['jenis_transaksi']); ?>">
                                            <span class="badge <?php echo $isSetoran ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                                <?php echo htmlspecialchars(ucfirst((string) $t['jenis_transaksi'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <strong class="ui-agt-money <?php echo $isSetoran ? 'is-in' : 'is-out'; ?>">
                                                <?php echo $isSetoran ? '+' : '-'; ?><?php echo formatRupiah($t['jumlah']); ?>
                                            </strong>
                                        </td>
                                        <td class="text-end"><span class="ui-agt-text"><?php echo formatRupiah($t['saldo_sesudah']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
</body>
</html>

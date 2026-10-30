<?php
require_once '../includes/auth.php';
checkAnggota();

$anggota_id = $_SESSION['user_id'];
$nama_lengkap = $_SESSION['nama_lengkap'];

// Get anggota data
$query_anggota = "SELECT a.*, u.username 
                  FROM anggota a 
                  JOIN users u ON a.user_id = u.id 
                  WHERE a.user_id = :user_id";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->bindParam(':user_id', $anggota_id);
$stmt_anggota->execute();
$anggota_data = $stmt_anggota->fetch(PDO::FETCH_ASSOC);

$anggota_id_db = $anggota_data['id'];

// Get total simpanan (HANYA SIMPANAN REAL, EXCLUDE ANG)
$query_total_simpanan = "SELECT COALESCE(SUM(s.saldo), 0) as total_simpanan 
                         FROM simpanan s 
                         JOIN jenis_simpanan j ON s.jenis_simpanan_id = j.id
                         WHERE s.anggota_id = :anggota_id AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')";
$stmt_simpanan = $db->prepare($query_total_simpanan);
$stmt_simpanan->bindParam(':anggota_id', $anggota_id_db);
$stmt_simpanan->execute();
$total_simpanan = $stmt_simpanan->fetch(PDO::FETCH_ASSOC)['total_simpanan'];

// Get pinjaman dengan perhitungan sisa yang benar (pokok + bunga)
$query_pinjaman = "SELECT 
                      COUNT(*) as total_pinjaman,
                      COALESCE(SUM(CASE WHEN status = 'aktif' THEN sisa_pokok ELSE 0 END), 0) as total_sisa_pokok,
                      COALESCE(SUM(CASE WHEN status = 'aktif' THEN (SELECT COALESCE(SUM(bunga), 0) FROM angsuran WHERE pinjaman_id = p.id AND status != 'lunas') ELSE 0 END), 0) as total_sisa_bunga
                   FROM pinjaman p 
                   WHERE anggota_id = :anggota_id";
$stmt_pinjaman = $db->prepare($query_pinjaman);
$stmt_pinjaman->bindParam(':anggota_id', $anggota_id_db);
$stmt_pinjaman->execute();
$pinjaman_data = $stmt_pinjaman->fetch(PDO::FETCH_ASSOC);

// Hitung total tagihan aktif (sisa pokok + sisa bunga)
$total_tagihan_aktif = $pinjaman_data['total_sisa_pokok'] + $pinjaman_data['total_sisa_bunga'];

// Jadwal angsuran pinjaman aktif (bulanan & mingguan), dibaca langsung dari tabel angsuran
// sehingga pembayaran oleh teller langsung terlihat
$query_jadwal = "SELECT p.id AS pinjaman_id, p.kode_pinjaman, p.tenor, p.frekuensi, p.jumlah_cicilan,
                        a.angsuran_ke, a.tanggal_jatuh_tempo, a.jumlah_angsuran, a.tanggal_bayar, a.status
                 FROM angsuran a
                 JOIN pinjaman p ON a.pinjaman_id = p.id
                 WHERE p.anggota_id = :anggota_id AND p.status = 'aktif'
                 ORDER BY p.tanggal_pencairan, p.id, a.angsuran_ke";
$stmt_jadwal = $db->prepare($query_jadwal);
$stmt_jadwal->bindParam(':anggota_id', $anggota_id_db);
$stmt_jadwal->execute();
$jadwal_angsuran = [];
foreach ($stmt_jadwal->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $jadwal_angsuran[$row['pinjaman_id']][] = $row;
}

// Get total pinjaman yang sudah lunas
$query_pinjaman_lunas = "SELECT COUNT(*) as total_lunas 
                         FROM pinjaman 
                         WHERE anggota_id = :anggota_id AND status = 'lunas'";
$stmt_lunas = $db->prepare($query_pinjaman_lunas);
$stmt_lunas->bindParam(':anggota_id', $anggota_id_db);
$stmt_lunas->execute();
$pinjaman_lunas = $stmt_lunas->fetch(PDO::FETCH_ASSOC)['total_lunas'];

// Get total pinjaman aktif (jumlah pinjaman, bukan nominal)
$query_pinjaman_aktif_count = "SELECT COUNT(*) as total 
                               FROM pinjaman 
                               WHERE anggota_id = :anggota_id AND status = 'aktif'";
$stmt_aktif_count = $db->prepare($query_pinjaman_aktif_count);
$stmt_aktif_count->bindParam(':anggota_id', $anggota_id_db);
$stmt_aktif_count->execute();
$pinjaman_aktif_count = $stmt_aktif_count->fetch(PDO::FETCH_ASSOC)['total'];

// Get jenis simpanan (HANYA SIMPANAN REAL, EXCLUDE ANG)
$query_jenis_simpanan = "SELECT j.nama_jenis, j.kode_jenis, s.saldo 
                         FROM simpanan s 
                         JOIN jenis_simpanan j ON s.jenis_simpanan_id = j.id 
                         WHERE s.anggota_id = :anggota_id 
                           AND s.saldo > 0 
                           AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
                         ORDER BY j.id";
$stmt_jenis = $db->prepare($query_jenis_simpanan);
$stmt_jenis->bindParam(':anggota_id', $anggota_id_db);
$stmt_jenis->execute();
$jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// Get transaksi terbaru (HANYA SIMPANAN REAL, EXCLUDE ANG)
$query_transaksi = "SELECT t.*, js.nama_jenis, js.kode_jenis
                    FROM transaksi t 
                    JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id 
                    WHERE t.anggota_id = :anggota_id 
                      AND js.kode_jenis NOT IN ('ANG','BONUS','SHU')
                    ORDER BY t.tanggal_transaksi DESC 
                    LIMIT 10";
$stmt_transaksi = $db->prepare($query_transaksi);
$stmt_transaksi->bindParam(':anggota_id', $anggota_id_db);
$stmt_transaksi->execute();
$transaksi_terbaru = $stmt_transaksi->fetchAll(PDO::FETCH_ASSOC);

schemaPastikanWaserda($db);
$waserda_total = ['total_transaksi' => 0, 'total_belanja' => 0];
$waserda_terbaru = [];
try {
    $stmt_waserda_total = $db->prepare("SELECT COUNT(*) AS total_transaksi, COALESCE(SUM(total_bayar),0) AS total_belanja
        FROM waserda_transaksi WHERE anggota_id = :anggota_id");
    $stmt_waserda_total->bindParam(':anggota_id', $anggota_id_db);
    $stmt_waserda_total->execute();
    $waserda_total = $stmt_waserda_total->fetch(PDO::FETCH_ASSOC) ?: $waserda_total;

    $stmt_waserda_terbaru = $db->prepare("SELECT w.*, u.nama_lengkap AS petugas
        FROM waserda_transaksi w
        LEFT JOIN users u ON w.user_id = u.id
        WHERE w.anggota_id = :anggota_id
        ORDER BY w.created_at DESC LIMIT 5");
    $stmt_waserda_terbaru->bindParam(':anggota_id', $anggota_id_db);
    $stmt_waserda_terbaru->execute();
    $waserda_terbaru = $stmt_waserda_terbaru->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($waserda_terbaru as &$w) {
        $items = json_decode($w['items'] ?? '[]', true);
        $w['jml_item'] = is_array($items) ? count($items) : 0;
        $w['nama_barang'] = (is_array($items) && isset($items[0]['nama'])) ? $items[0]['nama'] : 'Barang';
        $w['total_item'] = $w['jml_item'];
    }
    unset($w);
} catch (Throwable $e) {
    error_log('Anggota dashboard waserda: ' . $e->getMessage());
}

// Angsuran berikutnya (belum lunas, paling dekat)
$nextAngsuran = null;
foreach ($jadwal_angsuran as $jadwalRows) {
    foreach ($jadwalRows as $row) {
        if (($row['status'] ?? '') === 'lunas') {
            continue;
        }
        if ($nextAngsuran === null || $row['tanggal_jatuh_tempo'] < $nextAngsuran['tanggal_jatuh_tempo']) {
            $nextAngsuran = $row;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo APP_NAME; ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-dash-page">
            <header class="ui-dash-header">
                <div class="ui-dash-header-icon" aria-hidden="true"><i class="ti ti-dashboard"></i></div>
                <div class="ui-dash-header-copy">
                    <p class="ui-dash-eyebrow mb-0">Akun anggota</p>
                    <h1 class="ui-dash-title">Dashboard Saya</h1>
                    <p class="ui-dash-meta mb-0">
                        <span>Halo, <?php echo htmlspecialchars($nama_lengkap); ?></span>
                        <span>· <?php echo htmlspecialchars($anggota_data['kode_anggota']); ?></span>
                        <span class="badge bg-success"><?php echo htmlspecialchars(ucfirst($anggota_data['status'])); ?></span>
                        <span>· <?php echo formatTanggalUi(date('Y-m-d')); ?></span>
                    </p>
                </div>
            </header>

            <section class="ui-status-hero" aria-label="Status keuangan">
                <div class="ui-status-hero-card tone-success">
                    <div class="label">Simpanan saya</div>
                    <div class="value"><?php echo formatRupiah($total_simpanan); ?></div>
                    <div class="hint">Saldo real</div>
                </div>
                <div class="ui-status-hero-card tone-danger">
                    <div class="label">Sisa tagihan</div>
                    <div class="value"><?php echo formatRupiah($total_tagihan_aktif); ?></div>
                    <div class="hint"><?php echo (int)$pinjaman_aktif_count; ?> pinjaman aktif</div>
                </div>
                <div class="ui-status-hero-card tone-warning">
                    <div class="label">Angsuran berikutnya</div>
                    <?php if ($nextAngsuran): ?>
                        <div class="value"><?php echo formatRupiah($nextAngsuran['jumlah_angsuran']); ?></div>
                        <div class="hint"><?php echo htmlspecialchars($nextAngsuran['kode_pinjaman']); ?> · jatuh tempo <?php echo formatTanggalUi($nextAngsuran['tanggal_jatuh_tempo']); ?></div>
                    <?php else: ?>
                        <div class="value">—</div>
                        <div class="hint">Tidak ada tagihan terjadwal</div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="ui-stat-grid" data-count="3">
                <div class="stat-card tone-info">
                    <div class="card-body d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <p class="mb-0 text-muted small text-uppercase">Pinjaman lunas</p>
                            <h4 class="mb-0"><?php echo (int)$pinjaman_lunas; ?></h4>
                            <small class="text-muted">Selesai</small>
                        </div>
                        <span class="stat-icon"><i class="ti ti-circle-check"></i></span>
                    </div>
                </div>
                <div class="stat-card tone-success">
                    <div class="card-body d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <p class="mb-0 text-muted small text-uppercase">Waserda</p>
                            <h4 class="mb-0"><?php echo formatRupiah($waserda_total['total_belanja']); ?></h4>
                            <small class="text-muted"><?php echo (int)$waserda_total['total_transaksi']; ?> transaksi</small>
                        </div>
                        <span class="stat-icon"><i class="ti ti-building-store"></i></span>
                    </div>
                </div>
                <div class="stat-card tone-primary">
                    <div class="card-body d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <p class="mb-0 text-muted small text-uppercase">Pinjaman aktif</p>
                            <h4 class="mb-0"><?php echo (int)$pinjaman_aktif_count; ?></h4>
                            <small class="text-muted">Jumlah pinjaman</small>
                        </div>
                        <span class="stat-icon"><i class="ti ti-cash"></i></span>
                    </div>
                </div>
            </div>

            <section class="ui-dash-panel" aria-label="Aksi cepat">
                <div class="ui-dash-sec">
                    <h3 class="ui-dash-sec-title"><i class="ti ti-bolt" aria-hidden="true"></i>Aksi cepat</h3>
                    <span class="ui-dash-sec-note">Pintasan akun</span>
                </div>
                <div class="ui-dash-actions" data-count="6">
                    <a class="ui-dash-action tone-primary is-primary" href="simpanan.php"><span class="ui-dash-action-icon"><i class="ti ti-pig-money" aria-hidden="true"></i></span><span class="ui-dash-action-label">Simpanan</span></a>
                    <a class="ui-dash-action tone-warning" href="pinjaman.php"><span class="ui-dash-action-icon"><i class="ti ti-cash" aria-hidden="true"></i></span><span class="ui-dash-action-label">Pinjaman</span></a>
                    <a class="ui-dash-action tone-success" href="waserda.php"><span class="ui-dash-action-icon"><i class="ti ti-building-store" aria-hidden="true"></i></span><span class="ui-dash-action-label">Waserda</span></a>
                    <a class="ui-dash-action tone-info" href="riwayat_simpanan.php"><span class="ui-dash-action-icon"><i class="ti ti-history" aria-hidden="true"></i></span><span class="ui-dash-action-label">Riwayat</span></a>
                    <a class="ui-dash-action tone-secondary" href="shu_saya.php"><span class="ui-dash-action-icon"><i class="ti ti-calculator" aria-hidden="true"></i></span><span class="ui-dash-action-label">SHU</span></a>
                    <a class="ui-dash-action tone-secondary" href="profil.php"><span class="ui-dash-action-icon"><i class="ti ti-user" aria-hidden="true"></i></span><span class="ui-dash-action-label">Profil</span></a>
                </div>
            </section>

            <div class="row g-2 mb-3">
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header ui-dash-sec">
                            <span class="ui-dash-sec-title"><i class="ti ti-exchange" aria-hidden="true"></i>Transaksi simpanan</span>
                            <a href="riwayat_simpanan.php" class="ui-dash-sec-link">Lihat semua</a>
                        </div>
                        <div class="list-group list-group-flush ui-dash-list">
                            <?php if (empty($transaksi_terbaru)): ?>
                                <div class="ui-dash-empty"><i class="ti ti-exchange"></i>Belum ada transaksi simpanan</div>
                            <?php else: foreach (array_slice($transaksi_terbaru, 0, 5) as $transaksi): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="min-w-0">
                                            <div class="fw-semibold text-truncate"><?php echo htmlspecialchars($transaksi['nama_jenis']); ?></div>
                                            <small class="text-muted"><?php echo formatTanggalUi($transaksi['tanggal_transaksi']); ?> · <?php echo htmlspecialchars(ucfirst($transaksi['jenis_transaksi'])); ?></small>
                                        </div>
                                        <div class="text-end fw-semibold <?php echo $transaksi['jenis_transaksi'] === 'setoran' ? 'text-success' : 'text-warning'; ?>">
                                            <?php echo $transaksi['jenis_transaksi'] === 'setoran' ? '+' : '-'; ?><?php echo formatRupiah($transaksi['jumlah']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header ui-dash-sec">
                            <span class="ui-dash-sec-title"><i class="ti ti-building-store" aria-hidden="true"></i>Transaksi waserda</span>
                            <a href="waserda.php" class="ui-dash-sec-link">Lihat semua</a>
                        </div>
                        <div class="list-group list-group-flush ui-dash-list">
                            <?php if (empty($waserda_terbaru)): ?>
                                <div class="ui-dash-empty"><i class="ti ti-building-store"></i>Belum ada belanja waserda</div>
                            <?php else: foreach ($waserda_terbaru as $w): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="min-w-0">
                                            <div class="fw-semibold text-truncate"><?php echo htmlspecialchars($w['nama_barang']); ?></div>
                                            <small class="text-muted"><?php echo formatTanggalUi($w['created_at']); ?> · <?php echo (int)$w['jml_item']; ?> item</small>
                                        </div>
                                        <div class="text-end fw-semibold text-success"><?php echo formatRupiah($w['total_bayar']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header ui-dash-sec"><span class="ui-dash-sec-title"><i class="ti ti-wallet" aria-hidden="true"></i>Jenis simpanan</span></div>
                        <div class="list-group list-group-flush ui-dash-list">
                            <?php if (empty($jenis_simpanan)): ?>
                                <div class="ui-dash-empty"><i class="ti ti-wallet"></i>Belum ada simpanan</div>
                            <?php else: foreach ($jenis_simpanan as $jenis): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                    <div class="min-w-0">
                                        <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($jenis['kode_jenis']); ?></span>
                                        <small class="fw-semibold"><?php echo htmlspecialchars($jenis['nama_jenis']); ?></small>
                                    </div>
                                    <span class="fw-semibold text-success"><?php echo formatRupiah($jenis['saldo']); ?></span>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header ui-dash-sec"><span class="ui-dash-sec-title"><i class="ti ti-calendar-due" aria-hidden="true"></i>Jadwal angsuran aktif</span></div>
                        <div class="card-body p-0">
                            <?php if (empty($jadwal_angsuran)): ?>
                                <div class="ui-dash-empty"><i class="ti ti-calendar-check"></i>Tidak ada pinjaman aktif</div>
                            <?php else: foreach ($jadwal_angsuran as $jadwal): $info = $jadwal[0]; ?>
                                <div class="px-3 pt-2 pb-1">
                                    <div class="small mb-1">
                                        <strong><?php echo htmlspecialchars($info['kode_pinjaman']); ?></strong>
                                        <span class="text-muted">· <?php echo htmlspecialchars(labelTenorRow($info)); ?></span>
                                    </div>
                                    <div class="table-responsive ui-table-scroll mb-2">
                                        <table class="table table-sm table-striped mb-0 js-datatable" data-dt-plain>
                                            <thead>
                                                <tr>
                                                    <th>Ke</th>
                                                    <th>Jatuh tempo</th>
                                                    <th class="text-end">Nominal</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($jadwal as $a):
                                                if ($a['status'] == 'lunas') {
                                                    $status_badge = '<span class="badge bg-success">Lunas</span>';
                                                } elseif ($a['status'] == 'telat' || $a['tanggal_jatuh_tempo'] < date('Y-m-d')) {
                                                    $status_badge = '<span class="badge bg-danger">Terlambat</span>';
                                                } else {
                                                    $status_badge = '<span class="badge bg-secondary">Belum</span>';
                                                }
                                            ?>
                                                <tr>
                                                    <td><?php echo (int)$a['angsuran_ke']; ?>/<?php echo count($jadwal); ?></td>
                                                    <td><?php echo formatTanggalUi($a['tanggal_jatuh_tempo']); ?></td>
                                                    <td class="text-end"><?php echo formatRupiah($a['jumlah_angsuran']); ?></td>
                                                    <td><?php echo $status_badge; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
</body>
</html>

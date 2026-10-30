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

$query_pinjaman = "SELECT p.*, jp.nama_jenis, jp.memerlukan_jaminan,
                          jk.status_jaminan,
                          lk.status AS status_pengembalian_jaminan,
                          lk.alasan AS alasan_pengembalian_jaminan,
                          lk.catatan_admin AS catatan_admin_pengembalian,
                          lk.diperiksa_at AS tanggal_status_pengembalian,
                          (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') AS angsuran_lunas,
                          (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id) AS total_angsuran,
                          (SELECT COALESCE(SUM(pokok), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') AS total_pokok_dibayar,
                          (SELECT COALESCE(SUM(bunga), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') AS total_bunga_dibayar,
                          (SELECT COALESCE(SUM(denda), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') AS total_denda_dibayar,
                          (SELECT COALESCE(SUM(bunga), 0) FROM angsuran WHERE pinjaman_id = p.id AND status != 'lunas') AS sisa_bunga
                   FROM pinjaman p
                   JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                   LEFT JOIN jaminan_pinjaman jk ON jk.pinjaman_id = p.id
                   LEFT JOIN layanan_jaminan_kembali lk ON lk.pinjaman_id = p.id
                   WHERE p.anggota_id = :anggota_id
                   ORDER BY p.tanggal_pencairan DESC";
$stmt_pinjaman = $db->prepare($query_pinjaman);
$stmt_pinjaman->bindParam(':anggota_id', $anggota_data['id']);
$stmt_pinjaman->execute();
$pinjaman_list = $stmt_pinjaman->fetchAll(PDO::FETCH_ASSOC);

$detailMap = [];
foreach ($pinjaman_list as &$pinjaman_row) {
    $pinjaman_row['label_bunga'] = labelBungaRow($pinjaman_row);
    $pinjaman_row['label_tenor'] = labelTenorRow($pinjaman_row);
    $pinjaman_row['label_periode'] = labelPeriode($pinjaman_row['frekuensi']);
    $detailMap[(int) $pinjaman_row['id']] = [
        'id' => (int) $pinjaman_row['id'],
        'kode_pinjaman' => (string) $pinjaman_row['kode_pinjaman'],
        'nama_jenis' => (string) $pinjaman_row['nama_jenis'],
        'status' => (string) $pinjaman_row['status'],
        'tanggal_pencairan' => (string) ($pinjaman_row['tanggal_pencairan'] ?? ''),
        'jatuh_tempo' => (string) ($pinjaman_row['jatuh_tempo'] ?? ''),
        'jumlah_pinjaman' => (float) $pinjaman_row['jumlah_pinjaman'],
        'sisa_pokok' => (float) $pinjaman_row['sisa_pokok'],
        'sisa_bunga' => (float) $pinjaman_row['sisa_bunga'],
        'total_pinjaman' => (float) $pinjaman_row['total_pinjaman'],
        'angsuran_per_bulan' => (float) $pinjaman_row['angsuran_per_bulan'],
        'total_pokok_dibayar' => (float) $pinjaman_row['total_pokok_dibayar'],
        'total_bunga_dibayar' => (float) $pinjaman_row['total_bunga_dibayar'],
        'total_denda_dibayar' => (float) $pinjaman_row['total_denda_dibayar'],
        'angsuran_lunas' => (int) $pinjaman_row['angsuran_lunas'],
        'total_angsuran' => (int) $pinjaman_row['total_angsuran'],
        'label_bunga' => (string) $pinjaman_row['label_bunga'],
        'label_tenor' => (string) $pinjaman_row['label_tenor'],
        'label_periode' => (string) $pinjaman_row['label_periode'],
        'memerlukan_jaminan' => (int) !empty($pinjaman_row['memerlukan_jaminan']),
        'status_pengembalian_jaminan' => (string) ($pinjaman_row['status_pengembalian_jaminan'] ?? ''),
        'catatan_admin_pengembalian' => (string) ($pinjaman_row['catatan_admin_pengembalian'] ?? ''),
    ];
}
unset($pinjaman_row);

$pinjaman_tanpa_jaminan = [];
$pinjaman_jaminan = [];
foreach ($pinjaman_list as $pinjaman_row) {
    if (!empty($pinjaman_row['memerlukan_jaminan'])) {
        $pinjaman_jaminan[] = $pinjaman_row;
    } else {
        $pinjaman_tanpa_jaminan[] = $pinjaman_row;
    }
}

$query_angsuran = "SELECT a.*, p.kode_pinjaman, p.jumlah_pinjaman, jp.nama_jenis AS jenis_pinjaman,
                          jp.memerlukan_jaminan
                   FROM angsuran a
                   JOIN pinjaman p ON a.pinjaman_id = p.id
                   JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                   WHERE p.anggota_id = :anggota_id
                   ORDER BY a.tanggal_jatuh_tempo DESC, a.created_at DESC
                   LIMIT 500";
$stmt_angsuran = $db->prepare($query_angsuran);
$stmt_angsuran->bindParam(':anggota_id', $anggota_data['id']);
$stmt_angsuran->execute();
$angsuran_list = $stmt_angsuran->fetchAll(PDO::FETCH_ASSOC);

$query_pengajuan = "SELECT pp.*, jp.nama_jenis
                    FROM pengajuan_pinjaman pp
                    JOIN jenis_pinjaman jp ON pp.jenis_pinjaman_id = jp.id
                    WHERE pp.anggota_id = :anggota_id
                    ORDER BY pp.tanggal_pengajuan DESC";
$stmt_pengajuan = $db->prepare($query_pengajuan);
$stmt_pengajuan->bindParam(':anggota_id', $anggota_data['id']);
$stmt_pengajuan->execute();
$pengajuan_list = $stmt_pengajuan->fetchAll(PDO::FETCH_ASSOC);

$angsuran_tanpa_jaminan_belum = [];
$angsuran_tanpa_jaminan_lunas = [];
$angsuran_jaminan_belum = [];
$angsuran_jaminan_lunas = [];
foreach ($angsuran_list as $row_angsuran) {
    $adalah_jaminan = !empty($row_angsuran['memerlukan_jaminan']);
    if (($row_angsuran['status'] ?? '') === 'lunas') {
        if ($adalah_jaminan) {
            $angsuran_jaminan_lunas[] = $row_angsuran;
        } else {
            $angsuran_tanpa_jaminan_lunas[] = $row_angsuran;
        }
    } else {
        if ($adalah_jaminan) {
            $angsuran_jaminan_belum[] = $row_angsuran;
        } else {
            $angsuran_tanpa_jaminan_belum[] = $row_angsuran;
        }
    }
}
usort($angsuran_tanpa_jaminan_belum, static function ($x, $y) {
    return strcmp($x['tanggal_jatuh_tempo'] . sprintf('%06d', $x['angsuran_ke']), $y['tanggal_jatuh_tempo'] . sprintf('%06d', $y['angsuran_ke']));
});
usort($angsuran_jaminan_belum, static function ($x, $y) {
    return strcmp($x['tanggal_jatuh_tempo'] . sprintf('%06d', $x['angsuran_ke']), $y['tanggal_jatuh_tempo'] . sprintf('%06d', $y['angsuran_ke']));
});
usort($angsuran_tanpa_jaminan_lunas, static function ($x, $y) {
    return strcmp(($y['tanggal_bayar'] ?? '') . sprintf('%06d', $y['angsuran_ke']), ($x['tanggal_bayar'] ?? '') . sprintf('%06d', $x['angsuran_ke']));
});
usort($angsuran_jaminan_lunas, static function ($x, $y) {
    return strcmp(($y['tanggal_bayar'] ?? '') . sprintf('%06d', $y['angsuran_ke']), ($x['tanggal_bayar'] ?? '') . sprintf('%06d', $x['angsuran_ke']));
});

$total_pinjaman = 0;
$total_pokok_dibayar = 0;
$total_bunga_dibayar = 0;
$total_denda_dibayar = 0;
$total_tagihan_aktif = 0;
$pinjaman_aktif = 0;
$pinjaman_lunas = 0;
foreach ($pinjaman_list as $pinjaman) {
    $total_pinjaman += (float) $pinjaman['jumlah_pinjaman'];
    $total_pokok_dibayar += (float) $pinjaman['total_pokok_dibayar'];
    $total_bunga_dibayar += (float) $pinjaman['total_bunga_dibayar'];
    $total_denda_dibayar += (float) $pinjaman['total_denda_dibayar'];
    if (($pinjaman['status'] ?? '') === 'aktif') {
        $pinjaman_aktif++;
        $total_tagihan_aktif += max(0, (float) $pinjaman['sisa_pokok']) + (float) $pinjaman['sisa_bunga'];
    } elseif (($pinjaman['status'] ?? '') === 'lunas') {
        $pinjaman_lunas++;
    }
}
$total_pembayaran = $total_pokok_dibayar + $total_bunga_dibayar + $total_denda_dibayar;

$labelPengembalian = [
    'menunggu' => 'Menunggu pemeriksaan Admin',
    'pengecekan' => 'Sedang diperiksa Admin',
    'disetujui' => 'Disetujui, jaminan siap diambil',
    'ditolak' => 'Ditolak',
    'diserahkan' => 'Jaminan sudah dikembalikan',
];
$warnaPengembalian = [
    'menunggu' => 'warning text-dark',
    'pengecekan' => 'info text-dark',
    'disetujui' => 'primary',
    'ditolak' => 'danger',
    'diserahkan' => 'success',
];

$renderAngsuranTable = static function (array $rows, string $emptyMsg): void {
    echo '<div class="table-responsive ui-table-scroll">';
    echo '<table class="table table-hover table-sm align-middle mb-0 js-datatable" data-dt-page-length="10" data-dt-order="2,asc" data-dt-filter="0,8" data-empty-message="' . htmlspecialchars($emptyMsg) . '">';
    echo '<thead><tr><th>Kode</th><th>Ke</th><th>Jatuh tempo</th><th class="text-end">Pokok</th><th class="text-end">Bunga</th><th class="text-end">Denda</th><th class="text-end">Total</th><th>Bayar</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $a) {
        $kelas = ($a['status'] ?? '') === 'lunas' ? 'success' : (($a['status'] ?? '') === 'telat' ? 'danger' : 'warning text-dark');
        $labelJenis = !empty($a['memerlukan_jaminan']) ? 'Gadai' : 'Tanpa jaminan';
        echo '<tr>';
        echo '<td data-filter="' . htmlspecialchars($a['kode_pinjaman']) . '"><code>' . htmlspecialchars($a['kode_pinjaman']) . '</code>';
        echo '<span class="ui-agt-meta">' . htmlspecialchars($labelJenis) . '</span></td>';
        echo '<td class="text-center">' . (int) $a['angsuran_ke'] . '</td>';
        echo '<td data-order="' . htmlspecialchars((string) $a['tanggal_jatuh_tempo']) . '">' . htmlspecialchars(formatTanggalUi($a['tanggal_jatuh_tempo'])) . '</td>';
        echo '<td class="text-end">' . formatRupiah($a['pokok']) . '</td>';
        echo '<td class="text-end">' . formatRupiah($a['bunga']) . '</td>';
        echo '<td class="text-end text-danger">' . formatRupiah($a['denda']) . '</td>';
        echo '<td class="text-end fw-semibold">' . formatRupiah($a['jumlah_angsuran']) . '</td>';
        echo '<td>' . (!empty($a['tanggal_bayar']) ? htmlspecialchars(formatTanggalUi($a['tanggal_bayar'])) : '—') . '</td>';
        echo '<td data-filter="' . htmlspecialchars((string) $a['status']) . '"><span class="badge bg-' . $kelas . '">' . htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $a['status']))) . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pinjaman Saya - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-agtpin" aria-hidden="true"><i class="ti ti-cash"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Akun anggota</p>
                        <h1 class="ui-dash-title">Pinjaman Saya</h1>
                        <p class="ui-dash-meta mb-0">Status pinjaman, jadwal angsuran, dan pengajuan · hanya lihat</p>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-agt-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Pinjaman', 'value' => count($pinjaman_list), 'tone' => 'primary', 'icon' => 'ti ti-cash', 'hint' => 'Semua status'],
                    ['label' => 'Aktif', 'value' => $pinjaman_aktif, 'tone' => 'success', 'icon' => 'ti ti-player-play', 'hint' => 'Berjalan'],
                    ['label' => 'Lunas', 'value' => $pinjaman_lunas, 'tone' => 'info', 'icon' => 'ti ti-circle-check', 'hint' => 'Selesai'],
                    ['label' => 'Total dipinjam', 'value' => formatRupiah($total_pinjaman), 'tone' => 'warning', 'icon' => 'ti ti-coin', 'hint' => 'Pokok cair'],
                    ['label' => 'Sisa tagihan', 'value' => formatRupiah($total_tagihan_aktif), 'tone' => 'danger', 'icon' => 'ti ti-credit-card', 'hint' => 'Aktif'],
                    ['label' => 'Sudah dibayar', 'value' => formatRupiah($total_pembayaran), 'tone' => 'success', 'icon' => 'ti ti-arrow-up', 'hint' => 'Pokok+bunga+denda'],
                ]); ?>
                </div>

                <?php
                $kelompok_pinjaman = [
                    ['judul' => 'Pinjaman tanpa jaminan', 'ikon' => 'ti-cash', 'tone' => 'primary', 'data' => $pinjaman_tanpa_jaminan, 'kosong' => 'Belum ada pinjaman tanpa jaminan.'],
                    ['judul' => 'Pinjaman jaminan / gadai', 'ikon' => 'ti-shield-check', 'tone' => 'warning', 'data' => $pinjaman_jaminan, 'kosong' => 'Belum ada pinjaman jaminan atau gadai.'],
                ];
                foreach ($kelompok_pinjaman as $kelompok):
                ?>
                <div class="card ui-agt-daftar mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti <?php echo $kelompok['ikon']; ?>" aria-hidden="true"></i><?php echo htmlspecialchars($kelompok['judul']); ?></span>
                        <span class="ui-dash-sec-note"><?php echo (int) count($kelompok['data']); ?> data</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($kelompok['data'])): ?>
                        <div class="ui-agt-empty">
                            <i class="ti <?php echo $kelompok['ikon']; ?>" aria-hidden="true"></i>
                            <strong>Kosong</strong>
                            <p><?php echo htmlspecialchars($kelompok['kosong']); ?></p>
                        </div>
                        <?php else: ?>
                        <div class="ui-agt-pinjaman-grid">
                            <?php foreach ($kelompok['data'] as $pinjaman):
                                $status = $pinjaman['status'] ?? '';
                                $status_class = $status === 'aktif' ? 'success' : ($status === 'lunas' ? 'secondary' : 'danger');
                                $status_pengembalian = strtolower((string) ($pinjaman['status_pengembalian_jaminan'] ?? ''));
                                $label_pengembalian = $labelPengembalian[$status_pengembalian] ?? '';
                                $warna_pengembalian = $warnaPengembalian[$status_pengembalian] ?? 'secondary';
                                $sisa_pokok_tampil = max(0, (float) $pinjaman['sisa_pokok']);
                                $sisa_bunga_tampil = (float) $pinjaman['sisa_bunga'];
                                $total_tagihan = $sisa_pokok_tampil + $sisa_bunga_tampil;
                                $progress = ((int) $pinjaman['total_angsuran'] > 0)
                                    ? ((float) $pinjaman['angsuran_lunas'] / (float) $pinjaman['total_angsuran'] * 100)
                                    : 0;
                                ?>
                            <article class="ui-agt-pinjaman-card is-<?php echo htmlspecialchars($status); ?>">
                                <div class="ui-agt-pinjaman-head">
                                    <div>
                                        <strong><?php echo htmlspecialchars($pinjaman['kode_pinjaman']); ?></strong>
                                        <span class="ui-agt-meta"><?php echo htmlspecialchars($pinjaman['nama_jenis']); ?> · <?php echo htmlspecialchars($pinjaman['label_bunga']); ?> · <?php echo htmlspecialchars($pinjaman['label_tenor']); ?></span>
                                    </div>
                                    <div class="ui-agt-pinjaman-actions">
                                        <span class="badge bg-<?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                        <button type="button" class="btn btn-sm btn-outline-primary js-agt-pinjaman-detail"
                                                data-pinjaman-id="<?php echo (int) $pinjaman['id']; ?>"
                                                aria-label="Detail pinjaman">
                                            <i class="ti ti-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="ui-agt-pinjaman-stats">
                                    <div><span>Pokok</span><strong><?php echo formatRupiah($pinjaman['jumlah_pinjaman']); ?></strong></div>
                                    <div><span>Angsuran/<?php echo htmlspecialchars($pinjaman['label_periode']); ?></span><strong><?php echo formatRupiah($pinjaman['angsuran_per_bulan']); ?></strong></div>
                                    <div><span>Sisa pokok</span><strong class="<?php echo $sisa_pokok_tampil > 0 ? 'text-warning' : 'text-success'; ?>"><?php echo formatRupiah($sisa_pokok_tampil); ?></strong></div>
                                    <div><span>Sisa bunga</span><strong><?php echo formatRupiah($sisa_bunga_tampil); ?></strong></div>
                                    <div><span>Total tagihan</span><strong class="text-danger"><?php echo formatRupiah($total_tagihan); ?></strong></div>
                                </div>
                                <?php if (!empty($pinjaman['memerlukan_jaminan'])): ?>
                                <div class="ui-agt-jaminan-note <?php echo $status_pengembalian === 'ditolak' ? 'is-bad' : ($status_pengembalian === 'diserahkan' ? 'is-ok' : ''); ?>">
                                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                        <strong><i class="ti ti-package me-1" aria-hidden="true"></i>Pengembalian barang</strong>
                                        <?php if ($label_pengembalian): ?>
                                        <span class="badge bg-<?php echo $warna_pengembalian; ?>"><?php echo htmlspecialchars($label_pengembalian); ?></span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Belum diajukan</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($status_pengembalian === 'ditolak'): ?>
                                    <p class="mb-0 mt-1">Penolakan: <?php echo htmlspecialchars(trim((string) $pinjaman['catatan_admin_pengembalian']) ?: 'Tidak ada keterangan dari Admin.'); ?></p>
                                    <?php elseif ($status_pengembalian && !empty($pinjaman['catatan_admin_pengembalian'])): ?>
                                    <p class="mb-0 mt-1">Admin: <?php echo htmlspecialchars($pinjaman['catatan_admin_pengembalian']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($status === 'aktif'): ?>
                                <div class="ui-agt-progress">
                                    <div class="d-flex justify-content-between"><span>Progress</span><span><?php echo (int) $pinjaman['angsuran_lunas']; ?> / <?php echo (int) $pinjaman['total_angsuran']; ?></span></div>
                                    <div class="progress"><div class="progress-bar bg-success" style="width: <?php echo (float) $progress; ?>%"></div></div>
                                </div>
                                <?php endif; ?>
                            </article>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php
                $kelompok_angsuran = [
                    ['judul' => 'Angsuran tanpa jaminan', 'ikon' => 'ti-cash', 'belum' => $angsuran_tanpa_jaminan_belum, 'lunas' => $angsuran_tanpa_jaminan_lunas],
                    ['judul' => 'Angsuran jaminan / gadai', 'ikon' => 'ti-shield-check', 'belum' => $angsuran_jaminan_belum, 'lunas' => $angsuran_jaminan_lunas],
                ];
                foreach ($kelompok_angsuran as $kelompok):
                ?>
                <div class="card ui-agt-daftar mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti <?php echo $kelompok['ikon']; ?>" aria-hidden="true"></i><?php echo htmlspecialchars($kelompok['judul']); ?></span>
                        <span class="ui-dash-sec-note">
                            <span class="badge bg-warning text-dark"><?php echo count($kelompok['belum']); ?> belum</span>
                            <span class="badge bg-success"><?php echo count($kelompok['lunas']); ?> lunas</span>
                        </span>
                    </div>
                    <div class="card-body">
                        <h3 class="ui-agt-subhead"><i class="ti ti-hourglass me-1 text-warning" aria-hidden="true"></i>Belum lunas</h3>
                        <?php $renderAngsuranTable($kelompok['belum'], 'Tidak ada angsuran yang belum lunas.'); ?>
                        <h3 class="ui-agt-subhead mt-3"><i class="ti ti-circle-check me-1 text-success" aria-hidden="true"></i>Sudah lunas</h3>
                        <?php $renderAngsuranTable($kelompok['lunas'], 'Belum ada angsuran lunas.'); ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="card ui-agt-daftar mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-file-text" aria-hidden="true"></i>Pengajuan pinjaman</span>
                        <span class="ui-dash-sec-note"><?php echo (int) count($pengajuan_list); ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="5,desc"
                                   data-dt-filter="1,4"
                                   data-empty-message="Belum ada pengajuan pinjaman.">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Jumlah</th>
                                        <th>Tenor</th>
                                        <th>Status</th>
                                        <th>Tanggal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($pengajuan_list as $pengajuan):
                                    $st = $pengajuan['status'] ?? '';
                                    $badge = $st === 'disetujui' ? 'success' : ($st === 'ditolak' ? 'danger' : ($st === 'batal_cair' ? 'secondary' : 'warning text-dark'));
                                    $label = $st === 'batal_cair' ? 'Batal Cair' : ucfirst($st);
                                    ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($pengajuan['kode_pengajuan']); ?></code></td>
                                        <td data-filter="<?php echo htmlspecialchars($pengajuan['nama_jenis']); ?>"><?php echo htmlspecialchars($pengajuan['nama_jenis']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($pengajuan['jumlah_pinjaman']); ?></td>
                                        <td><?php echo (int) $pengajuan['tenor']; ?> bulan</td>
                                        <td data-filter="<?php echo htmlspecialchars($st); ?>">
                                            <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($label); ?></span>
                                        </td>
                                        <td data-order="<?php echo htmlspecialchars((string) $pengajuan['tanggal_pengajuan']); ?>">
                                            <?php echo htmlspecialchars(formatTanggalUi($pengajuan['tanggal_pengajuan'], true)); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="ui-agt-note" role="note">
                    <i class="ti ti-info-circle" aria-hidden="true"></i>
                    <div>
                        <strong>Informasi</strong>
                        <span>Halaman ini hanya menampilkan riwayat. Pembayaran angsuran dilakukan melalui teller koperasi.</span>
                    </div>
                </div>
            </div>

    <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>

<div class="modal fade" id="detailPinjamanModal" tabindex="-1" aria-labelledby="detailPinjamanTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailPinjamanTitle"><i class="ti ti-cash me-1" aria-hidden="true"></i>Detail pinjaman</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body" id="detailPinjamanContent">
                <p class="text-muted mb-0">Memuat detail…</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<script>
window.__agtPinjamanDetail = <?php echo json_encode($detailMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
(function () {
    var map = window.__agtPinjamanDetail || {};
    var modalEl = document.getElementById('detailPinjamanModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    document.body.appendChild(modalEl);
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var bodyEl = document.getElementById('detailPinjamanContent');
    var titleEl = document.getElementById('detailPinjamanTitle');

    function formatRupiah(angka) {
        var n = Math.round(Number(angka) || 0);
        var neg = n < 0;
        var s = Math.abs(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return (neg ? '-Rp ' : 'Rp ') + s;
    }
    function formatTanggal(value) {
        if (!value) return '—';
        var m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return m[3] + '/' + m[2] + '/' + m[1];
        try { return new Date(value).toLocaleDateString('id-ID'); } catch (e) { return String(value); }
    }
    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
        });
    }
    function row(label, value) {
        return '<div><dt>' + escapeHtml(label) + '</dt><dd>' + value + '</dd></div>';
    }
    function render(p) {
        var sisaPokok = Number(p.sisa_pokok) > 0 ? Number(p.sisa_pokok) : 0;
        var sisaBunga = Number(p.sisa_bunga) || 0;
        var totalTagihan = sisaPokok + sisaBunga;
        var progress = p.total_angsuran > 0 ? Math.round(p.angsuran_lunas / p.total_angsuran * 100) : 0;
        var statusBadge = p.status === 'aktif'
            ? '<span class="badge bg-success">Aktif</span>'
            : (p.status === 'lunas'
                ? '<span class="badge bg-secondary">Lunas</span>'
                : '<span class="badge bg-danger">Macet</span>');
        var statusPeng = String(p.status_pengembalian_jaminan || '').toLowerCase();
        var labelPeng = {
            menunggu: 'Menunggu pemeriksaan Admin',
            pengecekan: 'Sedang diperiksa Admin',
            disetujui: 'Disetujui, jaminan siap diambil',
            ditolak: 'Ditolak',
            diserahkan: 'Jaminan sudah dikembalikan'
        }[statusPeng] || 'Belum diajukan';
        var warnaPeng = {menunggu:'warning text-dark',pengecekan:'info text-dark',disetujui:'primary',ditolak:'danger',diserahkan:'success'}[statusPeng] || 'secondary';
        var catatan = escapeHtml(p.catatan_admin_pengembalian || '');

        titleEl.innerHTML = '<i class="ti ti-cash me-1" aria-hidden="true"></i>' + escapeHtml(p.kode_pinjaman);
        bodyEl.innerHTML =
            '<div class="ui-agt-modal-kpis">'
            + '<div><span>Pokok</span><strong class="text-primary">' + formatRupiah(p.jumlah_pinjaman) + '</strong></div>'
            + '<div><span>Sisa tagihan</span><strong class="text-danger">' + formatRupiah(totalTagihan) + '</strong></div>'
            + '<div><span>Progress</span><strong>' + p.angsuran_lunas + '/' + p.total_angsuran + ' (' + progress + '%)</strong>'
            + '<div class="progress mt-1"><div class="progress-bar bg-success" style="width:' + progress + '%"></div></div></div>'
            + '</div>'
            + '<dl class="ui-agt-dl ui-agt-modal-dl">'
            + row('Jenis', escapeHtml(p.nama_jenis))
            + row('Status', statusBadge)
            + row('Pencairan', escapeHtml(formatTanggal(p.tanggal_pencairan)))
            + row('Jatuh tempo', escapeHtml(formatTanggal(p.jatuh_tempo)))
            + row('Tenor', escapeHtml(p.label_tenor || '—'))
            + row('Bunga', escapeHtml(p.label_bunga || '—'))
            + row('Total + bunga', '<strong>' + formatRupiah(p.total_pinjaman) + '</strong>')
            + row('Angsuran / ' + (p.label_periode || 'periode'), '<strong class="text-success">' + formatRupiah(p.angsuran_per_bulan) + '</strong>')
            + row('Sisa pokok', '<strong class="' + (sisaPokok > 0 ? 'text-warning' : 'text-success') + '">' + formatRupiah(sisaPokok) + '</strong>')
            + row('Sisa bunga', '<strong>' + formatRupiah(sisaBunga) + '</strong>')
            + row('Pokok dibayar', '<span class="text-success">' + formatRupiah(p.total_pokok_dibayar) + '</span>')
            + row('Bunga dibayar', formatRupiah(p.total_bunga_dibayar))
            + row('Denda dibayar', '<span class="text-danger">' + formatRupiah(p.total_denda_dibayar) + '</span>')
            + '</dl>'
            + (Number(p.memerlukan_jaminan)
                ? '<div class="ui-agt-jaminan-note ' + (statusPeng === 'ditolak' ? 'is-bad' : (statusPeng === 'diserahkan' ? 'is-ok' : '')) + ' mt-3 mb-0">'
                + '<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap"><strong><i class="ti ti-package me-1"></i>Pengembalian barang</strong><span class="badge bg-' + warnaPeng + '">' + escapeHtml(labelPeng) + '</span></div>'
                + (statusPeng === 'ditolak'
                    ? '<p class="mb-0 mt-1">Penolakan: ' + (catatan || 'Tidak ada keterangan dari Admin.') + '</p>'
                    : (catatan ? '<p class="mb-0 mt-1">Admin: ' + catatan + '</p>' : ''))
                + '</div>'
                : '');
    }

    document.querySelectorAll('.js-agt-pinjaman-detail').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = String(btn.getAttribute('data-pinjaman-id') || '');
            var p = map[id];
            if (!p) {
                bodyEl.innerHTML = '<div class="alert alert-warning mb-0">Detail pinjaman tidak ditemukan.</div>';
            } else {
                render(p);
            }
            modal.show();
        });
    });
})();
</script>
</body>
</html>

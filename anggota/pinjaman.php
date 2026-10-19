<?php
require_once '../includes/auth.php';
checkAnggota();

$anggota_id = $_SESSION['user_id'];

// Get anggota data
$query_anggota = "SELECT a.* FROM anggota a WHERE a.user_id = :user_id";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->bindParam(':user_id', $anggota_id);
$stmt_anggota->execute();

if ($stmt_anggota->rowCount() == 0) {
    error_log("Data anggota tidak ditemukan untuk user_id: " . $anggota_id);
    die("Data anggota tidak ditemukan");
}

$anggota_data = $stmt_anggota->fetch(PDO::FETCH_ASSOC);

// Get filter parameters
$filter_pinjaman = $_GET['pinjaman_id'] ?? '';
$filter_status = $_GET['status'] ?? '';

// Get pinjaman dengan perhitungan sisa yang benar (termasuk bunga)
$query_pinjaman = "SELECT p.*, jp.nama_jenis, jp.memerlukan_jaminan,
                          (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as angsuran_lunas,
                          (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id) as total_angsuran,
                          (SELECT COALESCE(SUM(pokok), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_pokok_dibayar,
                          (SELECT COALESCE(SUM(bunga), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_bunga_dibayar,
                          (SELECT COALESCE(SUM(denda), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_denda_dibayar,
                          (SELECT COALESCE(SUM(bunga), 0) FROM angsuran WHERE pinjaman_id = p.id AND status != 'lunas') as sisa_bunga
                   FROM pinjaman p 
                   JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id 
                   WHERE p.anggota_id = :anggota_id 
                   ORDER BY p.tanggal_pencairan DESC";
$stmt_pinjaman = $db->prepare($query_pinjaman);
$stmt_pinjaman->bindParam(':anggota_id', $anggota_data['id']);
$stmt_pinjaman->execute();
$pinjaman_list = $stmt_pinjaman->fetchAll(PDO::FETCH_ASSOC);
// Label bunga/tenor dari data pinjaman itu sendiri (bukan tarif jenis saat ini)
foreach ($pinjaman_list as &$pinjaman_row) {
    $pinjaman_row['label_bunga'] = labelBungaRow($pinjaman_row);
    $pinjaman_row['label_tenor'] = labelTenorRow($pinjaman_row);
    $pinjaman_row['label_periode'] = labelPeriode($pinjaman_row['frekuensi']);
}
unset($pinjaman_row);

// Pisahkan pinjaman supaya pinjaman biasa dan gadai tidak tercampur.
$pinjaman_tanpa_jaminan = [];
$pinjaman_jaminan = [];
foreach ($pinjaman_list as $pinjaman_row) {
    if (!empty($pinjaman_row['memerlukan_jaminan'])) {
        $pinjaman_jaminan[] = $pinjaman_row;
    } else {
        $pinjaman_tanpa_jaminan[] = $pinjaman_row;
    }
}

// Get riwayat angsuran
$query_angsuran = "SELECT a.*, p.kode_pinjaman, p.jumlah_pinjaman, jp.nama_jenis as jenis_pinjaman,
                          jp.memerlukan_jaminan
                   FROM angsuran a
                   JOIN pinjaman p ON a.pinjaman_id = p.id
                   JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                   WHERE p.anggota_id = :anggota_id";

if (!empty($filter_pinjaman)) {
    $query_angsuran .= " AND a.pinjaman_id = :pinjaman_id";
}
if (!empty($filter_status)) {
    $query_angsuran .= " AND a.status = :status";
}

$query_angsuran .= " ORDER BY a.tanggal_jatuh_tempo DESC, a.created_at DESC
                    LIMIT 500";

$stmt_angsuran = $db->prepare($query_angsuran);
$stmt_angsuran->bindParam(':anggota_id', $anggota_data['id']);

if (!empty($filter_pinjaman)) {
    $stmt_angsuran->bindParam(':pinjaman_id', $filter_pinjaman);
}
if (!empty($filter_status)) {
    $stmt_angsuran->bindParam(':status', $filter_status);
}

$stmt_angsuran->execute();
$angsuran_list = $stmt_angsuran->fetchAll(PDO::FETCH_ASSOC);

// Get pengajuan pinjaman
$query_pengajuan = "SELECT pp.*, jp.nama_jenis 
                    FROM pengajuan_pinjaman pp 
                    JOIN jenis_pinjaman jp ON pp.jenis_pinjaman_id = jp.id 
                    WHERE pp.anggota_id = :anggota_id 
                    ORDER BY pp.tanggal_pengajuan DESC";
$stmt_pengajuan = $db->prepare($query_pengajuan);
$stmt_pengajuan->bindParam(':anggota_id', $anggota_data['id']);
$stmt_pengajuan->execute();
$pengajuan_list = $stmt_pengajuan->fetchAll(PDO::FETCH_ASSOC);

// Get jenis pinjaman untuk filter
$query_jenis_pinjaman = "SELECT * FROM jenis_pinjaman WHERE status = 'aktif' ORDER BY nama_jenis";
$stmt_jenis = $db->prepare($query_jenis_pinjaman);
$stmt_jenis->execute();
$jenis_pinjaman = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// Status options untuk filter
$status_options = [
    '' => 'Semua Status',
    'lunas' => 'Lunas',
    'belum_bayar' => 'Belum Bayar',
    'telat' => 'Telat'
];

// Fungsi format rupiah
function formatRupiah($angka) {
    if ($angka == 0) {
        return 'Rp 0';
    }
    if ($angka < 0) {
        return '-Rp ' . number_format(abs($angka), 0, ',', '.');
    }
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Level Anggota hanya melihat riwayat; fitur cetak/unduh PDF dihapus (permintaan klien).

/** Tabel riwayat angsuran (dipakai untuk tabel "belum lunas" dan "lunas"). */
function tabelAngsuranAnggota($rows, $pesan_kosong) {
    if (empty($rows)) {
        echo '<div class="text-center py-2"><p class="text-muted mb-0 small">' . htmlspecialchars($pesan_kosong) . '</p></div>';
        return;
    }
    $no = 1; $t_pokok = 0; $t_bunga = 0; $t_denda = 0; $t_jumlah = 0;
    echo '<div class="table-responsive"><table class="table table-sm table-striped table-hover">';
    echo '<thead><tr><th>No</th><th>Kode Pinjaman</th><th>Angsuran Ke</th><th>Jatuh Tempo</th><th>Pokok</th><th>Bunga</th><th>Denda</th><th>Total</th><th>Tanggal Bayar</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $a) {
        $t_pokok += $a['pokok']; $t_bunga += $a['bunga']; $t_denda += $a['denda']; $t_jumlah += $a['jumlah_angsuran'];
        $kelas = $a['status'] == 'lunas' ? 'success' : ($a['status'] == 'telat' ? 'danger' : 'warning');
        echo '<tr>';
        echo '<td>' . $no++ . '</td>';
        echo '<td><code>' . htmlspecialchars($a['kode_pinjaman']) . '</code></td>';
        echo '<td class="text-center">' . (int) $a['angsuran_ke'] . '</td>';
        echo '<td>' . date('d/m/Y', strtotime($a['tanggal_jatuh_tempo'])) . '</td>';
        echo '<td class="text-end">' . formatRupiah($a['pokok']) . '</td>';
        echo '<td class="text-end">' . formatRupiah($a['bunga']) . '</td>';
        echo '<td class="text-end text-danger">' . formatRupiah($a['denda']) . '</td>';
        echo '<td class="text-end fw-bold text-primary">' . formatRupiah($a['jumlah_angsuran']) . '</td>';
        echo '<td>' . ($a['tanggal_bayar'] ? date('d/m/Y', strtotime($a['tanggal_bayar'])) : '-') . '</td>';
        echo '<td><span class="badge bg-' . $kelas . '">' . ucfirst(str_replace('_', ' ', $a['status'])) . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody><tfoot class="table-light"><tr><th colspan="4" class="text-end">TOTAL</th>';
    echo '<th class="text-end">' . formatRupiah($t_pokok) . '</th><th class="text-end">' . formatRupiah($t_bunga) . '</th>';
    echo '<th class="text-end text-danger">' . formatRupiah($t_denda) . '</th><th class="text-end text-primary">' . formatRupiah($t_jumlah) . '</th>';
    echo '<th colspan="2"></th></tr></tfoot></table></div>';
}

// Pisahkan: belum lunas (urut jatuh tempo terdekat) dan lunas (urut pembayaran terbaru)
$angsuran_belum = [];
$angsuran_lunas = [];
$angsuran_tanpa_jaminan_belum = [];
$angsuran_tanpa_jaminan_lunas = [];
$angsuran_jaminan_belum = [];
$angsuran_jaminan_lunas = [];
foreach ($angsuran_list as $row_angsuran) {
    $adalah_jaminan = !empty($row_angsuran['memerlukan_jaminan']);
    if ($row_angsuran['status'] == 'lunas') {
        $angsuran_lunas[] = $row_angsuran;
        if ($adalah_jaminan) {
            $angsuran_jaminan_lunas[] = $row_angsuran;
        } else {
            $angsuran_tanpa_jaminan_lunas[] = $row_angsuran;
        }
    } else {
        $angsuran_belum[] = $row_angsuran;
        if ($adalah_jaminan) {
            $angsuran_jaminan_belum[] = $row_angsuran;
        } else {
            $angsuran_tanpa_jaminan_belum[] = $row_angsuran;
        }
    }
}
usort($angsuran_belum, function ($x, $y) {
    return strcmp($x['tanggal_jatuh_tempo'] . sprintf('%06d', $x['angsuran_ke']), $y['tanggal_jatuh_tempo'] . sprintf('%06d', $y['angsuran_ke']));
});
usort($angsuran_lunas, function ($x, $y) {
    return strcmp($y['tanggal_bayar'] . sprintf('%06d', $y['angsuran_ke']), $x['tanggal_bayar'] . sprintf('%06d', $x['angsuran_ke']));
});
foreach ([&$angsuran_tanpa_jaminan_belum, &$angsuran_jaminan_belum] as &$daftar_belum) {
    usort($daftar_belum, function ($x, $y) {
        return strcmp($x['tanggal_jatuh_tempo'] . sprintf('%06d', $x['angsuran_ke']), $y['tanggal_jatuh_tempo'] . sprintf('%06d', $y['angsuran_ke']));
    });
}
unset($daftar_belum);
foreach ([&$angsuran_tanpa_jaminan_lunas, &$angsuran_jaminan_lunas] as &$daftar_lunas) {
    usort($daftar_lunas, function ($x, $y) {
        return strcmp($y['tanggal_bayar'] . sprintf('%06d', $y['angsuran_ke']), $x['tanggal_bayar'] . sprintf('%06d', $x['angsuran_ke']));
    });
}
unset($daftar_lunas);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pinjaman Saya - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .main-content {
            margin-left: 280px;
            margin-top: 10px;
            padding: 15px;
        }
        .page-header {
            padding-top: 5px;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .pinjaman-card {
            border-left: 4px solid #3498db;
        }
        
        .pinjaman-card.aktif { border-left-color: #27ae60; }
        .pinjaman-card.lunas { border-left-color: #95a5a6; }
        .pinjaman-card.macet { border-left-color: #e74c3c; }
        
        .progress {
            height: 6px;
        }
        
        .detail-table td {
            padding: 8px 5px;
        }
        
        .detail-table tr:last-child td {
            border-bottom: none;
        }
        
        .btn-pdf {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
        }
        .btn-pdf:hover {
            background: linear-gradient(135deg, #c82333, #b21f2d);
            color: white;
        }
        
        .filter-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 60px;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center page-header border-bottom">
                    <h1 class="h3">
                        <i class="fas fa-hand-holding-usd me-2"></i>Pinjaman Saya
                    </h1>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-3">
                    <?php
                    $total_pinjaman = 0;
                    $total_pokok_dibayar = 0;
                    $total_bunga_dibayar = 0;
                    $total_denda_dibayar = 0;
                    $sisa_pinjaman_aktif = 0; // Sisa pokok + bunga yang belum dibayar
                    $total_tagihan_aktif = 0; // Total yang harus dibayar (pokok + bunga)
                    $pinjaman_aktif = 0;
                    $pinjaman_lunas = 0;
                    
                    foreach ($pinjaman_list as $pinjaman) {
                        $total_pinjaman += $pinjaman['jumlah_pinjaman'];
                        
                        // Hitung total yang sudah dibayar
                        $total_pokok_dibayar += $pinjaman['total_pokok_dibayar'];
                        $total_bunga_dibayar += $pinjaman['total_bunga_dibayar'];
                        $total_denda_dibayar += $pinjaman['total_denda_dibayar'];
                        
                        if ($pinjaman['status'] == 'aktif') {
                            $pinjaman_aktif++;
                            // Sisa pokok yang harus dibayar (hanya positif)
                            $sisa_pokok_positif = max(0, $pinjaman['sisa_pokok']);
                            // Sisa bunga (dari angsuran yang belum lunas)
                            $sisa_bunga = $pinjaman['sisa_bunga'];
                            // Total tagihan = sisa pokok + sisa bunga
                            $total_tagihan = $sisa_pokok_positif + $sisa_bunga;
                            
                            $sisa_pinjaman_aktif += $total_tagihan;
                            $total_tagihan_aktif += $total_tagihan;
                        } elseif ($pinjaman['status'] == 'lunas') {
                            $pinjaman_lunas++;
                        }
                    }
                    
                    $total_pembayaran = $total_pokok_dibayar + $total_bunga_dibayar + $total_denda_dibayar;
                    $total_angsuran_data = count($angsuran_list);
                    $total_angsuran_lunas = count(array_filter($angsuran_list, function($a) { return $a['status'] == 'lunas'; }));
                    ?>
                    
                    <div class="col-xl-2 col-md-4 col-6 mb-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="mb-1"><?php echo count($pinjaman_list); ?></h5>
                                        <small>Total Pinjaman</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-hand-holding-usd"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-2 col-md-4 col-6 mb-3">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="mb-1"><?php echo $pinjaman_aktif; ?></h5>
                                        <small>Pinjaman Aktif</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-play-circle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-2 col-md-4 col-6 mb-3">
                        <div class="card stat-card bg-secondary text-white">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="mb-1"><?php echo $pinjaman_lunas; ?></h5>
                                        <small>Pinjaman Lunas</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-2 col-md-4 col-6 mb-3">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="mb-1"><?php echo formatRupiah($total_pinjaman); ?></h5>
                                        <small>Total Dipinjam</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-2 col-md-4 col-6 mb-3">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="mb-1"><?php echo formatRupiah($total_tagihan_aktif); ?></h5>
                                        <small>Sisa Tagihan Aktif</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-credit-card"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-2 col-md-4 col-6 mb-3">
                        <div class="card stat-card bg-danger text-white">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="mb-1"><?php echo formatRupiah($total_pembayaran); ?></h5>
                                        <small>Total Dibayar</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-arrow-up"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Daftar pinjaman dipisah berdasarkan jenis jaminan -->
                    <div class="col-lg-12">
                        <?php
                        $kelompok_pinjaman = [
                            ['judul' => 'Pinjaman Tanpa Jaminan', 'ikon' => 'fa-hand-holding-usd', 'warna' => 'primary', 'data' => $pinjaman_tanpa_jaminan, 'kosong' => 'Belum ada pinjaman tanpa jaminan.'],
                            ['judul' => 'Pinjaman Jaminan / Gadai', 'ikon' => 'fa-shield-alt', 'warna' => 'warning', 'data' => $pinjaman_jaminan, 'kosong' => 'Belum ada pinjaman jaminan atau gadai.']
                        ];
                        foreach ($kelompok_pinjaman as $kelompok):
                        ?>
                        <div class="card mb-3">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas <?php echo $kelompok['ikon']; ?> me-2 text-<?php echo $kelompok['warna']; ?>"></i><?php echo $kelompok['judul']; ?>
                                    <span class="badge bg-<?php echo $kelompok['warna']; ?> ms-2"><?php echo count($kelompok['data']); ?></span>
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <?php if (!empty($kelompok['data'])): ?>
                                    <div class="row">
                                        <?php foreach ($kelompok['data'] as $pinjaman):
                                            $status_class = $pinjaman['status'] == 'aktif' ? 'success' : ($pinjaman['status'] == 'lunas' ? 'secondary' : 'danger');
                                            $pokok_dibayar = $pinjaman['total_pokok_dibayar'];
                                            
                                            $sisa_pokok_tampil = max(0, $pinjaman['sisa_pokok']);
                                            $sisa_bunga_tampil = $pinjaman['sisa_bunga'];
                                            $total_tagihan = $sisa_pokok_tampil + $sisa_bunga_tampil;
                                            
                                            $progress = $pinjaman['total_angsuran'] > 0 ? ($pinjaman['angsuran_lunas'] / $pinjaman['total_angsuran'] * 100) : 0;
                                        ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card pinjaman-card <?php echo $pinjaman['status']; ?> h-100">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <h6 class="mb-1">
                                                                <?php echo $pinjaman['kode_pinjaman']; ?>
                                                                <span class="badge bg-<?php echo $status_class; ?> ms-2">
                                                                    <?php echo ucfirst($pinjaman['status']); ?>
                                                                </span>
                                                            </h6>
                                                            <p class="mb-1 text-muted small">
                                                                <?php echo $pinjaman['nama_jenis']; ?> | 
                                                                Bunga: <?php echo $pinjaman['label_bunga']; ?> | 
                                                                Tenor: <?php echo $pinjaman['label_tenor']; ?>
                                                            </p>
                                                        </div>
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#detailPinjamanModal"
                                                                data-pinjaman='<?php echo htmlspecialchars(json_encode($pinjaman), ENT_QUOTES); ?>'>
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                    
                                                    <div class="row mt-2">
                                                        <div class="col-6">
                                                            <small class="text-muted">Jumlah Pinjaman</small>
                                                            <div class="fw-bold text-primary small"><?php echo formatRupiah($pinjaman['jumlah_pinjaman']); ?></div>
                                                        </div>
                                                        <div class="col-6">
                                                            <small class="text-muted">Angsuran/<?php echo $pinjaman['label_periode']; ?></small>
                                                            <div class="fw-bold text-success small"><?php echo formatRupiah($pinjaman['angsuran_per_bulan']); ?></div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-1">
                                                        <div class="col-4">
                                                            <small class="text-muted">Sisa Pokok</small>
                                                            <div class="fw-bold <?php echo $sisa_pokok_tampil > 0 ? 'text-warning' : 'text-success'; ?> small">
                                                                <?php echo formatRupiah($sisa_pokok_tampil); ?>
                                                            </div>
                                                        </div>
                                                        <div class="col-4">
                                                            <small class="text-muted">Sisa Bunga</small>
                                                            <div class="fw-bold text-info small"><?php echo formatRupiah($sisa_bunga_tampil); ?></div>
                                                        </div>
                                                        <div class="col-4">
                                                            <small class="text-muted">Total Tagihan</small>
                                                            <div class="fw-bold text-danger small"><?php echo formatRupiah($total_tagihan); ?></div>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if ($pinjaman['status'] == 'aktif'): ?>
                                                    <div class="mt-2">
                                                        <small class="text-muted">
                                                            Progress: <?php echo $pinjaman['angsuran_lunas']; ?> / <?php echo $pinjaman['total_angsuran']; ?> angsuran
                                                        </small>
                                                        <div class="progress mt-1">
                                                            <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%"></div>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-3">
                                        <i class="fas <?php echo $kelompok['ikon']; ?> fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0"><?php echo $kelompok['kosong']; ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Riwayat Angsuran: dipisah antara belum lunas dan lunas -->
                        <div class="card mb-3">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-credit-card me-2"></i>Riwayat Angsuran
                                    <span class="badge bg-warning text-dark ms-2"><?php echo count($angsuran_belum); ?> belum lunas</span>
                                    <span class="badge bg-success ms-2"><?php echo count($angsuran_lunas); ?> lunas</span>
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <!-- Filter Angsuran -->
                                <div class="row mb-3">
                                    <div class="col-md-12">
                                        <form method="GET" class="row g-2">
                                            <div class="col-md-4">
                                                <select name="pinjaman_id" class="form-select form-select-sm">
                                                    <option value="">Semua Pinjaman</option>
                                                    <?php foreach ($pinjaman_list as $p): ?>
                                                    <option value="<?php echo $p['id']; ?>" <?php echo $filter_pinjaman == $p['id'] ? 'selected' : ''; ?>>
                                                        <?php echo $p['kode_pinjaman']; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <select name="status" class="form-select form-select-sm">
                                                    <?php foreach ($status_options as $key => $value): ?>
                                                    <option value="<?php echo $key; ?>" <?php echo $filter_status == $key ? 'selected' : ''; ?>>
                                                        <?php echo $value; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <button type="submit" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-filter me-1"></i> Filter
                                                </button>
                                                <a href="pinjaman.php" class="btn btn-sm btn-secondary">
                                                    <i class="fas fa-refresh me-1"></i> Reset
                                                </a>
                                            </div>
                                        </form>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <?php
                        $kelompok_angsuran = [
                            ['judul' => 'Angsuran Pinjaman Tanpa Jaminan', 'ikon' => 'fa-hand-holding-usd', 'warna' => 'primary', 'belum' => $angsuran_tanpa_jaminan_belum, 'lunas' => $angsuran_tanpa_jaminan_lunas],
                            ['judul' => 'Angsuran Pinjaman Jaminan / Gadai', 'ikon' => 'fa-shield-alt', 'warna' => 'warning', 'belum' => $angsuran_jaminan_belum, 'lunas' => $angsuran_jaminan_lunas]
                        ];
                        foreach ($kelompok_angsuran as $kelompok):
                        ?>
                        <div class="card mb-3 border-<?php echo $kelompok['warna']; ?>">
                            <div class="card-header py-2 bg-light">
                                <h6 class="card-title mb-0">
                                    <i class="fas <?php echo $kelompok['ikon']; ?> me-2 text-<?php echo $kelompok['warna']; ?>"></i><?php echo $kelompok['judul']; ?>
                                    <span class="badge bg-warning text-dark ms-2"><?php echo count($kelompok['belum']); ?> belum lunas</span>
                                    <span class="badge bg-success ms-1"><?php echo count($kelompok['lunas']); ?> lunas</span>
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <h6 class="small mb-2"><i class="fas fa-hourglass-half me-1 text-warning"></i>Belum Lunas / Akan Datang</h6>
                                <?php tabelAngsuranAnggota($kelompok['belum'], 'Tidak ada angsuran yang belum lunas pada kelompok ini'); ?>
                                <hr>
                                <h6 class="small mb-2"><i class="fas fa-check-circle me-1 text-success"></i>Sudah Lunas</h6>
                                <?php tabelAngsuranAnggota($kelompok['lunas'], 'Belum ada angsuran lunas pada kelompok ini'); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Pengajuan Pinjaman -->
                        <div class="card mt-3">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-clock me-2"></i>Pengajuan Pinjaman
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <?php if (!empty($pengajuan_list)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Kode Pengajuan</th>
                                                    <th>Jenis</th>
                                                    <th>Jumlah</th>
                                                    <th>Tenor</th>
                                                    <th>Status</th>
                                                    <th>Tanggal</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pengajuan_list as $pengajuan): ?>
                                                <tr>
                                                    <td><small><code><?php echo $pengajuan['kode_pengajuan']; ?></code></small></td>
                                                    <td><small><?php echo $pengajuan['nama_jenis']; ?></small></td>
                                                    <td><small><?php echo formatRupiah($pengajuan['jumlah_pinjaman']); ?></small></td>
                                                    <td><small><?php echo $pengajuan['tenor']; ?> bulan</small></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $pengajuan['status'] == 'disetujui' ? 'success' : ($pengajuan['status'] == 'ditolak' ? 'danger' : 'warning'); ?>">
                                                            <?php echo ucfirst($pengajuan['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><small><?php echo date('d/m/Y H:i', strtotime($pengajuan['tanggal_pengajuan'])); ?></small></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-2">
                                        <i class="fas fa-file-alt fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0 small">Belum ada pengajuan pinjaman</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Informasi -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="alert alert-info py-2">
                            <i class="fas fa-info-circle me-2"></i>
                            <small>
                                <strong>Informasi:</strong> 
                                Angsuran yang belum lunas dan yang sudah lunas ditampilkan di tabel terpisah.
                                Gunakan filter untuk menyesuaikan data yang ditampilkan.
                            </small>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Detail Pinjaman -->
    <div class="modal fade" id="detailPinjamanModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title">Detail Pinjaman</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailPinjamanContent">
                    <!-- Content will be loaded by JavaScript -->
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Detail Pinjaman Modal
        var detailModal = document.getElementById('detailPinjamanModal');
        detailModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var pinjaman = JSON.parse(button.getAttribute('data-pinjaman'));
            
            // Nilai DECIMAL dari JSON berupa string: ubah ke angka agar "+" menjumlah, bukan menyambung teks
            var pokok_dibayar = Number(pinjaman.total_pokok_dibayar) || 0;
            var sisa_pokok = Number(pinjaman.sisa_pokok) > 0 ? Number(pinjaman.sisa_pokok) : 0;
            var sisa_bunga = Number(pinjaman.sisa_bunga) || 0;
            var total_tagihan = sisa_pokok + sisa_bunga;
            
            var status_badge = '';
            if (pinjaman.status == 'aktif') {
                status_badge = '<span class="badge bg-success">Aktif</span>';
            } else if (pinjaman.status == 'lunas') {
                status_badge = '<span class="badge bg-secondary">Lunas</span> <span class="badge bg-success ms-1"><i class="fas fa-check-circle"></i> Lunas</span>';
            } else {
                status_badge = '<span class="badge bg-danger">Macet</span>';
            }
            
            var content = `
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm detail-table">
                            <tr>
                                <td width="40%"><strong>Kode Pinjaman</strong></td>
                                <td>:</td>
                                <td><code>${pinjaman.kode_pinjaman}</code></td>
                            </tr>
                            <tr>
                                <td><strong>Jenis Pinjaman</strong></td>
                                <td>:</td>
                                <td>${pinjaman.nama_jenis}</td>
                            </tr>
                            <tr>
                                <td><strong>Status</strong></td>
                                <td>:</td>
                                <td>${status_badge}</td>
                            </tr>
                            <tr>
                                <td><strong>Tanggal Pencairan</strong></td>
                                <td>:</td>
                                <td>${new Date(pinjaman.tanggal_pencairan).toLocaleDateString('id-ID')}</td>
                            </tr>
                            <tr>
                                <td><strong>Jatuh Tempo</strong></td>
                                <td>:</td>
                                <td>${new Date(pinjaman.jatuh_tempo).toLocaleDateString('id-ID')}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm detail-table">
                            <tr>
                                <td width="40%"><strong>Jumlah Pinjaman</strong></td>
                                <td>:</td>
                                <td class="fw-bold text-primary">${formatRupiah(pinjaman.jumlah_pinjaman)}</td>
                            </tr>
                            <tr>
                                <td><strong>Sisa Pokok</strong></td>
                                <td>:</td>
                                <td class="fw-bold ${sisa_pokok > 0 ? 'text-warning' : 'text-success'}">${formatRupiah(sisa_pokok)}</td>
                            </tr>
                            <tr>
                                <td><strong>Sisa Bunga</strong></td>
                                <td>:</td>
                                <td class="fw-bold text-info">${formatRupiah(sisa_bunga)}</td>
                            </tr>
                            <tr>
                                <td><strong>Total Tagihan</strong></td>
                                <td>:</td>
                                <td class="fw-bold text-danger">${formatRupiah(total_tagihan)}</td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <hr>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="mb-2">Detail Pinjaman</h6>
                        <table class="table table-sm detail-table">
                            <tr>
                                <td width="40%"><strong>Tenor</strong></td>
                                <td>:</td>
                                <td>${pinjaman.label_tenor}</td>
                            </tr>
                            <tr>
                                <td><strong>Bunga</strong></td>
                                <td>:</td>
                                <td>${pinjaman.label_bunga}</td>
                            </tr>
                            <tr>
                                <td><strong>Total Pinjaman + Bunga</strong></td>
                                <td>:</td>
                                <td class="fw-bold">${formatRupiah(pinjaman.total_pinjaman)}</td>
                            </tr>
                            <tr>
                                <td><strong>Angsuran per ${pinjaman.label_periode}</strong></td>
                                <td>:</td>
                                <td class="fw-bold text-success">${formatRupiah(pinjaman.angsuran_per_bulan)}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="mb-2">Pembayaran</h6>
                        <table class="table table-sm detail-table">
                            <tr>
                                <td width="40%"><strong>Pokok Dibayar</strong></td>
                                <td>:</td>
                                <td class="fw-bold text-success">${formatRupiah(pinjaman.total_pokok_dibayar)}</td>
                            </tr>
                            <tr>
                                <td><strong>Bunga Dibayar</strong></td>
                                <td>:</td>
                                <td class="fw-bold text-warning">${formatRupiah(pinjaman.total_bunga_dibayar)}</td>
                            </tr>
                            <tr>
                                <td><strong>Denda Dibayar</strong></td>
                                <td>:</td>
                                <td class="fw-bold text-danger">${formatRupiah(pinjaman.total_denda_dibayar)}</td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <hr>
                
                <div class="row">
                    <div class="col-12">
                        <h6 class="mb-2">Progress Angsuran</h6>
                        <table class="table table-sm detail-table">
                            <tr>
                                <td width="40%"><strong>Angsuran Lunas</strong></td>
                                <td>:</td>
                                <td>${pinjaman.angsuran_lunas} / ${pinjaman.total_angsuran}</td>
                            </tr>
                            <tr>
                                <td><strong>Progress</strong></td>
                                <td>:</td>
                                <td>
                                    <div class="progress">
                                        <div class="progress-bar bg-success" style="width: ${(pinjaman.angsuran_lunas / pinjaman.total_angsuran * 100)}%"></div>
                                    </div>
                                    <small class="text-muted">${Math.round(pinjaman.angsuran_lunas / pinjaman.total_angsuran * 100)}%</small>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            `;
            
            document.getElementById('detailPinjamanContent').innerHTML = content;
        });
        
        function formatRupiah(angka) {
            if (angka < 0) {
                return '-Rp ' + Math.abs(angka).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }
            if (angka == 0) {
                return 'Rp 0';
            }
            return 'Rp ' + angka.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
    </script>
</body>
</html>

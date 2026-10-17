<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

$page_title = "Laporan SHU";

// Get all SHU periods
$periode_query = "SELECT * FROM shu_periode ORDER BY tahun DESC";
$periode_stmt = $db->prepare($periode_query);
$periode_stmt->execute();
$periode_list = $periode_stmt->fetchAll(PDO::FETCH_ASSOC);

// Inisialisasi variabel
$shu_data = [];
$total_shu = 0;
$total_simpanan = 0;
$total_pinjaman = 0;
$total_transaksi = 0;
$total_jasa_simpanan = 0;
$total_jasa_pinjaman = 0;
$total_jasa_transaksi = 0;
$total_belum_bayar = 0;
$total_sudah_bayar = 0;
$jumlah_belum_bayar = 0;
$jumlah_sudah_bayar = 0;
$pengaturan = [];
$periode_info = null;

// Filter parameters
$filter_periode = $_GET['periode_id'] ?? (!empty($periode_list) ? $periode_list[0]['id'] : '');
$filter_tipe = $_GET['tipe'] ?? 'rekap';

// Jika ada periode yang dipilih dan periode_list tidak kosong
if ($filter_periode && !empty($periode_list)) {
    // Get periode info
    foreach ($periode_list as $p) {
        if ($p['id'] == $filter_periode) {
            $periode_info = $p;
            break;
        }
    }

    // Get SHU data for selected periode
    if ($periode_info) {
        $query = "SELECT sa.*, a.kode_anggota, a.nama_lengkap 
                  FROM shu_anggota sa
                  JOIN anggota a ON sa.anggota_id = a.id
                  WHERE sa.shu_periode_id = :periode_id
                  ORDER BY sa.total_shu DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':periode_id', $filter_periode);
        $stmt->execute();
        $shu_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        foreach ($shu_data as $d) {
            $total_shu += $d['total_shu'];
            $total_simpanan += $d['total_simpanan'];
            $total_pinjaman += $d['total_pinjaman'];
            $total_transaksi += $d['total_transaksi'];
            $total_jasa_simpanan += $d['jasa_simpanan'];
            $total_jasa_pinjaman += $d['jasa_pinjaman'];
            $total_jasa_transaksi += $d['jasa_transaksi'];
            
            if ($d['status_bayar'] == 'sudah') {
                $total_sudah_bayar += $d['total_shu'];
                $jumlah_sudah_bayar++;
            } else {
                $total_belum_bayar += $d['total_shu'];
                $jumlah_belum_bayar++;
            }
        }

        // Get pengaturan SHU untuk periode ini
        $pengaturan_query = "SELECT * FROM shu_pengaturan WHERE tahun = :tahun";
        $pengaturan_stmt = $db->prepare($pengaturan_query);
        $pengaturan_stmt->bindParam(':tahun', $periode_info['tahun']);
        $pengaturan_stmt->execute();
        $pengaturan = $pengaturan_stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Get anggota list for filter (tetap diambil meskipun tidak ada periode)
$anggota_query = "SELECT id, kode_anggota, nama_lengkap FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$anggota_stmt = $db->prepare($anggota_query);
$anggota_stmt->execute();
$anggota_list = $anggota_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fungsi format rupiah
function formatRupiah($angka) {
    if ($angka == 0) return 'Rp 0';
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Handle export Excel
if (isset($_GET['export']) && $_GET['export'] == 'excel' && $periode_info) {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=SHU_" . $periode_info['tahun'] . ".xls");
    ?>
    <html>
    <head>
        <title>Laporan SHU <?php echo $periode_info['tahun']; ?></title>
        <style>
            table { border-collapse: collapse; width: 100%; }
            th { background-color: #f2f2f2; }
            th, td { border: 1px solid #000; padding: 5px; }
            .text-end { text-align: right; }
        </style>
    </head>
    <body>
        <h2>LAPORAN SHU TAHUN <?php echo $periode_info['tahun']; ?></h2>
        <p>Periode: <?php echo date('d/m/Y', strtotime($periode_info['tanggal_mulai'])); ?> - <?php echo date('d/m/Y', strtotime($periode_info['tanggal_selesai'])); ?></p>
        <p>Tanggal Cetak: <?php echo date('d/m/Y H:i:s'); ?></p>
        
        <h3>RINGKASAN SHU</h3>
        <table>
            <tr>
                <th>Uraian</th>
                <th class="text-end">Jumlah</th>
            </tr>
            <tr>
                <td>Total SHU</td>
                <td class="text-end"><?php echo formatRupiah($total_shu); ?></td>
            </tr>
            <tr>
                <td>Sudah Dibayar</td>
                <td class="text-end"><?php echo formatRupiah($total_sudah_bayar); ?> (<?php echo $jumlah_sudah_bayar; ?> anggota)</td>
            </tr>
            <tr>
                <td>Belum Dibayar</td>
                <td class="text-end"><?php echo formatRupiah($total_belum_bayar); ?> (<?php echo $jumlah_belum_bayar; ?> anggota)</td>
            </tr>
        </table>
        
        <h3>DETAIL SHU PER ANGGOTA</h3>
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Kode</th>
                    <th>Nama Anggota</th>
                    <th class="text-end">Total Simpanan</th>
                    <th class="text-end">Total Pinjaman</th>
                    <th class="text-end">Total Transaksi</th>
                    <th class="text-end">Jasa Simpanan</th>
                    <th class="text-end">Jasa Pinjaman</th>
                    <th class="text-end">Jasa Transaksi</th>
                    <th class="text-end">Total SHU</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($shu_data as $d): ?>
                <tr>
                    <td><?php echo $no++; ?></td>
                    <td><?php echo $d['kode_anggota']; ?></td>
                    <td><?php echo $d['nama_lengkap']; ?></td>
                    <td class="text-end"><?php echo formatRupiah($d['total_simpanan']); ?></td>
                    <td class="text-end"><?php echo formatRupiah($d['total_pinjaman']); ?></td>
                    <td class="text-end"><?php echo formatRupiah($d['total_transaksi']); ?></td>
                    <td class="text-end"><?php echo formatRupiah($d['jasa_simpanan']); ?></td>
                    <td class="text-end"><?php echo formatRupiah($d['jasa_pinjaman']); ?></td>
                    <td class="text-end"><?php echo formatRupiah($d['jasa_transaksi']); ?></td>
                    <td class="text-end"><?php echo formatRupiah($d['total_shu']); ?></td>
                    <td><?php echo ucfirst($d['status_bayar']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3" class="text-end">TOTAL</th>
                    <th class="text-end"><?php echo formatRupiah($total_simpanan); ?></th>
                    <th class="text-end"><?php echo formatRupiah($total_pinjaman); ?></th>
                    <th class="text-end"><?php echo formatRupiah($total_transaksi); ?></th>
                    <th class="text-end"><?php echo formatRupiah($total_jasa_simpanan); ?></th>
                    <th class="text-end"><?php echo formatRupiah($total_jasa_pinjaman); ?></th>
                    <th class="text-end"><?php echo formatRupiah($total_jasa_transaksi); ?></th>
                    <th class="text-end"><?php echo formatRupiah($total_shu); ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </body>
    </html>
    <?php
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 12px 16px;
            font-weight: 600;
        }
        .card-body {
            padding: 16px;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .summary-box {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            background-color: #f8f9fa;
        }
        .summary-value {
            font-size: 1.5rem;
            font-weight: 600;
        }
        .badge-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        .btn-export {
            min-width: 100px;
        }
        .table-responsive {
            max-height: 500px;
            overflow-y: auto;
        }
        .sticky-header th {
            position: sticky;
            top: 0;
            background-color: #f8f9fa;
            z-index: 10;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3">
                        <i class="fas fa-file-alt me-2"></i>Laporan SHU
                    </h1>
                    <div>
                        <a href="shu.php" class="btn btn-secondary me-2">
                            <i class="fas fa-arrow-left me-1"></i> Kembali
                        </a>
                    </div>
                </div>

                <?php if (empty($periode_list)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Belum ada periode SHU. Silakan buat periode SHU terlebih dahulu di menu 
                    <a href="shu.php" class="alert-link">Manajemen SHU</a>.
                </div>
                <?php else: ?>

                <!-- Filter Form -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-filter me-2"></i>Filter Laporan
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label">Periode SHU</label>
                                <select name="periode_id" class="form-control" onchange="this.form.submit()">
                                    <?php foreach ($periode_list as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" <?php echo $filter_periode == $p['id'] ? 'selected' : ''; ?>>
                                        <?php echo $p['nama_periode']; ?> (<?php echo ucfirst($p['status']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Tipe Laporan</label>
                                <select name="tipe" class="form-control" onchange="this.form.submit()">
                                    <option value="rekap" <?php echo $filter_tipe == 'rekap' ? 'selected' : ''; ?>>Rekap SHU</option>
                                    <option value="detail" <?php echo $filter_tipe == 'detail' ? 'selected' : ''; ?>>Detail Perhitungan</option>
                                    <option value="pembayaran" <?php echo $filter_tipe == 'pembayaran' ? 'selected' : ''; ?>>Status Pembayaran</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <?php if ($periode_info): ?>
                                <a href="?periode_id=<?php echo $filter_periode; ?>&export=excel" class="btn btn-success w-100">
                                    <i class="fas fa-file-excel me-1"></i> Excel
                                </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($periode_info): ?>

                <!-- Info Periode -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="summary-box">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="fw-bold">Periode:</div>
                                    <div><?php echo $periode_info['nama_periode']; ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="fw-bold">Tahun:</div>
                                    <div><?php echo $periode_info['tahun']; ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="fw-bold">Rentang Waktu:</div>
                                    <div><?php echo date('d/m/Y', strtotime($periode_info['tanggal_mulai'])); ?> - <?php echo date('d/m/Y', strtotime($periode_info['tanggal_selesai'])); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="fw-bold">Status:</div>
                                    <div>
                                        <span class="badge <?php 
                                            echo $periode_info['status'] == 'final' ? 'bg-success' : ($periode_info['status'] == 'dihitung' ? 'bg-warning' : 'bg-secondary'); 
                                        ?> badge-status">
                                            <?php echo ucfirst($periode_info['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($filter_tipe == 'rekap'): ?>
                <!-- Tipe Rekap -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="summary-box">
                            <div class="text-muted">Total SHU</div>
                            <div class="summary-value text-primary"><?php echo formatRupiah($total_shu); ?></div>
                            <small><?php echo count($shu_data); ?> anggota</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-box">
                            <div class="text-muted">Sudah Dibayar</div>
                            <div class="summary-value text-success"><?php echo formatRupiah($total_sudah_bayar); ?></div>
                            <small><?php echo $jumlah_sudah_bayar; ?> anggota</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-box">
                            <div class="text-muted">Belum Dibayar</div>
                            <div class="summary-value text-warning"><?php echo formatRupiah($total_belum_bayar); ?></div>
                            <small><?php echo $jumlah_belum_bayar; ?> anggota</small>
                        </div>
                    </div>
                </div>

                <!-- Tabel Rekap -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-list me-2"></i>Rekap SHU per Anggota
                    </div>
                    <div class="card-body">
                        <?php if (empty($shu_data)): ?>
                            <div class="text-center py-4">
                                <p class="text-muted mb-0">Belum ada data SHU untuk periode ini</p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="sticky-header">
                                    <tr>
                                        <th>No</th>
                                        <th>Kode</th>
                                        <th>Nama Anggota</th>
                                        <th class="text-end">Total Simpanan</th>
                                        <th class="text-end">Total Pinjaman</th>
                                        <th class="text-end">Jasa Simpanan</th>
                                        <th class="text-end">Jasa Pinjaman</th>
                                        <th class="text-end">Total SHU</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; foreach ($shu_data as $d): ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo $d['kode_anggota']; ?></td>
                                        <td><?php echo $d['nama_lengkap']; ?></td>
                                        <td class="text-end"><?php echo formatRupiah($d['total_simpanan']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($d['total_pinjaman']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($d['jasa_simpanan']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($d['jasa_pinjaman']); ?></td>
                                        <td class="text-end fw-bold text-success"><?php echo formatRupiah($d['total_shu']); ?></td>
                                        <td class="text-center">
                                            <span class="badge <?php echo $d['status_bayar'] == 'sudah' ? 'bg-success' : 'bg-warning'; ?> badge-status">
                                                <?php echo ucfirst($d['status_bayar']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="3" class="text-end">TOTAL</th>
                                        <th class="text-end"><?php echo formatRupiah($total_simpanan); ?></th>
                                        <th class="text-end"><?php echo formatRupiah($total_pinjaman); ?></th>
                                        <th class="text-end"><?php echo formatRupiah($total_jasa_simpanan); ?></th>
                                        <th class="text-end"><?php echo formatRupiah($total_jasa_pinjaman); ?></th>
                                        <th class="text-end"><?php echo formatRupiah($total_shu); ?></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php elseif ($filter_tipe == 'detail'): ?>
                <!-- Tipe Detail Perhitungan -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-calculator me-2"></i>Detail Perhitungan SHU
                    </div>
                    <div class="card-body">
                        <?php if (empty($shu_data)): ?>
                            <div class="text-center py-4">
                                <p class="text-muted mb-0">Belum ada data SHU untuk periode ini</p>
                            </div>
                        <?php else: ?>
                        <!-- Persentase Pembagian -->
                        <?php if ($pengaturan): ?>
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="mb-3">Persentase Pembagian SHU</h6>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-box">
                                    <div class="fw-bold">Jasa Simpanan</div>
                                    <div class="text-primary"><?php echo $pengaturan['persen_jasa_simpanan']; ?>%</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-box">
                                    <div class="fw-bold">Jasa Pinjaman</div>
                                    <div class="text-primary"><?php echo $pengaturan['persen_jasa_pinjaman']; ?>%</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-box">
                                    <div class="fw-bold">Jasa Transaksi</div>
                                    <div class="text-primary"><?php echo $pengaturan['persen_jasa_transaksi']; ?>%</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-box">
                                    <div class="fw-bold">Cadangan & Lainnya</div>
                                    <div class="text-primary">
                                        <?php 
                                        $total_lain = $pengaturan['persen_cadangan'] + $pengaturan['persen_pengurus'] + 
                                                     $pengaturan['persen_pengawas'] + $pengaturan['persen_dana_sosial'] + 
                                                     $pengaturan['persen_pendidikan'];
                                        echo $total_lain; ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Tabel Detail -->
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="sticky-header">
                                    <tr>
                                        <th>No</th>
                                        <th>Kode</th>
                                        <th>Nama Anggota</th>
                                        <th class="text-end">Total Simpanan</th>
                                        <th class="text-end">Total Pinjaman</th>
                                        <th class="text-end">Total Transaksi</th>
                                        <th class="text-end">Jasa Simpanan</th>
                                        <th class="text-end">Jasa Pinjaman</th>
                                        <th class="text-end">Jasa Transaksi</th>
                                        <th class="text-end">Total SHU</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; foreach ($shu_data as $d): ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo $d['kode_anggota']; ?></td>
                                        <td><?php echo $d['nama_lengkap']; ?></td>
                                        <td class="text-end"><?php echo formatRupiah($d['total_simpanan']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($d['total_pinjaman']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($d['total_transaksi']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($d['jasa_simpanan']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($d['jasa_pinjaman']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($d['jasa_transaksi']); ?></td>
                                        <td class="text-end fw-bold text-success"><?php echo formatRupiah($d['total_shu']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="3" class="text-end">TOTAL</th>
                                        <th class="text-end"><?php echo formatRupiah($total_simpanan); ?></th>
                                        <th class="text-end"><?php echo formatRupiah($total_pinjaman); ?></th>
                                        <th class="text-end"><?php echo formatRupiah($total_transaksi); ?></th>
                                        <th class="text-end"><?php echo formatRupiah($total_jasa_simpanan); ?></th>
                                        <th class="text-end"><?php echo formatRupiah($total_jasa_pinjaman); ?></th>
                                        <th class="text-end"><?php echo formatRupiah($total_jasa_transaksi); ?></th>
                                        <th class="text-end"><?php echo formatRupiah($total_shu); ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php elseif ($filter_tipe == 'pembayaran'): ?>
                <!-- Tipe Status Pembayaran -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <i class="fas fa-check-circle me-2"></i>Sudah Dibayar
                            </div>
                            <div class="card-body">
                                <?php 
                                $sudah_bayar = array_filter($shu_data, function($d) { return $d['status_bayar'] == 'sudah'; });
                                ?>
                                <?php if (empty($sudah_bayar)): ?>
                                <p class="text-muted text-center py-3">Belum ada pembayaran</p>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Kode</th>
                                                <th>Nama</th>
                                                <th class="text-end">Total SHU</th>
                                                <th>Tanggal Bayar</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no = 1; foreach ($sudah_bayar as $d): ?>
                                            <tr>
                                                <td><?php echo $no++; ?></td>
                                                <td><?php echo $d['kode_anggota']; ?></td>
                                                <td><?php echo $d['nama_lengkap']; ?></td>
                                                <td class="text-end text-success"><?php echo formatRupiah($d['total_shu']); ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($d['tanggal_bayar'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-light">
                                                <th colspan="3" class="text-end">TOTAL</th>
                                                <th class="text-end"><?php echo formatRupiah($total_sudah_bayar); ?></th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-warning">
                                <i class="fas fa-clock me-2"></i>Belum Dibayar
                            </div>
                            <div class="card-body">
                                <?php 
                                $belum_bayar = array_filter($shu_data, function($d) { return $d['status_bayar'] == 'belum'; });
                                ?>
                                <?php if (empty($belum_bayar)): ?>
                                <p class="text-muted text-center py-3">Semua sudah dibayar</p>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Kode</th>
                                                <th>Nama</th>
                                                <th class="text-end">Total SHU</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no = 1; foreach ($belum_bayar as $d): ?>
                                            <tr>
                                                <td><?php echo $no++; ?></td>
                                                <td><?php echo $d['kode_anggota']; ?></td>
                                                <td><?php echo $d['nama_lengkap']; ?></td>
                                                <td class="text-end text-warning"><?php echo formatRupiah($d['total_shu']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-light">
                                                <th colspan="3" class="text-end">TOTAL</th>
                                                <th class="text-end"><?php echo formatRupiah($total_belum_bayar); ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Silakan pilih periode SHU untuk melihat laporan.
                </div>
                <?php endif; ?>

                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
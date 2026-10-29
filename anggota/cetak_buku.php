<?php
require_once '../includes/auth.php';
checkAnggota();

$anggota_id = $_SESSION['user_id'];

// Get anggota data
$query_anggota = "SELECT a.* FROM anggota a WHERE a.user_id = :user_id";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->bindParam(':user_id', $anggota_id);
$stmt_anggota->execute();
$anggota_data = $stmt_anggota->fetch(PDO::FETCH_ASSOC);

// Get filter parameters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$jenis_simpanan = $_GET['jenis_simpanan'] ?? '';

// Get all transaksi untuk buku tabungan (HANYA SIMPANAN REAL, EXCLUDE ANG)
$query_transaksi = "SELECT t.*, js.nama_jenis, js.kode_jenis
                    FROM transaksi t 
                    JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id 
                    WHERE t.anggota_id = :anggota_id 
                      AND js.kode_jenis NOT IN ('ANG','BONUS','SHU')";

if (!empty($start_date) && !empty($end_date)) {
    $query_transaksi .= " AND DATE(t.tanggal_transaksi) BETWEEN :start_date AND :end_date";
}
if (!empty($jenis_simpanan)) {
    $query_transaksi .= " AND t.jenis_simpanan_id = :jenis_simpanan";
}

$query_transaksi .= " ORDER BY t.tanggal_transaksi DESC";

$stmt_transaksi = $db->prepare($query_transaksi);
$stmt_transaksi->bindParam(':anggota_id', $anggota_data['id']);

if (!empty($start_date) && !empty($end_date)) {
    $stmt_transaksi->bindParam(':start_date', $start_date);
    $stmt_transaksi->bindParam(':end_date', $end_date);
}
if (!empty($jenis_simpanan)) {
    $stmt_transaksi->bindParam(':jenis_simpanan', $jenis_simpanan);
}

$stmt_transaksi->execute();
$transaksi_list = $stmt_transaksi->fetchAll(PDO::FETCH_ASSOC);

// Get all jenis simpanan for filter
$query_all_jenis = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' AND kode_jenis NOT IN ('ANG','BONUS','SHU') ORDER BY nama_jenis";
$stmt_all_jenis = $db->prepare($query_all_jenis);
$stmt_all_jenis->execute();
$all_jenis = $stmt_all_jenis->fetchAll(PDO::FETCH_ASSOC);

// Get current saldo per jenis
$query_saldo = "SELECT js.id, js.kode_jenis, js.nama_jenis, COALESCE(s.saldo, 0) as saldo
                FROM jenis_simpanan js
                LEFT JOIN simpanan s ON s.jenis_simpanan_id = js.id AND s.anggota_id = :anggota_id
                WHERE js.status = 'aktif' AND js.kode_jenis NOT IN ('ANG','BONUS','SHU')
                ORDER BY js.id";
$stmt_saldo = $db->prepare($query_saldo);
$stmt_saldo->bindParam(':anggota_id', $anggota_data['id']);
$stmt_saldo->execute();
$saldo_list = $stmt_saldo->fetchAll(PDO::FETCH_ASSOC);

// Data saldo per jenis
$saldo = [
    'DEPOSITO' => 0,
    'HAJI' => 0,
    'UMROH' => 0,
    'POKOK' => 0,
    'WAJIB' => 0,
    'SUKARELA' => 0
];

$total_saldo = 0;
foreach ($saldo_list as $s) {
    $saldo[$s['kode_jenis']] = $s['saldo'];
    $total_saldo += $s['saldo'];
}

// Hitung statistik transaksi
$statistik = [
    'total_transaksi' => count($transaksi_list),
    'total_setoran' => 0,
    'total_penarikan' => 0,
    'deposito' => ['setoran' => 0, 'penarikan' => 0, 'transaksi' => 0],
    'haji' => ['setoran' => 0, 'penarikan' => 0, 'transaksi' => 0],
    'umroh' => ['setoran' => 0, 'penarikan' => 0, 'transaksi' => 0]
];

foreach ($transaksi_list as $t) {
    if ($t['jenis_transaksi'] == 'setoran') {
        $statistik['total_setoran'] += $t['jumlah'];
        if ($t['kode_jenis'] == 'DEPOSITO') {
            $statistik['deposito']['setoran'] += $t['jumlah'];
            $statistik['deposito']['transaksi']++;
        } elseif ($t['kode_jenis'] == 'HAJI') {
            $statistik['haji']['setoran'] += $t['jumlah'];
            $statistik['haji']['transaksi']++;
        } elseif ($t['kode_jenis'] == 'UMROH') {
            $statistik['umroh']['setoran'] += $t['jumlah'];
            $statistik['umroh']['transaksi']++;
        }
    } else {
        $statistik['total_penarikan'] += $t['jumlah'];
        if ($t['kode_jenis'] == 'DEPOSITO') {
            $statistik['deposito']['penarikan'] += $t['jumlah'];
            $statistik['deposito']['transaksi']++;
        }
    }
}

// Get koperasi data
$query_koperasi = "SELECT * FROM koperasi LIMIT 1";
$stmt_koperasi = $db->prepare($query_koperasi);
$stmt_koperasi->execute();
$koperasi_data = $stmt_koperasi->fetch(PDO::FETCH_ASSOC);

// Handle PDF download
if (isset($_GET['action']) && $_GET['action'] == 'pdf') {
    require_once '../tcpdf/tcpdf.php';
    
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Koperasi System');
    $pdf->SetAuthor('Anggota');
    $pdf->SetTitle('Buku Tabungan');
    $pdf->SetMargins(10, 15, 10);
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, strtoupper($koperasi_data['nama'] ?? APP_NAME), 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'BUKU TABUNGAN ANGGOTA', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Periode: ' . (empty($start_date) ? 'Semua Transaksi' : date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date))), 0, 1, 'C');
    $pdf->Ln(5);
    
    // Info Anggota
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'DATA ANGGOTA', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(35, 5, 'Kode Anggota', 0, 0, 'L');
    $pdf->Cell(5, 5, ':', 0, 0, 'C');
    $pdf->Cell(70, 5, $anggota_data['kode_anggota'], 0, 0, 'L');
    $pdf->Cell(30, 5, 'Telepon', 0, 0, 'L');
    $pdf->Cell(5, 5, ':', 0, 0, 'C');
    $pdf->Cell(35, 5, $anggota_data['telepon'] ?? '-', 0, 1, 'L');
    
    $pdf->Cell(35, 5, 'Nama Anggota', 0, 0, 'L');
    $pdf->Cell(5, 5, ':', 0, 0, 'C');
    $pdf->Cell(70, 5, $anggota_data['nama_lengkap'], 0, 0, 'L');
    $pdf->Cell(30, 5, 'Tgl Daftar', 0, 0, 'L');
    $pdf->Cell(5, 5, ':', 0, 0, 'C');
    $pdf->Cell(35, 5, date('d/m/Y', strtotime($anggota_data['tanggal_daftar'])), 0, 1, 'L');
    $pdf->Ln(5);
    
    // Saldo Saat Ini
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'SALDO SAAT INI', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(70, 5, 'DEPOSITO: ' . formatRupiah($saldo['DEPOSITO']), 0, 0, 'L');
    $pdf->Cell(70, 5, 'HAJI: ' . formatRupiah($saldo['HAJI']), 0, 0, 'L');
    $pdf->Cell(0, 5, 'UMROH: ' . formatRupiah($saldo['UMROH']), 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'TOTAL: ' . formatRupiah($total_saldo), 0, 1, 'L');
    $pdf->Ln(5);
    
    // Tabel Transaksi (Landscape)
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(220, 220, 220);
    
    // Lebar kolom untuk landscape
    $col_no = 8;
    $col_tgl = 25;
    $col_kode = 30;
    $col_jenis = 20;
    $col_ket = 50;
    $col_setoran = 30;
    $col_penarikan = 30;
    $col_saldo = 30;
    
    $pdf->Cell($col_no, 7, 'No', 1, 0, 'C', 1);
    $pdf->Cell($col_tgl, 7, 'Tanggal', 1, 0, 'C', 1);
    $pdf->Cell($col_kode, 7, 'Kode Transaksi', 1, 0, 'C', 1);
    $pdf->Cell($col_jenis, 7, 'Jenis', 1, 0, 'C', 1);
    $pdf->Cell($col_ket, 7, 'Keterangan', 1, 0, 'C', 1);
    $pdf->Cell($col_setoran, 7, 'Setoran', 1, 0, 'C', 1);
    $pdf->Cell($col_penarikan, 7, 'Penarikan', 1, 0, 'C', 1);
    $pdf->Cell($col_saldo, 7, 'Saldo', 1, 1, 'C', 1);
    
    $pdf->SetFont('helvetica', '', 7);
    $no = 1;
    $total_setoran_pdf = 0;
    $total_penarikan_pdf = 0;
    
    foreach ($transaksi_list as $t) {
        $total_setoran_pdf += ($t['jenis_transaksi'] == 'setoran') ? $t['jumlah'] : 0;
        $total_penarikan_pdf += ($t['jenis_transaksi'] == 'penarikan') ? $t['jumlah'] : 0;
        
        $tanggal = date('d/m/Y', strtotime($t['tanggal_transaksi']));
        $kode = substr($t['kode_transaksi'], 0, 15);
        $keterangan = substr($t['keterangan'] ?? '-', 0, 25);
        
        $pdf->Cell($col_no, 6, $no++, 1, 0, 'C');
        $pdf->Cell($col_tgl, 6, $tanggal, 1, 0, 'C');
        $pdf->Cell($col_kode, 6, $kode, 1, 0, 'C');
        $pdf->Cell($col_jenis, 6, $t['kode_jenis'], 1, 0, 'C');
        $pdf->Cell($col_ket, 6, $keterangan, 1, 0, 'L');
        $pdf->Cell($col_setoran, 6, ($t['jenis_transaksi'] == 'setoran') ? formatRupiah($t['jumlah']) : '-', 1, 0, 'R');
        $pdf->Cell($col_penarikan, 6, ($t['jenis_transaksi'] == 'penarikan') ? formatRupiah($t['jumlah']) : '-', 1, 0, 'R');
        $pdf->Cell($col_saldo, 6, formatRupiah($t['saldo_sesudah']), 1, 1, 'R');
    }
    
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell($col_no+$col_tgl+$col_kode+$col_jenis+$col_ket, 7, 'TOTAL', 1, 0, 'R', 1);
    $pdf->Cell($col_setoran, 7, formatRupiah($total_setoran_pdf), 1, 0, 'R', 1);
    $pdf->Cell($col_penarikan, 7, formatRupiah($total_penarikan_pdf), 1, 0, 'R', 1);
    $pdf->Cell($col_saldo, 7, formatRupiah($total_saldo), 1, 1, 'R', 1);
    
    // Footer
    $pdf->Ln(10);
    $pdf->Cell(0, 6, 'Dicetak pada: ' . date('d/m/Y H:i'), 0, 1, 'L');
    $pdf->Ln(10);
    $pdf->Cell(0, 6, 'Hormat Kami,', 0, 1, 'R');
    $pdf->Ln(15);
    $pdf->Cell(0, 6, $anggota_data['nama_lengkap'], 0, 1, 'R');
    
    $pdf->Output('Buku_Tabungan_' . $anggota_data['kode_anggota'] . '.pdf', 'I');
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Buku Tabungan - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .main-content {
            margin-left:0;
            margin-top: 10px;
            padding: 15px;
        }
        .page-header {
            padding-top: 5px;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .buku-header {
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        .info-card {
            background: #087f83;
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .badge-deposito { background-color: #f39c12; color: white; }
        .badge-haji { background-color: #087f83; color: white; }
        .badge-umroh { background-color: #1abc9c; color: white; }
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            height: 100%;
        }
        .table-transaksi th {
            background-color: #343a40;
            color: white;
            white-space: nowrap;
        }
        .ringkasan-row {
            background-color: #e9ecef;
            font-weight: bold;
        }
        .btn-pdf {
            background:#c0392b;
            color: white;
            border: none;
        }
        .btn-pdf:hover {
            background:#a93226;
            color: white;
        }
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table {
            min-width: 1000px; /* Memastikan tabel tidak mengecil */
        }
        .table th, .table td {
            white-space: nowrap;
            padding: 0.5rem 0.75rem;
        }
        .table td:first-child, .table th:first-child {
            position: sticky;
            left: 0;
            background-color: white;
            z-index: 1;
        }
        .table thead th:first-child {
            background-color: #343a40;
            color: white;
            z-index: 2;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 60px;
            }
            .table td:first-child, .table th:first-child {
                position: static;
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
                
                <?php ui_page_header([
                    'title' => 'Buku Tabungan',
                    'icon' => 'fas fa-book text-primary',
                    'subtitle' => 'Cetak dan unduh buku tabungan',
                    'actions' => '<a href="?action=pdf&start_date=' . urlencode((string)$start_date) . '&end_date=' . urlencode((string)$end_date) . '&jenis_simpanan=' . urlencode((string)$jenis_simpanan) . '" class="btn btn-sm btn-danger" target="_blank"><i class="fas fa-file-pdf me-1"></i>Download PDF</a>'
                        . '<a href="riwayat_simpanan.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Kembali</a>',
                ]); ?>

                <!-- Info Card -->
                <div class="info-card">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="mb-2">Ringkasan Simpanan</h5>
                            <div class="row">
                                <div class="col-md-4">
                                    <small>DEPOSITO</small>
                                    <h6 class="fw-bold"><?php echo formatRupiah($saldo['DEPOSITO']); ?></h6>
                                </div>
                                <div class="col-md-4">
                                    <small>HAJI</small>
                                    <h6 class="fw-bold"><?php echo formatRupiah($saldo['HAJI']); ?></h6>
                                </div>
                                <div class="col-md-4">
                                    <small>UMROH</small>
                                    <h6 class="fw-bold"><?php echo formatRupiah($saldo['UMROH']); ?></h6>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <h4 class="fw-bold"><?php echo formatRupiah($total_saldo); ?></h4>
                            <small>Total Simpanan</small>
                        </div>
                    </div>
                </div>

                <!-- Statistik Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <small class="text-muted">Total Transaksi</small>
                                    <h4 class="mb-0"><?php echo $statistik['total_transaksi']; ?></h4>
                                </div>
                                <i class="fas fa-exchange-alt fa-2x text-primary opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <small class="text-muted">Total Setoran</small>
                                    <h4 class="text-success mb-0"><?php echo formatRupiah($statistik['total_setoran']); ?></h4>
                                </div>
                                <i class="fas fa-arrow-down fa-2x text-success opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <small class="text-muted">Total Penarikan</small>
                                    <h4 class="text-danger mb-0"><?php echo formatRupiah($statistik['total_penarikan']); ?></h4>
                                </div>
                                <i class="fas fa-arrow-up fa-2x text-danger opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <small class="text-muted">Saldo Akhir</small>
                                    <h4 class="text-info mb-0"><?php echo formatRupiah($total_saldo); ?></h4>
                                </div>
                                <i class="fas fa-wallet fa-2x text-info opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="card mb-4">
                    <div class="card-header bg-white py-2">
                        <h6 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Transaksi</h6>
                    </div>
                    <div class="card-body py-2">
                        <form method="GET" class="row g-2">
                            <div class="col-md-3">
                                <input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo $start_date; ?>" placeholder="Dari">
                            </div>
                            <div class="col-md-3">
                                <input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo $end_date; ?>" placeholder="Sampai">
                            </div>
                            <div class="col-md-3">
                                <select name="jenis_simpanan" class="form-select form-select-sm">
                                    <option value="">Semua Jenis</option>
                                    <?php foreach ($all_jenis as $j): ?>
                                    <option value="<?php echo $j['id']; ?>" <?php echo $jenis_simpanan == $j['id'] ? 'selected' : ''; ?>>
                                        <?php echo $j['nama_jenis']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                    <i class="fas fa-search me-1"></i> Tampilkan
                                </button>
                            </div>
                        </form>
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i> Kosongkan tanggal untuk melihat semua transaksi
                        </small>
                    </div>
                </div>

                <!-- Ringkasan per Jenis -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="card border-warning">
                            <div class="card-header bg-warning text-white py-1">
                                <h6 class="mb-0"><i class="fas fa-coins me-2"></i>DEPOSITO</h6>
                            </div>
                            <div class="card-body py-2">
                                <div class="d-flex justify-content-between">
                                    <span>Setoran:</span>
                                    <span class="text-success fw-bold"><?php echo formatRupiah($statistik['deposito']['setoran']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Penarikan:</span>
                                    <span class="text-danger fw-bold"><?php echo formatRupiah($statistik['deposito']['penarikan']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between border-top mt-1 pt-1">
                                    <span>Saldo Akhir:</span>
                                    <span class="text-primary fw-bold"><?php echo formatRupiah($saldo['DEPOSITO']); ?></span>
                                </div>
                                <small class="text-muted"><?php echo $statistik['deposito']['transaksi']; ?> transaksi</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-info">
                            <div class="card-header bg-info text-white py-1">
                                <h6 class="mb-0"><i class="fas fa-mosque me-2"></i>HAJI</h6>
                            </div>
                            <div class="card-body py-2">
                                <div class="d-flex justify-content-between">
                                    <span>Setoran:</span>
                                    <span class="text-success fw-bold"><?php echo formatRupiah($statistik['haji']['setoran']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Penarikan:</span>
                                    <span class="text-danger fw-bold"><?php echo formatRupiah($statistik['haji']['penarikan']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between border-top mt-1 pt-1">
                                    <span>Saldo Akhir:</span>
                                    <span class="text-primary fw-bold"><?php echo formatRupiah($saldo['HAJI']); ?></span>
                                </div>
                                <small class="text-muted"><?php echo $statistik['haji']['transaksi']; ?> transaksi</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-success">
                            <div class="card-header bg-success text-white py-1">
                                <h6 class="mb-0"><i class="fas fa-kaaba me-2"></i>UMROH</h6>
                            </div>
                            <div class="card-body py-2">
                                <div class="d-flex justify-content-between">
                                    <span>Setoran:</span>
                                    <span class="text-success fw-bold"><?php echo formatRupiah($statistik['umroh']['setoran']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Penarikan:</span>
                                    <span class="text-danger fw-bold"><?php echo formatRupiah($statistik['umroh']['penarikan']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between border-top mt-1 pt-1">
                                    <span>Saldo Akhir:</span>
                                    <span class="text-primary fw-bold"><?php echo formatRupiah($saldo['UMROH']); ?></span>
                                </div>
                                <small class="text-muted"><?php echo $statistik['umroh']['transaksi']; ?> transaksi</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabel Transaksi dengan Scroll -->
                <div class="card">
                    <div class="card-header bg-white py-2">
                        <h6 class="mb-0">
                            <i class="fas fa-list me-2"></i>Daftar Transaksi
                            <span class="badge bg-primary ms-2"><?php echo count($transaksi_list); ?> transaksi</span>
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>No</th>
                                        <th>Tanggal</th>
                                        <th>Kode Transaksi</th>
                                        <th>Jenis</th>
                                        <th>Keterangan</th>
                                        <th class="text-end">Setoran</th>
                                        <th class="text-end">Penarikan</th>
                                        <th class="text-end">Saldo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($transaksi_list)): ?>
                                        <?php $no = 1; ?>
                                        <?php foreach ($transaksi_list as $t): ?>
                                        <tr>
                                            <td class="text-center"><?php echo $no++; ?></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($t['tanggal_transaksi'])); ?></td>
                                            <td><code><?php echo $t['kode_transaksi']; ?></code></td>
                                            <td>
                                                <span class="badge <?php 
                                                    echo $t['kode_jenis'] == 'DEPOSITO' ? 'badge-deposito' : 
                                                        ($t['kode_jenis'] == 'HAJI' ? 'badge-haji' : 'badge-umroh'); 
                                                ?>">
                                                    <?php echo $t['kode_jenis']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $t['keterangan'] ?? '-'; ?></td>
                                            <td class="text-end text-success fw-bold">
                                                <?php echo $t['jenis_transaksi'] == 'setoran' ? formatRupiah($t['jumlah']) : '-'; ?>
                                            </td>
                                            <td class="text-end text-danger fw-bold">
                                                <?php echo $t['jenis_transaksi'] == 'penarikan' ? formatRupiah($t['jumlah']) : '-'; ?>
                                            </td>
                                            <td class="text-end fw-bold"><?php echo formatRupiah($t['saldo_sesudah']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="ringkasan-row">
                                            <td colspan="5" class="text-end">TOTAL</td>
                                            <td class="text-end text-success"><?php echo formatRupiah($statistik['total_setoran']); ?></td>
                                            <td class="text-end text-danger"><?php echo formatRupiah($statistik['total_penarikan']); ?></td>
                                            <td class="text-end text-primary"><?php echo formatRupiah($total_saldo); ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-3">
                                                <i class="fas fa-info-circle me-2"></i>Tidak ada transaksi
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Footer Info -->
                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="alert alert-secondary py-2 mb-0">
                            <i class="fas fa-print me-2"></i>
                            <small>
                                <strong>Informasi:</strong> 
                                Total setoran: <?php echo formatRupiah($statistik['total_setoran']); ?>, 
                                Total penarikan: <?php echo formatRupiah($statistik['total_penarikan']); ?>,
                                Saldo akhir: <?php echo formatRupiah($total_saldo); ?>
                            </small>
                        </div>
                    </div>
                    <div class="col-md-6 text-end">
                        <small class="text-muted">
                            Dicetak: <?php echo date('d/m/Y H:i'); ?>
                        </small>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_jenis = $_GET['jenis_transaksi'] ?? '';

// Build query
$query = "SELECT t.*, a.kode_anggota, a.nama_lengkap, 
                 j.nama_jenis, u.nama_lengkap as teller
          FROM transaksi t 
          JOIN anggota a ON t.anggota_id = a.id 
          JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id 
          JOIN users u ON t.user_id = u.id 
          WHERE t.tanggal_transaksi BETWEEN :start_date AND :end_date";

$params = [
    ':start_date' => $start_date . ' 00:00:00',
    ':end_date' => $end_date . ' 23:59:59'
];

if (!empty($filter_jenis)) {
    $query .= " AND t.jenis_transaksi = :jenis_transaksi";
    $params[':jenis_transaksi'] = $filter_jenis;
}

$query .= " ORDER BY t.tanggal_transaksi DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$transaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Jenis transaksi options
$jenis_transaksi_list = [
    '' => 'Semua Transaksi',
    'setoran' => 'Setoran',
    'penarikan' => 'Penarikan'
];

// Calculate totals
$total_setoran = 0;
$total_penarikan = 0;

foreach ($transaksi as $t) {
    if ($t['jenis_transaksi'] == 'setoran') {
        $total_setoran += $t['jumlah'];
    } else {
        $total_penarikan += $t['jumlah'];
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Transaksi - <?php echo APP_NAME; ?></title>
    <link href="../assets/vendor/tabler/tabler.min.css" rel="stylesheet">
    <link href="../assets/vendor/icons/tabler-icons.min.css" rel="stylesheet">
    <style>
        @media print {
            @page {
                size: landscape;
                margin: 0.5cm;
            }
            body {
                padding: 0;
                margin: 0;
                font-size: 11px;
            }
            .no-print {
                display: none !important;
            }
            .table {
                font-size: 10px;
            }
            .header-info {
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 2px solid #333;
            }
        }
        body {
            font-family: Arial, sans-serif;
            padding: 10px;
        }
        .header-info {
            text-align: center;
            margin-bottom: 20px;
        }
        .filter-info {
            margin-bottom: 15px;
            font-size: 12px;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
        }
        .table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .total-row {
            font-weight: bold;
            background-color: #e9ecef;
        }
    </style>
</head>
<body>
    <!-- Header Information -->
    <div class="header-info">
        <h2>LAPORAN TRANSAKSI</h2>
        <h4><?php echo APP_NAME; ?></h4>
        <p>Periode: <?php echo date('d/m/Y H:i:s'); ?></p>
    </div>

    <!-- Filter Information -->
    <div class="filter-info">
        <strong>Periode:</strong> <?php echo date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)); ?>
        
        <?php if (!empty($filter_jenis)): ?>
            | <strong>Jenis Transaksi:</strong> <?php echo $jenis_transaksi_list[$filter_jenis]; ?>
        <?php endif; ?>
        
        <?php if (empty($filter_jenis)): ?>
            | <strong>Jenis Transaksi:</strong> Semua Transaksi
        <?php endif; ?>
    </div>

    <!-- Laporan Table -->
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th width="5%">No</th>
                    <th width="10%">Kode Transaksi</th>
                    <th width="10%">Tanggal</th>
                    <th width="15%">Anggota</th>
                    <th width="10%">Jenis Simpanan</th>
                    <th width="8%">Jenis</th>
                    <th width="10%">Jumlah</th>
                    <th width="10%">Saldo Sebelum</th>
                    <th width="10%">Saldo Sesudah</th>
                    <th width="12%">Teller</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transaksi)): ?>
                    <tr>
                        <td colspan="10" class="text-center">Tidak ada data transaksi</td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; ?>
                    <?php foreach ($transaksi as $t): ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td><?php echo $t['kode_transaksi']; ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($t['tanggal_transaksi'])); ?></td>
                        <td>
                            <div><?php echo $t['kode_anggota']; ?></div>
                            <small class="text-muted"><?php echo $t['nama_lengkap']; ?></small>
                        </td>
                        <td><?php echo $t['nama_jenis']; ?></td>
                        <td>
                            <span class="badge <?php echo $t['jenis_transaksi'] == 'setoran' ? 'bg-success' : 'bg-warning'; ?>">
                                <?php echo ucfirst($t['jenis_transaksi']); ?>
                            </span>
                        </td>
                        <td class="<?php echo $t['jenis_transaksi'] == 'setoran' ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatRupiah($t['jumlah']); ?>
                        </td>
                        <td><?php echo formatRupiah($t['saldo_sebelum']); ?></td>
                        <td><?php echo formatRupiah($t['saldo_sesudah']); ?></td>
                        <td><?php echo $t['teller']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="6" class="text-end"><strong>Total Setoran:</strong></td>
                    <td class="text-success"><?php echo formatRupiah($total_setoran); ?></td>
                    <td colspan="3"></td>
                </tr>
                <tr class="total-row">
                    <td colspan="6" class="text-end"><strong>Total Penarikan:</strong></td>
                    <td class="text-danger"><?php echo formatRupiah($total_penarikan); ?></td>
                    <td colspan="3"></td>
                </tr>
                <tr class="total-row">
                    <td colspan="6" class="text-end"><strong>Selisih:</strong></td>
                    <td class="<?php echo ($total_setoran - $total_penarikan) >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatRupiah($total_setoran - $total_penarikan); ?>
                    </td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Print Button (only visible when not printing) -->
    <div class="no-print text-center mt-3">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="ti ti-printer"></i> Cetak Laporan
        </button>
        <button onclick="window.close()" class="btn btn-secondary">
            <i class="ti ti-x"></i> Tutup
        </button>
    </div>

    <script src="../assets/vendor/tabler/tabler.min.js"></script>
</body>
</html>
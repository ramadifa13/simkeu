<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Set header untuk download Excel
header("Content-type: application/vnd-ms-excel");
header("Content-Disposition: attachment; filename=Laporan_Semua_Transaksi_" . date('Ymd') . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_jenis = $_GET['jenis_transaksi'] ?? '';
$filter_sumber = $_GET['sumber'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';

// Get data koperasi
$query_koperasi = "SELECT * FROM koperasi WHERE id = 1";
$stmt_koperasi = $db->prepare($query_koperasi);
$stmt_koperasi->execute();
$koperasi = $stmt_koperasi->fetch(PDO::FETCH_ASSOC);

if (!$koperasi) {
    $koperasi = [
        'nama' => 'KOPERASI SIMPAN PINJAM',
        'alamat' => 'Jl. Koperasi No. 123',
        'telepon' => '(021) 1234-5678',
        'email' => 'info@koperasi.com'
    ];
}

// ============================================
// AMBIL DATA DARI TABEL
// ============================================

// 1. DATA KAS
$kas = $db->query("SELECT * FROM kas ORDER BY tanggal DESC")->fetchAll(PDO::FETCH_ASSOC);

// 2. DATA PINJAMAN
$pinjaman = $db->query("SELECT p.*, a.nama_lengkap as nama_anggota, a.kode_anggota 
                        FROM pinjaman p 
                        LEFT JOIN anggota a ON p.anggota_id = a.id")->fetchAll(PDO::FETCH_ASSOC);

// 3. DATA TRANSAKSI (untuk setoran dan angsuran)
$transaksi = $db->query("SELECT t.*, a.nama_lengkap, a.kode_anggota, js.nama_jenis 
                        FROM transaksi t 
                        LEFT JOIN anggota a ON t.anggota_id = a.id 
                        LEFT JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id")->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// GABUNGKAN DATA
// ============================================
$all_transaksi = array();

// Data kas (termasuk waserda)
foreach ($kas as $k) {
    $anggota_id = null;
    $nama_anggota = null;
    $kode_anggota = null;
    $tipe = 'Kas Lainnya';
    $jenis_kode = 'KAS';
    $sumber = 'Kas';
    
    // Identifikasi tipe transaksi
    if (strpos($k['keterangan'], 'angsuran') !== false) {
        $tipe = 'Angsuran';
        $jenis_kode = 'ANG';
        $sumber = 'Angsuran';
    } elseif (strpos($k['keterangan'], 'Waserda') !== false) {
        $tipe = 'Waserda';
        $jenis_kode = 'WSR';
        $sumber = 'Waserda';
    } elseif ($k['jumlah'] == 1000000 || $k['jumlah'] == 100000) {
        $tipe = 'Setoran';
        $jenis_kode = 'SET';
        $sumber = 'Tabungan';
    }
    
    // Cari data anggota
    if (!empty($k['transaksi_id'])) {
        foreach ($transaksi as $t) {
            if ($t['id'] == $k['transaksi_id']) {
                $anggota_id = $t['anggota_id'];
                $nama_anggota = $t['nama_lengkap'];
                $kode_anggota = $t['kode_anggota'];
                break;
            }
        }
    }
    
    $tgl = date('Y-m-d', strtotime($k['tanggal']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = array(
            'tanggal' => $k['tanggal'],
            'sumber' => $sumber,
            'kode_transaksi' => 'KAS-' . $k['id'],
            'kode_anggota' => $kode_anggota,
            'nama_anggota' => $nama_anggota,
            'jenis_kode' => $jenis_kode,
            'tipe' => $tipe,
            'keterangan' => $k['keterangan'],
            'jumlah' => $k['jumlah'],
            'jenis' => $k['jenis']
        );
    }
}

// Data pinjaman (PENCAIRAN - INI PENGELUARAN)
foreach ($pinjaman as $p) {
    $tgl = date('Y-m-d', strtotime($p['tanggal_pencairan']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = array(
            'tanggal' => $p['tanggal_pencairan'] . ' 23:59:59',
            'sumber' => 'Pinjaman',
            'kode_transaksi' => $p['kode_pinjaman'],
            'kode_anggota' => $p['kode_anggota'] ?? '64534225',
            'nama_anggota' => $p['nama_anggota'] ?? 'smp2 siak',
            'jenis_kode' => 'PJN',
            'tipe' => 'Pencairan',
            'keterangan' => 'Pencairan Pinjaman',
            'jumlah' => $p['jumlah_pinjaman'],
            'jenis' => 'keluar' // INI PENGELUARAN
        );
    }
}

// Urutkan
usort($all_transaksi, function($a, $b) {
    return strtotime($b['tanggal']) - strtotime($a['tanggal']);
});

// ============================================
// FILTER
// ============================================
$filtered_transaksi = array();

foreach ($all_transaksi as $t) {
    // Filter sumber
    if (!empty($filter_sumber)) {
        $filter_sumber_lower = strtolower($filter_sumber);
        $sumber_lower = strtolower($t['sumber']);
        if ($filter_sumber_lower == 'kas' && $sumber_lower != 'kas' && $sumber_lower != 'waserda' && $sumber_lower != 'tabungan') continue;
        if ($filter_sumber_lower == 'tabungan' && $sumber_lower != 'tabungan') continue;
        if ($filter_sumber_lower == 'waserda' && $sumber_lower != 'waserda') continue;
        if ($filter_sumber_lower == 'pinjaman' && $sumber_lower != 'pinjaman') continue;
        if ($filter_sumber_lower == 'angsuran' && $sumber_lower != 'angsuran') continue;
    }
    
    // Filter jenis
    if (!empty($filter_jenis) && strtolower($t['tipe']) != strtolower($filter_jenis)) {
        continue;
    }
    
    // Filter anggota
    if (!empty($filter_anggota)) {
        if (!empty($t['kode_anggota'])) {
            $stmt = $db->prepare("SELECT id FROM anggota WHERE kode_anggota = :kode");
            $stmt->bindParam(':kode', $t['kode_anggota']);
            $stmt->execute();
            $angg = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$angg || $angg['id'] != $filter_anggota) {
                continue;
            }
        } else {
            continue;
        }
    }
    
    $filtered_transaksi[] = $t;
}

// ============================================
// HITUNG STATISTIK
// ============================================
$total_masuk = 0;
$total_keluar = 0;
$total_setoran = 0;
$total_angsuran = 0;
$total_waserda = 0;
$total_pencairan = 0;
$jumlah_setoran = 0;
$jumlah_angsuran = 0;
$jumlah_waserda = 0;
$jumlah_pencairan = 0;

foreach ($filtered_transaksi as $t) {
    if ($t['jenis'] == 'masuk') {
        $total_masuk += $t['jumlah'];
        if ($t['tipe'] == 'Setoran') {
            $total_setoran += $t['jumlah'];
            $jumlah_setoran++;
        } elseif ($t['tipe'] == 'Angsuran') {
            $total_angsuran += $t['jumlah'];
            $jumlah_angsuran++;
        } elseif ($t['tipe'] == 'Waserda') {
            $total_waserda += $t['jumlah'];
            $jumlah_waserda++;
        }
    } else {
        $total_keluar += $t['jumlah'];
        if ($t['tipe'] == 'Pencairan') {
            $total_pencairan += $t['jumlah'];
            $jumlah_pencairan++;
        }
    }
}

// Ambil saldo akhir dari kas
$saldo_akhir = 0;
$query_saldo = "SELECT saldo_sesudah FROM kas ORDER BY id DESC LIMIT 1";
$stmt_saldo = $db->prepare($query_saldo);
$stmt_saldo->execute();
$saldo_data = $stmt_saldo->fetch(PDO::FETCH_ASSOC);
$saldo_akhir = $saldo_data ? $saldo_data['saldo_sesudah'] : 0;

?>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laporan Semua Transaksi</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; }
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #4CAF50; color: white; font-weight: bold; text-align: center; padding: 6px; }
        td { border: 1px solid #ddd; padding: 4px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .amount-positive { color: green; font-weight: bold; }
        .amount-negative { color: red; font-weight: bold; }
        .kop { text-align: center; margin-bottom: 20px; }
        .kop h2 { margin: 0; padding: 0; }
        .summary-table { width: 70%; margin-bottom: 20px; }
        .footer { margin-top: 30px; }
    </style>
</head>
<body>
    <!-- Kop -->
    <div class="kop">
        <h2><?php echo htmlspecialchars($koperasi['nama']); ?></h2>
        <p><?php echo htmlspecialchars($koperasi['alamat'] ?? ''); ?></p>
        <p>Telp: <?php echo htmlspecialchars($koperasi['telepon'] ?? ''); ?> | Email: <?php echo htmlspecialchars($koperasi['email'] ?? ''); ?></p>
        <hr>
        <h3>LAPORAN SEMUA TRANSAKSI</h3>
        <p>Periode: <?php echo date('d/m/Y', strtotime($start_date)); ?> s/d <?php echo date('d/m/Y', strtotime($end_date)); ?></p>
        <?php if (!empty($filter_sumber)): ?>
        <p>Sumber: <?php echo ucfirst($filter_sumber); ?></p>
        <?php endif; ?>
        <?php if (!empty($filter_jenis)): ?>
        <p>Jenis: <?php echo ucfirst($filter_jenis); ?></p>
        <?php endif; ?>
        <?php if (!empty($filter_anggota)): 
            $stmt_nama = $db->prepare("SELECT nama_lengkap FROM anggota WHERE id = ?");
            $stmt_nama->execute([$filter_anggota]);
            $anggota_nama = $stmt_nama->fetchColumn();
        ?>
        <p>Anggota: <?php echo htmlspecialchars($anggota_nama ?: 'Anggota ID ' . $filter_anggota); ?></p>
        <?php endif; ?>
        <p>Dicetak: <?php echo date('d/m/Y H:i:s'); ?></p>
    </div>

    <!-- RINGKASAN -->
    <h4>RINGKASAN TRANSAKSI</h4>
    <table class="summary-table">
        <tr>
            <td width="50%"><strong>Total Semua Transaksi</strong></td>
            <td class="text-right"><?php echo count($filtered_transaksi); ?> transaksi</td>
        </tr>
        <tr>
            <td><strong>Total Pemasukan</strong></td>
            <td class="text-right amount-positive"><?php echo formatRupiah($total_masuk); ?></td>
        </tr>
        <tr>
            <td><strong>Total Pengeluaran</strong></td>
            <td class="text-right amount-negative"><?php echo formatRupiah($total_keluar); ?></td>
        </tr>
        <tr>
            <td><strong>Selisih Kas</strong></td>
            <td class="text-right <?php echo ($total_masuk - $total_keluar) >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                <?php echo formatRupiah($total_masuk - $total_keluar); ?>
            </td>
        </tr>
        <tr>
            <td><strong>Saldo Akhir Kas</strong></td>
            <td class="text-right"><?php echo formatRupiah($saldo_akhir); ?></td>
        </tr>
    </table>

    <!-- RINCIAN PEMASUKAN -->
    <h4>RINCIAN PEMASUKAN</h4>
    <table style="width: 70%; margin-bottom: 20px;">
        <tr>
            <th width="50%">Jenis Pemasukan</th>
            <th>Jumlah Transaksi</th>
            <th>Total Nominal</th>
        </tr>
        <tr>
            <td>Setoran Tabungan</td>
            <td class="text-center"><?php echo $jumlah_setoran; ?>x</td>
            <td class="text-right amount-positive"><?php echo formatRupiah($total_setoran); ?></td>
        </tr>
        <tr>
            <td>Pembayaran Angsuran</td>
            <td class="text-center"><?php echo $jumlah_angsuran; ?>x</td>
            <td class="text-right amount-positive"><?php echo formatRupiah($total_angsuran); ?></td>
        </tr>
        <tr>
            <td>Waserda</td>
            <td class="text-center"><?php echo $jumlah_waserda; ?>x</td>
            <td class="text-right amount-positive"><?php echo formatRupiah($total_waserda); ?></td>
        </tr>
        <tr style="background-color: #e8f5e9; font-weight: bold;">
            <td>TOTAL PEMASUKAN</td>
            <td class="text-center"><?php echo $jumlah_setoran + $jumlah_angsuran + $jumlah_waserda; ?>x</td>
            <td class="text-right amount-positive"><?php echo formatRupiah($total_masuk); ?></td>
        </tr>
    </table>

    <!-- RINCIAN PENGELUARAN -->
    <h4>RINCIAN PENGELUARAN</h4>
    <table style="width: 70%; margin-bottom: 20px;">
        <tr>
            <th width="50%">Jenis Pengeluaran</th>
            <th>Jumlah Transaksi</th>
            <th>Total Nominal</th>
        </tr>
        <tr>
            <td>Pencairan Pinjaman</td>
            <td class="text-center"><?php echo $jumlah_pencairan; ?>x</td>
            <td class="text-right amount-negative"><?php echo formatRupiah($total_pencairan); ?></td>
        </tr>
        <tr style="background-color: #ffebee; font-weight: bold;">
            <td>TOTAL PENGELUARAN</td>
            <td class="text-center"><?php echo $jumlah_pencairan; ?>x</td>
            <td class="text-right amount-negative"><?php echo formatRupiah($total_keluar); ?></td>
        </tr>
    </table>

    <!-- DETAIL TRANSAKSI -->
    <h4>DETAIL TRANSAKSI</h4>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Tanggal</th>
                <th>Sumber</th>
                <th>Kode</th>
                <th>Kode Anggota</th>
                <th>Nama Anggota</th>
                <th>Jenis</th>
                <th>Keterangan</th>
                <th>Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1; 
            $total_display = 0;
            foreach ($filtered_transaksi as $t): 
                $total_display += $t['jumlah'];
            ?>
            <tr>
                <td class="text-center"><?php echo $no++; ?></td>
                <td><?php echo date('d/m/Y H:i', strtotime($t['tanggal'])); ?></td>
                <td><?php echo $t['sumber']; ?></td>
                <td><?php echo $t['kode_transaksi']; ?></td>
                <td><?php echo $t['kode_anggota'] ?? '-'; ?></td>
                <td><?php echo $t['nama_anggota'] ?? '-'; ?></td>
                <td><?php echo $t['tipe']; ?></td>
                <td><?php echo htmlspecialchars($t['keterangan']); ?></td>
                <td class="text-right <?php echo $t['jenis'] == 'masuk' ? 'amount-positive' : 'amount-negative'; ?>">
                    <?php echo formatRupiah($t['jumlah']); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight: bold; background-color: #f0f0f0;">
                <td colspan="8" class="text-right">TOTAL KESELURUHAN:</td>
                <td class="text-right"><?php echo formatRupiah($total_display); ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Tanda Tangan -->
    <div class="footer">
        <table style="width: 100%; border: none;">
            <tr>
                <td style="border: none; width: 50%;">Mengetahui,</td>
                <td style="border: none;">Hormat Kami,</td>
            </tr>
            <tr>
                <td style="border: none; height: 50px;"></td>
                <td style="border: none;"></td>
            </tr>
            <tr>
                <td style="border: none;">(____________________)</td>
                <td style="border: none;">(____________________)</td>
            </tr>
            <tr>
                <td style="border: none;">Ketua Koperasi</td>
                <td style="border: none;">Teller</td>
            </tr>
        </table>
    </div>

    <!-- Footer Info -->
    <div style="margin-top: 20px; font-size: 9px; color: #666; text-align: center;">
        <p><em>Laporan ini digenerate otomatis pada <?php echo date('d/m/Y H:i:s'); ?></em></p>
    </div>
</body>
</html>
<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Filter parameters - DEFAULT KOSONG (TANPA FILTER)
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$filter_jenis = $_GET['jenis'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';

// Validasi tanggal jika diisi
if (!empty($start_date) && !empty($end_date) && strtotime($start_date) > strtotime($end_date)) {
    die("Tanggal awal tidak boleh lebih besar dari tanggal akhir");
}

// Get data anggota untuk filter (jika diperlukan)
$anggota_nama = '';
if (!empty($filter_anggota)) {
    $query_anggota = "SELECT nama_lengkap, kode_anggota FROM anggota WHERE id = :id";
    $stmt_anggota = $db->prepare($query_anggota);
    $stmt_anggota->bindParam(':id', $filter_anggota);
    $stmt_anggota->execute();
    $anggota = $stmt_anggota->fetch(PDO::FETCH_ASSOC);
    if ($anggota) {
        $anggota_nama = $anggota['nama_lengkap'] . ' (' . $anggota['kode_anggota'] . ')';
    }
}

// ============================================
// AMBIL DATA DARI TABEL KAS (TRANSAKSI SIMPANAN, ANGSURAN & WASERDA)
// ============================================
$query_kas = "SELECT 
    'kas' as sumber,
    k.id as sumber_id,
    k.tanggal,
    k.jenis,
    k.jumlah,
    k.saldo_sebelum,
    k.saldo_sesudah,
    k.keterangan as keterangan_asli,
    t.id as transaksi_id,
    t.kode_transaksi,
    t.jenis_transaksi,
    t.anggota_id,
    a.nama_lengkap as nama_anggota,
    a.kode_anggota,
    js.nama_jenis as nama_simpanan,
    js.kode_jenis,
    u.nama_lengkap as nama_user,
    NULL as pinjaman_id,
    NULL as kode_pinjaman,
    NULL as jenis_pinjaman,
    NULL as pengajuan_id,
    CASE 
        WHEN k.keterangan LIKE '%Waserda%' THEN CONCAT('Waserda - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(k.keterangan, ''), ')')
        WHEN t.keterangan LIKE '%angsuran%' THEN CONCAT('Pembayaran Angsuran - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(t.keterangan, ''), ')')
        WHEN t.jenis_transaksi = 'setoran' THEN CONCAT('Setoran - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(js.nama_jenis, 'Simpanan'), ')')
        WHEN t.jenis_transaksi = 'penarikan' THEN CONCAT('Penarikan - ', COALESCE(a.nama_lengkap, 'Unknown'), ' (', COALESCE(js.nama_jenis, 'Simpanan'), ')')
        ELSE COALESCE(k.keterangan, 'Transaksi Kas')
    END as keterangan_lengkap,
    CASE 
        WHEN k.keterangan LIKE '%Waserda%' THEN 'waserda'
        WHEN t.keterangan LIKE '%angsuran%' THEN 'angsuran'
        WHEN t.jenis_transaksi = 'setoran' THEN 'setoran'
        WHEN t.jenis_transaksi = 'penarikan' THEN 'penarikan'
        ELSE 'lainnya'
    END as tipe_transaksi
FROM kas k 
LEFT JOIN transaksi t ON k.transaksi_id = t.id 
LEFT JOIN anggota a ON t.anggota_id = a.id
LEFT JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id
LEFT JOIN users u ON t.user_id = u.id
WHERE 1=1";

// ============================================
// AMBIL DATA DARI TABEL PINJAMAN (PENCAIRAN)
// ============================================
$query_pinjaman = "SELECT 
    'pinjaman' as sumber,
    p.id as sumber_id,
    CONCAT(p.tanggal_pencairan, ' ', '23:59:59') as tanggal,
    'keluar' as jenis,
    p.jumlah_pinjaman as jumlah,
    0 as saldo_sebelum,
    0 as saldo_sesudah,
    CONCAT('Pencairan Pinjaman - ', a.nama_lengkap) as keterangan_asli,
    NULL as transaksi_id,
    p.kode_pinjaman as kode_transaksi,
    NULL as jenis_transaksi,
    p.anggota_id,
    a.nama_lengkap as nama_anggota,
    a.kode_anggota,
    NULL as nama_simpanan,
    NULL as kode_jenis,
    u.nama_lengkap as nama_user,
    p.id as pinjaman_id,
    p.kode_pinjaman,
    jp.nama_jenis as jenis_pinjaman,
    p.pengajuan_id,
    CONCAT('Pencairan Pinjaman - ', a.nama_lengkap, ' (', jp.nama_jenis, ') - ', p.kode_pinjaman) as keterangan_lengkap,
    'pencairan_pinjaman' as tipe_transaksi
FROM pinjaman p
JOIN anggota a ON p.anggota_id = a.id
JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
LEFT JOIN users u ON p.id = u.id
WHERE 1=1";

// ============================================
// GABUNGKAN KEDUA QUERY
// ============================================
$combined_query = "($query_kas) UNION ALL ($query_pinjaman)";
$params = [];

// Filter berdasarkan tanggal
if (!empty($start_date) && !empty($end_date)) {
    $combined_query = "SELECT * FROM ($combined_query) as semua WHERE DATE(tanggal) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
}

// Filter berdasarkan jenis
if (!empty($filter_jenis)) {
    if (!empty($start_date) && !empty($end_date)) {
        $combined_query .= " AND tipe_transaksi = :jenis";
    } else {
        $combined_query = "SELECT * FROM ($combined_query) as semua WHERE tipe_transaksi = :jenis";
    }
    $params[':jenis'] = $filter_jenis;
}

// Filter berdasarkan anggota
if (!empty($filter_anggota)) {
    if (!empty($start_date) && !empty($end_date) || !empty($filter_jenis)) {
        $combined_query .= " AND anggota_id = :anggota_id";
    } else {
        $combined_query = "SELECT * FROM ($combined_query) as semua WHERE anggota_id = :anggota_id";
    }
    $params[':anggota_id'] = $filter_anggota;
}

// Urutkan dari yang terlama ke terbaru (untuk perhitungan saldo yang benar)
if (strpos($combined_query, 'ORDER BY') === false) {
    $combined_query .= " ORDER BY tanggal ASC, sumber_id ASC";
}

$stmt = $db->prepare($combined_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$kas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung total per jenis (TERMASUK WASERDA)
$total_masuk = 0;
$total_keluar = 0;
$total_setoran = 0;
$total_penarikan = 0;
$total_angsuran = 0;
$total_pencairan = 0;
$total_waserda = 0;

// Ambil saldo awal (sebelum transaksi pertama)
$saldo_awal = 0;
if (!empty($kas)) {
    $saldo_awal = $kas[0]['saldo_sebelum'];
}

foreach ($kas as $k) {
    if ($k['jenis'] == 'masuk') {
        $total_masuk += $k['jumlah'];
        if ($k['tipe_transaksi'] == 'angsuran') {
            $total_angsuran += $k['jumlah'];
        } elseif ($k['tipe_transaksi'] == 'setoran') {
            $total_setoran += $k['jumlah'];
        } elseif ($k['tipe_transaksi'] == 'waserda') {
            $total_waserda += $k['jumlah'];
        }
    } else {
        $total_keluar += $k['jumlah'];
        if ($k['tipe_transaksi'] == 'penarikan') {
            $total_penarikan += $k['jumlah'];
        } elseif ($k['tipe_transaksi'] == 'pencairan_pinjaman') {
            $total_pencairan += $k['jumlah'];
        }
    }
}

$saldo_akhir = $saldo_awal + $total_masuk - $total_keluar;

// Ambil data koperasi untuk header
$query_koperasi = "SELECT * FROM koperasi WHERE id = 1";
$stmt_koperasi = $db->prepare($query_koperasi);
$stmt_koperasi->execute();
$koperasi = $stmt_koperasi->fetch(PDO::FETCH_ASSOC);
$nama_koperasi = $koperasi['nama'] ?? APP_NAME;
$alamat_koperasi = $koperasi['alamat'] ?? '-';
$telepon_koperasi = $koperasi['telepon'] ?? '-';
$email_koperasi = $koperasi['email'] ?? '-';

// Set filename
$filename = 'Laporan_Kas_' . date('Ymd_His') . '.csv';
if (!empty($start_date) && !empty($end_date)) {
    $filename = 'Laporan_Kas_' . $start_date . '_' . $end_date . '.csv';
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Fungsi untuk menulis CSV dengan delimiter ;
function writeCsvLine($output, $data) {
    $processed = array_map(function($field) {
        if (strpos($field, ';') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
            $field = str_replace('"', '""', $field);
            return '"' . $field . '"';
        }
        return $field;
    }, $data);
    
    fputcsv($output, $processed, ';');
}

// ==================== HEADER KOPERASI ====================
writeCsvLine($output, ['LAPORAN KAS LENGKAP']);
writeCsvLine($output, ['KOPERASI', $nama_koperasi]);
writeCsvLine($output, ['ALAMAT', $alamat_koperasi]);
writeCsvLine($output, ['TELEPON', $telepon_koperasi]);
writeCsvLine($output, ['EMAIL', $email_koperasi]);
writeCsvLine($output, []);

// ==================== INFORMASI LAPORAN ====================
writeCsvLine($output, ['INFORMASI LAPORAN']);
if (!empty($start_date) && !empty($end_date)) {
    writeCsvLine($output, ['Periode', date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date))]);
} else {
    writeCsvLine($output, ['Periode', 'Semua Transaksi']);
}
if (!empty($filter_jenis)) {
    $jenis_labels = [
        'setoran' => 'Setoran Simpanan',
        'penarikan' => 'Penarikan Simpanan',
        'angsuran' => 'Pembayaran Angsuran',
        'pencairan_pinjaman' => 'Pencairan Pinjaman',
        'waserda' => 'Waserda'
    ];
    writeCsvLine($output, ['Filter Jenis', $jenis_labels[$filter_jenis] ?? $filter_jenis]);
}
if (!empty($anggota_nama)) {
    writeCsvLine($output, ['Filter Anggota', $anggota_nama]);
}
writeCsvLine($output, ['Tanggal Export', date('d/m/Y H:i:s')]);
writeCsvLine($output, ['Diekspor Oleh', $_SESSION['nama_lengkap'] . ' (' . $_SESSION['role'] . ')']);
writeCsvLine($output, ['Total Transaksi', count($kas) . ' transaksi']);
writeCsvLine($output, []);

// ==================== RINGKASAN KAS ====================
writeCsvLine($output, ['RINGKASAN KAS']);
writeCsvLine($output, ['Total Pemasukan', 'Rp ' . number_format($total_masuk, 0, ',', '.')]);
writeCsvLine($output, ['Total Pengeluaran', 'Rp ' . number_format($total_keluar, 0, ',', '.')]);
writeCsvLine($output, ['Saldo Awal', 'Rp ' . number_format($saldo_awal, 0, ',', '.')]);
writeCsvLine($output, ['Saldo Akhir', 'Rp ' . number_format($saldo_akhir, 0, ',', '.')]);
writeCsvLine($output, []);

// ==================== RINCIAN PER JENIS ====================
writeCsvLine($output, ['RINCIAN PER JENIS TRANSAKSI']);
writeCsvLine($output, ['Setoran Simpanan', 'Rp ' . number_format($total_setoran, 0, ',', '.')]);
writeCsvLine($output, ['Penarikan Simpanan', 'Rp ' . number_format($total_penarikan, 0, ',', '.')]);
writeCsvLine($output, ['Pembayaran Angsuran', 'Rp ' . number_format($total_angsuran, 0, ',', '.')]);
writeCsvLine($output, ['Waserda', 'Rp ' . number_format($total_waserda, 0, ',', '.')]);
writeCsvLine($output, ['Pencairan Pinjaman', 'Rp ' . number_format($total_pencairan, 0, ',', '.')]);
writeCsvLine($output, []);

// ==================== HEADER KOLOM ====================
$headers = [
    'No',
    'Tanggal',
    'Keterangan',
    'Kode Transaksi',
    'Jenis',
    'Tipe Transaksi',
    'Jumlah (Rp)',
    'Saldo Sebelum (Rp)',
    'Saldo Sesudah (Rp)',
    'Anggota',
    'Kode Anggota',
    'Jenis Simpanan/Pinjaman',
    'Sumber',
    'Petugas'
];
writeCsvLine($output, $headers);

// ==================== DATA TRANSAKSI ====================
if (empty($kas)) {
    writeCsvLine($output, ['1', '', '', '', '', '', '', '', '', '', '', '', 'TIDAK ADA DATA', '']);
} else {
    $no = 1;
    foreach ($kas as $k) {
        // Format tipe transaksi
        $tipe_transaksi_label = '';
        switch ($k['tipe_transaksi']) {
            case 'setoran':
                $tipe_transaksi_label = 'Setoran';
                break;
            case 'penarikan':
                $tipe_transaksi_label = 'Penarikan';
                break;
            case 'angsuran':
                $tipe_transaksi_label = 'Angsuran';
                break;
            case 'waserda':
                $tipe_transaksi_label = 'Waserda';
                break;
            case 'pencairan_pinjaman':
                $tipe_transaksi_label = 'Pencairan Pinjaman';
                break;
            default:
                $tipe_transaksi_label = 'Lainnya';
        }
        
        // Format sumber
        $sumber_label = $k['sumber'] == 'kas' ? 'Kas' : ($k['sumber'] == 'pinjaman' ? 'Pinjaman' : 'Waserda');
        
        // Format jenis simpanan/pinjaman
        $jenis_detail = '-';
        if (!empty($k['nama_simpanan'])) {
            $jenis_detail = $k['nama_simpanan'];
        } elseif (!empty($k['jenis_pinjaman'])) {
            $jenis_detail = $k['jenis_pinjaman'] . ' - ' . $k['kode_pinjaman'];
        } elseif ($k['tipe_transaksi'] == 'waserda') {
            $jenis_detail = 'Waserda';
        }
        
        $row = [
            $no++,
            date('d/m/Y H:i', strtotime($k['tanggal'])),
            $k['keterangan_lengkap'] ?: $k['keterangan_asli'] ?: 'Transaksi Kas',
            $k['kode_transaksi'] ?: '-',
            ucfirst($k['jenis']),
            $tipe_transaksi_label,
            $k['jumlah'],
            $k['saldo_sebelum'],
            $k['saldo_sesudah'],
            $k['nama_anggota'] ?: '-',
            $k['kode_anggota'] ?: '-',
            $jenis_detail,
            $sumber_label,
            $k['nama_user'] ?: '-'
        ];
        writeCsvLine($output, $row);
    }
}

// ==================== STATISTIK ====================
writeCsvLine($output, []);
writeCsvLine($output, ['STATISTIK TRANSAKSI']);
writeCsvLine($output, ['Total Transaksi Masuk', count(array_filter($kas, function($k) { return $k['jenis'] == 'masuk'; })) . ' transaksi']);
writeCsvLine($output, ['Total Transaksi Keluar', count(array_filter($kas, function($k) { return $k['jenis'] == 'keluar'; })) . ' transaksi']);

if (!empty($start_date) && !empty($end_date)) {
    $hari = (strtotime($end_date) - strtotime($start_date)) / 86400 + 1;
    $rata2 = count($kas) > 0 ? round(count($kas) / $hari, 1) : 0;
    writeCsvLine($output, ['Rata-rata Transaksi', $rata2 . ' transaksi/hari']);
}
writeCsvLine($output, []);

// ==================== RINCIAN PER SUMBER ====================
$count_kas = count(array_filter($kas, function($k) { return $k['sumber'] == 'kas'; }));
$count_pinjaman = count(array_filter($kas, function($k) { return $k['sumber'] == 'pinjaman'; }));
$count_waserda = count(array_filter($kas, function($k) { return $k['tipe_transaksi'] == 'waserda'; }));

writeCsvLine($output, ['RINCIAN PER SUMBER']);
writeCsvLine($output, ['Transaksi dari Kas', $count_kas . ' transaksi']);
writeCsvLine($output, ['Transaksi dari Pinjaman', $count_pinjaman . ' transaksi']);
writeCsvLine($output, ['Transaksi Waserda', $count_waserda . ' transaksi']);
writeCsvLine($output, []);

// ==================== FOOTER ====================
writeCsvLine($output, []);
writeCsvLine($output, ['CATATAN:']);
writeCsvLine($output, ['- File ini diekspor dari sistem ' . APP_NAME . ' pada ' . date('d/m/Y H:i:s')]);
if (!empty($start_date) && !empty($end_date)) {
    writeCsvLine($output, ['- Data berdasarkan periode ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date))]);
} else {
    writeCsvLine($output, ['- Data berdasarkan semua transaksi (tanpa filter tanggal)']);
}
writeCsvLine($output, ['- Data mencakup: Setoran, Penarikan, Angsuran, Waserda, dan Pencairan Pinjaman']);
writeCsvLine($output, ['- Pencairan Pinjaman diambil langsung dari tabel pinjaman']);
writeCsvLine($output, ['- Waserda diambil dari tabel kas']);
writeCsvLine($output, ['- Pemisah yang digunakan adalah titik koma (;)']);
writeCsvLine($output, ['- Untuk membuka di Excel: Data > From Text/CSV, pilih delimiter ;']);

fclose($output);
exit;
?>
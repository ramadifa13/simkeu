<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    die('Method not allowed');
}

// Get filter parameters (sama seperti di anggota.php)
$filter_status = $_GET['status'] ?? '';
$filter_search = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));

// Build query with filters
$query = "SELECT 
    a.kode_anggota,
    a.nama_lengkap,
    a.alamat,
    a.telepon,
    a.email,
    a.tempat_lahir,
    a.tanggal_lahir,
    a.jenis_kelamin,
    a.pekerjaan,
    a.ktp,
    a.rekening_bank,
    a.status,
    a.tanggal_daftar,
    a.created_at,
    (SELECT SUM(saldo) FROM simpanan WHERE anggota_id = a.id) as total_simpanan,
    (SELECT COUNT(*) FROM pinjaman WHERE anggota_id = a.id AND status = 'aktif') as pinjaman_aktif,
    (SELECT COALESCE(SUM(sisa_pokok), 0) FROM pinjaman WHERE anggota_id = a.id AND status = 'aktif') as sisa_pinjaman
    FROM anggota a 
    WHERE 1=1";

$params = [];

if (!empty($filter_status)) {
    $query .= " AND a.status = :status";
    $params[':status'] = $filter_status;
}

if (!empty($filter_search)) {
    $query .= " AND (a.kode_anggota LIKE :search_kode OR a.nama_lengkap LIKE :search_nama OR a.telepon LIKE :search_telepon OR a.ktp LIKE :search_ktp)";
    $nilai_search = "%$filter_search%";
    $params += [':search_kode'=>$nilai_search, ':search_nama'=>$nilai_search, ':search_telepon'=>$nilai_search, ':search_ktp'=>$nilai_search];
}

$query .= " ORDER BY a.created_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$anggota_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get koperasi info
$query_koperasi = "SELECT nama, alamat, telepon, email FROM koperasi WHERE id = 1";
$stmt_koperasi = $db->prepare($query_koperasi);
$stmt_koperasi->execute();
$koperasi = $stmt_koperasi->fetch(PDO::FETCH_ASSOC);
$nama_koperasi = $koperasi['nama'] ?? 'Koperasi';
$alamat_koperasi = $koperasi['alamat'] ?? '-';
$telepon_koperasi = $koperasi['telepon'] ?? '-';
$email_koperasi = $koperasi['email'] ?? '-';

// Set filename
$filename = 'Data_Anggota_' . date('Y-m-d_H-i-s') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Function to write CSV with semicolon delimiter
function writeCsvLine($output, $data) {
    // Pastikan semua data diproses dengan benar
    $processed = array_map(function($field) {
        // Jika field mengandung titik koma, kutip, atau newline, bungkus dengan tanda kutip
        if (strpos($field, ';') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
            // Ganti kutip ganda dengan dua kutip
            $field = str_replace('"', '""', $field);
            return '"' . $field . '"';
        }
        return $field;
    }, $data);
    
    fputcsv($output, $processed, ';');
}

// ==================== HEADER KOPERASI ====================
writeCsvLine($output, ['LAPORAN DATA ANGGOTA']);
writeCsvLine($output, ['KOPERASI', $nama_koperasi]);
writeCsvLine($output, ['ALAMAT', $alamat_koperasi]);
writeCsvLine($output, ['TELEPON', $telepon_koperasi]);
writeCsvLine($output, ['EMAIL', $email_koperasi]);
writeCsvLine($output, []); // Baris kosong

// ==================== INFORMASI EXPORT ====================
writeCsvLine($output, ['INFORMASI EXPORT']);
writeCsvLine($output, ['Tanggal Export', date('d/m/Y H:i:s')]);
writeCsvLine($output, ['Diekspor Oleh', $_SESSION['nama_lengkap'] . ' (' . $_SESSION['role'] . ')']);
writeCsvLine($output, ['Status Filter', $filter_status ? ucfirst($filter_status) : 'Semua Status']);
writeCsvLine($output, ['Pencarian', $filter_search ?: '-']);
writeCsvLine($output, ['Total Data', count($anggota_list) . ' Anggota']);
writeCsvLine($output, []); // Baris kosong

// ==================== HEADER KOLOM ====================
$headers = [
    'NO',
    'KODE ANGGOTA',
    'NAMA LENGKAP',
    'JENIS KELAMIN',
    'TEMPAT LAHIR',
    'TANGGAL LAHIR',
    'ALAMAT',
    'TELEPON',
    'EMAIL',
    'PEKERJAAN',
    'NO. KTP',
    'NO. REKENING',
    'STATUS',
    'TANGGAL DAFTAR',
    'TOTAL SIMPANAN (Rp)',
    'JUMLAH PINJAMAN AKTIF',
    'SISA PINJAMAN (Rp)',
    'TERDAFTAR PADA'
];
writeCsvLine($output, $headers);

// ==================== DATA ANGGOTA ====================
if (empty($anggota_list)) {
    writeCsvLine($output, ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'TIDAK ADA DATA']);
} else {
    foreach ($anggota_list as $index => $anggota) {
        // Format data
        $jenis_kelamin = $anggota['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan';
        $tanggal_lahir = $anggota['tanggal_lahir'] ? date('d/m/Y', strtotime($anggota['tanggal_lahir'])) : '-';
        $tanggal_daftar = $anggota['tanggal_daftar'] ? date('d/m/Y', strtotime($anggota['tanggal_daftar'])) : '-';
        $created_at = date('d/m/Y H:i:s', strtotime($anggota['created_at']));
        
        // Format angka tanpa desimal untuk tampilan lebih rapi
        $total_simpanan = number_format($anggota['total_simpanan'] ?? 0, 0, ',', '.');
        $sisa_pinjaman = number_format($anggota['sisa_pinjaman'] ?? 0, 0, ',', '.');
        
        // Status dengan format yang lebih baik
        $status = $anggota['status'] == 'aktif' ? 'AKTIF' : 'NONAKTIF';
        
        $row = [
            $index + 1,
            $anggota['kode_anggota'],
            $anggota['nama_lengkap'],
            $jenis_kelamin,
            $anggota['tempat_lahir'] ?: '-',
            $tanggal_lahir,
            $anggota['alamat'] ?: '-',
            $anggota['telepon'] ?: '-',
            $anggota['email'] ?: '-',
            $anggota['pekerjaan'] ?: '-',
            "'" . $anggota['ktp'] . "'", // Tambahkan kutip di depan untuk mencegah format ilmiah di Excel
            $anggota['rekening_bank'] ? "'" . $anggota['rekening_bank'] . "'" : '-', // Untuk nomor rekening
            $status,
            $tanggal_daftar,
            $total_simpanan,
            $anggota['pinjaman_aktif'] ?? 0,
            $sisa_pinjaman,
            $created_at
        ];
        writeCsvLine($output, $row);
    }
}

// ==================== RINGKASAN ====================
writeCsvLine($output, []); // Baris kosong
writeCsvLine($output, ['RINGKASAN DATA']);
writeCsvLine($output, []); // Baris kosong

// Hitung statistik
$total_anggota = count($anggota_list);
$total_aktif = count(array_filter($anggota_list, function($a) { return $a['status'] == 'aktif'; }));
$total_nonaktif = count(array_filter($anggota_list, function($a) { return $a['status'] == 'nonaktif'; }));
$total_simpanan_all = array_sum(array_column($anggota_list, 'total_simpanan'));
$total_pinjaman_all = array_sum(array_column($anggota_list, 'sisa_pinjaman'));
$total_pinjaman_aktif = array_sum(array_column($anggota_list, 'pinjaman_aktif'));

writeCsvLine($output, ['STATISTIK', 'NILAI']);
writeCsvLine($output, ['Total Anggota', $total_anggota . ' Orang']);
writeCsvLine($output, ['Anggota Aktif', $total_aktif . ' Orang']);
writeCsvLine($output, ['Anggota Nonaktif', $total_nonaktif . ' Orang']);
writeCsvLine($output, ['Total Simpanan', 'Rp ' . number_format($total_simpanan_all, 0, ',', '.')]);
writeCsvLine($output, ['Total Pinjaman Aktif', $total_pinjaman_aktif . ' Pinjaman']);
writeCsvLine($output, ['Total Sisa Pinjaman', 'Rp ' . number_format($total_pinjaman_all, 0, ',', '.')]);

// ==================== RATA-RATA ====================
writeCsvLine($output, []); // Baris kosong
if ($total_anggota > 0) {
    $rata_simpanan = $total_simpanan_all / $total_anggota;
    $rata_pinjaman = $total_pinjaman_all / $total_anggota;
    
    writeCsvLine($output, ['RATA-RATA PER ANGGOTA']);
    writeCsvLine($output, ['Rata-rata Simpanan', 'Rp ' . number_format($rata_simpanan, 0, ',', '.')]);
    writeCsvLine($output, ['Rata-rata Sisa Pinjaman', 'Rp ' . number_format($rata_pinjaman, 0, ',', '.')]);
}

// ==================== FOOTER ====================
writeCsvLine($output, []); // Baris kosong
writeCsvLine($output, []); // Baris kosong
writeCsvLine($output, ['CATATAN:']);
writeCsvLine($output, ['- File ini diekspor dari sistem ' . APP_NAME . ' pada ' . date('d/m/Y H:i:s')]);
writeCsvLine($output, ['- Data berdasarkan filter yang diterapkan']);
writeCsvLine($output, ['- Untuk nomor KTP dan Rekening, gunakan format TEXT di Excel agar tidak berubah']);
writeCsvLine($output, ['- Pemisah yang digunakan adalah titik koma (;)']);

// Close output stream
fclose($output);
exit;
?>

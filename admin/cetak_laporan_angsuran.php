<?php
// Start session jika belum
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include config dan auth dengan path yang benar
require_once '../config/config.php';
require_once '../includes/auth.php';

// Load TCPDF
require_once '../tcpdf/tcpdf.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

// Check if user has access to this page
if (!isAdmin() && !isTeller()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-1 month'));
$end_date = $_GET['end_date'] ?? date('Y-m-d', strtotime('+6 months'));
$filter_status = $_GET['status'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_pinjaman = $_GET['pinjaman_id'] ?? '';

// Get data for filters
$anggota_info = '';
if (!empty($filter_anggota)) {
    $query_anggota = "SELECT nama_lengkap FROM anggota WHERE id = :id";
    $stmt = $db->prepare($query_anggota);
    $stmt->bindParam(':id', $filter_anggota);
    $stmt->execute();
    $anggota = $stmt->fetch(PDO::FETCH_ASSOC);
    $anggota_info = $anggota ? $anggota['nama_lengkap'] : '';
}

$status_info = '';
if (!empty($filter_status)) {
    $status_list = [
        'belum_bayar' => 'Belum Bayar',
        'lunas' => 'Lunas',
        'telat' => 'Telat'
    ];
    $status_info = $status_list[$filter_status] ?? '';
}

$pinjaman_info = '';
if (!empty($filter_pinjaman)) {
    $query_pinjaman = "SELECT kode_pinjaman FROM pinjaman WHERE id = :id";
    $stmt = $db->prepare($query_pinjaman);
    $stmt->bindParam(':id', $filter_pinjaman);
    $stmt->execute();
    $pinjaman = $stmt->fetch(PDO::FETCH_ASSOC);
    $pinjaman_info = $pinjaman ? $pinjaman['kode_pinjaman'] : '';
}

// Get koperasi info
$query_koperasi = "SELECT * FROM koperasi WHERE id = 1";
$stmt_koperasi = $db->prepare($query_koperasi);
$stmt_koperasi->execute();
$koperasi = $stmt_koperasi->fetch(PDO::FETCH_ASSOC);

// Jika tidak ada data koperasi, gunakan default
if (!$koperasi) {
    $koperasi = [
        'nama' => 'Koperasi Simpan Pinjam Sejahtera',
        'alamat' => 'Jl. Koperasi No. 123, Jakarta',
        'telepon' => '(021) 123-4567',
        'email' => 'info@koperasi.com',
        'logo' => null
    ];
}

// Build query untuk ambil data angsuran
$query = "SELECT a.*, p.kode_pinjaman, p.anggota_id, p.tanggal_pencairan,
                 ag.kode_anggota, ag.nama_lengkap,
                 jp.nama_jenis as jenis_pinjaman
          FROM angsuran a 
          JOIN pinjaman p ON a.pinjaman_id = p.id 
          JOIN anggota ag ON p.anggota_id = ag.id 
          JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id 
          WHERE 1=1";

$params = [];

// Add date filter
if (!empty($start_date) && !empty($end_date)) {
    $query .= " AND (a.tanggal_jatuh_tempo BETWEEN :start_date AND :end_date 
                OR a.tanggal_bayar BETWEEN :start_date AND :end_date)";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
}

// Add status filter
if (!empty($filter_status)) {
    $query .= " AND a.status = :status";
    $params[':status'] = $filter_status;
}

// Add anggota filter
if (!empty($filter_anggota)) {
    $query .= " AND p.anggota_id = :anggota_id";
    $params[':anggota_id'] = $filter_anggota;
}

// Add pinjaman filter
if (!empty($filter_pinjaman)) {
    $query .= " AND p.id = :pinjaman_id";
    $params[':pinjaman_id'] = $filter_pinjaman;
}

$query .= " ORDER BY p.kode_pinjaman ASC, a.angsuran_ke ASC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$angsuran = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung total
$total_angsuran = 0;
$total_pokok = 0;
$total_bunga = 0;
$total_denda = 0;
$total_lunas = 0;
$total_belum_bayar = 0;
$total_telat = 0;

foreach ($angsuran as $a) {
    $total_angsuran += $a['jumlah_angsuran'];
    $total_pokok += $a['pokok'];
    $total_bunga += $a['bunga'];
    $total_denda += $a['denda'];
    
    if ($a['status'] == 'lunas') $total_lunas++;
    if ($a['status'] == 'belum_bayar') $total_belum_bayar++;
    if ($a['status'] == 'telat') $total_telat++;
}

// Fungsi format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// ============================================
// BUAT PDF
// ============================================

// Buat PDF baru
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Koperasi System');
$pdf->SetAuthor('Administrator');
$pdf->SetTitle('Laporan Angsuran');
$pdf->SetSubject('Laporan Angsuran Pinjaman');

// Set default header data - KOSONGKAN HEADER BAWAAN
$pdf->setHeaderData('', 0, '', '', array(0,0,0), array(0,0,0));

// Set header and footer fonts
$pdf->setHeaderFont(Array('helvetica', '', 10));
$pdf->setFooterFont(Array('helvetica', '', 8));

// Set margins
$pdf->SetMargins(15, 45, 15);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 25);

// ============================================
// FUNGSI UNTUK MEMBUAT KOP
// ============================================
function buatKop($pdf, $koperasi) {
    $startY = 10;
    $pdf->SetY($startY);
    
    $logo_path = '../uploads/logo/' . ($koperasi['logo'] ?? '');
    
    if (isset($koperasi['logo']) && !empty($koperasi['logo']) && file_exists($logo_path)) {
        $pdf->Image($logo_path, 15, $startY, 30, 0, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
        
        $teksWidth = 232;
        $teksX = 15 + 30 + 5;
        
        $pdf->SetXY($teksX, $startY);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell($teksWidth, 8, $koperasi['nama'], 0, 1, 'C');
        
        $pdf->SetX($teksX);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($teksWidth, 5, $koperasi['alamat'], 0, 1, 'C');
        
        $pdf->SetX($teksX);
        $telp_email = 'Telp: ' . $koperasi['telepon'] . '   |   Email: ' . $koperasi['email'];
        $pdf->Cell($teksWidth, 5, $telp_email, 0, 1, 'C');
        
        $pdf->SetY($startY + 30);
        $pdf->SetX(15);
        $pdf->Cell(267, 0, '', 'T', 1, 'C');
    } else {
        $lebarPenuh = 267;
        
        $pdf->SetX(15);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell($lebarPenuh, 8, $koperasi['nama'], 0, 1, 'C');
        
        $pdf->SetX(15);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($lebarPenuh, 5, $koperasi['alamat'], 0, 1, 'C');
        
        $pdf->SetX(15);
        $pdf->Cell($lebarPenuh, 5, 'Telp: ' . $koperasi['telepon'] . ' | Email: ' . $koperasi['email'], 0, 1, 'C');
        
        $pdf->SetY($startY + 25);
        $pdf->SetX(15);
        $pdf->Cell($lebarPenuh, 0, '', 'T', 1, 'C');
    }
    $pdf->Ln(5);
}

// ============================================
// FUNGSI UNTUK MEMBUAT HEADER LAPORAN
// ============================================
function buatHeaderLaporan($pdf, $start_date, $end_date, $anggota_info, $status_info, $pinjaman_info) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'LAPORAN ANGSURAN PINJAMAN', 0, 1, 'C');
    $pdf->Ln(2);
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, 'Periode: ' . date('d/m/Y', strtotime($start_date)) . ' s/d ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');
    
    $filter_text = [];
    if (!empty($anggota_info)) $filter_text[] = 'Anggota: ' . $anggota_info;
    if (!empty($status_info)) $filter_text[] = 'Status: ' . $status_info;
    if (!empty($pinjaman_info)) $filter_text[] = 'Pinjaman: ' . $pinjaman_info;
    
    if (!empty($filter_text)) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, implode(' | ', $filter_text), 0, 1, 'C');
    }
    
    $pdf->Ln(2);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 5, 'Tanggal Cetak: ' . date('d/m/Y H:i:s'), 0, 1, 'R');
    $pdf->Ln(5);
}

// Add a page
$pdf->AddPage();

// Buat kop
buatKop($pdf, $koperasi);

// Buat header laporan
buatHeaderLaporan($pdf, $start_date, $end_date, $anggota_info, $status_info, $pinjaman_info);

// Ringkasan Statistik
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 7, 'RINGKASAN STATISTIK', 0, 1, 'L');
$pdf->Ln(2);

// Tabel Ringkasan
$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(240, 240, 240);

// Baris 1
$pdf->Cell(65, 7, 'Total Angsuran: ' . count($angsuran), 1, 0, 'L', 1);
$pdf->Cell(65, 7, 'Lunas: ' . $total_lunas, 1, 0, 'L', 1);
$pdf->Cell(65, 7, 'Belum Bayar: ' . $total_belum_bayar, 1, 0, 'L', 1);
$pdf->Cell(65, 7, 'Telat: ' . $total_telat, 1, 1, 'L', 1);

// Baris 2
$pdf->Cell(65, 7, 'Total Nilai: ' . formatRupiah($total_angsuran), 1, 0, 'L', 1);
$pdf->Cell(65, 7, 'Total Pokok: ' . formatRupiah($total_pokok), 1, 0, 'L', 1);
$pdf->Cell(65, 7, 'Total Bunga: ' . formatRupiah($total_bunga), 1, 0, 'L', 1);
$pdf->Cell(65, 7, 'Total Denda: ' . formatRupiah($total_denda), 1, 1, 'L', 1);

$pdf->Ln(5);

// Group by Pinjaman
$grouped_angsuran = [];
foreach ($angsuran as $a) {
    $pinjaman_key = $a['kode_pinjaman'];
    if (!isset($grouped_angsuran[$pinjaman_key])) {
        $grouped_angsuran[$pinjaman_key] = [
            'anggota' => $a['nama_lengkap'],
            'kode_anggota' => $a['kode_anggota'],
            'jenis_pinjaman' => $a['jenis_pinjaman'],
            'angsuran' => []
        ];
    }
    $grouped_angsuran[$pinjaman_key]['angsuran'][] = $a;
}

// Tabel per Pinjaman
$no_pinjaman = 1;
foreach ($grouped_angsuran as $kode_pinjaman => $data) {
    // Info Pinjaman
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(200, 220, 240);
    $pdf->Cell(0, 7, 'PINJAMAN #' . $no_pinjaman++ . ': ' . $kode_pinjaman . ' | Anggota: ' . $data['kode_anggota'] . ' - ' . $data['anggota'] . ' | Jenis: ' . $data['jenis_pinjaman'], 1, 1, 'L', 1);
    
    // Header Tabel dengan lebar yang konsisten
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(220, 220, 220);
    
    $col1 = 15;  // No
    $col2 = 25;  // Jatuh Tempo
    $col3 = 30;  // Pokok
    $col4 = 30;  // Bunga
    $col5 = 25;  // Denda
    $col6 = 35;  // Total
    $col7 = 25;  // Tgl Bayar
    $col8 = 20;  // Status
    $col9 = 20;  // Terlambat
    
    $pdf->Cell($col1, 7, 'No', 1, 0, 'C', 1);
    $pdf->Cell($col2, 7, 'Jatuh Tempo', 1, 0, 'C', 1);
    $pdf->Cell($col3, 7, 'Pokok', 1, 0, 'C', 1);
    $pdf->Cell($col4, 7, 'Bunga', 1, 0, 'C', 1);
    $pdf->Cell($col5, 7, 'Denda', 1, 0, 'C', 1);
    $pdf->Cell($col6, 7, 'Total', 1, 0, 'C', 1);
    $pdf->Cell($col7, 7, 'Tgl Bayar', 1, 0, 'C', 1);
    $pdf->Cell($col8, 7, 'Status', 1, 0, 'C', 1);
    $pdf->Cell($col9, 7, 'Terlambat', 1, 1, 'C', 1);
    
    // Data Angsuran
    $pdf->SetFont('helvetica', '', 7);
    $total_pinjaman_pokok = 0;
    $total_pinjaman_bunga = 0;
    $total_pinjaman_denda = 0;
    $total_pinjaman_angsuran = 0;
    
    foreach ($data['angsuran'] as $a) {
        $total_pinjaman_pokok += $a['pokok'];
        $total_pinjaman_bunga += $a['bunga'];
        $total_pinjaman_denda += $a['denda'];
        $total_pinjaman_angsuran += $a['jumlah_angsuran'];
        
        // Alternating row colors
        $fill = ($pdf->GetY() % 2 == 0) ? 0 : 1;
        
        $pdf->Cell($col1, 6, $a['angsuran_ke'], 1, 0, 'C', $fill);
        $pdf->Cell($col2, 6, date('d/m/Y', strtotime($a['tanggal_jatuh_tempo'])), 1, 0, 'C', $fill);
        $pdf->Cell($col3, 6, formatRupiah($a['pokok']), 1, 0, 'R', $fill);
        $pdf->Cell($col4, 6, formatRupiah($a['bunga']), 1, 0, 'R', $fill);
        $pdf->Cell($col5, 6, formatRupiah($a['denda']), 1, 0, 'R', $fill);
        $pdf->Cell($col6, 6, formatRupiah($a['jumlah_angsuran']), 1, 0, 'R', $fill);
        $pdf->Cell($col7, 6, $a['tanggal_bayar'] ? date('d/m/Y', strtotime($a['tanggal_bayar'])) : '-', 1, 0, 'C', $fill);
        $pdf->Cell($col8, 6, ucfirst(str_replace('_', ' ', $a['status'])), 1, 0, 'C', $fill);
        $pdf->Cell($col9, 6, $a['keterlambatan_hari'] > 0 ? $a['keterlambatan_hari'] . ' hr' : '-', 1, 1, 'C', $fill);
    }
    
    // Subtotal per pinjaman
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell($col1, 7, '', 1, 0, 'C', 1);
    $pdf->Cell($col2, 7, 'SUBTOTAL', 1, 0, 'C', 1);
    $pdf->Cell($col3, 7, formatRupiah($total_pinjaman_pokok), 1, 0, 'R', 1);
    $pdf->Cell($col4, 7, formatRupiah($total_pinjaman_bunga), 1, 0, 'R', 1);
    $pdf->Cell($col5, 7, formatRupiah($total_pinjaman_denda), 1, 0, 'R', 1);
    $pdf->Cell($col6, 7, formatRupiah($total_pinjaman_angsuran), 1, 0, 'R', 1);
    $pdf->Cell($col7, 7, '', 1, 0, 'C', 1);
    $pdf->Cell($col8, 7, '', 1, 0, 'C', 1);
    $pdf->Cell($col9, 7, '', 1, 1, 'C', 1);
    
    $pdf->Ln(3);
}

// Grand Total
$pdf->Ln(3);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(200, 200, 200);
$pdf->Cell(0, 8, 'GRAND TOTAL', 1, 1, 'L', 1);

$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(240, 240, 240);

// Grand Total rows
$pdf->Cell(80, 7, 'Total Angsuran: ' . count($angsuran), 1, 0, 'L', 1);
$pdf->Cell(70, 7, 'Total Nilai: ' . formatRupiah($total_angsuran), 1, 0, 'L', 1);
$pdf->Cell(70, 7, 'Total Denda: ' . formatRupiah($total_denda), 1, 1, 'L', 1);

$pdf->Cell(80, 7, 'Total Pokok: ' . formatRupiah($total_pokok), 1, 0, 'L', 1);
$pdf->Cell(70, 7, 'Total Bunga: ' . formatRupiah($total_bunga), 1, 0, 'L', 1);
$pdf->Cell(70, 7, '', 1, 1, 'L', 1);

// Tanda tangan
$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, 'Jakarta, ' . date('d F Y'), 0, 1, 'R');
$pdf->Ln(10);
$pdf->Cell(0, 6, 'Administrator,', 0, 1, 'R');
$pdf->Ln(15);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, '(' . $_SESSION['nama_lengkap'] . ')', 0, 1, 'R');

// Output PDF
$pdf->Output('Laporan_Angsuran_' . date('Ymd') . '.pdf', 'I');
?>
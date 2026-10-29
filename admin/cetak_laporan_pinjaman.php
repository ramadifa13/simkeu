<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once '../includes/auth.php';

// Load TCPDF
require_once '../tcpdf/tcpdf.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

// Check if user has access to this page
if (!isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Filter parameters
$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_jenis = $_GET['jenis_pinjaman_id'] ?? '';
$filter_status = $_GET['status'] ?? '';

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

$jenis_info = '';
if (!empty($filter_jenis)) {
    $query_jenis = "SELECT nama_jenis FROM jenis_pinjaman WHERE id = :id";
    $stmt = $db->prepare($query_jenis);
    $stmt->bindParam(':id', $filter_jenis);
    $stmt->execute();
    $jenis = $stmt->fetch(PDO::FETCH_ASSOC);
    $jenis_info = $jenis ? $jenis['nama_jenis'] : '';
}

$status_info = '';
if (!empty($filter_status)) {
    $status_list = [
        'aktif' => 'Aktif',
        'lunas' => 'Lunas',
        'macet' => 'Macet'
    ];
    $status_info = $status_list[$filter_status] ?? '';
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

// Build query untuk ambil data pinjaman
$query = "SELECT 
            p.*, 
            a.kode_anggota, 
            a.nama_lengkap, 
            j.nama_jenis,
            (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as angsuran_terbayar,
            (SELECT COALESCE(SUM(pokok), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_pokok_dibayar,
            (SELECT COALESCE(SUM(bunga), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_bunga_dibayar,
            (SELECT COALESCE(SUM(denda), 0) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as total_denda_dibayar
          FROM pinjaman p 
          JOIN anggota a ON p.anggota_id = a.id 
          JOIN jenis_pinjaman j ON p.jenis_pinjaman_id = j.id 
          WHERE 1=1";

$params = [];

if (!empty($filter_anggota)) {
    $query .= " AND p.anggota_id = :anggota_id";
    $params[':anggota_id'] = $filter_anggota;
}

if (!empty($filter_jenis)) {
    $query .= " AND p.jenis_pinjaman_id = :jenis_pinjaman_id";
    $params[':jenis_pinjaman_id'] = $filter_jenis;
}

if (!empty($filter_status)) {
    $query .= " AND p.status = :status";
    $params[':status'] = $filter_status;
}

$query .= " ORDER BY p.tanggal_pencairan DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$pinjaman = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung total untuk footer
$total_pinjaman = 0;
$total_sisa = 0;
$total_dibayar = 0;
$total_bunga_dibayar = 0;
$total_denda_dibayar = 0;

foreach ($pinjaman as $p) {
    $total_pinjaman += $p['jumlah_pinjaman'];
    $total_sisa += $p['sisa_pokok'];
    $total_dibayar += $p['total_pokok_dibayar'];
    $total_bunga_dibayar += $p['total_bunga_dibayar'];
    $total_denda_dibayar += $p['total_denda_dibayar'];
}

// ============================================
// BUAT PDF
// ============================================

// Buat PDF baru
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Koperasi System');
$pdf->SetAuthor('Administrator');
$pdf->SetTitle('Laporan Pinjaman');
$pdf->SetSubject('Laporan Pinjaman Anggota');

// Set default header data - KOSONGKAN HEADER BAWAAN
$pdf->setHeaderData('', 0, '', '', array(0,0,0), array(0,0,0));

// Set header and footer fonts
$pdf->setHeaderFont(Array('helvetica', '', 10));
$pdf->setFooterFont(Array('helvetica', '', 8));

// Set margins
$pdf->SetMargins(15, 45, 15); // Margin atas 45mm untuk kop
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 25);

// ============================================
// FUNGSI UNTUK MEMBUAT KOP
// ============================================
function buatKop($pdf, $koperasi) {
    // Set posisi awal
    $startY = 10;
    $pdf->SetY($startY);
    
    // Cek apakah ada logo
    $logo_path = '../uploads/logo/' . ($koperasi['logo'] ?? '');
    
    if (isset($koperasi['logo']) && !empty($koperasi['logo']) && file_exists($logo_path)) {
        // Jika ada logo, tampilkan di kiri dengan ukuran proporsional
        $pdf->Image($logo_path, 15, $startY, 30, 0, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
        
        // Hitung posisi tengah untuk teks
        $teksWidth = 232; // 297 - 15 - 15 - 30 - 5
        $teksX = 15 + 30 + 5; // margin kiri + lebar logo + jarak
        
        // Nama Koperasi
        $pdf->SetXY($teksX, $startY);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell($teksWidth, 8, $koperasi['nama'], 0, 1, 'C');
        
        // Alamat
        $pdf->SetX($teksX);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($teksWidth, 5, $koperasi['alamat'], 0, 1, 'C');
        
        // Telepon dan Email
        $pdf->SetX($teksX);
        $telp_email = 'Telp: ' . $koperasi['telepon'] . '   |   Email: ' . $koperasi['email'];
        $pdf->Cell($teksWidth, 5, $telp_email, 0, 1, 'C');
        
        // Garis pemisah
        $pdf->SetY($startY + 30);
        $pdf->SetX(15);
        $pdf->Cell(267, 0, '', 'T', 1, 'C');
    } else {
        // Jika tidak ada logo, kop di tengah
        $lebarPenuh = 267; // 297 - 30
        
        // Nama Koperasi
        $pdf->SetX(15);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell($lebarPenuh, 8, $koperasi['nama'], 0, 1, 'C');
        
        // Alamat
        $pdf->SetX(15);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($lebarPenuh, 5, $koperasi['alamat'], 0, 1, 'C');
        
        // Telepon dan Email
        $pdf->SetX(15);
        $pdf->Cell($lebarPenuh, 5, 'Telp: ' . $koperasi['telepon'] . ' | Email: ' . $koperasi['email'], 0, 1, 'C');
        
        // Garis pemisah
        $pdf->SetY($startY + 25);
        $pdf->SetX(15);
        $pdf->Cell($lebarPenuh, 0, '', 'T', 1, 'C');
    }
    $pdf->Ln(5);
}

// ============================================
// FUNGSI UNTUK MEMBUAT HEADER LAPORAN
// ============================================
function buatHeaderLaporan($pdf, $filter_anggota, $filter_jenis, $filter_status, $anggota_info, $jenis_info, $status_info) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'LAPORAN PINJAMAN ANGGOTA', 0, 1, 'C');
    $pdf->Ln(2);
    
    $filter_text = [];
    if (!empty($anggota_info)) $filter_text[] = 'Anggota: ' . $anggota_info;
    if (!empty($jenis_info)) $filter_text[] = 'Jenis: ' . $jenis_info;
    if (!empty($status_info)) $filter_text[] = 'Status: ' . $status_info;
    
    if (!empty($filter_text)) {
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 6, implode(' | ', $filter_text), 0, 1, 'C');
    }
    
    $pdf->Ln(2);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Tanggal Cetak: ' . date('d/m/Y H:i:s'), 0, 1, 'R');
    $pdf->Ln(5);
}

// Add a page
$pdf->AddPage();

// Buat kop
buatKop($pdf, $koperasi);

// Buat header laporan
buatHeaderLaporan($pdf, $filter_anggota, $filter_jenis, $filter_status, $anggota_info, $jenis_info, $status_info);

// Tabel Pinjaman
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(200, 220, 240);
$pdf->Cell(10, 7, 'No', 1, 0, 'C', 1);
$pdf->Cell(25, 7, 'Kode', 1, 0, 'C', 1);
$pdf->Cell(40, 7, 'Nama Anggota', 1, 0, 'C', 1);
$pdf->Cell(35, 7, 'Jenis', 1, 0, 'C', 1);
$pdf->Cell(30, 7, 'Jumlah', 1, 0, 'C', 1);
$pdf->Cell(30, 7, 'Sisa Pokok', 1, 0, 'C', 1);
$pdf->Cell(30, 7, 'Dibayar', 1, 0, 'C', 1);
$pdf->Cell(20, 7, 'Tenor', 1, 0, 'C', 1);
$pdf->Cell(15, 7, 'Bunga', 1, 0, 'C', 1);
$pdf->Cell(25, 7, 'Tgl Cair', 1, 0, 'C', 1);
$pdf->Cell(20, 7, 'Status', 1, 1, 'C', 1);

$pdf->SetFont('helvetica', '', 8);
$no = 1;
foreach ($pinjaman as $p) {
    // Status badge color
    $status_class = 'bg-secondary';
    if ($p['status'] == 'aktif') $status_class = 'bg-warning';
    if ($p['status'] == 'lunas') $status_class = 'bg-success';
    if ($p['status'] == 'macet') $status_class = 'bg-danger';
    
    // Potong nama jika terlalu panjang
    $nama = strlen($p['nama_lengkap']) > 20 ? substr($p['nama_lengkap'], 0, 17) . '...' : $p['nama_lengkap'];
    
    $pdf->Cell(10, 6, $no++, 1, 0, 'C');
    $pdf->Cell(25, 6, $p['kode_pinjaman'], 1, 0, 'L');
    $pdf->Cell(40, 6, $nama, 1, 0, 'L');
    $pdf->Cell(35, 6, $p['nama_jenis'], 1, 0, 'L');
    $pdf->Cell(30, 6, formatRupiah($p['jumlah_pinjaman']), 1, 0, 'R');
    $pdf->Cell(30, 6, formatRupiah($p['sisa_pokok']), 1, 0, 'R');
    $pdf->Cell(30, 6, formatRupiah($p['total_pokok_dibayar']), 1, 0, 'R');
    $pdf->Cell(20, 6, $p['tenor'] . ' bln', 1, 0, 'C');
    $pdf->Cell(15, 6, labelBungaRow($p), 1, 0, 'C');
    $pdf->Cell(25, 6, date('d/m/Y', strtotime($p['tanggal_pencairan'])), 1, 0, 'C');
    $pdf->Cell(20, 6, ucfirst($p['status']), 1, 1, 'C');
}

// Total
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(10, 7, '', 1, 0, 'C', 1);
$pdf->Cell(25, 7, '', 1, 0, 'C', 1);
$pdf->Cell(40, 7, 'TOTAL', 1, 0, 'R', 1);
$pdf->Cell(35, 7, '', 1, 0, 'C', 1);
$pdf->Cell(30, 7, formatRupiah($total_pinjaman), 1, 0, 'R', 1);
$pdf->Cell(30, 7, formatRupiah($total_sisa), 1, 0, 'R', 1);
$pdf->Cell(30, 7, formatRupiah($total_dibayar), 1, 0, 'R', 1);
$pdf->Cell(20, 7, '', 1, 0, 'C', 1);
$pdf->Cell(15, 7, '', 1, 0, 'C', 1);
$pdf->Cell(25, 7, '', 1, 0, 'C', 1);
$pdf->Cell(20, 7, '', 1, 1, 'C', 1);

// Ringkasan Pembayaran
if ($total_bunga_dibayar > 0 || $total_denda_dibayar > 0) {
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(0, 6, 'RINCIAN PEMBAYARAN:', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(40, 6, 'Total Pokok Dibayar: ' . formatRupiah($total_dibayar), 0, 1, 'L');
    if ($total_bunga_dibayar > 0) {
        $pdf->Cell(40, 6, 'Total Bunga Dibayar: ' . formatRupiah($total_bunga_dibayar), 0, 1, 'L');
    }
    if ($total_denda_dibayar > 0) {
        $pdf->Cell(40, 6, 'Total Denda Dibayar: ' . formatRupiah($total_denda_dibayar), 0, 1, 'L');
    }
}

// Tanda tangan
$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, 'Jakarta, ' . date('d F Y'), 0, 1, 'R');
$pdf->Ln(10);
$pdf->Cell(0, 6, 'Administrator,', 0, 1, 'R');
$pdf->Ln(15);
$pdf->Cell(0, 6, '(' . $_SESSION['nama_lengkap'] . ')', 0, 1, 'R');

// Output PDF
$pdf->Output('Laporan_Pinjaman_' . date('Ymd') . '.pdf', 'I');
?>

<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Load TCPDF
require_once '../tcpdf/tcpdf.php';

// Cek akses admin
if (!isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Filter parameters
$filter_anggota = $_GET['anggota_id'] ?? '';
$filter_jenis = $_GET['jenis_simpanan_id'] ?? '';
$filter_tanggal_awal = $_GET['tanggal_awal'] ?? '2026-02-01';
$filter_tanggal_akhir = $_GET['tanggal_akhir'] ?? '2026-02-28';

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
    $query_jenis = "SELECT nama_jenis FROM jenis_simpanan WHERE id = :id";
    $stmt = $db->prepare($query_jenis);
    $stmt->bindParam(':id', $filter_jenis);
    $stmt->execute();
    $jenis = $stmt->fetch(PDO::FETCH_ASSOC);
    $jenis_info = $jenis ? $jenis['nama_jenis'] : '';
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

// Ambil data simpanan - HANYA JENIS SIMPANAN REAL (EXCLUDE ANG)
$query = "SELECT 
            a.id as anggota_id,
            a.kode_anggota, 
            a.nama_lengkap,
            j.id as jenis_id,
            j.kode_jenis,
            j.nama_jenis,
            j.minimum,
            j.maksimum,
            j.bagi_hasil,
            COALESCE((
                SELECT SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END)
                FROM transaksi t
                WHERE t.anggota_id = a.id 
                AND t.jenis_simpanan_id = j.id
                AND DATE(t.tanggal_transaksi) BETWEEN :tanggal_awal AND :tanggal_akhir
            ), 0) as saldo
          FROM anggota a
          CROSS JOIN jenis_simpanan j
          WHERE a.status = 'aktif'
          AND j.status = 'aktif'
          AND j.kode_jenis != 'ANG'
          HAVING saldo != 0";

$params = [
    ':tanggal_awal' => $filter_tanggal_awal,
    ':tanggal_akhir' => $filter_tanggal_akhir
];

if (!empty($filter_anggota)) {
    $query = str_replace("WHERE a.status = 'aktif'", "WHERE a.status = 'aktif' AND a.id = :anggota_id", $query);
    $params[':anggota_id'] = $filter_anggota;
}

if (!empty($filter_jenis)) {
    $query = str_replace("HAVING saldo != 0", "AND j.id = :jenis_id HAVING saldo != 0", $query);
    $params[':jenis_id'] = $filter_jenis;
}

$query .= " ORDER BY a.nama_lengkap, j.id";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$simpanan = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung summary per jenis - HANYA JENIS SIMPANAN REAL
$summary = [];
foreach ($simpanan as $s) {
    $jenis = $s['kode_jenis'];
    if (!isset($summary[$jenis])) {
        $summary[$jenis] = [
            'nama' => $s['nama_jenis'],
            'total' => 0,
            'anggota' => []
        ];
    }
    $summary[$jenis]['total'] += $s['saldo'];
    $summary[$jenis]['anggota'][] = $s['anggota_id'];
}

foreach ($summary as &$s) {
    $s['jumlah_anggota'] = count(array_unique($s['anggota']));
    unset($s['anggota']);
}

// Hitung total keseluruhan
$total_semua = array_sum(array_column($simpanan, 'saldo'));

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
$pdf->SetTitle('Laporan Simpanan');
$pdf->SetSubject('Laporan Simpanan Anggota');

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
        
        // Hitung posisi tengah untuk teks (lebar kertas 297mm - margin kiri 15mm - margin kanan 15mm - lebar logo 30mm - jarak 5mm = 232mm)
        $teksWidth = 232;
        $teksX = 15 + 30 + 5; // Posisi X = margin kiri + lebar logo + jarak
        
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
        
        // Garis pemisah (lebar penuh dari margin kiri ke kanan)
        $pdf->SetY($startY + 30);
        $pdf->SetX(15);
        $pdf->Cell(267, 0, '', 'T', 1, 'C');
    } else {
        // Jika tidak ada logo, kop di tengah dengan lebar penuh
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
function buatHeaderLaporan($pdf, $filter_tanggal_awal, $filter_tanggal_akhir, $anggota_info, $jenis_info) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'LAPORAN SIMPANAN ANGGOTA (NON-ANGSURAN)', 0, 1, 'C');
    $pdf->Ln(2);
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, 'Periode: ' . date('d/m/Y', strtotime($filter_tanggal_awal)) . ' s/d ' . date('d/m/Y', strtotime($filter_tanggal_akhir)), 0, 1, 'C');
    
    if (!empty($anggota_info)) {
        $pdf->Cell(0, 6, 'Anggota: ' . $anggota_info, 0, 1, 'C');
    }
    if (!empty($jenis_info)) {
        $pdf->Cell(0, 6, 'Jenis Simpanan: ' . $jenis_info, 0, 1, 'C');
    }
    $pdf->Ln(5);
}

// Add a page
$pdf->AddPage();

// Buat kop
buatKop($pdf, $koperasi);

// Buat header laporan
buatHeaderLaporan($pdf, $filter_tanggal_awal, $filter_tanggal_akhir, $anggota_info, $jenis_info);

// RINGKASAN PER JENIS
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'A. RINGKASAN PER JENIS SIMPANAN (NON-ANGSURAN)', 0, 1, 'L');
$pdf->Ln(2);

// Tabel Ringkasan
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(200, 220, 240);
$pdf->Cell(10, 7, 'No', 1, 0, 'C', 1);
$pdf->Cell(60, 7, 'Jenis Simpanan', 1, 0, 'C', 1);
$pdf->Cell(40, 7, 'Jumlah Anggota', 1, 0, 'C', 1);
$pdf->Cell(70, 7, 'Total Saldo', 1, 1, 'C', 1);

$pdf->SetFont('helvetica', '', 9);
$no = 1;
foreach ($summary as $s) {
    $pdf->Cell(10, 6, $no++, 1, 0, 'C');
    $pdf->Cell(60, 6, $s['nama'], 1, 0, 'L');
    $pdf->Cell(40, 6, $s['jumlah_anggota'] . ' orang', 1, 0, 'C');
    $pdf->Cell(70, 6, formatRupiah($s['total']), 1, 1, 'R');
}

// Total Ringkasan
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(10, 7, '', 1, 0, 'C', 1);
$pdf->Cell(60, 7, 'TOTAL', 1, 0, 'R', 1);
$pdf->Cell(40, 7, '', 1, 0, 'C', 1);
$pdf->Cell(70, 7, formatRupiah($total_semua), 1, 1, 'R', 1);

$pdf->Ln(10);

// DETAIL SIMPANAN
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'B. DETAIL SIMPANAN ANGGOTA (NON-ANGSURAN)', 0, 1, 'L');
$pdf->Ln(2);

// Tabel Detail
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(200, 220, 240);
$pdf->Cell(8, 7, 'No', 1, 0, 'C', 1);
$pdf->Cell(20, 7, 'Kode', 1, 0, 'C', 1);
$pdf->Cell(45, 7, 'Nama Anggota', 1, 0, 'C', 1);
$pdf->Cell(30, 7, 'Jenis', 1, 0, 'C', 1);
$pdf->Cell(35, 7, 'Saldo', 1, 0, 'C', 1);
$pdf->Cell(30, 7, 'Minimal', 1, 0, 'C', 1);
$pdf->Cell(30, 7, 'Maksimal', 1, 0, 'C', 1);
$pdf->Cell(20, 7, 'Bagi Hasil', 1, 1, 'C', 1);

$pdf->SetFont('helvetica', '', 8);
$no = 1;
$total_saldo = 0;
foreach ($simpanan as $s) {
    $total_saldo += $s['saldo'];
    
    // Potong nama jika terlalu panjang
    $nama = strlen($s['nama_lengkap']) > 25 ? substr($s['nama_lengkap'], 0, 22) . '...' : $s['nama_lengkap'];
    
    $pdf->Cell(8, 6, $no++, 1, 0, 'C');
    $pdf->Cell(20, 6, $s['kode_anggota'], 1, 0, 'L');
    $pdf->Cell(45, 6, $nama, 1, 0, 'L');
    $pdf->Cell(30, 6, $s['kode_jenis'], 1, 0, 'C');
    $pdf->Cell(35, 6, formatRupiah($s['saldo']), 1, 0, 'R');
    $pdf->Cell(30, 6, formatRupiah($s['minimum']), 1, 0, 'R');
    $pdf->Cell(30, 6, formatRupiah($s['maksimum']), 1, 0, 'R');
    $pdf->Cell(20, 6, $s['bagi_hasil'] . '%', 1, 1, 'C');
}

// Total Detail
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(8, 7, '', 1, 0, 'C', 1);
$pdf->Cell(20, 7, '', 1, 0, 'C', 1);
$pdf->Cell(45, 7, 'TOTAL', 1, 0, 'R', 1);
$pdf->Cell(30, 7, '', 1, 0, 'C', 1);
$pdf->Cell(35, 7, formatRupiah($total_saldo), 1, 0, 'R', 1);
$pdf->Cell(30, 7, '', 1, 0, 'C', 1);
$pdf->Cell(30, 7, '', 1, 0, 'C', 1);
$pdf->Cell(20, 7, '', 1, 1, 'C', 1);

// Tanda tangan
$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, 'Jakarta, ' . date('d F Y'), 0, 1, 'R');
$pdf->Ln(10);
$pdf->Cell(0, 6, 'Administrator,', 0, 1, 'R');
$pdf->Ln(15);
$pdf->Cell(0, 6, '(' . $_SESSION['nama_lengkap'] . ')', 0, 1, 'R');

// Output PDF
$pdf->Output('Laporan_Simpanan_' . date('Ymd') . '.pdf', 'I');
?>
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
if (!isTeller() && !isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Get date range
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

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

// ============================================
// AMBIL DATA JENIS SIMPANAN
// ============================================
$query_jenis = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' AND kode_jenis NOT IN ('ANG','BONUS','SHU') ORDER BY id";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_simpanan = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// STATISTIK TOTAL
// ============================================
$query_total = "SELECT 
    COUNT(*) as total_transaksi,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE 0 END), 0) as total_setoran,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'penarikan' THEN t.jumlah ELSE 0 END), 0) as total_penarikan
    FROM transaksi t
    JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
    WHERE DATE(t.tanggal_transaksi) BETWEEN :start_date AND :end_date AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')";
$stmt_total = $db->prepare($query_total);
$stmt_total->bindParam(':start_date', $start_date);
$stmt_total->bindParam(':end_date', $end_date);
$stmt_total->execute();
$total_stats = $stmt_total->fetch(PDO::FETCH_ASSOC);

if (!$total_stats) {
    $total_stats = [
        'total_transaksi' => 0,
        'total_setoran' => 0,
        'total_penarikan' => 0
    ];
}

// ============================================
// SALDO AKHIR PER JENIS SIMPANAN
// ============================================
$query_saldo = "SELECT 
    j.kode_jenis,
    COALESCE(SUM(s.saldo), 0) as saldo_akhir
    FROM jenis_simpanan j
    LEFT JOIN simpanan s ON j.id = s.jenis_simpanan_id
    WHERE j.status = 'aktif' AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
    GROUP BY j.id, j.kode_jenis
    ORDER BY j.id";
$stmt_saldo = $db->prepare($query_saldo);
$stmt_saldo->execute();
$saldo_list = $stmt_saldo->fetchAll(PDO::FETCH_ASSOC);

$saldo_map = [];
foreach ($saldo_list as $s) {
    $saldo_map[$s['kode_jenis']] = $s['saldo_akhir'];
}

// ============================================
// DETAIL TRANSAKSI
// ============================================
$query_transactions = "SELECT t.*, 
                              a.nama_lengkap,
                              a.kode_anggota,
                              j.kode_jenis,
                              j.nama_jenis,
                              u.nama_lengkap as teller
                      FROM transaksi t 
                      JOIN anggota a ON t.anggota_id = a.id 
                      JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                      JOIN users u ON t.user_id = u.id
                      WHERE DATE(t.tanggal_transaksi) BETWEEN :start_date AND :end_date 
                          AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
                      ORDER BY t.tanggal_transaksi DESC";
$stmt_transactions = $db->prepare($query_transactions);
$stmt_transactions->bindParam(':start_date', $start_date);
$stmt_transactions->bindParam(':end_date', $end_date);
$stmt_transactions->execute();
$transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUAT PDF
// ============================================

// Buat PDF baru
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Koperasi System');
$pdf->SetAuthor('Teller');
$pdf->SetTitle('Laporan Simpanan');
$pdf->SetSubject('Laporan Simpanan Anggota');

// Set default header data - KOSONGKAN HEADER BAWAAN
$pdf->setHeaderData('', 0, '', '', array(0,0,0), array(0,0,0));

// Set header and footer fonts
$pdf->setHeaderFont(Array('helvetica', '', 10));
$pdf->setFooterFont(Array('helvetica', '', 8));

// Set margins
$pdf->SetMargins(20, 45, 20); // Margin kiri dan kanan 20mm
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 25);

// ============================================
// FUNGSI UNTUK MEMBUAT KOP
// ============================================
function buatKop($pdf, $koperasi, $start_date, $end_date) {
    $startY = 10;
    $pdf->SetY($startY);
    
    $logo_path = '../uploads/logo/' . ($koperasi['logo'] ?? '');
    $lebar_kertas = 210; // A4 width in mm
    $margin = 20;
    $lebar_konten = $lebar_kertas - (2 * $margin); // 210 - 40 = 170mm
    
    if (isset($koperasi['logo']) && !empty($koperasi['logo']) && file_exists($logo_path)) {
        // Jika ada logo, lebar logo 25mm
        $lebar_logo = 25;
        $pdf->Image($logo_path, $margin, $startY, $lebar_logo, 0, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
        
        // Teks di tengah (lebar konten - lebar logo - jarak)
        $teksWidth = $lebar_konten - $lebar_logo - 5;
        $teksX = $margin + $lebar_logo + 5;
        
        $pdf->SetXY($teksX, $startY);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell($teksWidth, 7, $koperasi['nama'], 0, 1, 'C');
        
        $pdf->SetX($teksX);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell($teksWidth, 5, $koperasi['alamat'], 0, 1, 'C');
        
        $pdf->SetX($teksX);
        $telp_email = 'Telp: ' . $koperasi['telepon'] . '   |   Email: ' . $koperasi['email'];
        $pdf->Cell($teksWidth, 5, $telp_email, 0, 1, 'C');
        
        // Garis pemisah
        $pdf->SetY($startY + 27);
        $pdf->SetX($margin);
        $pdf->Cell($lebar_konten, 0, '', 'T', 1, 'C');
    } else {
        // Tanpa logo, teks di tengah
        $pdf->SetX($margin);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell($lebar_konten, 7, $koperasi['nama'], 0, 1, 'C');
        
        $pdf->SetX($margin);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell($lebar_konten, 5, $koperasi['alamat'], 0, 1, 'C');
        
        $pdf->SetX($margin);
        $pdf->Cell($lebar_konten, 5, 'Telp: ' . $koperasi['telepon'] . ' | Email: ' . $koperasi['email'], 0, 1, 'C');
        
        // Garis pemisah
        $pdf->SetY($startY + 22);
        $pdf->SetX($margin);
        $pdf->Cell($lebar_konten, 0, '', 'T', 1, 'C');
    }
    $pdf->Ln(5);
    
    // Judul Laporan
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell($lebar_konten, 7, 'LAPORAN SIMPANAN ANGGOTA', 0, 1, 'C');
    $pdf->Ln(2);
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell($lebar_konten, 6, 'Periode: ' . date('d/m/Y', strtotime($start_date)) . ' s/d ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');
    
    $pdf->Ln(2);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell($lebar_konten, 4, 'Tanggal Cetak: ' . date('d/m/Y H:i:s'), 0, 1, 'R');
    $pdf->Ln(5);
}

// Add a page
$pdf->AddPage();

// Buat kop
buatKop($pdf, $koperasi, $start_date, $end_date);

// ============================================
// RINGKASAN STATISTIK - DIPERBAIKI
// ============================================
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, 'RINGKASAN STATISTIK', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(240, 240, 240);

// Lebar kolom untuk ringkasan statistik (masing-masing 85mm, total 170mm)
$col_width = 85;

// Baris 1
$pdf->Cell($col_width, 6, 'Total Transaksi: ' . $total_stats['total_transaksi'], 1, 0, 'L', 1);
$pdf->Cell($col_width, 6, 'Total Setoran: ' . formatRupiah($total_stats['total_setoran']), 1, 1, 'L', 1);

// Baris 2
$pdf->Cell($col_width, 6, 'Total Penarikan: ' . formatRupiah($total_stats['total_penarikan']), 1, 0, 'L', 1);
$pdf->Cell($col_width, 6, 'Selisih: ' . formatRupiah($total_stats['total_setoran'] - $total_stats['total_penarikan']), 1, 1, 'L', 1);

$pdf->Ln(5);

// ============================================
// RINGKASAN PER JENIS SIMPANAN
// ============================================
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, 'RINGKASAN PER JENIS SIMPANAN', 0, 1, 'L');
$pdf->Ln(2);

// Header tabel dengan lebar fixed
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(200, 220, 240);

$col_no = 8;
$col_kode = 20;
$col_nama = 55;
$col_setoran = 35;
$col_penarikan = 35;
$col_saldo = 35;

$pdf->Cell($col_no, 7, 'No', 1, 0, 'C', 1);
$pdf->Cell($col_kode, 7, 'Kode', 1, 0, 'C', 1);
$pdf->Cell($col_nama, 7, 'Jenis Simpanan', 1, 0, 'C', 1);
$pdf->Cell($col_setoran, 7, 'Setoran', 1, 0, 'C', 1);
$pdf->Cell($col_penarikan, 7, 'Penarikan', 1, 0, 'C', 1);
$pdf->Cell($col_saldo, 7, 'Saldo Akhir', 1, 1, 'C', 1);

$pdf->SetFont('helvetica', '', 8);
$no = 1;

foreach ($jenis_simpanan as $jenis) {
    // Hitung total setoran
    $query_setoran = "SELECT COALESCE(SUM(jumlah), 0) as total 
                      FROM transaksi 
                      WHERE jenis_simpanan_id = :id 
                      AND jenis_transaksi = 'setoran'
                      AND DATE(tanggal_transaksi) BETWEEN :start AND :end";
    $stmt_setoran = $db->prepare($query_setoran);
    $stmt_setoran->bindParam(':id', $jenis['id']);
    $stmt_setoran->bindParam(':start', $start_date);
    $stmt_setoran->bindParam(':end', $end_date);
    $stmt_setoran->execute();
    $total_setoran = $stmt_setoran->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Hitung total penarikan
    $query_penarikan = "SELECT COALESCE(SUM(jumlah), 0) as total 
                        FROM transaksi 
                        WHERE jenis_simpanan_id = :id 
                        AND jenis_transaksi = 'penarikan'
                        AND DATE(tanggal_transaksi) BETWEEN :start AND :end";
    $stmt_penarikan = $db->prepare($query_penarikan);
    $stmt_penarikan->bindParam(':id', $jenis['id']);
    $stmt_penarikan->bindParam(':start', $start_date);
    $stmt_penarikan->bindParam(':end', $end_date);
    $stmt_penarikan->execute();
    $total_penarikan = $stmt_penarikan->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Saldo akhir
    $saldo_akhir = $saldo_map[$jenis['kode_jenis']] ?? 0;
    
    // Potong nama jika terlalu panjang
    $nama_jenis = $jenis['nama_jenis'];
    if (strlen($nama_jenis) > 20) {
        $nama_jenis = substr($nama_jenis, 0, 18) . '...';
    }
    
    $pdf->Cell($col_no, 6, $no++, 1, 0, 'C');
    $pdf->Cell($col_kode, 6, $jenis['kode_jenis'], 1, 0, 'C');
    $pdf->Cell($col_nama, 6, $nama_jenis, 1, 0, 'L');
    $pdf->Cell($col_setoran, 6, formatRupiah($total_setoran), 1, 0, 'R');
    $pdf->Cell($col_penarikan, 6, formatRupiah($total_penarikan), 1, 0, 'R');
    $pdf->Cell($col_saldo, 6, formatRupiah($saldo_akhir), 1, 1, 'R');
}

$pdf->Ln(5);

// ============================================
// DETAIL TRANSAKSI
// ============================================
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, 'DETAIL TRANSAKSI SIMPANAN', 0, 1, 'L');
$pdf->Ln(2);

if (empty($transactions)) {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Tidak ada transaksi pada periode ini', 0, 1, 'C');
} else {
    // Header tabel detail dengan lebar fixed
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetFillColor(200, 220, 240);
    
    $col_tgl = 15;
    $col_kode_trans = 22;
    $col_kode_anggota = 18;
    $col_nama = 40;
    $col_jenis = 15;
    $col_trans = 20;
    $col_jumlah = 30;
    $col_teller = 20;
    
    $pdf->Cell($col_tgl, 7, 'Tanggal', 1, 0, 'C', 1);
    $pdf->Cell($col_kode_trans, 7, 'Kode Transaksi', 1, 0, 'C', 1);
    $pdf->Cell($col_kode_anggota, 7, 'Kode Anggota', 1, 0, 'C', 1);
    $pdf->Cell($col_nama, 7, 'Nama Anggota', 1, 0, 'C', 1);
    $pdf->Cell($col_jenis, 7, 'Jenis', 1, 0, 'C', 1);
    $pdf->Cell($col_trans, 7, 'Transaksi', 1, 0, 'C', 1);
    $pdf->Cell($col_jumlah, 7, 'Jumlah', 1, 0, 'C', 1);
    $pdf->Cell($col_teller, 7, 'Teller', 1, 1, 'C', 1);
    
    $pdf->SetFont('helvetica', '', 6.5);
    foreach ($transactions as $t) {
        $tanggal = date('d/m/Y', strtotime($t['tanggal_transaksi']));
        $jenis_trans = ucfirst($t['jenis_transaksi']);
        $jumlah = ($t['jenis_transaksi'] == 'setoran' ? '+' : '-') . ' ' . formatRupiah($t['jumlah']);
        
        // Potong nama jika terlalu panjang
        $nama = $t['nama_lengkap'];
        if (strlen($nama) > 18) {
            $nama = substr($nama, 0, 15) . '...';
        }
        
        $pdf->Cell($col_tgl, 5, $tanggal, 1, 0, 'C');
        $pdf->Cell($col_kode_trans, 5, $t['kode_transaksi'], 1, 0, 'C');
        $pdf->Cell($col_kode_anggota, 5, $t['kode_anggota'], 1, 0, 'C');
        $pdf->Cell($col_nama, 5, $nama, 1, 0, 'L');
        $pdf->Cell($col_jenis, 5, $t['kode_jenis'], 1, 0, 'C');
        $pdf->Cell($col_trans, 5, $jenis_trans, 1, 0, 'C');
        $pdf->Cell($col_jumlah, 5, $jumlah, 1, 0, 'R');
        $pdf->Cell($col_teller, 5, $t['teller'], 1, 1, 'L');
    }
}

// Tanda tangan
$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, 'Jakarta, ' . date('d F Y'), 0, 1, 'R');
$pdf->Ln(10);
$pdf->Cell(0, 6, 'Teller,', 0, 1, 'R');
$pdf->Ln(15);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, '(' . $_SESSION['nama_lengkap'] . ')', 0, 1, 'R');

// Output PDF
$pdf->Output('Laporan_Simpanan_' . date('Ymd') . '.pdf', 'I');
?>

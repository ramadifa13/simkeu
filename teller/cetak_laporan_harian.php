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
if (!isTeller() && !isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Get tanggal parameter
$selected_date = $_GET['tanggal'] ?? date('Y-m-d');

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
// AMBIL DATA (SAMA DENGAN LAPORAN HARIAN)
// ============================================

// Ringkasan transaksi simpanan
$query_summary = "SELECT 
    COUNT(*) as total_transaksi,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE 0 END), 0) as total_setoran,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'penarikan' THEN t.jumlah ELSE 0 END), 0) as total_penarikan,
    COUNT(CASE WHEN t.jenis_transaksi = 'setoran' THEN 1 END) as jumlah_setoran,
    COUNT(CASE WHEN t.jenis_transaksi = 'penarikan' THEN 1 END) as jumlah_penarikan
    FROM transaksi t
    JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
    WHERE DATE(t.tanggal_transaksi) = :tanggal AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')";
$stmt_summary = $db->prepare($query_summary);
$stmt_summary->bindParam(':tanggal', $selected_date);
$stmt_summary->execute();
$summary = $stmt_summary->fetch(PDO::FETCH_ASSOC);

if (!$summary) {
    $summary = [
        'total_transaksi' => 0,
        'total_setoran' => 0,
        'total_penarikan' => 0,
        'jumlah_setoran' => 0,
        'jumlah_penarikan' => 0
    ];
}

// Ringkasan pembayaran angsuran
$query_angsuran_summary = "SELECT 
    COUNT(*) as total_angsuran,
    COALESCE(SUM(jumlah_angsuran + COALESCE(denda, 0)), 0) as total_pembayaran_angsuran
    FROM angsuran 
    WHERE tanggal_bayar = :tanggal AND status = 'lunas'";
$stmt_angsuran_summary = $db->prepare($query_angsuran_summary);
$stmt_angsuran_summary->bindParam(':tanggal', $selected_date);
$stmt_angsuran_summary->execute();
$angsuran_summary = $stmt_angsuran_summary->fetch(PDO::FETCH_ASSOC);

if (!$angsuran_summary) {
    $angsuran_summary = [
        'total_angsuran' => 0,
        'total_pembayaran_angsuran' => 0
    ];
}

// Ringkasan kas
$query_kas_masuk = "SELECT COALESCE(SUM(jumlah), 0) as total_penerimaan
    FROM kas
    WHERE jenis = 'masuk' AND DATE(tanggal) = :tanggal";
$stmt_kas_masuk = $db->prepare($query_kas_masuk);
$stmt_kas_masuk->bindParam(':tanggal', $selected_date);
$stmt_kas_masuk->execute();
$penerimaan = $stmt_kas_masuk->fetch(PDO::FETCH_ASSOC)['total_penerimaan'];

$query_kas_keluar = "SELECT COALESCE(SUM(jumlah), 0) as total_pengeluaran
    FROM kas
    WHERE jenis = 'keluar' AND DATE(tanggal) = :tanggal";
$stmt_kas_keluar = $db->prepare($query_kas_keluar);
$stmt_kas_keluar->bindParam(':tanggal', $selected_date);
$stmt_kas_keluar->execute();
$pengeluaran = $stmt_kas_keluar->fetch(PDO::FETCH_ASSOC)['total_pengeluaran'];

// Detail transaksi simpanan
$query_transactions = "SELECT t.*, 
                              a.kode_anggota,
                              a.nama_lengkap, 
                              j.kode_jenis,
                              j.nama_jenis, 
                              u.nama_lengkap as teller
                      FROM transaksi t 
                      JOIN anggota a ON t.anggota_id = a.id 
                      JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                      JOIN users u ON t.user_id = u.id
                      WHERE DATE(t.tanggal_transaksi) = :tanggal AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
                      ORDER BY t.tanggal_transaksi DESC";
$stmt_transactions = $db->prepare($query_transactions);
$stmt_transactions->bindParam(':tanggal', $selected_date);
$stmt_transactions->execute();
$transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

// Detail pembayaran angsuran
$query_angsuran = "SELECT a.*, 
                          ang.kode_anggota,
                          ang.nama_lengkap, 
                          p.kode_pinjaman,
                          (SELECT MAX(t.tanggal_transaksi) FROM transaksi t
                            WHERE t.keterangan = CONCAT('Pembayaran angsuran ke-', a.angsuran_ke, ' - ', p.kode_pinjaman)
                              AND t.jumlah > 0) AS waktu_bayar,
                          jp.nama_jenis as jenis_pinjaman
                  FROM angsuran a
                  JOIN pinjaman p ON a.pinjaman_id = p.id
                  JOIN anggota ang ON p.anggota_id = ang.id
                  JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                  WHERE a.tanggal_bayar = :tanggal AND a.status = 'lunas'
                  ORDER BY a.tanggal_bayar DESC, a.created_at DESC";
$stmt_angsuran = $db->prepare($query_angsuran);
$stmt_angsuran->bindParam(':tanggal', $selected_date);
$stmt_angsuran->execute();
$angsuran = $stmt_angsuran->fetchAll(PDO::FETCH_ASSOC);

// Detail transaksi kas
$query_kas = "SELECT k.*, t.kode_transaksi, a.nama_lengkap as anggota_nama
              FROM kas k
              LEFT JOIN transaksi t ON k.transaksi_id = t.id
              LEFT JOIN anggota a ON t.anggota_id = a.id
              WHERE DATE(k.tanggal) = :tanggal
              ORDER BY k.tanggal ASC";
$stmt_kas = $db->prepare($query_kas);
$stmt_kas->bindParam(':tanggal', $selected_date);
$stmt_kas->execute();
$kas_transactions = $stmt_kas->fetchAll(PDO::FETCH_ASSOC);

// Fungsi format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// ============================================
// BUAT PDF
// ============================================

// Buat PDF baru
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Koperasi System');
$pdf->SetAuthor('Teller');
$pdf->SetTitle('Laporan Harian');
$pdf->SetSubject('Laporan Harian Transaksi');

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
function buatKop($pdf, $koperasi, $selected_date) {
    $startY = 10;
    $pdf->SetY($startY);
    
    $logo_path = '../uploads/logo/' . ($koperasi['logo'] ?? '');
    
    if (isset($koperasi['logo']) && !empty($koperasi['logo']) && file_exists($logo_path)) {
        $pdf->Image($logo_path, 15, $startY, 30, 0, '', '', 'T', false, 300, '', false, false, 0, false, false, false);
        
        $teksWidth = 160;
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
        $pdf->Cell(180, 0, '', 'T', 1, 'C');
    } else {
        $pdf->SetX(15);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 8, $koperasi['nama'], 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, $koperasi['alamat'], 0, 1, 'C');
        $pdf->Cell(0, 5, 'Telp: ' . $koperasi['telepon'] . ' | Email: ' . $koperasi['email'], 0, 1, 'C');
        
        $pdf->Ln(2);
        $pdf->Cell(0, 0, '', 'T', 1, 'C');
    }
    $pdf->Ln(5);
}

// ============================================
// FUNGSI UNTUK MEMBUAT HEADER LAPORAN
// ============================================
function buatHeaderLaporan($pdf, $selected_date) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'LAPORAN HARIAN TRANSAKSI', 0, 1, 'C');
    $pdf->Ln(2);
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, 'Tanggal: ' . date('d F Y', strtotime($selected_date)), 0, 1, 'C');
    
    $pdf->Ln(2);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 5, 'Tanggal Cetak: ' . date('d/m/Y H:i:s'), 0, 1, 'R');
    $pdf->Ln(5);
}

// Add a page
$pdf->AddPage();

// Buat kop
buatKop($pdf, $koperasi, $selected_date);

// Buat header laporan
buatHeaderLaporan($pdf, $selected_date);

// ============================================
// RINGKASAN STATISTIK
// ============================================
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 7, 'RINGKASAN STATISTIK', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(240, 240, 240);

// Baris 1
$pdf->Cell(90, 7, 'Transaksi Simpanan: ' . $summary['total_transaksi'], 1, 0, 'L', 1);
$pdf->Cell(90, 7, 'Pembayaran Angsuran: ' . $angsuran_summary['total_angsuran'], 1, 1, 'L', 1);

// Baris 2
$pdf->Cell(90, 7, 'Total Setoran: ' . formatRupiah($summary['total_setoran']), 1, 0, 'L', 1);
$pdf->Cell(90, 7, 'Total Angsuran: ' . formatRupiah($angsuran_summary['total_pembayaran_angsuran']), 1, 1, 'L', 1);

// Baris 3
$pdf->Cell(90, 7, 'Total Penarikan: ' . formatRupiah($summary['total_penarikan']), 1, 0, 'L', 1);
$pdf->Cell(90, 7, 'Jumlah Setoran: ' . $summary['jumlah_setoran'] . ' transaksi', 1, 1, 'L', 1);

// Baris 4
$pdf->Cell(90, 7, 'Penerimaan Kas: ' . formatRupiah($penerimaan), 1, 0, 'L', 1);
$pdf->Cell(90, 7, 'Pengeluaran Kas: ' . formatRupiah($pengeluaran), 1, 1, 'L', 1);

// Baris 5 - Selisih
$selisih_kas = $penerimaan - $pengeluaran;
$pdf->SetFillColor(230, 230, 250);
$pdf->Cell(90, 7, 'Selisih Kas: ' . formatRupiah($selisih_kas), 1, 0, 'L', 1);
$pdf->Cell(90, 7, '', 1, 1, 'L', 1);

$pdf->Ln(5);

// ============================================
// TRANSAKSI SIMPANAN
// ============================================
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 7, 'A. TRANSAKSI SIMPANAN', 0, 1, 'L');
$pdf->Ln(2);

if (empty($transactions)) {
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 6, 'Tidak ada transaksi simpanan', 0, 1, 'L');
} else {
    // Header tabel
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(200, 200, 200);
    $pdf->Cell(20, 7, 'Waktu', 1, 0, 'C', 1);
    $pdf->Cell(25, 7, 'Kode', 1, 0, 'C', 1);
    $pdf->Cell(45, 7, 'Nama Anggota', 1, 0, 'C', 1);
    $pdf->Cell(20, 7, 'Jenis', 1, 0, 'C', 1);
    $pdf->Cell(25, 7, 'Transaksi', 1, 0, 'C', 1);
    $pdf->Cell(35, 7, 'Jumlah', 1, 1, 'C', 1);
    
    // Data
    $pdf->SetFont('helvetica', '', 7);
    foreach ($transactions as $t) {
        $waktu = date('H:i', strtotime($t['tanggal_transaksi']));
        $jenis_trans = ucfirst($t['jenis_transaksi']);
        $jumlah = ($t['jenis_transaksi'] == 'setoran' ? '+' : '-') . ' ' . formatRupiah($t['jumlah']);
        
        $pdf->Cell(20, 6, $waktu, 1, 0, 'C');
        $pdf->Cell(25, 6, $t['kode_anggota'], 1, 0, 'L');
        $pdf->Cell(45, 6, substr($t['nama_lengkap'], 0, 20), 1, 0, 'L');
        $pdf->Cell(20, 6, $t['kode_jenis'], 1, 0, 'C');
        $pdf->Cell(25, 6, $jenis_trans, 1, 0, 'C');
        $pdf->Cell(35, 6, $jumlah, 1, 1, 'R');
    }
}
$pdf->Ln(5);

// ============================================
// PEMBAYARAN ANGSURAN
// ============================================
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 7, 'B. PEMBAYARAN ANGSURAN', 0, 1, 'L');
$pdf->Ln(2);

if (empty($angsuran)) {
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 6, 'Tidak ada pembayaran angsuran', 0, 1, 'L');
} else {
    // Header tabel
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(200, 200, 200);
    $pdf->Cell(13, 7, 'Waktu', 1, 0, 'C', 1);
    $pdf->Cell(19, 7, 'Tgl Bayar', 1, 0, 'C', 1);
    $pdf->Cell(19, 7, 'Jatuh Tempo', 1, 0, 'C', 1);
    $pdf->Cell(20, 7, 'Kode', 1, 0, 'C', 1);
    $pdf->Cell(34, 7, 'Nama Anggota', 1, 0, 'C', 1);
    $pdf->Cell(25, 7, 'Pinjaman', 1, 0, 'C', 1);
    $pdf->Cell(10, 7, 'Ke', 1, 0, 'C', 1);
    $pdf->Cell(40, 7, 'Jumlah', 1, 1, 'C', 1);

    // Data
    $pdf->SetFont('helvetica', '', 7);
    foreach ($angsuran as $a) {
        $waktu = $a['waktu_bayar'] ? date('H:i', strtotime($a['waktu_bayar'])) : '-';
        $tgl_bayar = $a['tanggal_bayar'] ? date('d/m/Y', strtotime($a['tanggal_bayar'])) : '-';
        $tgl_tempo = date('d/m/Y', strtotime($a['tanggal_jatuh_tempo']));
        $jumlah = formatRupiah($a['jumlah_angsuran']);
        if ($a['denda'] > 0) {
            $jumlah .= ' + denda ' . formatRupiah($a['denda']);
        }
        
        $pdf->Cell(13, 6, $waktu, 1, 0, 'C');
        $pdf->Cell(19, 6, $tgl_bayar, 1, 0, 'C');
        $pdf->Cell(19, 6, $tgl_tempo, 1, 0, 'C');
        $pdf->Cell(20, 6, $a['kode_anggota'], 1, 0, 'L');
        $pdf->Cell(34, 6, substr($a['nama_lengkap'], 0, 18), 1, 0, 'L');
        $pdf->Cell(25, 6, $a['kode_pinjaman'], 1, 0, 'L');
        $pdf->Cell(10, 6, $a['angsuran_ke'], 1, 0, 'C');
        $pdf->Cell(40, 6, $jumlah, 1, 1, 'R');
    }
}
$pdf->Ln(5);

// ============================================
// TRANSAKSI KAS
// ============================================
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 7, 'C. TRANSAKSI KAS', 0, 1, 'L');
$pdf->Ln(2);

if (empty($kas_transactions)) {
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 6, 'Tidak ada transaksi kas', 0, 1, 'L');
} else {
    // Header tabel
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(200, 200, 200);
    $pdf->Cell(20, 7, 'Waktu', 1, 0, 'C', 1);
    $pdf->Cell(20, 7, 'Jenis', 1, 0, 'C', 1);
    $pdf->Cell(80, 7, 'Keterangan', 1, 0, 'C', 1);
    $pdf->Cell(35, 7, 'Jumlah', 1, 1, 'C', 1);
    
    // Data
    $pdf->SetFont('helvetica', '', 7);
    foreach ($kas_transactions as $k) {
        $waktu = date('H:i', strtotime($k['tanggal']));
        $jumlah = ($k['jenis'] == 'masuk' ? '+' : '-') . ' ' . formatRupiah($k['jumlah']);
        $keterangan = $k['keterangan'] ?: '-';
        if ($k['anggota_nama']) {
            $keterangan .= ' (' . $k['anggota_nama'] . ')';
        }
        
        $pdf->Cell(20, 6, $waktu, 1, 0, 'C');
        $pdf->Cell(20, 6, ucfirst($k['jenis']), 1, 0, 'C');
        $pdf->Cell(80, 6, substr($keterangan, 0, 40), 1, 0, 'L');
        $pdf->Cell(35, 6, $jumlah, 1, 1, 'R');
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
$pdf->Output('Laporan_Harian_' . date('Ymd', strtotime($selected_date)) . '.pdf', 'I');
?>
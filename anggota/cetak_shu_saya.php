<?php
require_once '../includes/auth.php';
require_once '../config/config.php';
require_once '../tcpdf/tcpdf.php';

// Cek akses anggota
if (!isAnggota()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

$user_id = $_SESSION['user_id'];
$tahun = $_GET['tahun'] ?? date('Y');

// Dapatkan data anggota berdasarkan user_id
$query_anggota = "SELECT a.*, u.username 
                  FROM anggota a 
                  LEFT JOIN users u ON a.user_id = u.id 
                  WHERE a.user_id = :user_id";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->bindParam(':user_id', $user_id);
$stmt_anggota->execute();
$anggota = $stmt_anggota->fetch(PDO::FETCH_ASSOC);

if (!$anggota) {
    die("Data anggota tidak ditemukan");
}

$anggota_id = $anggota['id'];

// Get data koperasi untuk kop surat
$query_koperasi = "SELECT * FROM koperasi WHERE id = 1";
$stmt_koperasi = $db->prepare($query_koperasi);
$stmt_koperasi->execute();
$koperasi = $stmt_koperasi->fetch(PDO::FETCH_ASSOC);

if (!$koperasi) {
    $koperasi = [
        'nama' => 'KOPERASI SIMPAN PINJAM',
        'alamat' => 'Jl. Koperasi No. 123',
        'telepon' => '(021) 1234-5678',
        'email' => 'info@koperasi.com',
        'logo' => ''
    ];
}

// ============================================
// CEK TABEL SHU
// ============================================
$shu_table_exists = false;
try {
    $check_table = $db->query("SHOW TABLES LIKE 'shu'");
    $shu_table_exists = $check_table->rowCount() > 0;
} catch (Exception $e) {
    // Table doesn't exist
}

// ============================================
// DATA SHU (JIKA TABEL ADA)
// ============================================
$shu_data = null;

if ($shu_table_exists) {
    // Ambil data SHU berdasarkan anggota_id dan tahun
    $query_shu = "SELECT * FROM shu WHERE anggota_id = :anggota_id AND tahun = :tahun";
    $stmt_shu = $db->prepare($query_shu);
    $stmt_shu->bindParam(':anggota_id', $anggota_id);
    $stmt_shu->bindParam(':tahun', $tahun);
    $stmt_shu->execute();
    $shu_data = $stmt_shu->fetch(PDO::FETCH_ASSOC);
}

// ============================================
// HITUNG DATA ANGGOTA UNTUK SHU
// ============================================

// Hitung total simpanan anggota
$query_simpanan = "SELECT 
    SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END) as total_saldo
FROM transaksi t
WHERE t.anggota_id = :anggota_id";
$stmt_simpanan = $db->prepare($query_simpanan);
$stmt_simpanan->bindParam(':anggota_id', $anggota_id);
$stmt_simpanan->execute();
$total_simpanan = $stmt_simpanan->fetch(PDO::FETCH_ASSOC)['total_saldo'] ?? 0;

// Hitung simpanan per jenis
$query_simpanan_per_jenis = "SELECT 
    js.kode_jenis,
    js.nama_jenis,
    COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END), 0) as saldo
FROM jenis_simpanan js
LEFT JOIN transaksi t ON js.id = t.jenis_simpanan_id AND t.anggota_id = :anggota_id
WHERE js.status = 'aktif' AND js.kode_jenis != 'ANG'
GROUP BY js.id, js.kode_jenis, js.nama_jenis
ORDER BY js.id";
$stmt_simpanan_per_jenis = $db->prepare($query_simpanan_per_jenis);
$stmt_simpanan_per_jenis->bindParam(':anggota_id', $anggota_id);
$stmt_simpanan_per_jenis->execute();
$simpanan_per_jenis = $stmt_simpanan_per_jenis->fetchAll(PDO::FETCH_ASSOC);

// Hitung total pinjaman
$query_pinjaman = "SELECT 
    COUNT(*) as total_pinjaman,
    COALESCE(SUM(jumlah_pinjaman), 0) as total_jumlah,
    COALESCE(SUM(sisa_pokok), 0) as total_sisa
FROM pinjaman 
WHERE anggota_id = :anggota_id AND status = 'aktif'";
$stmt_pinjaman = $db->prepare($query_pinjaman);
$stmt_pinjaman->bindParam(':anggota_id', $anggota_id);
$stmt_pinjaman->execute();
$pinjaman = $stmt_pinjaman->fetch(PDO::FETCH_ASSOC);

// Hitung total angsuran yang sudah dibayar
$query_angsuran = "SELECT 
    COUNT(*) as total_transaksi,
    COALESCE(SUM(a.jumlah_angsuran + COALESCE(a.denda, 0)), 0) as total_bayar
FROM angsuran a
JOIN pinjaman p ON a.pinjaman_id = p.id
WHERE p.anggota_id = :anggota_id AND a.status = 'lunas'";
$stmt_angsuran = $db->prepare($query_angsuran);
$stmt_angsuran->bindParam(':anggota_id', $anggota_id);
$stmt_angsuran->execute();
$angsuran = $stmt_angsuran->fetch(PDO::FETCH_ASSOC);

// Hitung total transaksi waserda
$query_waserda = "SELECT 
    COUNT(*) as total_transaksi,
    COALESCE(SUM(total_bayar), 0) as total_belanja
FROM waserda_transaksi
WHERE anggota_id = :anggota_id";
$stmt_waserda = $db->prepare($query_waserda);
$stmt_waserda->bindParam(':anggota_id', $anggota_id);
$stmt_waserda->execute();
$waserda = $stmt_waserda->fetch(PDO::FETCH_ASSOC);

// Hitung SHU (jika tidak ada di database, gunakan simulasi)
if ($shu_data) {
    $jumlah_shu = $shu_data['jumlah_shu'];
    $persentase = $shu_data['persentase'] ?? 5;
    $keterangan = $shu_data['keterangan'] ?? 'SHU Tahun ' . $tahun;
    $status = $shu_data['status'] ?? 'belum_dibagikan';
} else {
    // Simulasi SHU (5% dari total simpanan)
    $persentase = 5.0;
    $jumlah_shu = round($total_simpanan * $persentase / 100);
    $keterangan = 'SHU Tahun ' . $tahun . ' (Simulasi)';
    $status = ($tahun == date('Y')) ? 'belum_dibagikan' : 'sudah_dibagikan';
}

// Fungsi format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Extend TCPDF class
class PDF_SHU extends TCPDF {
    protected $koperasi;
    protected $anggota;
    
    public function setKoperasi($data) {
        $this->koperasi = $data;
    }
    
    public function setAnggota($data) {
        $this->anggota = $data;
    }
    
    public function Header() {
        $this->SetLeftMargin(20);
        $this->SetRightMargin(20);
        
        // Logo
        if (!empty($this->koperasi['logo']) && file_exists('../uploads/logo/' . $this->koperasi['logo'])) {
            $this->Image('../uploads/logo/' . $this->koperasi['logo'], 20, 10, 25, 0, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        
        // Kop Surat
        $this->SetY(10);
        $this->SetFont('helvetica', 'B', 16);
        $this->Cell(0, 8, $this->koperasi['nama'], 0, 1, 'C');
        $this->SetFont('helvetica', '', 10);
        $this->Cell(0, 5, $this->koperasi['alamat'], 0, 1, 'C');
        
        $kontak = '';
        if (!empty($this->koperasi['telepon'])) {
            $kontak .= 'Telp: ' . $this->koperasi['telepon'];
        }
        if (!empty($this->koperasi['email'])) {
            $kontak .= ' | Email: ' . $this->koperasi['email'];
        }
        $this->Cell(0, 5, $kontak, 0, 1, 'C');
        
        // Garis
        $this->SetLineWidth(0.5);
        $this->Line(20, $this->GetY() + 3, $this->getPageWidth() - 20, $this->GetY() + 3);
        $this->Ln(8);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetLineWidth(0.2);
        $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 5, 'Dicetak pada: ' . date('d/m/Y H:i:s') . ' | Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

// Buat PDF
$pdf = new PDF_SHU('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setKoperasi($koperasi);
$pdf->setAnggota($anggota);
$pdf->SetCreator('Koperasi System');
$pdf->SetAuthor('Anggota');
$pdf->SetTitle('Laporan SHU Anggota');
$pdf->SetMargins(20, 50, 20);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(20);
$pdf->SetAutoPageBreak(TRUE, 25);
$pdf->AddPage();

// ============================================
// KONTEN PDF
// ============================================

// Judul
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'LAPORAN SHU ANGGOTA', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 8, 'Tahun ' . $tahun, 0, 1, 'C');
$pdf->Ln(5);

// Info Anggota
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'DATA ANGGOTA', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$pdf->Cell(50, 7, 'Kode Anggota', 0, 0, 'L');
$pdf->Cell(5, 7, ':', 0, 0, 'L');
$pdf->Cell(0, 7, $anggota['kode_anggota'], 0, 1, 'L');

$pdf->Cell(50, 7, 'Nama Lengkap', 0, 0, 'L');
$pdf->Cell(5, 7, ':', 0, 0, 'L');
$pdf->Cell(0, 7, $anggota['nama_lengkap'], 0, 1, 'L');

$pdf->Cell(50, 7, 'Tanggal Daftar', 0, 0, 'L');
$pdf->Cell(5, 7, ':', 0, 0, 'L');
$pdf->Cell(0, 7, date('d/m/Y', strtotime($anggota['tanggal_daftar'])), 0, 1, 'L');

$pdf->Cell(50, 7, 'Status', 0, 0, 'L');
$pdf->Cell(5, 7, ':', 0, 0, 'L');
$pdf->Cell(0, 7, ucfirst($anggota['status']), 0, 1, 'L');
$pdf->Ln(5);

// Ringkasan SHU
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'RINGKASAN SHU TAHUN ' . $tahun, 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(60, 8, 'Jumlah SHU', 1, 0, 'L', true);
$pdf->Cell(5, 8, ':', 1, 0, 'C', true);
$pdf->Cell(0, 8, formatRupiah($jumlah_shu), 1, 1, 'L', true);

$pdf->Cell(60, 8, 'Persentase', 1, 0, 'L', true);
$pdf->Cell(5, 8, ':', 1, 0, 'C', true);
$pdf->Cell(0, 8, $persentase . '%', 1, 1, 'L', true);

$pdf->Cell(60, 8, 'Keterangan', 1, 0, 'L', true);
$pdf->Cell(5, 8, ':', 1, 0, 'C', true);
$pdf->Cell(0, 8, $keterangan, 1, 1, 'L', true);

$pdf->Cell(60, 8, 'Status', 1, 0, 'L', true);
$pdf->Cell(5, 8, ':', 1, 0, 'C', true);
$pdf->Cell(0, 8, ucwords(str_replace('_', ' ', $status)), 1, 1, 'L', true);
$pdf->Ln(10);

// Data Simpanan
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'RINCIAN SIMPANAN', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$pdf->SetFillColor(200, 200, 200);
$pdf->Cell(80, 7, 'Jenis Simpanan', 1, 0, 'C', true);
$pdf->Cell(50, 7, 'Kode', 1, 0, 'C', true);
$pdf->Cell(0, 7, 'Saldo', 1, 1, 'C', true);

$pdf->SetFillColor(255, 255, 255);
foreach ($simpanan_per_jenis as $s) {
    $pdf->Cell(80, 7, $s['nama_jenis'], 1, 0, 'L');
    $pdf->Cell(50, 7, $s['kode_jenis'], 1, 0, 'C');
    $pdf->Cell(0, 7, formatRupiah($s['saldo']), 1, 1, 'R');
}

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(130, 7, 'TOTAL SIMPANAN', 1, 0, 'R', true);
$pdf->Cell(0, 7, formatRupiah($total_simpanan), 1, 1, 'R', true);
$pdf->Ln(5);

// Aktivitas Anggota
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'AKTIVITAS ANGGOTA', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(80, 7, 'Total Pinjaman Aktif', 1, 0, 'L', true);
$pdf->Cell(5, 7, ':', 1, 0, 'C', true);
$pdf->Cell(0, 7, $pinjaman['total_pinjaman'] . ' pinjaman', 1, 1, 'L', true);

$pdf->Cell(80, 7, 'Jumlah Pinjaman', 1, 0, 'L', true);
$pdf->Cell(5, 7, ':', 1, 0, 'C', true);
$pdf->Cell(0, 7, formatRupiah($pinjaman['total_jumlah']), 1, 1, 'L', true);

$pdf->Cell(80, 7, 'Sisa Pinjaman', 1, 0, 'L', true);
$pdf->Cell(5, 7, ':', 1, 0, 'C', true);
$pdf->Cell(0, 7, formatRupiah($pinjaman['total_sisa']), 1, 1, 'L', true);

$pdf->Cell(80, 7, 'Total Angsuran Dibayar', 1, 0, 'L', true);
$pdf->Cell(5, 7, ':', 1, 0, 'C', true);
$pdf->Cell(0, 7, formatRupiah($angsuran['total_bayar'] ?? 0), 1, 1, 'L', true);

$pdf->Cell(80, 7, 'Total Belanja Waserda', 1, 0, 'L', true);
$pdf->Cell(5, 7, ':', 1, 0, 'C', true);
$pdf->Cell(0, 7, formatRupiah($waserda['total_belanja'] ?? 0), 1, 1, 'L', true);
$pdf->Ln(10);

// Perhitungan SHU
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'PERHITUNGAN SHU', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$pdf->MultiCell(0, 5, 'SHU dihitung berdasarkan ketentuan yang ditetapkan dalam Rapat Anggota Tahunan (RAT) dengan mempertimbangkan:', 0, 'L');
$pdf->Ln(2);

$pdf->Cell(10, 5, '1.', 0, 0, 'L');
$pdf->Cell(0, 5, 'Jasa Modal (berdasarkan simpanan)', 0, 1, 'L');

$pdf->Cell(10, 5, '2.', 0, 0, 'L');
$pdf->Cell(0, 5, 'Jasa Usaha (berdasarkan transaksi)', 0, 1, 'L');

$pdf->Cell(10, 5, '3.', 0, 0, 'L');
$pdf->Cell(0, 5, 'Lama Keanggotaan', 0, 1, 'L');
$pdf->Ln(5);

// Catatan
$pdf->SetFont('helvetica', 'I', 9);
$pdf->MultiCell(0, 5, 'Catatan: ' . ($shu_data ? 'Data SHU diambil dari sistem.' : 'Data SHU merupakan simulasi berdasarkan aktivitas Anda. SHU sesungguhnya akan dihitung setelah tutup buku.'), 0, 'L');

// Tanda tangan
$pdf->Ln(15);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(100, 6, 'Mengetahui,', 0, 0, 'L');
$pdf->Cell(0, 6, 'Hormat Kami,', 0, 1, 'L');
$pdf->Ln(20);
$pdf->Cell(100, 6, '(____________________)', 0, 0, 'L');
$pdf->Cell(0, 6, '(____________________)', 0, 1, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(100, 6, 'Ketua Koperasi', 0, 0, 'L');
$pdf->Cell(0, 6, 'Sekretaris', 0, 1, 'L');

// Output PDF
$filename = 'SHU_' . $anggota['kode_anggota'] . '_Tahun_' . $tahun . '.pdf';
$pdf->Output($filename, 'I');
exit;
?>
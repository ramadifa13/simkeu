<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Cek parameter ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ID anggota tidak valid!");
}

$id = $_GET['id'];

// Get member data
$query = "SELECT a.*, u.username
          FROM anggota a 
          LEFT JOIN users u ON a.user_id = u.id 
          WHERE a.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    die("Anggota tidak ditemukan!");
}

// Get ALL savings data
$savings_query = "SELECT 
    js.id,
    js.kode_jenis,
    js.nama_jenis,
    COALESCE(s.saldo, 0) as saldo
FROM jenis_simpanan js
LEFT JOIN simpanan s ON js.id = s.jenis_simpanan_id AND s.anggota_id = :id
WHERE js.status = 'aktif'
ORDER BY js.id";
$stmt_savings = $db->prepare($savings_query);
$stmt_savings->bindParam(':id', $id);
$stmt_savings->execute();
$savings_data = $stmt_savings->fetchAll(PDO::FETCH_ASSOC);

// Organize savings by type
$simpanan_pokok = 0;
$simpanan_wajib = 0;
$simpanan_sukarela = 0;
$simpanan_deposito = 0;
$simpanan_haji = 0;
$simpanan_umroh = 0;
$total_simpanan = 0;

foreach ($savings_data as $saving) {
    $total_simpanan += $saving['saldo'];
    
    switch ($saving['kode_jenis']) {
        case 'POKOK':
            $simpanan_pokok = $saving['saldo'];
            break;
        case 'WAJIB':
            $simpanan_wajib = $saving['saldo'];
            break;
        case 'SUKARELA':
            $simpanan_sukarela = $saving['saldo'];
            break;
        case 'DEPOSITO':
            $simpanan_deposito = $saving['saldo'];
            break;
        case 'HAJI':
            $simpanan_haji = $saving['saldo'];
            break;
        case 'UMROH':
            $simpanan_umroh = $saving['saldo'];
            break;
    }
}

// Get loan summary
$loan_query = "SELECT 
    COUNT(*) as total_pinjaman, 
    COALESCE(SUM(jumlah_pinjaman), 0) as total_jumlah,
    COALESCE(SUM(sisa_pokok), 0) as total_sisa
FROM pinjaman 
WHERE anggota_id = :id AND status = 'aktif'";
$stmt_loan = $db->prepare($loan_query);
$stmt_loan->bindParam(':id', $id);
$stmt_loan->execute();
$loan_summary = $stmt_loan->fetch(PDO::FETCH_ASSOC);

// Get active loans detail
$active_loans_query = "SELECT 
    p.kode_pinjaman,
    p.jumlah_pinjaman,
    p.sisa_pokok,
    p.tanggal_pencairan,
    p.jatuh_tempo,
    jp.nama_jenis,
    (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as angsuran_lunas,
    (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id) as total_angsuran
FROM pinjaman p
JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
WHERE p.anggota_id = :id AND p.status = 'aktif'
ORDER BY p.tanggal_pencairan DESC";
$stmt_loans = $db->prepare($active_loans_query);
$stmt_loans->bindParam(':id', $id);
$stmt_loans->execute();
$active_loans = $stmt_loans->fetchAll(PDO::FETCH_ASSOC);

// Get transaction history
$transactions_query = "SELECT 
    t.kode_transaksi,
    t.jenis_transaksi,
    t.jumlah,
    t.tanggal_transaksi,
    js.nama_jenis,
    u.nama_lengkap as teller
FROM transaksi t
JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id
JOIN users u ON t.user_id = u.id
WHERE t.anggota_id = :id
ORDER BY t.tanggal_transaksi DESC
LIMIT 10";
$stmt_transactions = $db->prepare($transactions_query);
$stmt_transactions->bindParam(':id', $id);
$stmt_transactions->execute();
$transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

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
        'email' => 'info@koperasi.com',
        'logo' => ''
    ];
}

// ============================================
// GENERATE PDF
// ============================================
require_once '../tcpdf/tcpdf.php';

// Extend TCPDF class dengan header modern
class PDF_DetailAnggota extends TCPDF {
    protected $koperasi;
    
    public function setKoperasi($data) {
        $this->koperasi = $data;
    }
    
    public function Header() {
        // Set margin
        $this->SetLeftMargin(20);
        $this->SetRightMargin(20);
        
        // Header dengan desain modern
        $headerY = 10;
        
        // Logo di kiri - dengan ukuran pas dan tidak keluar batas
        if (!empty($this->koperasi['logo']) && file_exists('../uploads/logo/' . $this->koperasi['logo'])) {
            // Cek ekstensi file untuk menentukan tipe gambar
            $ext = pathinfo($this->koperasi['logo'], PATHINFO_EXTENSION);
            $mime = '';
            if (strtolower($ext) == 'png') {
                $mime = 'PNG';
            } elseif (strtolower($ext) == 'jpg' || strtolower($ext) == 'jpeg') {
                $mime = 'JPEG';
            } elseif (strtolower($ext) == 'gif') {
                $mime = 'GIF';
            }
            
            if ($mime) {
                $this->Image('../uploads/logo/' . $this->koperasi['logo'], 20, 8, 25, 0, $mime, '', 'T', false, 300, '', false, false, 0, false, false, false);
            }
        }
        
        // Background kotak untuk teks kop
        $this->SetFillColor(245, 247, 250);
        $this->Rect(48, 8, $this->getPageWidth() - 68, 28, 'F');
        
        // Nama Koperasi
        $this->SetY(10);
        $this->SetX(50);
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(44, 62, 80); // Warna dark blue
        $this->Cell(0, 8, $this->koperasi['nama'], 0, 1, 'L');
        
        // Alamat
        $this->SetY(18);
        $this->SetX(50);
        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, $this->koperasi['alamat'], 0, 1, 'L');
        
        // Kontak
        $kontak = '';
        if (!empty($this->koperasi['telepon'])) {
            $kontak .= 'Telp: ' . $this->koperasi['telepon'];
        }
        if (!empty($this->koperasi['email'])) {
            $kontak .= ' | Email: ' . $this->koperasi['email'];
        }
        $this->SetY(23);
        $this->SetX(50);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5, $kontak, 0, 1, 'L');
        
        // Garis pembatas modern
        $this->SetY(38);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(52, 152, 219); // Warna biru
        $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
        
        // Garis tipis kedua
        $this->SetY(39);
        $this->SetLineWidth(0.1);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
        
        $this->SetY(45);
        $this->SetTextColor(0, 0, 0); // Reset warna
    }
    
    public function Footer() {
        // Posisi footer
        $this->SetY(-15);
        
        // Garis footer
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(52, 152, 219);
        $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
        
        // Text footer
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        
        $footerText = 'Dicetak pada: ' . date('d/m/Y H:i:s') . ' | Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages();
        $footerText .= ' | Laporan Anggota: ' . $this->koperasi['nama'];
        
        $this->Cell(0, 5, $footerText, 0, 0, 'C');
    }
    
    // Fungsi untuk membuat section header
    public function SectionHeader($title) {
        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 8, $title, 0, 1, 'L');
        
        $this->SetLineWidth(0.3);
        $this->SetDrawColor(52, 152, 219);
        $this->Line(20, $this->GetY(), 60, $this->GetY());
        
        $this->SetTextColor(0, 0, 0);
        $this->Ln(5);
    }
}

// Buat PDF
$pdf = new PDF_DetailAnggota('P', PDF_UNIT, 'A4', true, 'UTF-8', false);
$pdf->setKoperasi($koperasi);

$pdf->SetCreator('Koperasi System');
$pdf->SetAuthor('Administrator');
$pdf->SetTitle('Laporan Detail Anggota - ' . $member['nama_lengkap']);

$pdf->SetMargins(20, 50, 20);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(20);
$pdf->SetAutoPageBreak(TRUE, 25);

$pdf->AddPage();

// ============================================
// JUDUL LAPORAN
// ============================================
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(52, 152, 219);
$pdf->Cell(0, 10, 'LAPORAN DETAIL ANGGOTA', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

// ============================================
// PROFIL ANGGOTA
// ============================================
$pdf->SectionHeader('PROFIL ANGGOTA');

$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(250, 250, 250);

// Tabel profil dengan desain lebih modern
$html = '
<style>
.tabel-profil {
    width: 100%;
    border-collapse: collapse;
    font-family: helvetica;
    font-size: 9pt;
}
.tabel-profil td {
    padding: 5px;
    border: 0.5px solid #dddddd;
}
.label {
    background-color: #f2f2f2;
    font-weight: bold;
    width: 30%;
}
.value {
    width: 70%;
}
</style>

<table class="tabel-profil">
    <tr>
        <td class="label">Kode Anggota</td>
        <td class="value">: ' . $member['kode_anggota'] . '</td>
    </tr>
    <tr>
        <td class="label">Nama Lengkap</td>
        <td class="value">: ' . $member['nama_lengkap'] . '</td>
    </tr>
    <tr>
        <td class="label">Jenis Kelamin</td>
        <td class="value">: ' . ($member['jenis_kelamin'] == 'L' ? 'Laki-laki' : ($member['jenis_kelamin'] == 'P' ? 'Perempuan' : '-')) . '</td>
    </tr>
    <tr>
        <td class="label">Tempat/Tgl Lahir</td>
        <td class="value">: ' . $member['tempat_lahir'] . ', ' . ($member['tanggal_lahir'] ? date('d/m/Y', strtotime($member['tanggal_lahir'])) : '-') . '</td>
    </tr>
    <tr>
        <td class="label">Telepon</td>
        <td class="value">: ' . ($member['telepon'] ?: '-') . '</td>
    </tr>
    <tr>
        <td class="label">Email</td>
        <td class="value">: ' . ($member['email'] ?: '-') . '</td>
    </tr>
    <tr>
        <td class="label">Alamat</td>
        <td class="value">: ' . ($member['alamat'] ?: '-') . '</td>
    </tr>
    <tr>
        <td class="label">Status</td>
        <td class="value">: ' . ucfirst($member['status']) . '</td>
    </tr>
    <tr>
        <td class="label">Tanggal Daftar</td>
        <td class="value">: ' . date('d/m/Y', strtotime($member['tanggal_daftar'])) . '</td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, false, false, '');
$pdf->Ln(10);

// ============================================
// RINGKASAN SIMPANAN
// ============================================
$pdf->SectionHeader('RINGKASAN SIMPANAN');

$html_simpanan = '
<style>
.tabel-simpanan {
    width: 100%;
    border-collapse: collapse;
    font-family: helvetica;
    font-size: 9pt;
}
.tabel-simpanan th {
    background-color: #3498db;
    color: white;
    padding: 6px;
    text-align: center;
    border: 0.5px solid #2980b9;
}
.tabel-simpanan td {
    padding: 5px;
    border: 0.5px solid #dddddd;
}
.total-row {
    background-color: #e8f4fd;
    font-weight: bold;
}
</style>

<table class="tabel-simpanan">
    <tr>
        <th width="70%">Jenis Simpanan</th>
        <th width="30%">Saldo</th>
    </tr>
    <tr>
        <td>Simpanan Pokok</td>
        <td align="right">Rp ' . number_format($simpanan_pokok, 0, ',', '.') . '</td>
    </tr>
    <tr>
        <td>Simpanan Wajib</td>
        <td align="right">Rp ' . number_format($simpanan_wajib, 0, ',', '.') . '</td>
    </tr>
    <tr>
        <td>Simpanan Sukarela</td>
        <td align="right">Rp ' . number_format($simpanan_sukarela, 0, ',', '.') . '</td>
    </tr>
    <tr>
        <td>Deposito</td>
        <td align="right">Rp ' . number_format($simpanan_deposito, 0, ',', '.') . '</td>
    </tr>
    <tr>
        <td>Simpanan Haji</td>
        <td align="right">Rp ' . number_format($simpanan_haji, 0, ',', '.') . '</td>
    </tr>
    <tr>
        <td>Simpanan Umroh</td>
        <td align="right">Rp ' . number_format($simpanan_umroh, 0, ',', '.') . '</td>
    </tr>
    <tr class="total-row">
        <td><strong>TOTAL SIMPANAN</strong></td>
        <td align="right"><strong>Rp ' . number_format($total_simpanan, 0, ',', '.') . '</strong></td>
    </tr>
</table>';

$pdf->writeHTML($html_simpanan, true, false, false, false, '');
$pdf->Ln(10);

// ============================================
// RINGKASAN PINJAMAN
// ============================================
$pdf->SectionHeader('RINGKASAN PINJAMAN');

$html_pinjaman = '
<style>
.tabel-pinjaman {
    width: 100%;
    border-collapse: collapse;
    font-family: helvetica;
    font-size: 9pt;
}
.tabel-pinjaman td {
    padding: 5px;
    border: 0.5px solid #dddddd;
}
.label-pinjaman {
    background-color: #f2f2f2;
    font-weight: bold;
    width: 40%;
}
</style>

<table class="tabel-pinjaman">
    <tr>
        <td class="label-pinjaman">Total Pinjaman Aktif</td>
        <td>: ' . $loan_summary['total_pinjaman'] . ' pinjaman</td>
    </tr>
    <tr>
        <td class="label-pinjaman">Total Jumlah Pinjaman</td>
        <td>: Rp ' . number_format($loan_summary['total_jumlah'], 0, ',', '.') . '</td>
    </tr>
    <tr>
        <td class="label-pinjaman">Total Sisa Pinjaman</td>
        <td>: Rp ' . number_format($loan_summary['total_sisa'], 0, ',', '.') . '</td>
    </tr>
</table>';

$pdf->writeHTML($html_pinjaman, true, false, false, false, '');
$pdf->Ln(10);

// ============================================
// DETAIL PINJAMAN AKTIF
// ============================================
if (!empty($active_loans)) {
    $pdf->SectionHeader('DETAIL PINJAMAN AKTIF');
    
    $html_loan_detail = '
    <style>
    .tabel-loan {
        width: 100%;
        border-collapse: collapse;
        font-family: helvetica;
        font-size: 8pt;
    }
    .tabel-loan th {
        background-color: #f39c12;
        color: white;
        padding: 5px;
        text-align: center;
        border: 0.5px solid #e67e22;
    }
    .tabel-loan td {
        padding: 4px;
        border: 0.5px solid #dddddd;
    }
    </style>
    
    <table class="tabel-loan">
        <tr>
            <th width="18%">Kode</th>
            <th width="20%">Jenis</th>
            <th width="15%">Jumlah</th>
            <th width="15%">Sisa</th>
            <th width="15%">Tgl Cair</th>
            <th width="17%">Progress</th>
        </tr>';
    
    foreach ($active_loans as $loan) {
        $progress = $loan['total_angsuran'] > 0 ? round(($loan['angsuran_lunas'] / $loan['total_angsuran']) * 100) : 0;
        $html_loan_detail .= '
        <tr>
            <td>' . $loan['kode_pinjaman'] . '</td>
            <td>' . $loan['nama_jenis'] . '</td>
            <td align="right">Rp ' . number_format($loan['jumlah_pinjaman'], 0, ',', '.') . '</td>
            <td align="right">Rp ' . number_format($loan['sisa_pokok'], 0, ',', '.') . '</td>
            <td align="center">' . date('d/m/Y', strtotime($loan['tanggal_pencairan'])) . '</td>
            <td align="center">' . $loan['angsuran_lunas'] . '/' . $loan['total_angsuran'] . ' (' . $progress . '%)</td>
        </tr>';
    }
    
    $html_loan_detail .= '</table>';
    
    $pdf->writeHTML($html_loan_detail, true, false, false, false, '');
    $pdf->Ln(10);
}

// ============================================
// RIWAYAT TRANSAKSI
// ============================================
if (!empty($transactions)) {
    $pdf->SectionHeader('RIWAYAT TRANSAKSI SIMPANAN (10 TERAKHIR)');
    
    $html_transaksi = '
    <style>
    .tabel-transaksi {
        width: 100%;
        border-collapse: collapse;
        font-family: helvetica;
        font-size: 8pt;
    }
    .tabel-transaksi th {
        background-color: #27ae60;
        color: white;
        padding: 5px;
        text-align: center;
        border: 0.5px solid #229954;
    }
    .tabel-transaksi td {
        padding: 4px;
        border: 0.5px solid #dddddd;
    }
    .setoran {
        color: #27ae60;
        font-weight: bold;
    }
    .penarikan {
        color: #e74c3c;
        font-weight: bold;
    }
    </style>
    
    <table class="tabel-transaksi">
        <tr>
            <th width="15%">Tanggal</th>
            <th width="20%">Kode</th>
            <th width="20%">Jenis Simpanan</th>
            <th width="12%">Jenis</th>
            <th width="18%">Jumlah</th>
            <th width="15%">Teller</th>
        </tr>';
    
    foreach ($transactions as $trx) {
        $class = $trx['jenis_transaksi'] == 'setoran' ? 'setoran' : 'penarikan';
        $html_transaksi .= '
        <tr>
            <td align="center">' . date('d/m/Y H:i', strtotime($trx['tanggal_transaksi'])) . '</td>
            <td>' . $trx['kode_transaksi'] . '</td>
            <td>' . $trx['nama_jenis'] . '</td>
            <td align="center" class="' . $class . '">' . ucfirst($trx['jenis_transaksi']) . '</td>
            <td align="right" class="' . $class . '">Rp ' . number_format($trx['jumlah'], 0, ',', '.') . '</td>
            <td>' . $trx['teller'] . '</td>
        </tr>';
    }
    
    $html_transaksi .= '</table>';
    
    $pdf->writeHTML($html_transaksi, true, false, false, false, '');
    $pdf->Ln(10);
}

// ============================================
// TANDA TANGAN
// ============================================
$pdf->Ln(15);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(100, 6, 'Mengetahui,', 0, 0, 'L');
$pdf->Cell(0, 6, 'Hormat Kami,', 0, 1, 'L');
$pdf->Ln(20);
$pdf->Cell(100, 6, '(____________________)', 0, 0, 'L');
$pdf->Cell(0, 6, '(____________________)', 0, 1, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(100, 6, 'Ketua Koperasi', 0, 0, 'L');
$pdf->Cell(0, 6, 'Teller', 0, 1, 'L');

// Output PDF
$pdf->Output('Laporan_Anggota_' . $member['kode_anggota'] . '_' . date('Ymd') . '.pdf', 'I');
exit;
?>
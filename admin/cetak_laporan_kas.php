<?php
require_once '../includes/auth.php';
require_once '../config/config.php';
require_once '../tcpdf/tcpdf.php';

// Cek akses admin
if (!isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Filter parameters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$filter_jenis = $_GET['jenis'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';

// Validasi tanggal
if (!empty($start_date) && !empty($end_date) && strtotime($start_date) > strtotime($end_date)) {
    die("Tanggal awal tidak boleh lebih besar dari tanggal akhir");
}

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

// Urutkan dari terlama ke terbaru
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

// Ambil saldo awal (sebelum transaksi pertama)
$saldo_awal = 0;
if (!empty($kas)) {
    $saldo_awal = $kas[0]['saldo_sebelum'];
}
$saldo_akhir = $saldo_awal + $total_masuk - $total_keluar;

// ============================================
// BUAT PDF
// ============================================

// Extend TCPDF class untuk custom header/footer
class PDF_Kas extends TCPDF {
    protected $koperasi;
    protected $start_date;
    protected $end_date;
    protected $filter_jenis;
    protected $anggota_nama;
    protected $jenis_filter_labels;
    
    public function setKoperasi($data) {
        $this->koperasi = $data;
    }
    
    public function setFilter($start, $end, $jenis, $anggota, $jenis_labels) {
        $this->start_date = $start;
        $this->end_date = $end;
        $this->filter_jenis = $jenis;
        $this->anggota_nama = $anggota;
        $this->jenis_filter_labels = $jenis_labels;
    }
    
    public function Header() {
        // Set margin
        $this->SetLeftMargin(20);
        $this->SetRightMargin(20);
        
        // Logo di kiri
        $image_y = 10;
        if (!empty($this->koperasi['logo']) && file_exists('../uploads/logo/' . $this->koperasi['logo'])) {
            $ext = pathinfo($this->koperasi['logo'], PATHINFO_EXTENSION);
            $mime = strtoupper($ext);
            $this->Image('../uploads/logo/' . $this->koperasi['logo'], 20, 8, 25, 0, $mime, '', 'T', false, 300, '', false, false, 0, false, false, false);
            $image_y = 15;
        }
        
        // Background kotak untuk teks kop
        $this->SetFillColor(245, 247, 250);
        $this->Rect(48, 8, $this->getPageWidth() - 68, 32, 'F');
        
        // Nama Koperasi
        $this->SetY(10);
        $this->SetX(50);
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(44, 62, 80);
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
        
        // Garis pembatas
        $this->SetY(38);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(52, 152, 219);
        $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
        
        $this->SetY(39);
        $this->SetLineWidth(0.1);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
        
        $this->SetY(45);
        $this->SetTextColor(0, 0, 0);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(52, 152, 219);
        $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
        
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        
        $footerText = 'Dicetak pada: ' . date('d/m/Y H:i:s') . ' | Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages();
        $footerText .= ' | Laporan Kas - ' . $this->koperasi['nama'];
        
        $this->Cell(0, 5, $footerText, 0, 0, 'C');
    }
    
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

// Buat PDF instance
$pdf = new PDF_Kas('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
$pdf->setKoperasi($koperasi);

// Labels untuk filter jenis (TAMBAHKAN WASERDA)
$jenis_labels = [
    '' => 'Semua Transaksi',
    'setoran' => 'Setoran Simpanan',
    'penarikan' => 'Penarikan Simpanan',
    'angsuran' => 'Pembayaran Angsuran',
    'pencairan_pinjaman' => 'Pencairan Pinjaman',
    'waserda' => 'Waserda'
];

$pdf->setFilter($start_date, $end_date, $filter_jenis, $anggota_nama, $jenis_labels);

// Set document information
$pdf->SetCreator('Koperasi System');
$pdf->SetAuthor('Administrator');
$pdf->SetTitle('Laporan Kas');
$pdf->SetSubject('Laporan Kas Koperasi');

// Set margins
$pdf->SetMargins(20, 50, 20);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(20);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 25);

// Add a page
$pdf->AddPage();

// ============================================
// KONTEN PDF
// ============================================

// Judul Laporan
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(52, 152, 219);
$pdf->Cell(0, 10, 'LAPORAN KAS', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

// Periode
$pdf->SetFont('helvetica', '', 11);
$periode_text = 'Periode: ';
if (!empty($start_date) && !empty($end_date)) {
    $periode_text .= date('d/m/Y', strtotime($start_date)) . ' s/d ' . date('d/m/Y', strtotime($end_date));
} else {
    $periode_text .= 'Semua Transaksi';
}
$pdf->Cell(0, 8, $periode_text, 0, 1, 'C');

// Filter info
if (!empty($filter_jenis) || !empty($anggota_nama)) {
    $filter_text = '';
    if (!empty($filter_jenis)) {
        $filter_text .= 'Jenis: ' . ($jenis_labels[$filter_jenis] ?? $filter_jenis);
    }
    if (!empty($anggota_nama)) {
        if (!empty($filter_text)) $filter_text .= ' | ';
        $filter_text .= 'Anggota: ' . $anggota_nama;
    }
    $pdf->Cell(0, 6, $filter_text, 0, 1, 'C');
}
$pdf->Ln(5);

// Summary Cards dalam bentuk tabel
$pdf->SectionHeader('RINGKASAN KAS');

$pdf->SetFillColor(240, 240, 240);
$pdf->SetFont('helvetica', 'B', 10);

// Buat tabel ringkasan 2 kolom
$pdf->Cell(95, 8, 'Total Pemasukan', 1, 0, 'L', true);
$pdf->Cell(95, 8, ': ' . formatRupiah($total_masuk), 1, 1, 'L', true);

$pdf->Cell(95, 8, 'Total Pengeluaran', 1, 0, 'L', true);
$pdf->Cell(95, 8, ': ' . formatRupiah($total_keluar), 1, 1, 'L', true);

$pdf->Cell(95, 8, 'Saldo Awal', 1, 0, 'L', true);
$pdf->Cell(95, 8, ': ' . formatRupiah($saldo_awal), 1, 1, 'L', true);

$pdf->Cell(95, 8, 'Saldo Akhir', 1, 0, 'L', true);
$pdf->Cell(95, 8, ': ' . formatRupiah($saldo_akhir), 1, 1, 'L', true);
$pdf->Ln(5);

// Detail per Jenis (SEKARANG 5 JENIS + WASERDA)
$pdf->SectionHeader('RINCIAN PER JENIS TRANSAKSI');

$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(245, 245, 245);

$pdf->Cell(70, 7, 'Setoran Simpanan', 1, 0, 'L', true);
$pdf->Cell(60, 7, formatRupiah($total_setoran), 1, 1, 'R', true);

$pdf->Cell(70, 7, 'Penarikan Simpanan', 1, 0, 'L', true);
$pdf->Cell(60, 7, formatRupiah($total_penarikan), 1, 1, 'R', true);

$pdf->Cell(70, 7, 'Pembayaran Angsuran', 1, 0, 'L', true);
$pdf->Cell(60, 7, formatRupiah($total_angsuran), 1, 1, 'R', true);

$pdf->Cell(70, 7, 'Waserda', 1, 0, 'L', true);
$pdf->Cell(60, 7, formatRupiah($total_waserda), 1, 1, 'R', true);

$pdf->Cell(70, 7, 'Pencairan Pinjaman', 1, 0, 'L', true);
$pdf->Cell(60, 7, formatRupiah($total_pencairan), 1, 1, 'R', true);
$pdf->Ln(10);

// Detail Transaksi
$pdf->SectionHeader('DETAIL TRANSAKSI KAS');

if (empty($kas)) {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 10, 'Tidak ada data transaksi', 0, 1, 'C');
} else {
    // Header tabel
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(200, 200, 200);
    
    $col_no = 8;
    $col_tanggal = 25;
    $col_keterangan = 70;
    $col_kode = 25;
    $col_jenis = 20;
    $col_jumlah = 30;
    $col_saldo = 30;
    $col_sumber = 18;
    
    $pdf->Cell($col_no, 8, 'No', 1, 0, 'C', true);
    $pdf->Cell($col_tanggal, 8, 'Tanggal', 1, 0, 'C', true);
    $pdf->Cell($col_keterangan, 8, 'Keterangan', 1, 0, 'C', true);
    $pdf->Cell($col_kode, 8, 'Kode', 1, 0, 'C', true);
    $pdf->Cell($col_jenis, 8, 'Jenis', 1, 0, 'C', true);
    $pdf->Cell($col_jumlah, 8, 'Jumlah', 1, 0, 'C', true);
    $pdf->Cell($col_saldo, 8, 'Saldo', 1, 0, 'C', true);
    $pdf->Cell($col_sumber, 8, 'Sumber', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetFillColor(255, 255, 255);
    $no = 1;
    $running_balance = $saldo_awal;
    
    foreach ($kas as $k) {
        // Hitung running balance
        if ($k['jenis'] == 'masuk') {
            $running_balance += $k['jumlah'];
        } else {
            $running_balance -= $k['jumlah'];
        }
        
        // Cek apakah perlu page break
        if ($pdf->GetY() > 180) {
            $pdf->AddPage();
            // Header tabel di halaman baru
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(200, 200, 200);
            $pdf->Cell($col_no, 8, 'No', 1, 0, 'C', true);
            $pdf->Cell($col_tanggal, 8, 'Tanggal', 1, 0, 'C', true);
            $pdf->Cell($col_keterangan, 8, 'Keterangan', 1, 0, 'C', true);
            $pdf->Cell($col_kode, 8, 'Kode', 1, 0, 'C', true);
            $pdf->Cell($col_jenis, 8, 'Jenis', 1, 0, 'C', true);
            $pdf->Cell($col_jumlah, 8, 'Jumlah', 1, 0, 'C', true);
            $pdf->Cell($col_saldo, 8, 'Saldo', 1, 0, 'C', true);
            $pdf->Cell($col_sumber, 8, 'Sumber', 1, 1, 'C', true);
            $pdf->SetFont('helvetica', '', 7);
        }
        
        // Potong teks jika terlalu panjang
        $keterangan = substr($k['keterangan_lengkap'], 0, 60);
        if (strlen($k['keterangan_lengkap']) > 60) $keterangan .= '...';
        
        $kode = $k['kode_transaksi'] ? substr($k['kode_transaksi'], -10) : '-';
        $sumber = $k['sumber'] == 'kas' ? 'Kas' : ($k['sumber'] == 'pinjaman' ? 'Pinjaman' : 'Waserda');
        
        $pdf->Cell($col_no, 6, $no++, 1, 0, 'C');
        $pdf->Cell($col_tanggal, 6, date('d/m/Y H:i', strtotime($k['tanggal'])), 1, 0, 'C');
        $pdf->Cell($col_keterangan, 6, $keterangan, 1, 0, 'L');
        $pdf->Cell($col_kode, 6, $kode, 1, 0, 'C');
        $pdf->Cell($col_jenis, 6, ucfirst($k['jenis']), 1, 0, 'C');
        $pdf->Cell($col_jumlah, 6, number_format($k['jumlah'], 0, ',', '.'), 1, 0, 'R');
        $pdf->Cell($col_saldo, 6, number_format($running_balance, 0, ',', '.'), 1, 0, 'R');
        $pdf->Cell($col_sumber, 6, $sumber, 1, 1, 'C');
    }
    
    // Total row
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell($col_no, 6, '', 1, 0, 'C', true);
    $pdf->Cell($col_tanggal, 6, '', 1, 0, 'C', true);
    $pdf->Cell($col_keterangan, 6, 'TOTAL', 1, 0, 'R', true);
    $pdf->Cell($col_kode, 6, '', 1, 0, 'C', true);
    $pdf->Cell($col_jenis, 6, '', 1, 0, 'C', true);
    $pdf->Cell($col_jumlah, 6, number_format($total_masuk + $total_keluar, 0, ',', '.'), 1, 0, 'R', true);
    $pdf->Cell($col_saldo, 6, number_format($saldo_akhir, 0, ',', '.'), 1, 0, 'R', true);
    $pdf->Cell($col_sumber, 6, '', 1, 1, 'C', true);
}

// Keterangan
$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 8);
$pdf->MultiCell(0, 5, 'Catatan: Data pencairan pinjaman (sumber Pinjaman) diambil langsung dari tabel pinjaman. Data Waserda diambil dari tabel kas. Laporan ini digenerate otomatis oleh sistem.', 0, 'L');

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
$pdf->Cell(0, 6, 'Bendahara', 0, 1, 'L');

// Output PDF
$pdf->Output('Laporan_Kas_' . date('Ymd') . '.pdf', 'I');
exit;
?>
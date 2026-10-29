<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    die("Anda tidak memiliki akses ke halaman ini");
}

// Load TCPDF library
require_once '../tcpdf/tcpdf.php';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_jenis = $_GET['jenis_transaksi'] ?? '';
$filter_sumber = $_GET['sumber'] ?? '';
$filter_anggota = $_GET['anggota_id'] ?? '';

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
// AMBIL DATA DARI TABEL (LENGKAP DENGAN SPESIFIKASI)
// ============================================

// 1. DATA KAS
$kas = $db->query("SELECT * FROM kas ORDER BY tanggal DESC")->fetchAll(PDO::FETCH_ASSOC);

// 2. DATA PINJAMAN (dengan nama anggota)
$pinjaman = $db->query("SELECT p.*, a.nama_lengkap as nama_anggota, a.kode_anggota 
                        FROM pinjaman p 
                        LEFT JOIN anggota a ON p.anggota_id = a.id")->fetchAll(PDO::FETCH_ASSOC);

// 3. DATA TRANSAKSI (UNTUK MENDAPATKAN JENIS TABUNGAN)
$transaksi = $db->query("SELECT t.*, a.nama_lengkap, a.kode_anggota, js.nama_jenis as jenis_tabungan 
                        FROM transaksi t 
                        LEFT JOIN anggota a ON t.anggota_id = a.id 
                        LEFT JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id")->fetchAll(PDO::FETCH_ASSOC);

// Buat mapping transaksi_id ke jenis tabungan
$jenis_tabungan_map = [];
foreach ($transaksi as $t) {
    $jenis_tabungan_map[$t['id']] = $t['jenis_tabungan'] ?? 'Tabungan';
}

// ============================================
// GABUNGKAN DATA
// ============================================
$all_transaksi = array();

// Data kas (termasuk waserda dan tabungan)
foreach ($kas as $k) {
    $anggota_id = null;
    $nama_anggota = null;
    $kode_anggota = null;
    $tipe = 'Kas Lainnya';
    $jenis_kode = 'KAS';
    $sumber = 'Kas';
    $spesifikasi = '-';
    
    // Identifikasi tipe transaksi dari keterangan
    if (strpos($k['keterangan'], 'angsuran') !== false) {
        $tipe = 'Angsuran';
        $jenis_kode = 'ANG';
        $sumber = 'Angsuran';
        $spesifikasi = 'Angsuran Pinjaman';
    } elseif (strpos($k['keterangan'], 'Waserda') !== false) {
        $tipe = 'Waserda';
        $jenis_kode = 'WSR';
        $sumber = 'Waserda';
        $spesifikasi = 'Pembelian Barang';
    } elseif ($k['jumlah'] == 1000000 || $k['jumlah'] == 100000) {
        $tipe = 'Setoran';
        $jenis_kode = 'SET';
        $sumber = 'Tabungan';
        
        // Cari jenis tabungan dari transaksi_id
        if (!empty($k['transaksi_id']) && isset($jenis_tabungan_map[$k['transaksi_id']])) {
            $spesifikasi = $jenis_tabungan_map[$k['transaksi_id']];
        } else {
            $spesifikasi = 'Tabungan';
        }
    }
    
    // Cari data anggota
    if (!empty($k['transaksi_id'])) {
        foreach ($transaksi as $t) {
            if ($t['id'] == $k['transaksi_id']) {
                $anggota_id = $t['anggota_id'];
                $nama_anggota = $t['nama_lengkap'];
                $kode_anggota = $t['kode_anggota'];
                break;
            }
        }
    }
    
    $tgl = date('Y-m-d', strtotime($k['tanggal']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = array(
            'tanggal' => $k['tanggal'],
            'sumber' => $sumber,
            'spesifikasi' => $spesifikasi,
            'kode_transaksi' => 'KAS-' . $k['id'],
            'kode_anggota' => $kode_anggota,
            'nama_anggota' => $nama_anggota,
            'jenis_kode' => $jenis_kode,
            'tipe' => $tipe,
            'keterangan' => $k['keterangan'],
            'jumlah' => $k['jumlah'],
            'jenis' => $k['jenis']
        );
    }
}

// Data pinjaman
foreach ($pinjaman as $p) {
    $tgl = date('Y-m-d', strtotime($p['tanggal_pencairan']));
    if ($tgl >= $start_date && $tgl <= $end_date) {
        $all_transaksi[] = array(
            'tanggal' => $p['tanggal_pencairan'] . ' 23:59:59',
            'sumber' => 'Pinjaman',
            'spesifikasi' => 'Pencairan Pinjaman',
            'kode_transaksi' => $p['kode_pinjaman'],
            'kode_anggota' => $p['kode_anggota'] ?? '64534225',
            'nama_anggota' => $p['nama_anggota'] ?? 'smp2 siak',
            'jenis_kode' => 'PJN',
            'tipe' => 'Pencairan',
            'keterangan' => 'Pencairan Pinjaman',
            'jumlah' => $p['jumlah_pinjaman'],
            'jenis' => 'keluar'
        );
    }
}

// Urutkan berdasarkan tanggal (terbaru ke terlama)
usort($all_transaksi, function($a, $b) {
    return strtotime($b['tanggal']) - strtotime($a['tanggal']);
});

// ============================================
// FILTER
// ============================================
$filtered_transaksi = array();

foreach ($all_transaksi as $t) {
    // Filter sumber
    if (!empty($filter_sumber)) {
        $filter_sumber_lower = strtolower($filter_sumber);
        $sumber_lower = strtolower($t['sumber']);
        if ($filter_sumber_lower == 'kas' && $sumber_lower != 'kas' && $sumber_lower != 'waserda' && $sumber_lower != 'tabungan') continue;
        if ($filter_sumber_lower == 'tabungan' && $sumber_lower != 'tabungan') continue;
        if ($filter_sumber_lower == 'waserda' && $sumber_lower != 'waserda') continue;
        if ($filter_sumber_lower == 'pinjaman' && $sumber_lower != 'pinjaman') continue;
    }
    
    // Filter jenis
    if (!empty($filter_jenis) && strtolower($t['tipe']) != strtolower($filter_jenis)) {
        continue;
    }
    
    $filtered_transaksi[] = $t;
}

// ============================================
// HITUNG STATISTIK
// ============================================
$total_masuk = 0;
$total_keluar = 0;
$total_setoran = 0;
$total_angsuran = 0;
$total_waserda = 0;
$total_pencairan = 0;
$jumlah_setoran = 0;
$jumlah_angsuran = 0;
$jumlah_waserda = 0;
$jumlah_pencairan = 0;

foreach ($filtered_transaksi as $t) {
    if ($t['jenis'] == 'masuk') {
        $total_masuk += $t['jumlah'];
        if ($t['tipe'] == 'Setoran') {
            $total_setoran += $t['jumlah'];
            $jumlah_setoran++;
        } elseif ($t['tipe'] == 'Angsuran') {
            $total_angsuran += $t['jumlah'];
            $jumlah_angsuran++;
        } elseif ($t['tipe'] == 'Waserda') {
            $total_waserda += $t['jumlah'];
            $jumlah_waserda++;
        }
    } else {
        $total_keluar += $t['jumlah'];
        if ($t['tipe'] == 'Pencairan') {
            $total_pencairan += $t['jumlah'];
            $jumlah_pencairan++;
        }
    }
}

// Extend TCPDF class
class MYPDF extends TCPDF {
    protected $koperasi;
    
    public function setKoperasi($data) {
        $this->koperasi = $data;
    }
    
    public function Header() {
        $this->SetLeftMargin(15);
        $this->SetRightMargin(15);
        
        if (!empty($this->koperasi['logo']) && file_exists('../uploads/logo/' . $this->koperasi['logo'])) {
            $this->Image('../uploads/logo/' . $this->koperasi['logo'], 15, 10, 25, 0, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        
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
        
        $this->SetLineWidth(0.5);
        $this->Line(15, $this->GetY() + 3, $this->getPageWidth() - 15, $this->GetY() + 3);
        $this->Ln(8);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetLineWidth(0.2);
        $this->Line(15, $this->GetY(), $this->getPageWidth() - 15, $this->GetY());
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 5, 'Dicetak pada: ' . date('d/m/Y H:i:s') . ' | Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

// Buat PDF
$pdf = new MYPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
$pdf->setKoperasi($koperasi);
$pdf->SetCreator('Koperasi System');
$pdf->SetAuthor('Administrator');
$pdf->SetTitle('Laporan Semua Transaksi');
$pdf->SetMargins(15, 50, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(20);
$pdf->SetAutoPageBreak(TRUE, 25);
$pdf->AddPage();

// Judul
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'LAPORAN SEMUA TRANSAKSI', 0, 1, 'C');
$pdf->Ln(2);

// Periode
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 8, 'Periode: ' . date('d/m/Y', strtotime($start_date)) . ' s/d ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');

// Filter info
$filter_text = '';
if (!empty($filter_sumber)) {
    $filter_text .= 'Sumber: ' . ucfirst($filter_sumber);
}
if (!empty($filter_jenis)) {
    if (!empty($filter_text)) $filter_text .= ' | ';
    $filter_text .= 'Jenis: ' . ucfirst($filter_jenis);
}
if (!empty($filter_text)) {
    $pdf->Cell(0, 8, $filter_text, 0, 1, 'C');
}
$pdf->Ln(5);

// Summary
$pdf->SetFillColor(240, 240, 240);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'RINGKASAN TRANSAKSI', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$pdf->SetFillColor(255, 255, 255);
$pdf->Cell(95, 8, 'Total Semua Transaksi', 1, 0, 'L', true);
$pdf->Cell(95, 8, ': ' . count($filtered_transaksi) . ' transaksi', 1, 1, 'L', true);

$pdf->Cell(95, 8, 'Total Pemasukan', 1, 0, 'L', true);
$pdf->Cell(95, 8, ': ' . formatRupiah($total_masuk), 1, 1, 'L', true);

$pdf->Cell(95, 8, 'Total Pengeluaran', 1, 0, 'L', true);
$pdf->Cell(95, 8, ': ' . formatRupiah($total_keluar), 1, 1, 'L', true);

$pdf->Cell(95, 8, 'Selisih Kas', 1, 0, 'L', true);
$pdf->Cell(95, 8, ': ' . formatRupiah($total_masuk - $total_keluar), 1, 1, 'L', true);
$pdf->Ln(5);

// Rincian per kategori
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 8, 'RINCIAN PER KATEGORI', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);

$pdf->Cell(63, 7, 'Setoran Tabungan', 1, 0, 'L', true);
$pdf->Cell(63, 7, formatRupiah($total_setoran) . ' (' . $jumlah_setoran . 'x)', 1, 0, 'R', true);
$pdf->Cell(63, 7, 'Angsuran', 1, 0, 'L', true);
$pdf->Cell(63, 7, formatRupiah($total_angsuran) . ' (' . $jumlah_angsuran . 'x)', 1, 1, 'R', true);

$pdf->Cell(63, 7, 'Waserda', 1, 0, 'L', true);
$pdf->Cell(63, 7, formatRupiah($total_waserda) . ' (' . $jumlah_waserda . 'x)', 1, 0, 'R', true);
$pdf->Cell(63, 7, 'Pencairan Pinjaman', 1, 0, 'L', true);
$pdf->Cell(63, 7, formatRupiah($total_pencairan) . ' (' . $jumlah_pencairan . 'x)', 1, 1, 'R', true);
$pdf->Ln(10);

// Table header (DENGAN KOLOM SPESIFIKASI)
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(200, 200, 200);

$col_no = 8;
$col_tanggal = 25;
$col_sumber = 18;
$col_spesifikasi = 25;
$col_kode = 27;
$col_anggota = 18;
$col_nama = 27;
$col_jenis = 15;
$col_keterangan = 40;
$col_jumlah = 25;

$pdf->Cell($col_no, 10, 'No', 1, 0, 'C', true);
$pdf->Cell($col_tanggal, 10, 'Tanggal', 1, 0, 'C', true);
$pdf->Cell($col_sumber, 10, 'Sumber', 1, 0, 'C', true);
$pdf->Cell($col_spesifikasi, 10, 'Spesifikasi', 1, 0, 'C', true);
$pdf->Cell($col_kode, 10, 'Kode', 1, 0, 'C', true);
$pdf->Cell($col_anggota, 10, 'Kode Angg', 1, 0, 'C', true);
$pdf->Cell($col_nama, 10, 'Nama Anggota', 1, 0, 'C', true);
$pdf->Cell($col_jenis, 10, 'Jns', 1, 0, 'C', true);
$pdf->Cell($col_keterangan, 10, 'Keterangan', 1, 0, 'C', true);
$pdf->Cell($col_jumlah, 10, 'Jumlah', 1, 1, 'C', true);

// Table data
$pdf->SetFont('helvetica', '', 7);
$no = 1;
$total_display = 0;

foreach ($filtered_transaksi as $t) {
    $total_display += $t['jumlah'];
    
    if ($pdf->GetY() > 180) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(200, 200, 200);
        $pdf->Cell($col_no, 10, 'No', 1, 0, 'C', true);
        $pdf->Cell($col_tanggal, 10, 'Tanggal', 1, 0, 'C', true);
        $pdf->Cell($col_sumber, 10, 'Sumber', 1, 0, 'C', true);
        $pdf->Cell($col_spesifikasi, 10, 'Spesifikasi', 1, 0, 'C', true);
        $pdf->Cell($col_kode, 10, 'Kode', 1, 0, 'C', true);
        $pdf->Cell($col_anggota, 10, 'Kode Angg', 1, 0, 'C', true);
        $pdf->Cell($col_nama, 10, 'Nama Anggota', 1, 0, 'C', true);
        $pdf->Cell($col_jenis, 10, 'Jns', 1, 0, 'C', true);
        $pdf->Cell($col_keterangan, 10, 'Keterangan', 1, 0, 'C', true);
        $pdf->Cell($col_jumlah, 10, 'Jumlah', 1, 1, 'C', true);
        $pdf->SetFont('helvetica', '', 7);
    }
    
    $tanggal = date('d/m/Y', strtotime($t['tanggal']));
    $jam = date('H:i', strtotime($t['tanggal']));
    
    $pdf->Cell($col_no, 7, $no++, 1, 0, 'C');
    $pdf->Cell($col_tanggal, 7, $tanggal . "\n" . $jam, 1, 0, 'C');
    $pdf->Cell($col_sumber, 7, $t['sumber'], 1, 0, 'C');
    $pdf->Cell($col_spesifikasi, 7, $t['spesifikasi'] ?? '-', 1, 0, 'C');
    $pdf->Cell($col_kode, 7, $t['kode_transaksi'], 1, 0, 'L');
    $pdf->Cell($col_anggota, 7, $t['kode_anggota'] ?? '-', 1, 0, 'L');
    $pdf->Cell($col_nama, 7, $t['nama_anggota'] ?? '-', 1, 0, 'L');
    $pdf->Cell($col_jenis, 7, $t['jenis_kode'], 1, 0, 'C');
    $pdf->Cell($col_keterangan, 7, substr($t['keterangan'], 0, 35), 1, 0, 'L');
    $pdf->Cell($col_jumlah, 7, number_format($t['jumlah'], 0, ',', '.'), 1, 1, 'R');
}

// Total row
$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell($col_no, 7, '', 1, 0, 'C', true);
$pdf->Cell($col_tanggal, 7, '', 1, 0, 'C', true);
$pdf->Cell($col_sumber, 7, '', 1, 0, 'C', true);
$pdf->Cell($col_spesifikasi, 7, '', 1, 0, 'C', true);
$pdf->Cell($col_kode, 7, '', 1, 0, 'C', true);
$pdf->Cell($col_anggota, 7, '', 1, 0, 'C', true);
$pdf->Cell($col_nama, 7, 'TOTAL', 1, 0, 'R', true);
$pdf->Cell($col_jenis, 7, '', 1, 0, 'C', true);
$pdf->Cell($col_keterangan, 7, '', 1, 0, 'C', true);
$pdf->Cell($col_jumlah, 7, number_format($total_display, 0, ',', '.'), 1, 1, 'R', true);

// Catatan
$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 8);
$pdf->MultiCell(0, 5, 'Catatan: Laporan ini mencakup transaksi dari Kas, Tabungan (Deposito, Pokok, dll), Pencairan Pinjaman, dan Waserda.', 0, 'L');

// Tanda tangan
$pdf->Ln(10);
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
$pdf->Output('Laporan_Semua_Transaksi_' . date('Ymd') . '.pdf', 'I');
?>